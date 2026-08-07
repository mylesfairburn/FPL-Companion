"""
FastAPI backend for FPL Companion.

Run locally with:
    uvicorn main:app --reload
"""

import os

import pandas as pd
from fastapi import Body, FastAPI, Header, HTTPException, Request, Response
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from fastapi.staticfiles import StaticFiles

import ai_manager
import ai_team
import db
import drafts
import gameweek as gw_clock
import manager_history
import seasons
from pipeline import run_pipeline
from rating_model import get_rated_position_dfs
from fixture_rotator import (get_rotation_data, rank_rotation_pairs, recommend_pair_players, team_fixture_map)
from search import search_player
from squad_optimiser import DEFAULT_BUDGET, OptimisationError
from team_service import (get_team_view, get_league_standings, get_all_players, get_player_summary,
                          get_news_feed, get_underperforming_players)
from gameweek import detect_mode

# Set this in production. Once /api/refresh drives DB writes and a minute of
# pipeline work, leaving it open to the internet is a free denial-of-service;
# unset (local dev) it stays open so nothing breaks for a local run.
REFRESH_TOKEN = os.environ.get("FPL_REFRESH_TOKEN", "")

app = FastAPI()
templates = Jinja2Templates(directory="templates")
app.mount("/static", StaticFiles(directory="static"), name="static")
templates.env.cache = None  # workaround for a Jinja2/Starlette/Python 3.14 bug

state = {"mode": "preseason", "position_dfs": None, "rotation_df": None}


def load_data(mode=None):
    """Load rated data for `mode`, or auto-detect it from the first gameweek
    deadline when no mode is given (the normal path - there's no manual toggle).

    'inseason' needs current-season gameweek history to exist; if the deadline
    has passed but that data hasn't been pulled yet, fall back to preseason
    ratings rather than failing to start. The app still works, just off last
    season's form, and the next refresh picks up inseason once the data lands."""
    if mode is None:
        mode = detect_mode()
    print(f"Loading FPL data ({mode})...")
    data = run_pipeline()

    try:
        position_dfs = get_rated_position_dfs(data["position_dfs"], mode=mode)
    except ValueError as e:
        if mode != "inseason":
            raise
        print(f"Can't use inseason ratings yet ({e}) - falling back to preseason.")
        mode = "preseason"
        position_dfs = get_rated_position_dfs(data["position_dfs"], mode=mode)

    rotation_df = get_rotation_data(mode=mode, n_gameweeks=8)

    state["mode"] = mode
    state["position_dfs"] = position_dfs
    state["rotation_df"] = rotation_df
    clear_preview_cache()   # ratings moved; any cached AI squad is now stale
    print(f"Ready ({mode}).")


def _player_pool():
    """Flat rated player pool from the in-memory state, for DB read-joins."""
    if state["position_dfs"] is None:
        return []
    return get_all_players(state["position_dfs"]).get("players", [])


# Solving an ILP per page view would make the AI tabs feel slow and burn CPU
# re-deriving an answer that only changes when the underlying ratings do. These
# hold the live preview for the upcoming gameweek; load_data() clears them, so
# a refresh (or the nightly job) is what invalidates, not a timer.
_preview_cache = {"best_xv": {}, "manager": {}}


def clear_preview_cache():
    _preview_cache["best_xv"].clear()
    _preview_cache["manager"].clear()


def cached_best_xv(gameweek, budget=DEFAULT_BUDGET):
    key = (gameweek, budget)
    if key not in _preview_cache["best_xv"]:
        _preview_cache["best_xv"][key] = ai_team.build_best_xv(
            _player_pool(), gameweek, budget=budget)
    return _preview_cache["best_xv"][key]


def cached_manager_preview(gameweek):
    if gameweek not in _preview_cache["manager"]:
        _preview_cache["manager"][gameweek] = ai_manager.run_gameweek(
            _player_pool(), gameweek, persist=False)
    return _preview_cache["manager"][gameweek]


def require_refresh_token(token):
    if REFRESH_TOKEN and token != REFRESH_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid or missing refresh token.")


@app.on_event("startup")
def startup():
    # Creating the schema is idempotent, and doing it here means a fresh
    # container comes up with a usable DB without a manual init step.
    try:
        seasons.ensure_seeded()
    except Exception as e:
        print(f"WARNING: couldn't seed the data volume ({e}).")
    try:
        print(f"SQLite: {db.init_db()}")
    except Exception as e:
        print(f"WARNING: couldn't initialise SQLite ({e}); AI tabs will be unavailable.")
    load_data()

@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    return templates.TemplateResponse("index.html", {"request": request, "mode": state["mode"]})


@app.get("/api/search")
def search(q: str):
    if not q or state["position_dfs"] is None:
        return {"results": []}
    result = search_player(q, state["position_dfs"])
    if result is None:
        return {"results": []}
    return {"results": result.to_dict(orient="records")}


