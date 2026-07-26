"""
Team view service for FPL Companion.

Everything here uses the PUBLIC FPL API (no login), so it can READ a manager's
team, points, bank, leagues and history - but it CANNOT write changes back to
FPL (transfers/lineup changes need an authenticated session). The optimise /
captain / transfer endpoints therefore return recommendations only; applying
them to the real team is done by the user in the FPL app.

Free-transfer count and "chips available" are not exposed by the public API, so
both are DERIVED here (walk the history / infer from used chips) and flagged as
estimates in the response.
"""

import requests

from fetch_data import get_bootstrap_data

BASE = "https://fantasy.premierleague.com/api"

# Position ids -> short labels, matching FPL's element_type.
POS_SHORT = {1: "GK", 2: "DEF", 3: "MID", 4: "FWD"}

# Formation legality for a starting XI (1 GK is always fixed separately).
MIN_OUTFIELD = {"DEF": 3, "MID": 2, "FWD": 1}
MAX_OUTFIELD = {"DEF": 5, "MID": 5, "FWD": 3}

# A single standard set of chips; "available" = this set minus what's been used.
# Kept deliberately simple - the exact yearly allocation (e.g. two of each per
# season split into halves) changes, so this is an approximation.
ALL_CHIPS = {"wildcard": "Wildcard", "freehit": "Free Hit",
             "bboost": "Bench Boost", "3xc": "Triple Captain"}


def _get(url):
    """GET returning parsed JSON, or None on any HTTP/connection error."""
    try:
        r = requests.get(url, timeout=10)
        r.raise_for_status()
        return r.json()
    except requests.exceptions.RequestException:
        return None


def _num(v):
    """Parse a possibly-string numeric field (e.g. FPL 'form') to float or None."""
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


_TEAM_SHORT = None


def _team_short_map():
    """team id -> short name (e.g. 1 -> 'ARS'), fetched once and cached."""
    global _TEAM_SHORT
    if _TEAM_SHORT is None:
        data = _get(f"{BASE}/bootstrap-static/")
        _TEAM_SHORT = {t["id"]: t["short_name"] for t in (data or {}).get("teams", [])}
    return _TEAM_SHORT or {}


def _build_element_index(position_dfs):
    """element_id -> everything the front end needs about that player, pulled
    from the already-rated position DataFrames (so ratings/predictions come for
    free without another model run)."""
    index = {}
    short = _team_short_map()
    for pos_name, df in position_dfs.items():
        for _, row in df.iterrows():
            next_gws = row.get("next_gameweeks")
            next_pts = None
            if isinstance(next_gws, list) and next_gws:
                next_pts = next_gws[0].get("points")

            predicted = next_pts
            if predicted is None:
                pp = row.get("predicted_points")
                predicted = float(pp) if pp is not None and pp == pp else 0.0

            index[int(row["id"])] = {
                "id": int(row["id"]),
                "web_name": row.get("web_name", ""),
                "pos": POS_SHORT.get(int(row["element_type"]), "?"),
                "element_type": int(row["element_type"]),
                "team_code": int(row["team_code"]) if row.get("team_code") == row.get("team_code") else None,
                "team": int(row["team"]) if row.get("team") == row.get("team") else None,
                "team_name": short.get(int(row["team"])) if row.get("team") == row.get("team") else None,
                "form": _num(row.get("form")),
                "next_gameweeks": next_gws if isinstance(next_gws, list) else [],
                "cost": round(float(row["now_cost"]) / 10, 1) if row.get("now_cost") is not None else None,
                "rating": round(float(row["rating"]), 1) if row.get("rating") == row.get("rating") else 0.0,
                "predicted": round(float(predicted), 1),
                "status": row.get("status", "a"),
                "news": row.get("news", "") or "",
                "next_gameweeks": (next_gws[:3] if isinstance(next_gws, list) else []),
            }
    return index


def _estimate_free_transfers(history):
    """Walk the season history to estimate free transfers for the NEXT GW.
    Rule modelled: start 1, +1 per gameweek, capped at 5, minus transfers made.
    Approximate - ignores wildcard/free-hit weeks, and pre-2024 the cap was 2."""
    if not history or "current" not in history or not history["current"]:
        return 1
    ft = 1
    for gw in history["current"]:
        made = gw.get("event_transfers", 0) or 0
        ft = min(5, ft + 1) - made
        ft = max(0, ft)
    return max(1, min(5, ft + 1))


def _chips_state(history):
    used = []
    if history and history.get("chips"):
        used = [c["name"] for c in history["chips"]]
    available = [label for key, label in ALL_CHIPS.items() if key not in used]
    used_labels = [ALL_CHIPS.get(name, name) for name in used]
    return used_labels, available


