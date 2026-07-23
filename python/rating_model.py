import os

import joblib
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

MODEL_DIR = '../data/models'
ROLL_WINDOW = 3


def load_models(model_dir=MODEL_DIR):
    """Loads the {model, scaler, feature_cols} bundle saved by train_model.py, per position."""
    bundles = {}
    for filename in os.listdir(model_dir):
        if filename.endswith('_model.pkl'):
            position = filename.replace('_model.pkl', '').capitalize()
            bundles[position] = joblib.load(f'{model_dir}/{filename}')
    return bundles


def build_fixture_features(fixtures_df, n_fixtures=3):
    """For each team, averages difficulty and home ratio across their next
    N unplayed fixtures - this replaces the neutral placeholders with real
    upcoming schedule strength."""
    upcoming = fixtures_df[fixtures_df['finished'] == False].sort_values('event')

    team_features = []
    team_ids = pd.concat([upcoming['team_h'], upcoming['team_a']]).unique()

    for team_id in team_ids:
        home_fixtures = upcoming[upcoming['team_h'] == team_id][['event', 'team_h_difficulty']] \
            .rename(columns={'team_h_difficulty': 'difficulty'})
        home_fixtures['was_home'] = True

        away_fixtures = upcoming[upcoming['team_a'] == team_id][['event', 'team_a_difficulty']] \
            .rename(columns={'team_a_difficulty': 'difficulty'})
        away_fixtures['was_home'] = False

        team_fixtures = pd.concat([home_fixtures, away_fixtures]).sort_values('event').head(n_fixtures)

        if team_fixtures.empty:
            continue

        team_features.append({
            'team': team_id,
            # FPL's difficulty is 1 (easiest) to 5 (hardest) - inverted here
            # so it aligns with opponent_strength direction the model was
            # trained on (higher strength = harder opponent).
            'opponent_strength': team_fixtures['difficulty'].mean() * 220,  # roughly matches teams_df's strength scale
            'was_home': team_fixtures['was_home'].mean(),
        })

    return pd.DataFrame(team_features)


def build_current_form_features(
    current_gw_path='../data/raw/gameweek_history.csv',
    fallback_path='../data/raw/previous_season_stats.csv',
    current_players_path='../data/raw/players_full.csv',
    previous_players_path='../data/raw/previous_season_players.csv',
    min_current_gameweeks=3,
):
    """Builds a rolling-3-gameweek 'current form' row per player, keyed by
    'code' rather than 'id' - FPL reassigns 'id' every season, so a brand
    new signing can end up sharing last season's id with a departed player.
    'code' is the one identifier that stays consistent for a given real
    player across seasons, so it's the only safe join key here."""

    stat_cols = ['expected_goal_involvements', 'minutes', 'bonus']

    use_fallback = True
    if os.path.exists(current_gw_path):
        current = pd.read_csv(current_gw_path)
        if 'element' in current.columns:
            current = current.rename(columns={'element': 'player_id'})
        if current['round'].nunique() >= min_current_gameweeks:
            use_fallback = False

    if use_fallback:
        print("Not enough current-season gameweeks yet - using last season's full-season average as current form")
        source = pd.read_csv(fallback_path)
        if 'element' in source.columns:
            source = source.rename(columns={'element': 'player_id'})

        previous_players = pd.read_csv(previous_players_path)
        id_to_code = dict(zip(previous_players['id'], previous_players['code']))

    else:
        source = current
        current_players = pd.read_csv(current_players_path)
        id_to_code = dict(zip(current_players['id'], current_players['code']))

    source['code'] = source['player_id'].map(id_to_code)
    source = source.dropna(subset=['code'])
    source['code'] = source['code'].astype(int)

    for col in stat_cols:
        source[col] = pd.to_numeric(source[col], errors='coerce')

    if use_fallback:
        # Full-season average, not just the last 3 GWs - avoids being skewed by
        # end-of-season rotation/dead rubbers, which is exactly what was
        # producing unreliable ratings (e.g. Haaland ranking low due to a
        # rested run-in, fringe players ranking high off one lucky game).
        # Filter out players with minimal minutes so a single substitute
        # cameo doesn't produce a misleadingly high average.
        minutes_played = source.groupby('code')['minutes'].sum()
        eligible_codes = minutes_played[minutes_played >= 180].index  # ~2 full games minimum
        source = source[source['code'].isin(eligible_codes)]
        form = source.groupby('code')[stat_cols].mean()
    else:
        source = source.sort_values(['code', 'round'])
        recent = source.groupby('code').tail(ROLL_WINDOW)
        form = recent.groupby('code')[stat_cols].mean()

    form.columns = [f'{c}_roll{ROLL_WINDOW}' for c in stat_cols]
    return form.reset_index()


