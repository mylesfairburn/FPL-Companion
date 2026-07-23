"""
Entry point for the FPL Companion. Run this file directly to pull data and
search for a player from the command line.

    python main.py

NOTE: player ratings aren't implemented yet - that requires train_model.py
(training on previous_season_stats.csv) and a rating_model.py that scores
current players using the trained model. Once that exists, its output should
be merged into position_dfs before search_player() is called, so the
'rating' column it expects actually exists.
"""

from pipeline import run_pipeline
from search import search_player


def main():
    print("Fetching FPL data...")
    data = run_pipeline()
    position_dfs = data['position_dfs']

    print(f"Loaded {len(data['players_df'])} players.\n")

    while True:
        name = input("Search player (or 'quit' to exit): ").strip()
        if name.lower() == 'quit':
            break
        if not name:
            continue

        result = search_player(name, position_dfs)
        if result is not None:
            print(result.to_string(index=False))
        print()

if __name__ == '__main__':
    main()