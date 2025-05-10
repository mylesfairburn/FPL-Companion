<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>FPL Companion</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="stylesheet.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
<?php
    set_time_limit(600);

    session_start();

    // Cache configuration
    define('CACHE_TTL', 6 * 60 * 60); // 2 hour cache
    define('CACHE_GRACE_PERIOD', 600); // 10 minute grace period

    $db = new SQLite3('FPL-DB.db');

    $query = $db->prepare("
                SELECT teams.id, teams.name, teams.abbreviation, teams.short_name, kits.outfield_kit, kits.keeper_kit 
                FROM teams
                INNER JOIN kits ON teams.id = kits.team_id
            ");
    
    $queryresult = $query->execute();
    $teamArray = [];

    while ($row = $queryresult->fetchArray(SQLITE3_ASSOC)) {
        $teamArray[$row['id']] = $row;
    }

    $db -> close();

    function generateFreshPlayersData($teamArray) {
        $url = "https://fantasy.premierleague.com/api/bootstrap-static/";
        $response = file_get_contents($url);
        $data = json_decode($response, true);
    
        $formattedPlayerData = []; // Initialize as array
        
        foreach($data['elements'] as $player){
            if($player['can_transact'] && $player['can_select']){
                $apiUrl = 'https://fantasy.premierleague.com/api/element-summary/' . $player['id'] . '/';
                $response = file_get_contents($apiUrl);
                $individualData = json_decode($response, true);
                
                $last3Gameweeks = array_slice($individualData['history'], -3);
            
                $totalPoints = 0;
                $totalGA = 0;
                $totalExpectedGA = 0;
                $totalCleanSheets = 0;
                $totalMinutes = 0;
                $totalBonus = 0;
                $gameweekCount = 0;
            
                foreach ($last3Gameweeks as $gameweek) {
                    $totalPoints += $gameweek['total_points'];
                    $totalGA += $gameweek['goals_scored'] + $gameweek['assists'];
                    $totalExpectedGA += $gameweek['expected_goal_involvements'];
                    $totalCleanSheets += $gameweek['clean_sheets'];
                    $totalMinutes += $gameweek['minutes'];
                    $totalBonus += $gameweek['bonus'];
                    $gameweekCount++;
                }
            
                $averagePoints = $gameweekCount > 0 ? $totalPoints / $gameweekCount : 0;
                $averageMinutes = $gameweekCount > 0 ? $totalMinutes / $gameweekCount : 0;
                $averageBonus = $gameweekCount > 0 ? $totalBonus / $gameweekCount : 0;
            
                $upcomingFixtures = [];
                foreach ($individualData['fixtures'] as $fixture) {
                    $teamID = $fixture['is_home'] ? $fixture['team_a'] : $fixture['team_h'];
            
                    $upcomingFixtures[] = [
                        'team' => $teamArray[$teamID]['abbreviation'] ?? '',
                        'difficulty' => $fixture['difficulty'],
                        'event' => $fixture['event'],
                        'is_home' => $fixture['is_home']
                    ];
                }
        
                $formattedPlayerData[] = [
                    'id' => $player['id'],
                    'can_transact' => $player['can_transact'],
                    'web_name' => $player['web_name'],
                    'team' => $player['team'],
                    'element_type' => $player['element_type'],
                    'now_cost' => $player['now_cost'],
                    'total_points' => $player['total_points'],
                    'totalGA' => $totalGA,
                    'totalExpectedGA' => $totalExpectedGA,
                    'totalCleanSheets' => $totalCleanSheets,
                    'averagePoints' => $averagePoints,
                    'averageMinutes' => $averageMinutes,
                    'averageBonus' => $averageBonus,
                    'upcomingFixtures' => $upcomingFixtures                       
                ];
            }
        }
        
        // Return all players data after processing all players
        return $formattedPlayerData;
    }
    function getCachedPlayers($forceRefresh, $teamArray) {
        $cacheKey = 'fpl_players_data';
        
        // If forcing refresh, skip cache check
        if (!$forceRefresh) {
            $cached = apcu_fetch($cacheKey, $success);
            
            // If valid cache exists, return it
            if ($success && isset($cached['expires']) && $cached['expires'] > time()) {
                return $cached['players'];
            }
            
            // If in grace period, return stale data while regenerating
            if ($success && isset($cached['generated']) && 
                (time() - $cached['generated']) < (CACHE_TTL + CACHE_GRACE_PERIOD)) {
                
                // Trigger async regeneration
                if (!isset($cached['regenerating'])) {
                    register_shutdown_function(function() use ($cacheKey) {
                        $freshData = generateFreshPlayersData($teamArray);
                        apcu_store($cacheKey, [
                            'players' => $freshData,
                            'generated' => time(),
                            'expires' => time() + CACHE_TTL
                        ], CACHE_TTL + CACHE_GRACE_PERIOD);

                    });
                }
                
                return $cached['players'];
            }
        }
        
        // Full regeneration required
        $freshData = generateFreshPlayersData($teamArray);
        apcu_store($cacheKey, [
            'players' => $freshData,
            'generated' => time(),
            'expires' => time() + CACHE_TTL
        ], CACHE_TTL + CACHE_GRACE_PERIOD);
        
        return $freshData;
    }

    if (!isset($_SESSION['CurrentPage'])) {
        $_SESSION['CurrentPage'] = 1;
    }
    if (!isset($_SESSION['formToken'])) {
        $_SESSION['formToken'] = bin2hex(random_bytes(16));
    }
    if (!isset($_SESSION['currentOrder'])) {
        $_SESSION['currentOrder'] = 'price';
    }
    if (!isset($_SESSION['selectedPositions'])) {
        $_SESSION['selectedPositions'] = [];
    }
    if (!isset($_SESSION['selectedTeam'])) {
        $_SESSION['selectedTeam'] = 0;
    }

    $players = getCachedPlayers(false, $teamArray);
    $playersPerPage = 10;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['formToken'])) {
        if (hash_equals($_SESSION['formToken'], $_POST['formToken'])) {
            $shouldResetPage = false;
    
            if (isset($_POST['orderByPrice'])) {
                $_SESSION['currentOrder'] = 'price';
                $_SESSION['CurrentPage'] = 1;
            } 
            elseif (isset($_POST['orderByForm'])) {
                $_SESSION['currentOrder'] = 'form';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByxGA'])) {
                $_SESSION['currentOrder'] = 'xGA';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByGA'])) {
                $_SESSION['currentOrder'] = 'GA';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByAvgMins'])) {
                $_SESSION['currentOrder'] = 'avgMins';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByCleanSheets'])) {
                $_SESSION['currentOrder'] = 'cleanSheets';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByBonus'])) {
                $_SESSION['currentOrder'] = 'bonus';
                $_SESSION['CurrentPage'] = 1;
            }
            elseif (isset($_POST['orderByPoints'])) {
                $_SESSION['currentOrder'] = 'points';
                $_SESSION['CurrentPage'] = 1;
            }
    
            if (isset($_POST['positionFilter'])) {
                $position = intval($_POST['positionFilter']);
                if ($position >= 1 && $position <= 5) {
                    if (in_array($position, $_SESSION['selectedPositions'])) {
                        $_SESSION['selectedPositions'] = array_diff($_SESSION['selectedPositions'], [$position]);
                    } else {
                        $_SESSION['selectedPositions'][] = $position;
                    }
                } else {
                    $_SESSION['selectedPositions'] = [];
                }
                $shouldResetPage = true;
            }

            if (isset($_POST['teamFilter'])) {
                $_SESSION['selectedTeam'] = intval($_POST['teamFilter']);
                $shouldResetPage = true;
            }
    
            if (isset($_POST['resetCache'])) {
                $players = getCachedPlayers(true, $teamArray);
                $shouldResetPage = true;
            }
    
            if (isset($_POST['prev'])) {
                $_SESSION['CurrentPage'] = max(1, $_SESSION['CurrentPage'] - 1);
            } 
            elseif (isset($_POST['next'])) {
                $_SESSION['CurrentPage']++; // Will be bounded later
            }
    
            if ($shouldResetPage) {
                $_SESSION['CurrentPage'] = 1;
            }
    
            $_SESSION['formToken'] = bin2hex(random_bytes(16));
        }
    }
    
    if (!empty($_SESSION['selectedPositions'])) {
        $players = array_filter($players, function($player) {
            return in_array($player['element_type'], $_SESSION['selectedPositions']);
        });
        $players = array_values($players);
    }
    if ($_SESSION['selectedTeam'] > 0) {
        $players = array_filter($players, function($player) {
            return $player['team'] == $_SESSION['selectedTeam'];
        });
    }
    
    $players = OrderPlayers($players);
    
    $playerCount = count($players);
    $pageCount = max(1, ceil($playerCount / $playersPerPage));
    
    $_SESSION['CurrentPage'] = max(1, min($_SESSION['CurrentPage'], $pageCount));
    $currentPage = $_SESSION['CurrentPage'];
    $startIndex = ($currentPage - 1) * $playersPerPage;
    $endIndex = min($startIndex + $playersPerPage, $playerCount);

    function OrderPlayers($players) {
        switch ($_SESSION['currentOrder']) {
            case 'price':
                usort($players, function ($a, $b) {
                    return $b['now_cost'] <=> $a['now_cost'];
                });
                break;
            case 'form':
                usort($players, function ($a, $b) {
                    return $b['averagePoints'] <=> $a['averagePoints'];
                });
                break;
            case 'xGA':
                usort($players, function ($a, $b) {
                    return $b['totalExpectedGA'] <=> $a['totalExpectedGA'];
                });
                break;
            case 'GA':
                usort($players, function ($a, $b) {
                    return $b['totalGA'] <=> $a['totalGA'];
                });
                break;
            case 'avgMins':
                usort($players, function ($a, $b) {
                    return $b['averageMinutes'] <=> $a['averageMinutes'];
                });
                break;
            case 'cleanSheets':
                usort($players, function ($a, $b) {
                    return $b['totalCleanSheets'] <=> $a['totalCleanSheets'];
                });
                break;
            case 'bonus':
                usort($players, function ($a, $b) {
                    return $b['averageBonus'] <=> $a['averageBonus'];
                });
                break;
            case 'points':
                usort($players, function ($a, $b) {
                    return $b['total_points'] <=> $a['total_points'];
                });
                break;
            default:
                usort($players, function ($a, $b) {
                    return $b['now_cost'] <=> $a['now_cost'];
                });
        }
        return $players;
    }
    function FetchKitPath($playerInfo, $teamArray){
        if ($playerInfo['element_type'] == 1) {
            return $teamArray[$playerInfo['team']]['keeper_kit'];
        } else {
            return $teamArray[$playerInfo['team']]['outfield_kit'];
        }
    }   
    function DisplayUpcomingFixtures($upcomingFixtures){
        echo "<td><div class='row'>";
        foreach ($upcomingFixtures as $fixture) {
            switch ($fixture['difficulty']){
                case 1:
                    $color= "#46f131";
                    break;
                case 2:
                    $color= "#1d7a12";
                    break;
                case 3:
                    $color= "#fca227";
                    break;
                case 4:
                    $color= "#ff6868";
                    break;
                case 5:
                    $color= "#b42626";
                    break;
            }

            $homeOrAway = $fixture['is_home'] ? 'H' : 'A';
            echo "<div class='col'>";
                echo "<div class='card pitch-card mx-auto text-center' style='width: 75px; height: 50px; background-color:$color; color: white;'><b><u>GW" . $fixture['event'] . "</u><br>" . $fixture['team'] . " (" . $homeOrAway . ")</b></div>";
            echo "</div>";
        }
        echo "</div></td>";
    }
    ?>

    <div class="container mt-5">
        <div class="card mx-auto" style="width: 100%;">
            <div class="card-body">
                <h2 class='text-center'><u>Select Your Team</u></h2>
                <div class="row">
                    <div class="col-md-3">
                        <h5>Recommended transfers:</h5>
                    </div>
                    <div class="col-md-6 text-center">
                        <div class="card mx-auto" style="width: 90%; background-image: url('images/background-pitch.png'); background-size: cover; background-position: center;">
                            <br> 
                            <div class="container text-center">
                                <div class="row">
                                    <div class="col">
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> GKP</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> GKP</i></div>
                                    </div>
                                    <div class="col">
                                    </div>
                                </div>
                            </div>
                            <br>
                            <div class="container text-center">
                                <div class="row">
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> DEF</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> DEF</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> DEF</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> DEF</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> DEF</div>
                                    </div>
                                </div>
                            </div>
                            <br>
                            <div class="container text-center">
                                <div class="row">
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> MID</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> MID</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> MID</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> MID</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> MID</div>
                                    </div>
                                </div>
                            </div>
                            <br>
                            <div class="container text-center">
                                <div class="row">
                                    <div class="col">
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> FWD</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> FWD</div>
                                    </div>
                                    <div class="col">
                                        <div class="card pitch-card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;"><i class='bx bxs-user-plus'></i> FWD</div>
                                    </div>
                                    <div class="col">
                                    </div>
                                </div>
                            </div>
                            <!-- <br>
                            <div class="container text-center" style="width: 75%;">
                                <h5 style="text-align: left;">Bench:</h5>
                                <div class="row">
                                    <div class="col">
                                        <div class="card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;">Add player</div>
                                    </div>
                                    <div class="col">
                                        <div class="card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;">Add player</div>
                                    </div>
                                    <div class="col">
                                        <div class="card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;">Add player</div>
                                    </div>
                                    <div class="col">
                                        <div class="card mx-auto" style="width: 75px; height: 100px; background-color:#ffffff9e; color: #ffffff;">Add player</div>
                                    </div>
                                </div>
                            </div> Alignment for the bench -->
                            <br>
                        </div>
                    </div>
                    <div class="col-md-3"> 
                        <h5>Player watchlist:</h5>
                    </div>                       
                </div>

                <br>

                <div class="d-flex">
                    <input class="form-control me-2" list="datalistOptions" id="exampleDataList" placeholder="Search Players:">
                    <datalist id="datalistOptions">
                        <?php
                        for ($x = 0; $x < $playerCount; $x++) {
                            echo "<option>" . $players[$x]['web_name'] . "</option>";
                        }
                        ?>
                    </datalist> <!-- Search player options -->
                    <button class="btn btn-primary" type="button">Go</button>
                </div>

                <br>
                <div class="d-inline-flex gap-2">
                    <div class="dropdown">
                        <form method="POST" action="SelectTeam.php" class="d-inline">
                            <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                            
                            <button class="btn btn-outline-info dropdown-toggle <?= $_SESSION['selectedTeam'] > 0 ? 'active' : '' ?>" type="button" id="teamDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <b>
                                    <?= $_SESSION['selectedTeam'] > 0 ? $teamArray[$_SESSION['selectedTeam']]['abbreviation'] : 'Select Team' ?>
                                </b>
                            </button>

                            <ul class="dropdown-menu" aria-labelledby="teamDropdown" style="max-height: 300px; overflow-y: auto;">
                                <li>
                                    <button class="dropdown-item <?= $_SESSION['selectedTeam'] == 0 ? 'active' : '' ?>" type="submit" name="teamFilter"value="0">
                                        All Teams
                                    </button>
                                </li>
                                <?php foreach ($teamArray as $team): ?>
                                    <li>
                                        <button class="dropdown-item <?= $_SESSION['selectedTeam'] == $team['id'] ? 'active' : '' ?>" type="submit" name="teamFilter" value="<?= $team['id'] ?>">
                                            <img src="<?= $team['outfield_kit'] ?>" style="width: 20px; height: 25px; object-fit: contain;">
                                            <span style="margin-left: 8px;"><?= $team['short_name'] ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </form>
                    </div>
                    <form method="POST" action="SelectTeam.php" class="d-inline">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button type="submit" name="positionFilter" value="1" 
                                class="btn btn-outline-info <?= in_array(1, $_SESSION['selectedPositions'] ?? []) ? 'active' : '' ?>">
                            <b>Goalkeepers</b>
                        </button>
                    </form>
                    <form method="POST" action="SelectTeam.php" class="d-inline">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button type="submit" name="positionFilter" value="2" 
                                class="btn btn-outline-info <?= in_array(2, $_SESSION['selectedPositions'] ?? []) ? 'active' : '' ?>">
                            <b>Defenders</b>
                        </button>
                    </form>
                    <form method="POST" action="SelectTeam.php" class="d-inline">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button type="submit" name="positionFilter" value="3" 
                                class="btn btn-outline-info <?= in_array(3, $_SESSION['selectedPositions'] ?? []) ? 'active' : '' ?>">
                            <b>Midfielders</b>
                        </button>
                    </form>
                    <form method="POST" action="SelectTeam.php" class="d-inline">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button type="submit" name="positionFilter" value="4" 
                                class="btn btn-outline-info <?= in_array(4, $_SESSION['selectedPositions'] ?? []) ? 'active' : '' ?>">
                            <b>Forwards</b>
                        </button>
                    </form>
                    <form method="POST" action="SelectTeam.php" class="d-inline">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button type="submit" name="positionFilter" value="5" 
                                class="btn btn-outline-info <?= in_array(5, $_SESSION['selectedPositions'] ?? []) ? 'active' : '' ?>">
                            <b>Managers</b>
                        </button>
                    </form>
                </div> <!-- Select player data -->

                <table class="table table-bordered table-hover mt-3">
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByPrice" id="orderByPrice" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle"> Price </p>
                            </div>
                        </th> <!-- price -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByForm" id="orderByForm" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle"> Form </p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayFormInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- form, last 3 gameweeks -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByxGA" id="orderBxGA" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">xGA</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayxGAInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- xGA, last 3 gameweeks -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByGA" id="orderByGA" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">GA</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayGAInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- GA, last 3 gameweeks -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByAvgMins" id="orderByAvgMins" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">Mins</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayAvgMinutesInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- Average minutes played, last 3 gameweeks -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByCleanSheets" id="orderByCleanSheets" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">CSs</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayCleanSheetsInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- clean sheets, last 3 games -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByBonus" id="orderByBonus" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">Bonus</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayAvgBonusInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- Average bonus, last 3 gameweeks -->
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
                                    <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                                    <button class="btn p-0 border-0 align-middle" type="submit" name="orderByPoints" id="orderByPoints" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                        <img src="images/Up-Down-icon.png" alt="Order By" style="width: 16px; height: 16px;">
                                    </button>
                                </form>
                                <p class="mb-0 me-1 align-middle">Points</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayTotalPointsInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th> <!-- Total points -->
                        <th style="width: 290px;">Fixtures</th>
                    </tr>
                    <?php
                    for ($x = $startIndex; $x < $endIndex; $x++) {
                        
                        $kitDir = FetchKitPath($players[$x], $teamArray);
                    
                        echo "<tr>";
                        echo "<td><div class='d-flex align-items-center'><img src='$kitDir' class='rounded float-start' style='width: 30px; height: 40px;' alt='Kit Img'><span style='margin-left: 10px;'><b>" . $players[$x]['web_name'] . "</b></span></div></td>";
                        echo "<td>";
                        switch ($players[$x]['element_type']) {
                            case 1:
                                echo "<p style='color:green;'>GKP</p>";
                                break;
                            case 2:
                                echo "<p style='color:blue;'>DEF</p>";
                                break;
                            case 3:
                                echo "<p style='color:orange;'>MID</p>";
                                break;
                            case 4:
                                echo "<p style='color:red;'>FWD</p>";
                                break;
                            default:
                                echo "<p style='color:grey;'>MNG</p>";
                                break;
                        }
                        echo "</td>";

                        echo "<td>£" . $players[$x]['now_cost'] / 10 . "m</td>";
                        echo "<td>" . number_format($players[$x]['averagePoints'], 2) . "</td>";
                        echo "<td>" . number_format($players[$x]['totalExpectedGA'], 2) . "</td>";
                        echo "<td>" . $players[$x]['totalGA'] . "</td>";
                        echo "<td>" . number_format($players[$x]['averageMinutes'], 2) . "</td>";
                        echo "<td>" . $players[$x]['totalCleanSheets'] . "</td>";
                        echo "<td>" . number_format($players[$x]['averageBonus'], 2) . "</td>";
                        echo "<td><p>" . $players[$x]['total_points'] . "</p></td>";

                        DisplayUpcomingFixtures($players[$x]['upcomingFixtures']);

                        echo "</tr>";
                    }
                    ?>
                </table>

                <div class="d-flex justify-content-between">
                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="prev" id="prev" <?php echo $currentPage == 1 ? 'disabled' : ''; ?>> < Prev </button>
                    </form>

                    <span>Page <?php echo $currentPage; ?> of <?php echo $pageCount; ?></span>

                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="next" id="next" <?php echo $currentPage == $pageCount ? 'disabled' : ''; ?>> Next > </button>
                    </form>
                </div> <!-- prev and next buttons -->

                <div class="text-center mt-3">
                    <button class="btn btn-danger" style="height: 50px;" onclick="confirmCacheReset()">
                        Reset Cache
                    </button>
                    <form id="resetCacheForm" method="POST" action="SelectTeam.php" style="display: none;">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <input type="hidden" name="resetCache" value="1">
                    </form>
                </div> <!-- reset cache -->

                <div class="text-center">
                    <br><a href="HomePage.php"><button class="btn btn-primary" type="button">Back</button></a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Save the current scroll position before the page is unloaded
        window.onbeforeunload = function () {
            sessionStorage.setItem('scrollPosition', window.scrollY);
        };

        // Restore the scroll position instantly on page load
        window.onload = function () {
            let scrollPosition = sessionStorage.getItem('scrollPosition');
            if (scrollPosition) {
                document.documentElement.style.scrollBehavior = 'auto'; // Temporarily disable smooth scroll
                window.scrollTo(0, scrollPosition);
                setTimeout(() => {
                    document.documentElement.style.scrollBehavior = ''; // Re-enable smooth scroll
                }, 1); // Allow styles to reset
            }
        };

        function DisplayFormInfo(){
            Swal.fire({
                title: "<strong><u>Form</u></strong>",
                icon: "info",
                text: "'Form' refers to the players average points over the previous 3 gameweeks. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayGAInfo(){
            Swal.fire({
                title: "<strong><u>GA</u></strong>",
                icon: "info",
                text: "'GA' refers to the players total goals and assists over the previous 3 gameweeks.  Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayxGAInfo(){
            Swal.fire({
                title: "<strong><u>xGA</u></strong>",
                icon: "info",
                text: "'xGA' refers to the players average expected goals and assists over the previous 3 gameweeks. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayAvgMinutesInfo(){
            Swal.fire({
                title: "<strong><u>Average Minutes</u></strong>",
                icon: "info",
                text: "'Avg Minutes' refers to the average minutes over the previous 3 gameweeks. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayAvgBonusInfo(){
            Swal.fire({
                title: "<strong><u>Average Bonus Points</u></strong>",
                icon: "info",
                text: "'Avg Bonus' refers to the average bonus points recieved by the player over the previous 3 gameweeks. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayCleanSheetsInfo(){
            Swal.fire({
                title: "<strong><u>Clean Sheets</u></strong>",
                icon: "info",
                text: "'Clean Sheets' refers to the total clean sheets kept by the players team over the previous 3 gameweeks. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function DisplayTotalPointsInfo(){
            Swal.fire({
                title: "<strong><u>Total Points</u></strong>",
                icon: "info",
                text: "'Total Points' refers to the players total points over the course of the whole season. Press the arrows to the left to order by this stat, the players will be put in descending order.",
                focusConfirm: false,
                confirmButtonText: `
                    <i class="fa fa-thumbs-up"></i> Great!
                `,
            });
        }
        function confirmCacheReset() {
            Swal.fire({
                title: 'Reset Cache?',
                text: 'This will fetch fresh player data from the FPL API.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, reset it!',
                cancelButtonText: 'Cancel',
                background: '#f8f9fa',
                showClass: {
                    popup: 'animate__animated animate__fadeInDown'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOutUp'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Submit the hidden form
                    document.getElementById('resetCacheForm').submit();
                }
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>