def predict_ratings(position_dfs, model_bundles, form_features, fixture_features):
    """Predicts expected points per player, converts to a 0-100 rating per position."""
    updated = {}

    for position, df in position_dfs.items():
        df = df.copy()

        if position not in model_bundles:
            print(f"No trained model for {position}, skipping")
            df['rating'] = np.nan
            updated[position] = df
            continue

        bundle = model_bundles[position]
        model, scaler, feature_cols = bundle['model'], bundle['scaler'], bundle['feature_cols']

        merged = df.merge(form_features, on='code', how='left')
        # Real upcoming fixture difficulty/home-ratio, keyed by the player's team
        merged = merged.merge(fixture_features, on='team', how='left')

        has_features = merged[feature_cols].notna().all(axis=1)

        predicted_points = pd.Series(np.nan, index=merged.index)
        if has_features.any():
            X = scaler.transform(merged.loc[has_features, feature_cols])
            predicted_points.loc[has_features] = model.predict(X)

        merged['predicted_points'] = predicted_points

        # 0-100 rating via percentile rank within position - players with no
        # predictable form (e.g. never played) naturally rank at the bottom
        merged['rating'] = (merged['predicted_points'].rank(pct=True) * 100).round(1)
        merged.loc[merged['predicted_points'].isna(), 'rating'] = 0

        updated[position] = merged

    return updated


POSITION_COLORS = {
    'Goalkeeper': '#ffcc29',
    'Defender': '#04f5ff',
    'Midfielder': '#e90052',
    'Forward': '#37003c',
}


def plot_top_ratings(position_dfs, top_n=10, out_dir='../data/plots'):
    """Saves one bar chart per position showing the top N rated players."""
    os.makedirs(out_dir, exist_ok=True)
    plt.style.use('seaborn-v0_8-whitegrid')

    for position, df in position_dfs.items():
        top = df.sort_values('rating', ascending=False).head(top_n).iloc[::-1]
        if top.empty:
            continue

        fig, ax = plt.subplots(figsize=(9, 5.5))
        color = POSITION_COLORS.get(position, '#37003c')
        bars = ax.barh(top['web_name'], top['rating'], color=color, edgecolor='none')

        for bar, rating in zip(bars, top['rating']):
            ax.text(bar.get_width() + 1, bar.get_y() + bar.get_height() / 2,
                    f'{rating:.0f}', va='center', fontsize=9, color='#333333')

        ax.set_xlabel('Rating (0-100)', fontsize=10)
        ax.set_title(f'Top {top_n} {position}s', fontsize=13, fontweight='bold', pad=12)
        ax.set_xlim(0, 110)
        ax.spines[['top', 'right']].set_visible(False)
        ax.tick_params(axis='y', labelsize=10)
        fig.tight_layout()

        path = f'{out_dir}/top_{position.lower()}s.png'
        fig.savefig(path, dpi=150)
        plt.close(fig)
        print(f"Saved chart to {path}")


if __name__ == '__main__':
    from pipeline import run_pipeline
    from fetch_data import get_fixtures

    data = run_pipeline()
    position_dfs = data['position_dfs']

    model_bundles = load_models()
    form_features = build_current_form_features()

    fixtures_df = get_fixtures()
    fixture_features = build_fixture_features(fixtures_df, n_fixtures=3)

    position_dfs = predict_ratings(position_dfs, model_bundles, form_features, fixture_features)

    for position, df in position_dfs.items():
        print(f"\n--- Top 10 {position}s ---")
        print(df[['web_name', 'rating', 'predicted_points']]
                .sort_values('rating', ascending=False)
                .head(10)
                .to_string(index=False))

    plot_top_ratings(position_dfs)