def _optimise(squad):
    """Pick the best legal starting XI by predicted points, then order the bench.

    Rules enforced: exactly 1 GK starts, min 3 DEF / 2 MID / 1 FWD, 11 total.
    Bench = the other GK (always bench slot 1) + the 3 weakest remaining
    outfielders, ordered best-first so auto-subs bring the strongest on."""
    gks = sorted([p for p in squad if p["pos"] == "GK"], key=lambda p: -p["predicted"])
    outs = {pos: sorted([p for p in squad if p["pos"] == pos], key=lambda p: -p["predicted"])
            for pos in ("DEF", "MID", "FWD")}

    if len(gks) < 2 or sum(len(v) for v in outs.values()) < 10:
        return None  # not a full 15-man squad; skip optimisation

    start_gk, bench_gk = gks[0], gks[1]

    # Lock in the minimum at each outfield position, then fill the 4 open slots
    # with the highest predicted players left, respecting the max per position.
    starters, counts = [], {"DEF": 0, "MID": 0, "FWD": 0}
    for pos, need in MIN_OUTFIELD.items():
        starters += outs[pos][:need]
        counts[pos] = need

    pool = []
    for pos in outs:
        pool += outs[pos][MIN_OUTFIELD[pos]:]
    pool.sort(key=lambda p: -p["predicted"])

    for p in pool:
        if len(starters) >= 10:
            break
        if counts[p["pos"]] < MAX_OUTFIELD[p["pos"]]:
            starters.append(p)
            counts[p["pos"]] += 1

    starter_ids = {p["id"] for p in starters} | {start_gk["id"]}
    bench_out = sorted([p for p in squad if p["id"] not in starter_ids and p["pos"] != "GK"],
                       key=lambda p: -p["predicted"])

    # Present starters grouped GK->DEF->MID->FWD for a natural pitch layout.
    order = {"GK": 0, "DEF": 1, "MID": 2, "FWD": 3}
    starting = sorted([start_gk] + starters, key=lambda p: (order[p["pos"]], -p["predicted"]))
    return {
        "starting": [p["id"] for p in starting],
        "bench": [bench_gk["id"]] + [p["id"] for p in bench_out],
    }


def _transfer_recs(squad, index, bank, free_transfers, max_recs=3):
    """Swap the weakest-rated players for the best affordable upgrade in the
    same position that isn't already owned. Budget-aware (bank + funds freed by
    selling). NB: uses now_cost as a proxy for selling price, and does not check
    the 3-per-club limit - treat as suggestions to sanity-check in the app."""
    owned = {p["id"] for p in squad}
    by_pos = {}
    for p in index.values():
        by_pos.setdefault(p["pos"], []).append(p)
    for pos in by_pos:
        by_pos[pos].sort(key=lambda p: -p["rating"])

    budget = bank
    recs = []
    for weak in sorted(squad, key=lambda p: p["rating"]):
        if len(recs) >= max_recs:
            break
        affordable = weak["cost"] + budget
        for cand in by_pos.get(weak["pos"], []):
            if cand["id"] in owned:
                continue
            if cand["rating"] <= weak["rating"]:
                break  # list is sorted, nothing better remains
            if cand["cost"] <= affordable:
                recs.append({
                    "out": weak, "in": cand,
                    "rating_gain": round(cand["rating"] - weak["rating"], 1),
                    "cost_change": round(cand["cost"] - weak["cost"], 1),
                    "free": len(recs) < free_transfers,
                })
                owned.add(cand["id"])
                budget -= (cand["cost"] - weak["cost"])
                break
    return recs


# Name fragments that mark a public league as broadcaster-run rather than a
# core FPL global league. Heuristic - the API has no explicit flag - so easy to
# extend once you see the real names your account is in.
BROADCASTER_HINTS = ("sky", "espn", "the sun", "talksport", "bbc", "itv",
                     "tnt", "bt sport", "guardian", "telegraph", "daily mail",
                     "mirror", "premier league")


def _categorise_leagues(entry):
    """Split classic leagues into personal / general / broadcaster.
    - league_type 's' = private mini-leagues you joined => personal
    - the rest are FPL's auto public leagues => general, unless the name looks
      like a broadcaster league."""
    groups = {"personal": [], "general": [], "broadcaster": []}
    for l in entry.get("leagues", {}).get("classic", []):
        item = {"id": l["id"], "name": l["name"], "rank": l.get("entry_rank")}
        name = (l.get("name") or "").lower()
        if l.get("league_type") == "s":
            groups["personal"].append(item)
        elif any(h in name for h in BROADCASTER_HINTS):
            groups["broadcaster"].append(item)
        else:
            groups["general"].append(item)
    return groups


def get_all_players(position_dfs):
    """Full rated player pool for the team builder / player search
    (id, cost, rating, club name, upcoming fixtures)."""
    if position_dfs is None:
        return {"players": []}
    idx = _build_element_index(position_dfs)
    players = [{"id": p["id"], "web_name": p["web_name"], "pos": p["pos"],
                "team_code": p["team_code"], "team": p.get("team"),
                "team_name": p.get("team_name"), "form": p.get("form"),
                "cost": p["cost"], "rating": p["rating"], "predicted": p["predicted"],
                "next_gameweeks": p.get("next_gameweeks", [])}
               for p in idx.values()]
    return {"players": players}


