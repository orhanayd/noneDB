<?php
//12.08.2018 -- 01:12:05
set_time_limit(0);
require_once("config.php");
require_once("func/func.php");


    if($_POST['noneDB_process']=="insert"){
        try {
            /**
             * (process, arg, db)
             */
            $test=noneDB_process("insert", $_POST['noneDB_data'], "test");
                    echo json_encode($test);
        } catch (\Throwable $th) {
            echo "ERROR: ". $th->getMessage(), "\n";
        }
    }
    if($_POST['noneDB_process']=="find"){
        try {
            /**
             * (process, arg, db)
             */
            $test=noneDB_process("find", null, "test");
                    echo json_encode($test);
        } catch (\Throwable $th) {
            echo "ERROR: ". $th->getMessage(), "\n";
        }
    }

    if($_POST['noneDB_process']=="status"){
        try {
            /**
             * (process, null, db)
             */
            $test=noneDB_process("status", null, "test");
                    echo json_encode($test);
        } catch (\Throwable $th) {
            echo "ERROR: ". $th->getMessage(), "\n";
        }
    }

?>