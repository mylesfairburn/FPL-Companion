"""Train the per-position points models.

Trains on EVERY season present from 2025-26 onwards (see seasons.py), not just
the most recent one. Each extra season is more evidence for the thing the model
is really estimating - how underlying stats (xGI, minutes, bonus) convert into
FPL points - so rerunning this next August with 2026-27 on disk sharpens the
weights without any code change.

Two things make multi-season training different from just concatenating files:

  * Rolling features must never span a season boundary. Grouping by player_id
    alone would let a player's last three gameweeks of 2025-26 become the
    "recent form" for his 2026-27 opener, which is leakage across a summer of
    transfers and pre-season.
  * player_id is reassigned by FPL every season. `code` is the stable
    per-person identifier, so rows are keyed on that where it's available.

Run from python/:  python train_model.py
"""

import os

import joblib
import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge
from sklearn.metrics import mean_absolute_error
from sklearn.preprocessing import StandardScaler

import seasons

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

# Hold out the run-in of the most recent season rather than a random split:
# the model is used to predict forwards in time, so it should be validated
# that way too. A random split would let a player's GW38 row train a model
# that's then tested on his GW20 row.
HOLDOUT_LAST_N_ROUNDS = 8

POSITION_MAP = {1: 'Goalkeeper', 2: 'Defender', 3: 'Midfielder', 4: 'Forward'}


def load_season(season):
    """One season's per-gameweek rows, tagged with the season and a stable
    player key. Returns None if the season has no usable stats file."""
    stats_path = seasons.gameweek_stats_path(season)
    players_path = seasons.players_path(season)
    if not os.path.exists(stats_path):
        return None
    try:
        stats = pd.read_csv(stats_path)
    except (pd.errors.EmptyDataError, OSError):
        return None
    if stats.empty or 'round' not in stats.columns:
        return None

    if 'element' in stats.columns and 'player_id' not in stats.columns:
        stats = stats.rename(columns={'element': 'player_id'})
    if 'player_id' not in stats.columns:
        return None

    stats['season'] = season
    stats['season_start'] = seasons.season_start_year(season)

    # Map to the season-stable `code` so a player's history lines up across
    # seasons even though FPL reissues `id` every year.
    try:
        players = pd.read_csv(players_path)
        id_to_code = dict(zip(players['id'], players['code']))
        stats['code'] = stats['player_id'].map(id_to_code)
        stats['element_type'] = stats['player_id'].map(
            dict(zip(players['id'], players['element_type'])))
    except (FileNotFoundError, OSError, KeyError):
        stats['code'] = np.nan
        stats['element_type'] = np.nan

    # Fall back to a season-scoped id where `code` is missing, so those rows
    # still get rolling features (just not linked across seasons).
    stats['player_key'] = stats['code'].fillna(
        stats['season_start'].astype(str) + '_' + stats['player_id'].astype(str))
    return stats


def load_all_seasons(season_list=None):
    season_list = season_list or seasons.training_seasons()
    frames = []
    for s in season_list:
        df = load_season(s)
        if df is None or df.empty:
            print(f"  {s}: no usable gameweek stats, skipping")
            continue
        print(f"  {s}: {len(df):,} rows, GW{int(df['round'].min())}-{int(df['round'].max())}")
        frames.append(df)
    if not frames:
        raise RuntimeError(
            "No season has usable gameweek stats. Expected at least "
            f"{seasons.gameweek_stats_path(seasons.FIRST_TRAINING_SEASON)}")
    return pd.concat(frames, ignore_index=True)


def build_rolling_features(df):
    """Shift(1) before rolling so a gameweek's own result never leaks into its
    own features - only gameweeks strictly before it are used.

    Grouped by (player, season) so form never carries across a summer break."""
    df = df.sort_values(['player_key', 'season_start', 'round']).copy()
    group = df.groupby(['player_key', 'season_start'], sort=False)

    for col in ROLLING_COLS:
        df[col] = pd.to_numeric(df[col], errors='coerce')
        df[f'{col}_roll{ROLL_WINDOW}'] = group[col].transform(
            lambda x: x.shift(1).rolling(ROLL_WINDOW, min_periods=1).mean())
    return df


