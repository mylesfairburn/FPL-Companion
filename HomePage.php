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
                    <h4 class='text-center'>Player Stats (Sorted by Points)</h4>

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
                                foreach ($groupedPlayers[1] as $player) {
                                    echo "Name: " . $player['web_name'] . " - Points: " . $player['total_points'] . "(ID: " . $player['id'] . ")<br>";   
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
                                foreach ($groupedPlayers[2] as $player) {
                                    echo "Name: " . $player['web_name'] . " - Points: " . $player['total_points'] . "(ID: " . $player['id'] . ")<br>";                                
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
                                foreach ($groupedPlayers[3] as $player) {
                                    echo "Name: " . $player['web_name'] . " - Points: " . $player['total_points'] . "(ID: " . $player['id'] . ")<br>";                                
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
                                foreach ($groupedPlayers[4] as $player) {
                                    echo "Name: " . $player['web_name'] . " - Points: " . $player['total_points'] . "(ID: " . $player['id'] . ")<br>";                                
                                }
                            } else {
                                echo "No players found.";
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div> 

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>