<?php
//12.08.2018 -- 01:12:05
set_time_limit(0);
require_once("config.php");
require_once("func/func.php");

try {
    $test=noneDB_process($_POST['noneDB_process'], $_POST['noneDB_data'], $_POST['noneDB_db']);
            echo json_encode($test);
} catch (\Throwable $th) {
    echo "ERROR: ". $th->getMessage(), "\n";
}

?>