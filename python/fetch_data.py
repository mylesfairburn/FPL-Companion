import json
import os
import time

import pandas as pd
import requests


def get_bootstrap_data(cache_path='../data/raw/players_full.csv'):
    """All players, teams, positions, and season totals in one call.
    Falls back to the cached CSV if the API is unavailable."""
    try:
        response = requests.get("https://fantasy.premierleague.com/api/bootstrap-static/")
        response.raise_for_status()
        data = response.json()
        pd.DataFrame(data['elements']).to_csv(cache_path, index=False)
        return data
    except requests.exceptions.RequestException:
        print("API unavailable, falling back to cached data")
        return pd.read_csv(cache_path)


def get_fixtures(cache_path='../data/raw/fixtures.csv'):
    """All fixtures for the season - past and upcoming, with FPL's own
    difficulty ratings per team. Falls back to cache if the API is down."""
    try:
        response = requests.get("https://fantasy.premierleague.com/api/fixtures/")
        response.raise_for_status()
        data = response.json()
        pd.DataFrame(data).to_csv(cache_path, index=False)
        return pd.DataFrame(data)
    except requests.exceptions.RequestException:
        print("API unavailable, falling back to cached fixtures")
        return pd.read_csv(cache_path)


def get_my_team(team_id, event_id, cache_path=None):
    """Pulls a manager's picks for a given gameweek by their FPL team ID -
    found in the URL when viewing your team on the FPL site
    (fantasy.premierleague.com/entry/{team_id}/event/{gw}).
    Public data, no login needed, unless the manager has set their team to
    private. Picks for a gameweek only exist once that gameweek has started -
    there's nothing to fetch before the season's first deadline."""
    cache_path = cache_path or f'../data/raw/my_team_gw{event_id}.csv'
    try:
        url = f"https://fantasy.premierleague.com/api/entry/{team_id}/event/{event_id}/picks/"
        response = requests.get(url)
        response.raise_for_status()
        data = response.json()
        picks = pd.DataFrame(data['picks'])
        picks.to_csv(cache_path, index=False)
        return picks
    except requests.exceptions.RequestException:
        print("API unavailable, falling back to cached team")
        return pd.read_csv(cache_path)


def get_previous_season_fixture_strength(
    fixtures_path='../data/raw/previous_season_fixtures.csv',
    teams_path='../data/raw/previous_season_teams.csv',
):
    """Real attack/defence strength per team, computed from last season's
    actual match results (goals scored/conceded) rather than a single Elo
    figure. Genuinely distinct attack vs defence numbers, and uses FPL's own
    short_name convention throughout - no external name-mapping needed."""

    try:
        fixtures = pd.read_csv(fixtures_path)
        teams = pd.read_csv(teams_path)
    except FileNotFoundError:
        fixtures_url = "https://raw.githubusercontent.com/vaastav/Fantasy-Premier-League/master/data/2025-26/fixtures.csv"
        teams_url = "https://raw.githubusercontent.com/vaastav/Fantasy-Premier-League/master/data/2025-26/teams.csv"
        fixtures = pd.read_csv(fixtures_url)
        teams = pd.read_csv(teams_url)
        fixtures.to_csv(fixtures_path, index=False)
        teams.to_csv(teams_path, index=False)

    id_to_short_name = dict(zip(teams['id'], teams['short_name']))
    finished = fixtures[fixtures['finished'] == True].copy()

    home_stats = finished.groupby('team_h').agg(
        attack_home=('team_h_score', 'mean'),
        defence_home=('team_a_score', 'mean'),
    )
    away_stats = finished.groupby('team_a').agg(
        attack_away=('team_a_score', 'mean'),
        defence_away=('team_h_score', 'mean'),
    )

    strength = home_stats.join(away_stats, how='outer')
    strength['short_name'] = strength.index.map(id_to_short_name)

    # Defence is inverted - fewer goals conceded should mean HIGHER strength,
    # matching the direction FPL's own strength columns use.
    strength['defence_home'] = -strength['defence_home']
    strength['defence_away'] = -strength['defence_away']

    return strength.reset_index(drop=True)


def get_clubelo_ratings(cache_path='../data/raw/clubelo_ratings.csv'):
    """Current Elo rating for every club, from clubelo.com. Unlike FPL's own
    team strength fields, Elo carries over between seasons rather than
    resetting to 0 - usable as a strength proxy before the new season's
    matches have generated FPL's own numbers."""
    try:
        response = requests.get("http://api.clubelo.com/2026-07-23")
        response.raise_for_status()
        from io import StringIO
        data = pd.read_csv(StringIO(response.text))
        data.to_csv(cache_path, index=False)
        return data
    except requests.exceptions.RequestException:
        print("ClubElo unavailable, falling back to cached ratings")
        return pd.read_csv(cache_path)


# ClubElo uses its own naming convention - map to FPL's short_name per team.
# One-time mapping, only needs updating if a club's ClubElo name changes.
CLUBELO_NAME_MAP = {
    'Arsenal': 'ARS', 'Aston Villa': 'AVL', 'Brighton': 'BHA', 'Bournemouth': 'BOU',
    'Brentford': 'BRE', 'Chelsea': 'CHE', 'Coventry': 'COV', 'Crystal Palace': 'CRY',
    'Everton': 'EVE', 'Fulham': 'FUL', 'Hull': 'HUL', 'Ipswich': 'IPS',
    'Leeds': 'LEE', 'Liverpool': 'LIV', 'Man City': 'MCI', 'Man United': 'MUN',
    'Newcastle': 'NEW', 'Forest': 'NFO', 'Sunderland': 'SUN', 'Tottenham': 'TOT',
}


def get_player_history(player_id, cache_dir='../data/raw/gameweek_history'):
    """Gameweek-by-gameweek history for a single player. Falls back to a local
    cached copy if the API is unavailable (e.g. during the summer reset)."""
    os.makedirs(cache_dir, exist_ok=True)
    cache_path = f"{cache_dir}/{player_id}.json"

    try:
        url = f"https://fantasy.premierleague.com/api/element-summary/{player_id}/"
        response = requests.get(url)
        response.raise_for_status()
        data = response.json()

        with open(cache_path, 'w') as f:
            json.dump(data, f)

        return data

    except requests.exceptions.RequestException:
        if os.path.exists(cache_path):
            print(f"API unavailable for player {player_id}, using cached copy")
            with open(cache_path) as f:
                return json.load(f)
        else:
            print(f"API unavailable for player {player_id}, no cache exists - skipping")
            return None


def get_all_gameweek_history(player_ids):
    """Pulls gameweek-by-gameweek history for every player ID given, returns one combined DataFrame."""
    all_history = []

    for player_id in player_ids:
        data = get_player_history(player_id)

        if data is None:
            continue  # already logged inside get_player_history

        history = pd.DataFrame(data['history'])
        history['player_id'] = player_id
        all_history.append(history)

        time.sleep(0.3)  # be polite to the API, avoid hammering it 620 times in a row

    if not all_history:
        print("No data retrieved for any player.")
        return pd.DataFrame()

    return pd.concat(all_history, ignore_index=True)