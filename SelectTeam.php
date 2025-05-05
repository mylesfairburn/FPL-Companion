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
    </head>
    
    <body>
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

        $playerCount = count($players);
        $nextPageCount = ceil($playerCount / 10);
        $prevPageCount = 0;

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
                            <div class="card mx-auto" style="width: 90%; background-image: url('background-pitch.png'); background-size: cover; background-position: center;">
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

                            <table class="table class='table table-bordered table-hover mt-3'">
                                <tr>
                                    <th>Name</th>
                                    <th>Points</th>
                                    <th>ID</th>
                                </tr>
                                <?php 
                                    for($x = 0; $x <= 10; $x++) {
                                        echo "<tr>";
                                        echo "<td><span style='color: red;'>" . $players[$x]['web_name'] . "</span></td>";   
                                        echo "<td>" . $players[$x]['total_points'] . "</td>";   
                                        echo "<td class = 'smaller-text'>(" . $players[$x]['id'] . ")</td>";   
                                        echo "</tr>";
                                    }
                                ?>
                            </table>
                            <div class="d-flex justify-content-between">
                                <button class="btn btn-secondary" type="button" name="prev" id="prev">
                                    < Prev (<?php echo $prevPageCount; ?>)
                                </button>
                                <button class="btn btn-secondary" type="button" name="next" id="next">
                                    Next (<?php echo $nextPageCount; ?>) >
                                </button>
                            </div> 
                        </div>                       
                    </div>

                    <div class="text-center">
                        <br><a href="HomePage.php"><button class="btn btn-primary" type="button">Back</button></a>
                    </div>
                </div>
            </div>
        </div> 

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>