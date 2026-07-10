import requests
import pandas as pd

def get_bootstrap_data():
    """All players, teams, positions, and season totals in one call."""
    url = "https://fantasy.premierleague.com/api/bootstrap-static/"
    response = requests.get(url)
    response.raise_for_status()
    return response.json()

def get_player_history(player_id):
    """Gameweek-by-gameweek history for a single player (needed for the 3-GW rolling window later)."""
    url = f"https://fantasy.premierleague.com/api/element-summary/{player_id}/"
    response = requests.get(url)
    response.raise_for_status()
    return response.json()


AllData = get_bootstrap_data()