def add_fixture_context(df):
    """Opponent strength based on home/away, using each season's OWN team
    strength ratings - a club's strength in 2025-26 isn't its strength in
    2026-27, and promoted sides don't exist in the older table at all."""
    pieces = []
    for season, chunk in df.groupby('season', sort=False):
        try:
            teams = pd.read_csv(seasons.teams_path(season))
        except (FileNotFoundError, OSError):
            print(f"  no teams.csv for {season}; opponent strength will be blank")
            chunk = chunk.copy()
            chunk['opponent_strength'] = np.nan
            pieces.append(chunk)
            continue
        strength = teams[['id', 'strength_overall_home', 'strength_overall_away']].rename(
            columns={'id': 'opponent_team'})
        merged = chunk.merge(strength, on='opponent_team', how='left')
        merged['opponent_strength'] = np.where(
            merged['was_home'],
            merged['strength_overall_away'],   # player home -> opponent's away strength
            merged['strength_overall_home'])
        pieces.append(merged)
    return pd.concat(pieces, ignore_index=True)


def attach_position(df):
    """Position from the season's own element list, falling back to the
    'position' string some exports already carry."""
    pos = df['element_type'].map(POSITION_MAP)
    if 'position' in df.columns:
        short = {'GK': 'Goalkeeper', 'GKP': 'Goalkeeper', 'DEF': 'Defender',
                 'MID': 'Midfielder', 'FWD': 'Forward'}
        pos = pos.fillna(df['position'].map(short)).fillna(df['position'])
    df['position'] = pos
    return df


def check_correlations(df):
    roll_cols = [f'{c}_roll{ROLL_WINDOW}' for c in ROLLING_COLS]
    print("--- Feature correlation matrix ---")
    print(df[roll_cols].corr().round(2))
    print()


def split_train_test(df):
    """Hold out the tail of the most recent season."""
    latest = df['season_start'].max()
    latest_rounds = df.loc[df['season_start'] == latest, 'round']
    cutoff = latest_rounds.max() - HOLDOUT_LAST_N_ROUNDS
    is_test = (df['season_start'] == latest) & (df['round'] > cutoff)
    return df[~is_test], df[is_test], latest, cutoff


def train_position_models(df):
    feature_cols = [f'{c}_roll{ROLL_WINDOW}' for c in ROLLING_COLS] + ['was_home', 'opponent_strength']
    target_col = 'total_points'

    df = df.dropna(subset=feature_cols + [target_col, 'position'])
    train, test, latest, cutoff = split_train_test(df)
    print(f"Training on {len(train):,} rows across {df['season'].nunique()} season(s); "
          f"holding out {len(test):,} rows (season starting {latest}, rounds > {cutoff}).\n")

    models = {}
    for position in sorted(df['position'].dropna().unique()):
        pos_train = train[train['position'] == position]
        pos_test = test[test['position'] == position]
        if pos_train.empty:
            print(f"Skipping {position} - no training rows")
            continue

        scaler = StandardScaler()
        X_train = scaler.fit_transform(pos_train[feature_cols])
        model = Ridge(alpha=1.0)
        model.fit(X_train, pos_train[target_col])

        print(f"--- {position} ---")
        print(f"Train rows: {len(pos_train):,}   Test rows: {len(pos_test):,}")
        if not pos_test.empty:
            preds = model.predict(scaler.transform(pos_test[feature_cols]))
            print(f"MAE: {mean_absolute_error(pos_test[target_col], preds):.3f}")
            # Beat-the-baseline check: predicting each position's mean is the
            # bar any model has to clear to be worth shipping.
            baseline = np.full(len(pos_test), pos_train[target_col].mean())
            print(f"MAE (predict-the-mean baseline): "
                  f"{mean_absolute_error(pos_test[target_col], baseline):.3f}")
        print("Coefficients (standardised - directly comparable):")
        for feat, coef in zip(feature_cols, model.coef_.round(3)):
            print(f"  {feat}: {coef}")
        print()

        models[position] = {
            'model': model, 'scaler': scaler, 'feature_cols': feature_cols,
            'trained_on_seasons': sorted(df['season'].unique().tolist()),
            'train_rows': int(len(pos_train)),
        }
    return models


def save_models(model_bundles, out_dir=None):
    out_dir = out_dir or seasons.MODELS_DIR
    os.makedirs(out_dir, exist_ok=True)
    for position, bundle in model_bundles.items():
        path = f"{out_dir}/{position.lower()}_model.pkl"
        joblib.dump(bundle, path)
        print(f"Saved {position} model to {path}")


def main(season_list=None):
    season_list = season_list or seasons.training_seasons()
    print(f"Training seasons: {', '.join(season_list) if season_list else '(none found)'}")
    df = load_all_seasons(season_list)
    df = build_rolling_features(df)
    df = add_fixture_context(df)
    df = attach_position(df)
    check_correlations(df)
    models = train_position_models(df)
    save_models(models)
    return models


if __name__ == '__main__':
    main()
