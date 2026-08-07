import seasons

# Which season's stats to clean. Defaults to the last completed one.
SEASON = seasons.previous_season() or seasons.FIRST_TRAINING_SEASON

import pandas as pd

pd.set_option('display.max_columns', None)

def align_player_sets(gameweek_stats, players):
    """Keeps only gameweek rows for players still present in the players file."""
    valid_ids = set(players['id'])

    before = gameweek_stats['player_id'].nunique()
    gameweek_stats = gameweek_stats[gameweek_stats['player_id'].isin(valid_ids)]
    after = gameweek_stats['player_id'].nunique()

    print(f"Filtered gameweek_stats: {before} -> {after} unique players")
    return gameweek_stats



gameweek_stats = pd.read_csv(seasons.gameweek_stats_path(SEASON))
players = pd.read_csv(seasons.players_path(SEASON))

if 'element' in gameweek_stats.columns:
    gameweek_stats = gameweek_stats.rename(columns={'element': 'player_id'})

gameweek_stats = align_player_sets(gameweek_stats, players)

gameweek_stats.to_csv(seasons.gameweek_stats_path(SEASON), index=False)
print(f"Saved filtered gameweek_stats back to {seasons.gameweek_stats_path(SEASON)}")