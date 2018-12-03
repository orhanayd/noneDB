<?php
//12.08.2018 -- 01:12:05
set_time_limit(0);
require_once("config.php");
require_once("func/func.php");

try {
    $noneDB_jsonData=noneDB_process($_POST['noneDB_process'], $_POST['noneDB_data'], $_POST['noneDB_db']);
    if(isset($_GET['type']) && $noneDB_apiActive==true && $_GET['type']=="api"){

        if($noneDB_apiHeaderJson===true){
            header("Content-type: application/json; charset=utf-8");
        }
            echo json_encode(noneDB_resultFunc($noneDB_jsonData['status'], $noneDB_jsonData['desc']));

    }
} catch (\Throwable $th) {
    echo $th->getMessage(), "\n";
}



?>