@app.get("/api/ratings")
def ratings(position: str = "All", top_n: int = 20):
    position_dfs = state["position_dfs"]
    if position_dfs is None:
        return {"results": []}

    if position == "All":
        combined = pd.concat([
            df.assign(position=pos) for pos, df in position_dfs.items()
        ])
    else:
        if position not in position_dfs:
            return {"results": []}
        combined = position_dfs[position].assign(position=position)

    cols = [c for c in ["web_name", "first_name", "second_name", "team_code",
                        "position", "rating", "predicted_points", "next_gameweeks"] if c in combined.columns]
    top = combined.sort_values("rating", ascending=False).head(top_n)
    return {"results": top[cols].to_dict(orient="records")}


@app.get("/api/rotation")
def rotation(category: str = "defender", n_gameweeks: int = 8, exclude_top_n: int = 4):
    rotation_df = state["rotation_df"]
    if rotation_df is None:
        return {"gameweeks": [], "teams": [], "pairs": []}

    difficulty_col = "defensive_difficulty" if category == "defender" else "attacking_difficulty"

    gameweeks = sorted(int(e) for e in rotation_df["event"].dropna().unique())
    fixture_map = team_fixture_map(rotation_df, difficulty_col)
    averages = rotation_df.groupby("team_name")[difficulty_col].mean().sort_values()

    def clean_fixtures(fixtures):
        return {
            str(gw): {"opponent": f["opponent"], "difficulty": float(f["difficulty"])}
            for gw, f in fixtures.items()
        }

    # short_name -> team_code, so the front end can render shirt images.
    team_code_map = {}
    if "team_code" in rotation_df.columns:
        team_code_map = {
            name: (int(code) if pd.notna(code) else None)
            for name, code in rotation_df.groupby("team_name")["team_code"].first().items()
        }

    teams = [
        {"team_name": team, "team_code": team_code_map.get(team),
         "average": round(float(avg), 1), "fixtures": clean_fixtures(fixture_map.get(team, {}))}
        for team, avg in averages.items()
    ]

    # Ranked rotation pairs: auto-starters (Man City/Arsenal etc) excluded, then
    # scored on never-stuck coverage + genuine week-on-week alternation, so the
    # cheap mid-table pairs a manager actually rotates rise to the top.
    raw_pairs = rank_rotation_pairs(rotation_df, difficulty_col, exclude_top_n=exclude_top_n)
    player_recs = recommend_pair_players(
        raw_pairs, state["position_dfs"], rotation_df, category=category
    ) if state["position_dfs"] is not None else []
    recs_by_pair = {(r["team_a"], r["team_b"]): r for r in player_recs}

    pairs = []
    for _, row in raw_pairs.iterrows():
        rec = recs_by_pair.get((row["team_a"], row["team_b"]), {})
        pairs.append({
            "team_a": row["team_a"],
            "team_b": row["team_b"],
            "team_a_code": team_code_map.get(row["team_a"]),
            "team_b_code": team_code_map.get(row["team_b"]),
            "worst_week": round(float(row["worst_week"]), 1),
            "avg_difficulty": round(float(row["avg_difficulty"]), 1),
            "improvement": round(float(row["improvement"]), 1),
            "team_a_fixtures": clean_fixtures(fixture_map.get(row["team_a"], {})),
            "team_b_fixtures": clean_fixtures(fixture_map.get(row["team_b"], {})),
            "position_pairs": rec.get("position_pairs", []),
        })

    return {"gameweeks": gameweeks, "teams": teams, "pairs": pairs}


@app.get("/api/team")
def team(team_id: int, event: int = None):
    """Full Team-tab payload for a manager id (optionally a specific gameweek).
    Read-only: recommendations are returned, but nothing is written back to FPL."""
    view = get_team_view(team_id, event, state["position_dfs"])
    # The gameweek being picked for. Before the season starts there's no
    # current_event at all, so without this the header has nothing to show but
    # the word "Preseason" - which doesn't tell you WHICH gameweek you're
    # picking for.
    try:
        view["next_event"] = gw_clock.next_gameweek()
    except Exception:
        view["next_event"] = None
    # Remember ids that resolve to a real manager, so the snapshot job has a
    # finite list to walk instead of all ~11M FPL entries. Never fatal.
    if view.get("header"):
        try:
            db.record_known_manager(team_id)
        except Exception as e:
            print(f"couldn't record known manager {team_id}: {e}")
    return view


@app.get("/api/news")
def news(response: Response, limit: int = 20):
    """Latest injury/transfer news snippets for the My Team news feed.

    Explicitly uncacheable: the payload is re-fetched from FPL on every call,
    so letting a browser (or any intermediary) reuse an old response is the
    one thing that would make the feed look stale."""
    response.headers["Cache-Control"] = "no-store, max-age=0"
    return get_news_feed(limit=limit)


