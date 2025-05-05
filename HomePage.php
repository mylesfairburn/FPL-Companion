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
    </head>
    
    <body>

        <div class="container mt-5">
            <div class="card mx-auto" style="width: 100%;">
                <div class="card-body">
                    <h2 class='text-center'><u>Player Stats (Sorted by Total Points)</u></h2>

                    <?php
                    $mylesFPLID = 253006;

                    // Fetch public FPL data
                    $url = "https://fantasy.premierleague.com/api/bootstrap-static/";
                    $response = file_get_contents($url);
                    $data = json_decode($response, true);

                    // Extract players data
                    $players = $data['elements'];

                    usort($players, function ($a, $b) {
                        return $b['total_points'] <=> $a['total_points'];
                    });
                    
                    // Group players by position
                    $positions = [
                        1 => "Goalkeepers",
                        2 => "Defenders",
                        3 => "Midfielders",
                        4 => "Forwards"
                    ];

                    $groupedPlayers = [];
                    foreach ($players as $player) {
                        $position = $player['element_type'];
                        $groupedPlayers[$position][] = $player;
                    }
                    ?>

                    <div class="row">
                        <div class="col-md-3">
                            <h5>Goalkeepers</h5>
                            <?php
                            if (isset($groupedPlayers[1])) {
                                for($x = 0; $x <= 25; $x++) {
                                    echo "<span style='color: red;'>" . $groupedPlayers[1][$x]['web_name'] . "</span> - Points: " . $groupedPlayers[1][$x]['total_points'] . "(ID: " . $groupedPlayers[1][$x]['id'] . ")<br>";   
                                }
                            } else {
                                echo "No players found.";
                            }
                            ?>
                        </div>
                        <div class="col-md-3">
                            <h5>Defenders</h5>
                            <?php
                            if (isset($groupedPlayers[2])) {
                                for($x = 0; $x <= 25; $x++) {
                                    echo "<span style='color: red;'>" . $groupedPlayers[2][$x]['web_name'] . "</span> - Points: " . $groupedPlayers[2][$x]['total_points'] . "(ID: " . $groupedPlayers[2][$x]['id'] . ")<br>";   
                                }
                            } else {
                                echo "No players found.";
                            }
                            ?>
                        </div>
                        <div class="col-md-3">
                            <h5>Midfielders</h5>
                            <?php
                            if (isset($groupedPlayers[3])) {
                                for($x = 0; $x <= 25; $x++) {
                                    echo "<span style='color: red;'>" . $groupedPlayers[3][$x]['web_name'] . "</span> - Points: " . $groupedPlayers[3][$x]['total_points'] . "(ID: " . $groupedPlayers[3][$x]['id'] . ")<br>";   
                                }
                            } else {
                                echo "No players found.";
                            }
                            ?>
                        </div>
                        <div class="col-md-3">
                            <h5>Forwards</h5>
                            <?php
                            if (isset($groupedPlayers[4])) {
                                for($x = 0; $x <= 25; $x++) {
                                    echo "<span style='color: red;'>" . $groupedPlayers[4][$x]['web_name'] . "</span> - Points: " . $groupedPlayers[4][$x]['total_points'] . "(ID: " . $groupedPlayers[4][$x]['id'] . ")<br>";   
                                }
                            } else {
                                echo "No players found.";
                            }
                            ?>
                        </div>

                        <div class="text-center">
                            <a href="SelectTeam.php"><button class="btn btn-primary" type="button">Select Team</button></a>
                        </div>
                    </div>
                </div>
            </div>
        </div> 

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>