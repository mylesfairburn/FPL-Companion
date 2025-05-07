<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>FPL Companion Login</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="stylesheet.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
    <?php
    session_start();

    $db = new SQLite3('FPL-DB.db'); 

    // Initialize the current page if not set
    if (!isset($_SESSION['CurrentPage'])) {
        $_SESSION['CurrentPage'] = 1;
    }

    // Handle unique token for form submission
    if (!isset($_SESSION['formToken'])) {
        $_SESSION['formToken'] = bin2hex(random_bytes(16));
    }

    // Fetch public FPL data
    $url = "https://fantasy.premierleague.com/api/bootstrap-static/";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    // Extract players data
    $players = $data['elements'];

    usort($players, function ($a, $b) {
        return $b['total_points'] <=> $a['total_points'];
    });

    $playerCount = count($players);

    $playersPerPage = 10;

    $pageCount = ceil($playerCount / $playersPerPage);

    // Handle POST requests for pagination
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['formToken'])) {
        // Validate the token to ensure the request isn't reprocessed on refresh
        if (hash_equals($_SESSION['formToken'], $_POST['formToken'])) {
            if (isset($_POST['prev']) && $_SESSION['CurrentPage'] > 1) {
                $_SESSION['CurrentPage']--;
            } elseif (isset($_POST['next']) && $_SESSION['CurrentPage'] < $pageCount) {
                $_SESSION['CurrentPage']++;
            }
            // Generate a new token to prevent reprocessing on refresh
            $_SESSION['formToken'] = bin2hex(random_bytes(16));
        }
    }

    $currentPage = $_SESSION['CurrentPage'];

    $startIndex = ($currentPage - 1) * $playersPerPage;
    $endIndex = min($startIndex + $playersPerPage, $playerCount);
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
                    </datalist>
                    <button class="btn btn-primary" type="button">Go</button>
                </div>

                <table class="table table-bordered table-hover mt-3">
                    <tr>
                        <th style="width: 100px;">Name</th>
                        <th>Position</th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">Form</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayFormInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">xGA</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayxGAInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">GA</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayGAInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">Avg Mins</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayAvgMinutesInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">Avg Bonus</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayAvgBonusInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">Clean Sheets</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayCleanSheetsInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center">
                                <p class="mb-0 me-1 align-middle">Total Pts</p>
                                <button class="btn p-0 border-0 bg-transparent align-middle" onclick="DisplayTotalPointsInfo()" style="width: 16px; height: 16px; display: inline-flex; align-items: center;">
                                    <img src="images/info-icon.png" alt="Info Card" style="width: 16px; height: 16px;">
                                </button>
                            </div>
                        </th>
                        <th>Fixtures</th>
                        <th>ID</th>
                    </tr>
                    <?php
                    for ($x = $startIndex; $x < $endIndex; $x++) {
                        // Fetch player's kit path based on their element type
                        if ($players[$x]['element_type'] == 1) {
                            $query = $db->prepare("
                                SELECT keeper_kit FROM Kits 
                                WHERE team_id = :teamID 
                            ");
                    
                            $query->bindValue(':teamID', $players[$x]['team'], SQLITE3_TEXT);
                            $queryresult = $query->execute();
                            $result = $queryresult->fetchArray(SQLITE3_ASSOC);
                    
                            $kitDir = $result['keeper_kit'];
                        } else {
                            $query = $db->prepare("
                                SELECT outfield_kit FROM Kits 
                                WHERE team_id = :teamID 
                            ");
                    
                            $query->bindValue(':teamID', $players[$x]['team'], SQLITE3_TEXT);
                            $queryresult = $query->execute();
                            $result = $queryresult->fetchArray(SQLITE3_ASSOC);
                    
                            $kitDir = $result['outfield_kit'];
                        }
                    
                        // Fetch player performance data from the FPL API
                        $playerId = $players[$x]['id'];
                        $apiUrl = 'https://fantasy.premierleague.com/api/element-summary/' . $playerId . '/';
                        $response = file_get_contents($apiUrl);
                        $data = json_decode($response, true);
                    
                        // Initialize variables for past performance
                        $last3Gameweeks = array_slice($data['history'], -3);
                        $totalMinutes = 0;
                        $totalPoints = 0;
                        $totalExpectedGA = 0;
                        $totalGA = 0;
                        $totalBonus = 0;
                        $totalCleanSheets = 0;
                        $gameweekCount = 0;
                    
                        foreach ($last3Gameweeks as $gameweek) {
                            $totalMinutes += $gameweek['minutes'];
                            $totalExpectedGA += $gameweek['expected_goal_involvements'];
                            $totalGA += $gameweek['goals_scored'] + $gameweek['assists'];
                            $totalPoints += $gameweek['total_points'];
                            $totalBonus += $gameweek['bonus'];
                            $totalCleanSheets += $gameweek['clean_sheets'];
                            $gameweekCount++;
                        }

                        $averagePoints = $gameweekCount > 0 ? $totalPoints / $gameweekCount : 0;
                        $averageMinutes = $gameweekCount > 0 ? $totalMinutes / $gameweekCount : 0;
                        $averageBonus = $gameweekCount > 0 ? $totalBonus / $gameweekCount : 0;
                    
                        // Fetch upcoming fixtures
                        $upcomingFixtures = [];
                        foreach ($data['fixtures'] as $fixture) {
                            $teamID = $fixture['is_home'] ? $fixture['team_a'] : $fixture['team_h'];
                    
                            $query = $db->prepare("
                                SELECT abbreviation FROM teams 
                                WHERE id = :teamID 
                            ");
                    
                            $query->bindValue(':teamID', $teamID, SQLITE3_TEXT);
                            $queryresult = $query->execute();
                            $result = $queryresult->fetchArray(SQLITE3_ASSOC);
                    
                            $upcomingFixtures[] = [
                                'team' => $result['abbreviation'],
                                'difficulty' => $fixture['difficulty'],
                                'event' => $fixture['event'],
                                'is_home' => $fixture['is_home']
                            ];
                        }
                    
                        // Display the player's details in the table
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
                        }
                        echo "</td>";
                    
                        echo "<td><p>" . round($averagePoints, 1) . "</p></td>";
                        echo "<td><p>" . $totalExpectedGA . "</p></td>";
                        echo "<td><p>" . $totalGA . "</p></td>";
                        echo "<td><p>" . round($averageMinutes, 1) . "</p></td>";
                        echo "<td><p>" . round($averageBonus, 1) . "</p></td>";
                        echo "<td><p>" . round($totalCleanSheets, 1) . "</p></td>";
                        echo "<td><p>" . $players[$x]['total_points'] . "</p></td>";

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
                    
                        echo "<td class='smaller-text'><p>(" . $players[$x]['id'] . ")</p></td>";
                        echo "</tr>";
                    }
                    ?>
                </table>

                <div class="d-flex justify-content-between">
                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="prev" id="prev"
                                <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                            < Prev
                        </button>
                    </form>

                    <span>Page <?php echo $currentPage; ?> of <?php echo $pageCount; ?></span>

                    <form method="POST" action="SelectTeam.php">
                        <input type="hidden" name="formToken" value="<?php echo $_SESSION['formToken']; ?>">
                        <button class="btn btn-secondary" type="submit" name="next" id="next"
                                <?php echo $currentPage == $pageCount ? 'disabled' : ''; ?>>
                            Next >
                        </button>
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
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>