@app.get("/api/league/{league_id}")
def league(league_id: int, page: int = 1):
    """Top of a classic league's standings table."""
    return get_league_standings(league_id, page)


@app.get("/api/all_players")
def all_players():
    """Full rated player pool - powers search/transfers and building a squad
    from empty pitch slots."""
    return get_all_players(state["position_dfs"])


@app.get("/api/underperforming")
def underperforming(top_n: int = 20):
    """Players whose actual returns lag their underlying xG/xGC numbers."""
    return get_underperforming_players(state["position_dfs"], top_n=top_n)


@app.get("/api/player/{player_id}")
def player(player_id: int):
    """Recent gameweek performance for a single player (player pop-up)."""
    return get_player_summary(player_id)


@app.get("/api/live/{gameweek}")
def live_scores(gameweek: int, response: Response = None):
    """Per-player points for a gameweek that's underway.

    One call covers every player, so the front end can light up a whole pitch
    from a single request and poll it cheaply while matches are on.

    `provisional` is the important field: FPL's bonus points aren't settled
    until `data_checked` flips, so totals shown before then WILL move. The UI
    labels them rather than presenting a mid-match number as final."""
    if response is not None:
        response.headers["Cache-Control"] = "no-store, max-age=0"
    events = gw_clock.get_events()
    started = gw_clock.started_gameweeks(events)
    if not started or gameweek > started[-1]:
        return {"available": False, "gameweek": gameweek,
                "detail": "That gameweek hasn't started yet."}
    points = gw_clock.get_event_live(gameweek)
    if not points:
        return {"available": False, "gameweek": gameweek,
                "detail": "Live data isn't available for that gameweek."}
    settled = gw_clock.gameweek_is_finished(gameweek, events)
    in_progress = gameweek == gw_clock.current_gameweek(events) and not settled
    return {"available": True, "gameweek": gameweek, "points": points,
            "provisional": not settled, "in_progress": in_progress}


# =========================================================================
#  Saved team (draft)
# =========================================================================
# Keyed on FPL id only - the same team follows a manager between devices.
# Deliberately unauthenticated, matching how the rest of the app treats an FPL
# id as the whole identity: anyone who knows the id can read or overwrite that
# draft. Fine while a draft is a scratchpad over already-public picks; revisit
# if it ever holds something private.

@app.get("/api/draft/{fpl_id}")
def get_draft(fpl_id: int):
    try:
        draft = drafts.get_draft(fpl_id, _player_pool())
    except Exception as e:
        return {"available": False, "detail": str(e)}
    if draft is None:
        return {"available": False, "detail": "No saved team for this FPL ID."}
    return {"available": True, **draft}


@app.post("/api/draft/{fpl_id}")
def save_draft(fpl_id: int, payload: dict = Body(...)):
    """Save the working squad. Replaces whatever was stored for this id."""
    try:
        result = drafts.save_draft(
            fpl_id, payload.get("picks") or [],
            gameweek=payload.get("gameweek") or gw_clock.next_gameweek(),
            bank=payload.get("bank"))
    except drafts.DraftError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
    try:
        db.record_known_manager(fpl_id)
    except Exception:
        pass
    return result


@app.delete("/api/draft/{fpl_id}")
def clear_draft(fpl_id: int):
    return drafts.delete_draft(fpl_id)


# =========================================================================
#  AI Manager
# =========================================================================

@app.get("/api/ai/manager")
def ai_manager_gameweek(gameweek: int = None):
    """The bot's squad for a gameweek, with the transfers and chip it chose.

    Stored weeks are served as recorded. The upcoming gameweek is simulated
    live (not persisted) so you can see what it intends before the deadline -
    the watcher is what commits a decision."""
    target = gameweek or gw_clock.next_gameweek() or gw_clock.current_gameweek()
    if target is None:
        return {"available": False, "detail": "No gameweek found."}
    pool = _player_pool()
    # Same reasoning as the Best-XV endpoint: the upcoming gameweek's plan is
    # simulated from the in-memory pool, so a database fault shouldn't blank
    # the tab. Record the fault and carry on.
    stored, db_error = None, None
    try:
        stored = ai_manager.get_gameweek(target, pool)
    except Exception as e:
        db_error = str(e)
    if stored:
        return {"available": True, "stored": True, **stored}

    started = gw_clock.started_gameweeks()
    if started and target <= started[-1]:
        return {"available": False, "gameweek": target,
                "detail": (f"Couldn't read stored gameweeks ({db_error})." if db_error
                           else f"The AI Manager wasn't running for GW{target}.")}
    if not pool:
        return {"available": False, "detail": "Ratings not loaded yet."}
    try:
        preview = cached_manager_preview(target)
    except OptimisationError as e:
        return {"available": False, "gameweek": target, "detail": str(e)}
    except Exception as e:
        return {"available": False, "gameweek": target,
                "detail": f"Couldn't simulate GW{target}: {e}"}
    return {"available": True, "stored": False, "db_error": db_error, **preview}


