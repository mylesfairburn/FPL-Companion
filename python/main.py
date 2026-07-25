"""
FastAPI backend for FPL Companion.

Run locally with:
    uvicorn web_main:app --reload

Loads all data and ratings once at startup, then serves search from memory -
fine for ~600 players, no database needed for this scale.
"""

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates

from pipeline import run_pipeline
from fetch_data import get_fixtures
from rating_model import load_models, build_current_form_features, build_fixture_features, predict_ratings
from search import search_player

app = FastAPI()
templates = Jinja2Templates(directory="templates")

# Populated once at startup - see load_data() below
state = {"position_dfs": None}


def load_data():
    print("Loading FPL data and ratings...")
    data = run_pipeline()
    position_dfs = data["position_dfs"]

    model_bundles = load_models()
    form_features = build_current_form_features()
    fixtures_df = get_fixtures()
    fixture_features = build_fixture_features(fixtures_df, n_fixtures=3)

    position_dfs = predict_ratings(position_dfs, model_bundles, form_features, fixture_features)
    state["position_dfs"] = position_dfs
    print("Ready.")


@app.on_event("startup")
def startup():
    load_data()


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    return templates.TemplateResponse("index.html", {"request": request})


@app.get("/api/search")
def search(q: str):
    if not q or state["position_dfs"] is None:
        return {"results": []}

    result = search_player(q, state["position_dfs"])
    if result is None:
        return {"results": []}

    return {"results": result.to_dict(orient="records")}


@app.post("/api/refresh")
def refresh():
    """Manually re-pull data and ratings, e.g. after a new gameweek."""
    load_data()
    return {"status": "refreshed"}