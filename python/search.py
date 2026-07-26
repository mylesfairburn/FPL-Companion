import pandas as pd

def search_player(name, position_dfs):
    """Case-insensitive partial name search across all positions. Returns matching rows with their rating."""
    name = name.lower()
    results = []

    for position, df in position_dfs.items():
        matches = df[
            df['web_name'].str.lower().str.contains(name, na=False) |
            df['first_name'].str.lower().str.contains(name, na=False) |
            df['second_name'].str.lower().str.contains(name, na=False)
        ]
        if not matches.empty:
            matches = matches.copy()
            matches['position'] = position
            results.append(matches)

    if not results:
        print(f"No players found matching '{name}'")
        return None

    combined = pd.concat(results)
    cols = [c for c in ['web_name', 'first_name', 'second_name', 'team_code',
                        'position', 'rating', 'predicted_points', 'next_gameweeks']
            if c in combined.columns]
    return combined[cols]