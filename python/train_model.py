import os

import joblib
import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge
from sklearn.metrics import mean_absolute_error
from sklearn.preprocessing import StandardScaler

pd.set_option('display.max_columns', None)

# total_points_roll3 was dropped - it's FPL's own formula output built from
# minutes/bonus/goals etc, so including it alongside those components caused
# the model to split credit unreliably between a total and its own ingredients.
ROLLING_COLS = [
    'expected_goal_involvements',
    'minutes',
    'bonus',
]
ROLL_WINDOW = 3
TRAIN_TEST_SPLIT_ROUND = 30  # train on rounds <= this, test on rounds after

def load_historical_data(
    stats_path='data/raw/previous_season_stats.csv',
    players_path='data/raw/previous_season_players.csv',
    teams_path='data/raw/teams.csv',
):
    gameweek_stats = pd.read_csv(stats_path)
    players = pd.read_csv(players_path)
    teams = pd.read_csv(teams_path)

    if 'element' in gameweek_stats.columns:
        gameweek_stats = gameweek_stats.rename(columns={'element': 'player_id'})

    gameweek_stats = gameweek_stats.sort_values(['player_id', 'round'])
    return gameweek_stats, players, teams

def build_rolling_features(gameweek_stats):
    """Shift(1) before rolling so a gameweek's own result never leaks into its
    own features - only gameweeks strictly before it are used."""
    df = gameweek_stats.copy()

    for col in ROLLING_COLS:
        df[col] = pd.to_numeric(df[col], errors='coerce')
        df[f'{col}_roll{ROLL_WINDOW}'] = (
            df.groupby('player_id')[col]
            .transform(lambda x: x.shift(1).rolling(ROLL_WINDOW, min_periods=1).mean())
        )

    return df

def add_fixture_context(df, teams):
    """Adds opponent strength based on home/away, using FPL's own team strength ratings."""
    strength = teams[['id', 'strength_overall_home', 'strength_overall_away']].rename(
        columns={'id': 'opponent_team'}
    )
    df = df.merge(strength, on='opponent_team', how='left')

    df['opponent_strength'] = np.where(
        df['was_home'],
        df['strength_overall_away'],  # player is home, so opponent's away strength applies
        df['strength_overall_home'],
    )
    return df

def attach_position(df, players):
    position_map = {1: 'Goalkeeper', 2: 'Defender', 3: 'Midfielder', 4: 'Forward'}
    id_to_type = dict(zip(players['id'], players['element_type']))
    df['position'] = df['player_id'].map(id_to_type).map(position_map)
    return df

def check_correlations(df):
    roll_cols = [f'{c}_roll{ROLL_WINDOW}' for c in ROLLING_COLS]
    print("--- Feature correlation matrix ---")
    print(df[roll_cols].corr().round(2))
    print()

def train_position_models(df):
    feature_cols = [f'{c}_roll{ROLL_WINDOW}' for c in ROLLING_COLS] + ['was_home', 'opponent_strength']
    target_col = 'total_points'

    df = df.dropna(subset=feature_cols + [target_col, 'position'])

    train = df[df['round'] <= TRAIN_TEST_SPLIT_ROUND]
    test = df[df['round'] > TRAIN_TEST_SPLIT_ROUND]

    models = {}

    for position in df['position'].unique():
        pos_train = train[train['position'] == position]
        pos_test = test[test['position'] == position]

        if len(pos_train) == 0 or len(pos_test) == 0:
            print(f"Skipping {position} - not enough rows (train={len(pos_train)}, test={len(pos_test)})")
            continue

        scaler = StandardScaler()
        X_train = scaler.fit_transform(pos_train[feature_cols])
        X_test = scaler.transform(pos_test[feature_cols])

        model = Ridge(alpha=1.0)
        model.fit(X_train, pos_train[target_col])

        preds = model.predict(X_test)
        mae = mean_absolute_error(pos_test[target_col], preds)

        print(f"\n--- {position} ---")
        print(f"MAE: {mae:.2f}")
        print(f"Train rows: {len(pos_train)}, Test rows: {len(pos_test)}")
        print("Coefficients (standardized - directly comparable):")
        for feat, coef in zip(feature_cols, model.coef_.round(3)):
            print(f"  {feat}: {coef}")

        models[position] = {'model': model, 'scaler': scaler, 'feature_cols': feature_cols}

    return models

def save_models(model_bundles, out_dir='data/models'):
    os.makedirs(out_dir, exist_ok=True)
    for position, bundle in model_bundles.items():
        path = f"{out_dir}/{position.lower()}_model.pkl"
        joblib.dump(bundle, path)
        print(f"Saved {position} model to {path}")

if __name__ == '__main__':
    gameweek_stats, players, teams = load_historical_data()

    df = build_rolling_features(gameweek_stats)
    df = add_fixture_context(df, teams)
    df = attach_position(df, players)

    check_correlations(df)

    models = train_position_models(df)
    save_models(models)