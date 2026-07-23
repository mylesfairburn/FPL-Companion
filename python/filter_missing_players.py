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



gameweek_stats = pd.read_csv('../data/raw/previous_season_stats.csv')
players = pd.read_csv('../data/raw/previous_season_players.csv')

if 'element' in gameweek_stats.columns:
    gameweek_stats = gameweek_stats.rename(columns={'element': 'player_id'})

gameweek_stats = align_player_sets(gameweek_stats, players)

gameweek_stats.to_csv('../data/raw/previous_season_stats.csv', index=False)
print("Saved filtered gameweek_stats back to previous_season_stats.csv")