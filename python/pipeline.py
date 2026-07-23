"""
Phase 3 pipeline: pulls fresh FPL data and builds the position-split DataFrames
used by the rest of the project.

NOTE: rating generation isn't wired in yet - that slots in here once
train_model.py produces a trained model to score players with. For now this
just gets you clean, fetched data ready to work with.
"""

import pandas as pd

from fetch_data import get_bootstrap_data, get_all_gameweek_history


def build_position_dfs(players_df, positions_df):
    """Splits players into one DataFrame per position."""
    return {
        name: players_df[players_df['element_type'] == pid].copy()
        for pid, name in zip(positions_df['id'], positions_df['singular_name'])
    }


def run_pipeline(pull_gameweek_history=False):
    """Runs the full data pull. Set pull_gameweek_history=True to also refresh
    the per-gameweek history (slow - one API call per player)."""

    all_data = get_bootstrap_data()

    players_df = pd.DataFrame(all_data['elements'])
    teams_df = pd.DataFrame(all_data['teams'])
    positions_df = pd.DataFrame(all_data['element_types'])

    players_df = players_df[players_df['status'] != 'u']

    position_dfs = build_position_dfs(players_df, positions_df)

    gameweek_history_df = None
    if pull_gameweek_history:
        gameweek_history_df = get_all_gameweek_history(players_df['id'])
        gameweek_history_df.to_csv('data/raw/gameweek_history.csv', index=False)

    return {
        'players_df': players_df,
        'teams_df': teams_df,
        'positions_df': positions_df,
        'position_dfs': position_dfs,
        'gameweek_history_df': gameweek_history_df,
    }


if __name__ == '__main__':
    result = run_pipeline()
    print(f"Pulled {len(result['players_df'])} players across "f"{len(result['position_dfs'])} positions.")