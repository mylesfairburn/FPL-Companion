import itertools

import pandas as pd


def find_best_pairs(rotation_df, difficulty_col, top_n=3):
    """For every possible pair of teams, assumes you always pick whichever
    team has the easier fixture that gameweek, then ranks pairs by their
    resulting average difficulty. A low average means the pair's hard weeks
    don't often overlap - exactly what you want for rotation."""

    pivot = rotation_df.pivot_table(index='team_name', columns='event', values=difficulty_col)

    pairs = []
    for team_a, team_b in itertools.combinations(pivot.index, 2):
        # Best available fixture each gameweek, picking whichever team is easier
        combined = pivot.loc[[team_a, team_b]].min(axis=0, skipna=True)

        if combined.isna().all():
            continue

        pairs.append({
            'team_a': team_a,
            'team_b': team_b,
            'avg_difficulty': combined.mean(),
            'gameweeks_covered': combined.count(),
        })

    pairs_df = pd.DataFrame(pairs).sort_values('avg_difficulty').head(top_n)
    return pairs_df.reset_index(drop=True)


def strength_data_is_empty(teams_df):
    """FPL's strength columns are 0 across the board before the season's
    first matches are played."""
    strength_cols = ['strength_attack_home', 'strength_attack_away',
                      'strength_defence_home', 'strength_defence_away']
    return (teams_df[strength_cols].sum().sum() == 0)


def apply_previous_season_strength(teams_df, strength_df, elo_df=None, name_map=None):
    """Substitutes previous-season attack/defence strength for FPL's own
    (still-empty) columns. Newly promoted teams have no top-flight history to
    compute this from, so they fall back to ClubElo instead of a flat league
    average - Elo ratings stay comparable across divisions, so a promoted
    team's rating correctly comes out below an established Prem side's,
    rather than assuming they're merely average."""
    teams_df = teams_df.merge(strength_df, on='short_name', how='left')

    missing = teams_df[teams_df['attack_home'].isna()]['short_name'].tolist()

    if missing and elo_df is not None and name_map is not None:
        print(f"No previous-season Prem data for: {missing} - using ClubElo instead (likely newly promoted)")
        elo_df = elo_df[elo_df['Club'].isin(name_map.keys())].copy()
        elo_df['short_name'] = elo_df['Club'].map(name_map)
        elo_lookup = dict(zip(elo_df['short_name'], elo_df['Elo']))

        # Raw Elo (~1400-2000) is a completely different scale to the
        # goals-per-game numbers used elsewhere (~0.5-2.5) - filling directly
        # would make promoted teams look absurdly extreme once scaled. So
        # instead: find each promoted team's relative standing (z-score)
        # against the RETURNING teams' Elo, then place them at the same
        # relative standing on the goals-based scale. This is what actually
        # produces "below average", calibrated correctly rather than mixing scales.
        returning_mask = teams_df['attack_home'].notna()
        returning_short_names = teams_df.loc[returning_mask, 'short_name']

        returning_elo = returning_short_names.map(elo_lookup)
        elo_mean, elo_std = returning_elo.mean(), returning_elo.std()

        promoted_elo = teams_df.loc[~returning_mask, 'short_name'].map(elo_lookup)
        z_scores = (promoted_elo - elo_mean) / elo_std

        for col in ['attack_home', 'attack_away', 'defence_home', 'defence_away']:
            col_mean = teams_df.loc[returning_mask, col].mean()
            col_std = teams_df.loc[returning_mask, col].std()
            fallback_values = col_mean + z_scores * col_std
            teams_df[col] = teams_df[col].fillna(fallback_values)
    elif missing:
        print(f"No previous-season data for: {missing} - and no ClubElo data supplied, using league average")
        league_avg = strength_df[['attack_home', 'attack_away', 'defence_home', 'defence_away']].mean()
        for col, avg in league_avg.items():
            teams_df[col] = teams_df[col].fillna(avg)

    still_missing = teams_df[teams_df['attack_home'].isna()]['short_name'].tolist()
    if still_missing:
        print(f"Warning: still no strength data for {still_missing} - check ClubElo name mapping")

    # Scale onto roughly FPL's usual strength range so downstream difficulty
    # maths (originally built around FPL's own numbers) behaves consistently.
    scale = 220
    teams_df['strength_attack_home'] = teams_df['attack_home'] * scale
    teams_df['strength_attack_away'] = teams_df['attack_away'] * scale
    teams_df['strength_defence_home'] = teams_df['defence_home'] * scale
    teams_df['strength_defence_away'] = teams_df['defence_away'] * scale

    return teams_df