def get_player_summary(player_id, n=6):
    """Recent per-gameweek performance for one player (plus last few seasons),
    for the player pop-up. Current-season history is empty until the season
    starts, in which case history_past carries the story."""
    from fetch_data import get_player_history
    data = get_player_history(player_id)
    if not data or "history" not in data:
        return {"available": False, "history": [], "history_past": []}
    short = _team_short_map()
    hist = data.get("history", [])
    rows = [{"event": h.get("round"), "opponent": short.get(h.get("opponent_team"), "?"),
             "was_home": h.get("was_home"), "points": h.get("total_points"),
             "minutes": h.get("minutes")} for h in hist[-n:]]
    past = [{"season": p.get("season_name"), "points": p.get("total_points")}
            for p in data.get("history_past", [])[-3:]]
    return {"available": True, "history": rows, "history_past": past}


def get_team_view(team_id, event, position_dfs):
    """Assemble the whole Team tab payload for a manager id + gameweek."""
    if position_dfs is None:
        return {"available": False, "detail": "Ratings not loaded yet."}

    entry = _get(f"{BASE}/entry/{team_id}/")
    if entry is None:
        return {"available": False, "detail": f"Couldn't find manager {team_id}. Check the ID."}

    current_event = entry.get("current_event")
    event = event or current_event
    index = _build_element_index(position_dfs)

    # Leagues + header basics are available even in preseason.
    leagues = _categorise_leagues(entry)
    header = {
        "name": entry.get("name", ""),
        "manager": f"{entry.get('player_first_name', '')} {entry.get('player_last_name', '')}".strip(),
        "total_points": entry.get("summary_overall_points"),
        "bank": round((entry.get("last_deadline_bank") or 0) / 10, 1),
        "value": round((entry.get("last_deadline_value") or 0) / 10, 1),
    }

    if not event:
        return {"available": False, "detail": "No gameweek has started yet (preseason).",
                "header": header, "leagues": leagues,
                "current_event": current_event, "min_event": 1}

    picks_data = _get(f"{BASE}/entry/{team_id}/event/{event}/picks/")
    if picks_data is None or "picks" not in picks_data:
        return {"available": False,
                "detail": f"Team for GW{event} isn't available yet (picks appear once the gameweek is live).",
                "header": header, "leagues": leagues,
                "current_event": current_event, "min_event": 1}

    history = _get(f"{BASE}/entry/{team_id}/history/")
    free_transfers = _estimate_free_transfers(history)
    chips_used, chips_available = _chips_state(history)

    eh = picks_data.get("entry_history", {})
    bank = round((eh.get("bank") or 0) / 10, 1)

    # Merge each pick with its rated player info.
    squad = []
    for pk in picks_data["picks"]:
        info = index.get(pk["element"])
        if info is None:
            continue
        squad.append({**info,
                      "position": pk["position"],           # 1-11 start, 12-15 bench
                      "starting": pk["position"] <= 11,
                      "is_captain": pk["is_captain"],
                      "is_vice_captain": pk["is_vice_captain"],
                      "multiplier": pk["multiplier"]})

    # Captain / vice recommendation: highest predicted points in the squad.
    ranked = sorted(squad, key=lambda p: -p["predicted"])
    recommended = {"captain": ranked[0]["id"] if ranked else None,
                   "vice": ranked[1]["id"] if len(ranked) > 1 else None}

    # Predicted GW points = sum of starters' predictions, current captain doubled.
    predicted_gw = 0.0
    for p in squad:
        if p["starting"]:
            predicted_gw += p["predicted"] * (2 if p["is_captain"] else 1)

    return {
        "available": True,
        "header": header,
        "gw": {
            "event": event,
            "points": eh.get("points"),
            "predicted_points": round(predicted_gw, 1),
            "bank": bank,
            "value": round((eh.get("value") or 0) / 10, 1),
            "transfers_made": eh.get("event_transfers"),
            "transfers_cost": eh.get("event_transfers_cost"),
            "free_transfers_est": free_transfers,
            "active_chip": picks_data.get("active_chip"),
            "chips_used": chips_used,
            "chips_available": chips_available,
        },
        "squad": squad,
        "recommended": recommended,
        "optimised": _optimise(squad),
        "transfer_recs": _transfer_recs(squad, index, bank, free_transfers),
        "leagues": leagues,
        "current_event": current_event,
        "min_event": 1,
    }


def get_league_standings(league_id, page=1):
    """Top of a classic league's table (paged, 50 per page from FPL)."""
    data = _get(f"{BASE}/leagues-classic/{league_id}/standings/?page_standings={page}")
    if data is None:
        return {"available": False, "detail": "Couldn't load that league."}
    results = data.get("standings", {}).get("results", [])
    return {
        "available": True,
        "name": data.get("league", {}).get("name", ""),
        "standings": [{
            "rank": r["rank"], "entry_name": r["entry_name"],
            "manager": r["player_name"], "total": r["total"],
            "event_total": r.get("event_total"),
        } for r in results[:25]],
    }