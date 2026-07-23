import pandas as pd

def search_player(name, position_dfs):
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

    display_cols = ['web_name', 'first_name', 'second_name', 'position']
    if 'rating' in combined.columns:
        display_cols.append('rating')

    return combined[display_cols]