def build_rotation_table(fixtures_df, teams_df, n_gameweeks=8):
    """Builds one row per team per upcoming fixture, with defensive and
    attacking difficulty scored as a NET difference - a team's own
    defence/attack strength relative to the opponent's attack/defence, not
    the opponent's strength alone. A strong defence facing a weak attack
    should look easier than a weak defence facing that same attack."""

    upcoming = fixtures_df[fixtures_df['finished'] == False].sort_values('event')

    next_events = sorted(upcoming['event'].dropna().unique())[:n_gameweeks]
    upcoming = upcoming[upcoming['event'].isin(next_events)]

    team_id_to_name = dict(zip(teams_df['id'], teams_df['short_name']))
    strength = teams_df.set_index('id')[
        ['strength_attack_home', 'strength_attack_away',
         'strength_defence_home', 'strength_defence_away']
    ]

    rows = []
    for _, fixture in upcoming.iterrows():
        home, away, event = fixture['team_h'], fixture['team_a'], fixture['event']

        # Home team (X) vs away team (Y):
        # defensive_difficulty = how X's own defence compares to Y's attack
        # attacking_difficulty = how X's own attack compares to Y's defence
        # Positive = harder than average, negative = easier than average
        rows.append({
            'team': home, 'event': event, 'opponent': team_id_to_name.get(away, away),
            'was_home': True,
            'defensive_difficulty': strength.loc[away, 'strength_attack_away'] - strength.loc[home, 'strength_defence_home'],
            'attacking_difficulty': strength.loc[away, 'strength_defence_away'] - strength.loc[home, 'strength_attack_home'],
        })
        # Away team (Y) vs home team (X): mirror of the above
        rows.append({
            'team': away, 'event': event, 'opponent': team_id_to_name.get(home, home),
            'was_home': False,
            'defensive_difficulty': strength.loc[home, 'strength_attack_home'] - strength.loc[away, 'strength_defence_away'],
            'attacking_difficulty': strength.loc[home, 'strength_defence_home'] - strength.loc[away, 'strength_attack_away'],
        })

    df = pd.DataFrame(rows)
    df['team_name'] = df['team'].map(team_id_to_name)
    return df


def display_rotation_table(rotation_df, difficulty_col, title):
    """Prints a team x gameweek pivot of difficulty, sorted easiest overall first."""
    pivot = rotation_df.pivot_table(index='team_name', columns='event', values=difficulty_col, aggfunc='mean')
    pivot = pivot.round(0)

    averages = rotation_df.groupby('team_name')[difficulty_col].mean().round(1)
    pivot['average'] = averages
    pivot = pivot.sort_values('average')

    print(f"\n--- {title} (lower = easier) ---")
    print(pivot.to_string())


if __name__ == '__main__':
    from fetch_data import (get_fixtures, get_bootstrap_data, get_previous_season_fixture_strength,
                            get_clubelo_ratings, CLUBELO_NAME_MAP)

    # ---- The one line you change ----
    MODE = 'preseason'   # switch to 'inseason' once FPL's own strength data is populated

    fixtures_df = get_fixtures()
    all_data = get_bootstrap_data()
    teams_df = pd.DataFrame(all_data['teams'])

    if MODE == 'preseason':
        print("Preseason mode - using last season's actual fixture results, "
              "ClubElo for any newly promoted teams")
        strength_df = get_previous_season_fixture_strength()
        elo_df = get_clubelo_ratings()
        teams_df = apply_previous_season_strength(teams_df, strength_df, elo_df, CLUBELO_NAME_MAP)
    else:
        if strength_data_is_empty(teams_df):
            print("Warning: inseason mode selected, but FPL strength data is still empty - "
                  "switch MODE back to 'preseason' until real matches have been played")

    rotation_df = build_rotation_table(fixtures_df, teams_df, n_gameweeks=8)

    display_rotation_table(
        rotation_df, 'defensive_difficulty',
        'Defender Fixture Rotation (opponent attack strength)'
    )
    display_rotation_table(
        rotation_df, 'attacking_difficulty',
        'Attacker Fixture Rotation (opponent defence strength)'
    )

    print("\n--- Top 3 Defensive Rotation Pairs (lower avg = better coverage) ---")
    print(find_best_pairs(rotation_df, 'defensive_difficulty', top_n=3).to_string(index=False))

    print("\n--- Top 3 Attacking Rotation Pairs (lower avg = better coverage) ---")
    print(find_best_pairs(rotation_df, 'attacking_difficulty', top_n=3).to_string(index=False))