@app.get("/api/ai/manager/history")
def ai_manager_history():
    try:
        return {"available": True, "history": ai_manager.history()}
    except Exception as e:
        return {"available": False, "detail": str(e), "history": []}


# =========================================================================
#  AI Best-XV
# =========================================================================

@app.get("/api/ai/best_xv")
def ai_best_xv(gameweek: int = None, budget: float = DEFAULT_BUDGET):
    """The AI's optimum squad for a gameweek.

    Prefers the FROZEN snapshot taken at that gameweek's deadline. Falls back to
    solving live only for a gameweek that hasn't been snapshotted yet - i.e. the
    upcoming one, which is legitimately still changing as prices and predictions
    move. A past gameweek with no snapshot returns unavailable rather than a
    fabricated squad built from today's numbers."""
    target = gameweek or gw_clock.next_gameweek()
    if target is None:
        return {"available": False, "detail": "No upcoming gameweek found."}

    pool = _player_pool()
    # A database problem must not hide the upcoming gameweek's squad: that one
    # is solved from the in-memory pool and needs no stored data at all. Note
    # the fault, then carry on to the live path below.
    stored, db_error = None, None
    try:
        stored = ai_team.get_snapshot(target, pool)
    except Exception as e:
        db_error = str(e)
    if stored:
        return {"available": True, **stored}

    started = gw_clock.started_gameweeks()
    if started and target <= started[-1]:
        return {"available": False, "gameweek": target, "stored": False,
                "detail": (f"Couldn't read stored snapshots ({db_error})." if db_error
                           else f"GW{target} was never snapshotted - it started "
                                f"before this feature was running.")}

    if not pool:
        return {"available": False, "detail": "Ratings not loaded yet."}
    try:
        result = cached_best_xv(target, budget)
    except OptimisationError as e:
        return {"available": False, "gameweek": target, "detail": str(e)}
    # Live preview: deliberately not persisted. The deadline watcher owns
    # writing snapshots, so a page view can't freeze a half-formed squad.
    return {"available": True, "stored": False, "actual_points": None,
            "db_error": db_error, **result}


@app.get("/api/ai/history")
def ai_history():
    """Predicted vs actual for every frozen AI Best-XV snapshot."""
    try:
        return {"available": True, "snapshots": ai_team.list_snapshots()}
    except Exception as e:
        return {"available": False, "detail": str(e), "snapshots": []}


@app.get("/api/ai/status")
def ai_status():
    """DB health + season clock. Makes a failed volume mount visible instead of
    silently writing snapshots into a throwaway file inside the container."""
    events = gw_clock.get_events()
    return {
        "db": db.healthcheck(),
        "data": seasons.describe(),
        "mode": state["mode"],
        "current_gameweek": gw_clock.current_gameweek(events),
        "next_gameweek": gw_clock.next_gameweek(events),
        "processed_deadlines": db.processed_deadlines(),
        "known_managers": len(db.known_managers()),
    }


@app.get("/api/manager/{fpl_id}/history")
def manager_gw_history(fpl_id: int):
    """Captured gameweek history for one manager, with the AI's numbers for the
    same gameweeks alongside."""
    try:
        return {"available": True, "history": manager_history.manager_history(fpl_id)}
    except Exception as e:
        return {"available": False, "detail": str(e), "history": []}


@app.post("/api/mode")
def set_mode(mode: str):
    """Manual override for the auto-detected mode. Not used by the UI (the mode
    follows the first gameweek deadline now) - kept for testing and for forcing
    preseason ratings back on if inseason data turns out to be unusable."""
    if mode not in ("preseason", "inseason"):
        return {"status": "error", "detail": "mode must be 'preseason' or 'inseason'"}
    try:
        load_data(mode)
        return {"status": "ok", "mode": state["mode"]}
    except Exception as e:
        return {"status": "error", "detail": str(e)}


@app.post("/api/refresh")
def refresh(x_refresh_token: str = Header(default="")):
    """Re-pull everything, re-detecting the mode - so the app crosses over to
    inseason on its own once the first deadline passes, with no redeploy.

    Token-gated when FPL_REFRESH_TOKEN is set: this triggers a full pipeline run
    (~a minute of CPU) and is what the cron jobs call, so it shouldn't be
    anonymously reachable from the internet."""
    require_refresh_token(x_refresh_token)
    load_data()
    return {"status": "refreshed", "mode": state["mode"]}