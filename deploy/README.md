# Deploying the DB + scheduled jobs (Proxmox VM)

Everything here runs on the **host**, not inside the app container. The app runs
a single uvicorn worker holding rated data in an in-memory `state` dict, so a
second worker (or a job that imported and mutated that state) would drift out of
sync with it. The jobs instead write to SQLite and then poke `/api/refresh` so
the live process reloads.

## 1. Persistent volume for SQLite

The DB **must** live outside the image. The GitHub Actions → ghcr.io → `docker
pull` cycle replaces the image wholesale on every deploy, so an in-image file is
destroyed each time you ship.

```bash
mkdir -p /srv/fpl-companion/state
```

Run the container with that mounted at `/app/state` (the path `FPL_DB_PATH`
defaults to in the image). Note it is deliberately **not** `/app/data` — that
directory holds the CSVs baked into the image, and mounting over it would hide
them.

```bash
docker run -d --name fpl-companion \
  -p 8000:8000 \
  -v /srv/fpl-companion/state:/app/state \
  -e FPL_REFRESH_TOKEN="$(cat /srv/fpl-companion/refresh_token)" \
  ghcr.io/mylesfairburn/fpl-companion:latest
```

Or with compose — see `deploy/docker-compose.yml`.

Verify the mount actually took effect after any deploy:

```bash
curl -s localhost:8000/api/ai/status | python -m json.tool
```

`db.path` should be `/app/state/fpl_companion.db`, `journal_mode` should be
`wal`, and the row counts should be non-zero once a deadline has passed. If the
counts reset to 0 after a deploy, the volume is not mounted.

## 2. Lock down `/api/refresh`

It triggers a full pipeline run (about a minute of CPU) and now drives DB
writes, so it must not be anonymously reachable.

```bash
openssl rand -hex 32 > /srv/fpl-companion/refresh_token
chmod 600 /srv/fpl-companion/refresh_token
```

Pass it as `FPL_REFRESH_TOKEN` to both the container and the cron jobs. When the
variable is set, the endpoint requires a matching `X-Refresh-Token` header and
returns 403 otherwise. When it is unset (local dev) the endpoint stays open so
nothing breaks for a local run.

Better still, don't expose it at all: keep port 8000 bound to localhost and put
the reverse proxy in front, denying `/api/refresh` from outside.

## 3. Cron

Two jobs, because they answer to different clocks.

**Hourly deadline watcher.** Runs two phases per poll.

*Before* a deadline (inside the final ~100 minutes) it commits both AI squads.
The timing is the point: injuries and suspensions are confirmed in the day or
two before a deadline and FPL updates player status as it learns, so a squad
chosen early can be built around someone since ruled out. The window is wider
than the poll interval so a run always lands inside it; committing twice is a
no-op because the stored rows are the ledger.

*After* a deadline it captures real managers' picks, replaces their in-app
drafts with the official team, and backfills the AI squads only if the
pre-deadline window was missed (flagged `committed_after_deadline`).
 FPL deadlines are irregular — midweek rounds land
Tuesday/Wednesday evening, an early Saturday kickoff pulls the deadline to
11:00, international breaks skip weeks, and double/blank gameweeks compress or
drop rounds entirely. There is no daily slot that reliably lands just after one,
so this polls `deadline_time` hourly and acts when one has newly passed. It's
cheap and idempotent — a second run in the same hour is a no-op.

**Daily heavy refresh at 03:00.** Deliberately late rather than at midnight: a
Monday night game can finish around 22:00 and FPL's bonus-point finalisation
(the `data_checked` flag) often lags full time by an hour or more. A midnight
run risks freezing provisional scores that later change.

Install with:

```bash
cp deploy/fpl-companion.cron /etc/cron.d/fpl-companion
chmod 644 /etc/cron.d/fpl-companion
```

Edit the paths and token at the top of that file first.

## 4. First run

```bash
docker exec fpl-companion python jobs.py init-db
docker exec fpl-companion python jobs.py deadline-watch
```

The first `deadline-watch` against a mid-season DB marks every already-passed
gameweek as `skipped` rather than reconstructing it. That's intentional: the
model's `next_gameweeks` predictions roll forward once fixtures finish, so a
squad built "for" a past gameweek would actually be built from a later
gameweek's numbers. A visible gap is better than a fabricated record.

To force a specific gameweek (testing, or catching up within the 24h window):

```bash
docker exec fpl-companion python jobs.py deadline-watch --gameweek 5
```

## Data layout

`data/` is addressed through `python/seasons.py`, never by hardcoded path:

```
data/seasons/2025-26/    completed season - READ ONLY to the app (training data)
data/seasons/2026-27/    current season - the only directory ever written to
data/reference/          season-independent (positions, ClubElo)
data/models/             trained model bundles
```

Adding next season is creating `data/seasons/2027-28/` - no code change. The
trainer picks up every season from 2025-26 onward automatically, so rerunning
`python train_model.py` next August trains on two seasons instead of one.

`FPL_DATA_ROOT` overrides the root if you ever want data on a volume too.

### Nightly stats pull

`jobs.py daily-refresh` now pulls this season's per-gameweek player rows into
`data/seasons/<season>/gameweek_stats.csv`. That file is what `inseason` ratings
are built from - until it exists, the app serves preseason ratings off last
season's averages. It's ~one API call per player, hence nightly. Run it on its
own with `jobs.py refresh-stats`, or skip it in the daily run with
`--skip-stats`.

## What's stored

| Table | Holds |
|---|---|
| `manager_team` / `manager_team_picks` | One header + 15 picks per (manager, gameweek). `fpl_id 0` is reserved for the AI Manager bot. |
| `ai_team_snapshot` / `ai_team_snapshot_picks` | The AI Best-XV optimum frozen at each deadline. |
| `ai_transfer_log` | Created for the AI Manager; nothing writes to it yet. |
| `processed_deadline` | Idempotency ledger for the hourly watcher. |
| `known_manager` | FPL ids someone has actually looked up — the snapshot job walks this, not all ~11M entries. |

Player master data (names, teams, costs, ratings) is **not** duplicated into
SQLite. Rows carry `element_id` and join against the existing pandas/CSV
pipeline at read time, so there's one source of truth for who a player is.

`predicted_points` is frozen at write time and never recomputed. If the model
improves mid-season and old snapshots were re-derived from it, every
"predicted X, actually scored Y" comparison would retroactively change and the
track record would be meaningless.
