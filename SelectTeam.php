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

    // Initialize database connection
    $db = new SQLite3('FPL-DB.db');

    // Cache configuration
    define('CACHE_TTL', 3600); // 1 hour cache
    define('CACHE_GRACE_PERIOD', 600); // 10 minute grace period

    function generateFreshPlayersData() {
        $url = "https://fantasy.premierleague.com/api/bootstrap-static/";
        $response = file_get_contents($url);
        $data = json_decode($response, true);
    
        $formattedPlayerData = []; // Initialize as array
        
        foreach($data['elements'] as $player){
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
        
                $db = new SQLite3('FPL-DB.db');
                $query = $db->prepare("SELECT abbreviation FROM teams WHERE id = :teamID");
                $query->bindValue(':teamID', $teamID, SQLITE3_TEXT);
                $queryResult = $query->execute();
                $result = $queryResult->fetchArray(SQLITE3_ASSOC);
        
                $upcomingFixtures[] = [
                    'team' => $result['abbreviation'] ?? '',
                    'difficulty' => $fixture['difficulty'],
                    'event' => $fixture['event'],
                    'is_home' => $fixture['is_home']
                ];
                $db->close();
            }
    
            // Add player data to the array instead of returning immediately
            $formattedPlayerData[] = [
                'id' => $player['id'],
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
        
        // Return all players data after processing all players
        return $formattedPlayerData;
    }

    function getCachedPlayers($forceRefresh = false) {
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
                        $freshData = generateFreshPlayersData();
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
        $freshData = generateFreshPlayersData();
        apcu_store($cacheKey, [
            'players' => $freshData,
            'generated' => time(),
            'expires' => time() + CACHE_TTL
        ], CACHE_TTL + CACHE_GRACE_PERIOD);
        
        return $freshData;
    }

    // Get players data (this will use cache when available)
    $players = getCachedPlayers();

    if (!isset($_SESSION['CurrentPage'])) {
        $_SESSION['CurrentPage'] = 1;
    }

    if (!isset($_SESSION['formToken'])) {
        $_SESSION['formToken'] = bin2hex(random_bytes(16));
    }

    $playerCount = count($players);
    $playersPerPage = 10;
    $pageCount = ceil($playerCount / $playersPerPage);

    // Handle POST requests for pagination
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['formToken'])) {
        if (hash_equals($_SESSION['formToken'], $_POST['formToken'])) {
            if (isset($_POST['prev']) && $_SESSION['CurrentPage'] > 1) {
                $_SESSION['CurrentPage']--;
            } 
            elseif (isset($_POST['next']) && $_SESSION['CurrentPage'] < $pageCount) {
                $_SESSION['CurrentPage']++;
            } 
            elseif (isset($_POST['resetCache'])) {
                $players = getCachedPlayers(true); // Force refresh
                $_SESSION['CurrentPage'] = 1;
            }
            $_SESSION['formToken'] = bin2hex(random_bytes(16));
        }
    }

    $currentPage = $_SESSION['CurrentPage'];
    $startIndex = ($currentPage - 1) * $playersPerPage;
    $endIndex = min($startIndex + $playersPerPage, $playerCount);

    function OrderPlayers($players){
        if (isset($_POST['orderByPrice'])) {
            usort($players, function ($a, $b) {
                return $b['now_cost'] <=> $a['now_cost'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else if (isset($_POST['orderByForm'])) {
            usort($players, function ($a, $b) {
                return $b['averagePoints'] <=> $a['averagePoints'];
            });
            $_SESSION['CurrentPage'] = 1;
        } 
        else if (isset($_POST['orderByxGA'])) {
            usort($players, function ($a, $b) {
                return $b['totalExpectedGA'] <=> $a['totalExpectedGA'];
            });
            $_SESSION['CurrentPage'] = 1;
        } 
        else if (isset($_POST['orderByGA'])) {
            usort($players, function ($a, $b) {
                return $b['totalGA'] <=> $a['totalGA'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else if (isset($_POST['orderByAvgMins'])) {
            usort($players, function ($a, $b) {
                return $b['averageMinutes'] <=> $a['averageMinutes'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else if (isset($_POST['orderByCleanSheets'])) {
            usort($players, function ($a, $b) {
                return $b['totalCleanSheets'] <=> $a['totalCleanSheets'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else if (isset($_POST['orderByBonus'])) {
            usort($players, function ($a, $b) {
                return $b['averageBonus'] <=> $a['averageBonus'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else if (isset($_POST['orderByPoints'])) {
            usort($players, function ($a, $b) {
                return $b['total_points'] <=> $a['total_points'];
            });
            $_SESSION['CurrentPage'] = 1;
        }
        else {
            usort($players, function ($a, $b) {
                return $b['now_cost'] <=> $a['now_cost'];
            });
        }
        return $players;
    }
    function FetchKitPath($playerInfo){
        $db = new SQLite3('FPL-DB.db');

        if ($playerInfo['element_type'] == 1) {
            $query = $db->prepare("
                SELECT keeper_kit FROM Kits 
                WHERE team_id = :teamID 
            ");
    
            $query->bindValue(':teamID', $playerInfo['team'], SQLITE3_TEXT);
            $queryresult = $query->execute();
            $result = $queryresult->fetchArray(SQLITE3_ASSOC);
    
            return $result['keeper_kit'];
        } else {
            $query = $db->prepare("
                SELECT outfield_kit FROM Kits 
                WHERE team_id = :teamID 
            ");
    
            $query->bindValue(':teamID', $playerInfo['team'], SQLITE3_TEXT);
            $queryresult = $query->execute();
            $result = $queryresult->fetchArray(SQLITE3_ASSOC);
    
            return $result['outfield_kit'];
        }

        $db -> close();
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
                    </datalist> <!-- Search PLayer options -->
                    <button class="btn btn-primary" type="button">Go</button>
                </div>

                <table class="table table-bordered table-hover mt-3">
                    <tr>
                        <th style="width: 100px;">Name</th>
                        <th>Position</th>
                        <th>
                            <div class="d-flex align-items-center">
                                <form method="POST" action="SelectTeam.php">
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
                    $players = OrderPlayers($players);
                    for ($x = $startIndex; $x < $endIndex; $x++) {
                        
                        $kitDir = FetchKitPath($players[$x]);
                    
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
                    <!-- Previous/Next buttons -->
                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="prev" id="prev" <?php echo $currentPage == 1 ? 'disabled' : ''; ?>> < Prev </button>
                    </form>

                    <span>Page <?php echo $currentPage; ?> of <?php echo $pageCount; ?></span>

                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="next" id="next" <?php echo $currentPage == $pageCount ? 'disabled' : ''; ?>> Next > </button>
                    </form>
                </div>

                <div class="text-center mt-3">
                    <button class="btn btn-danger" style="height: 50px;" onclick="confirmCacheReset()">
                        Reset Cache
                    </button>
                    <!-- Hidden form for submission -->
                    <form id="resetCacheForm" method="POST" action="SelectTeam.php" style="display: none;">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <input type="hidden" name="resetCache" value="1">
                    </form>
                </div>

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
                text: "'Form' refers to the players average points over the previous 3 gameweeks.",
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
                text: "'GA' refers to the players total goals and assists over the previous 3 gameweeks.",
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
                text: "'xGA' refers to the players average expected goals and assists over the previous 3 gameweeks.",
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
                text: "'Avg Minutes' refers to the average minutes over the previous 3 gameweeks.",
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
                text: "'Avg Bonus' refers to the average bonus points recieved by the player over the previous 3 gameweeks.",
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
                text: "'Clean Sheets' refers to the total clean sheets kept by the players team over the previous 3 gameweeks.",
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
                text: "'Total Points' refers to the players total points over the course of the whole season.",
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