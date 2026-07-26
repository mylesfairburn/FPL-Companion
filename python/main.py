"""
FastAPI backend for FPL Companion.

Run locally with:
    uvicorn main:app --reload
"""

import pandas as pd
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from fastapi.staticfiles import StaticFiles

from pipeline import run_pipeline
from rating_model import get_rated_position_dfs
from fixture_rotator import (get_rotation_data, rank_rotation_pairs, recommend_pair_players, team_fixture_map)
from search import search_player
from team_service import get_team_view, get_league_standings, get_all_players, get_player_summary

app = FastAPI()
templates = Jinja2Templates(directory="templates")
app.mount("/static", StaticFiles(directory="static"), name="static")
templates.env.cache = None  # workaround for a Jinja2/Starlette/Python 3.14 bug

state = {"mode": "preseason", "position_dfs": None, "rotation_df": None}


def load_data(mode):
    print(f"Loading FPL data ({mode})...")
    data = run_pipeline()
    position_dfs = get_rated_position_dfs(data["position_dfs"], mode=mode)
    rotation_df = get_rotation_data(mode=mode, n_gameweeks=8)

    state["mode"] = mode
    state["position_dfs"] = position_dfs
    state["rotation_df"] = rotation_df
    print("Ready.")


@app.on_event("startup")
def startup():
    load_data(state["mode"])

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
    return get_team_view(team_id, event, state["position_dfs"])


@app.get("/api/league/{league_id}")
def league(league_id: int, page: int = 1):
    """Top of a classic league's standings table."""
    return get_league_standings(league_id, page)


@app.get("/api/all_players")
def all_players():
    """Full rated pool for the preseason team builder."""
    return get_all_players(state["position_dfs"])


@app.get("/api/player/{player_id}")
def player(player_id: int):
    """Recent gameweek performance for a single player (player pop-up)."""
    return get_player_summary(player_id)


@app.post("/api/mode")
def set_mode(mode: str):
    if mode not in ("preseason", "inseason"):
        return {"status": "error", "detail": "mode must be 'preseason' or 'inseason'"}
    try:
        load_data(mode)
        return {"status": "ok", "mode": mode}
    except Exception as e:
        return {"status": "error", "detail": str(e)}


@app.post("/api/refresh")
def refresh():
    load_data(state["mode"])
    return {"status": "refreshed"}