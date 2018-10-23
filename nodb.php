<?php
//12.08.2018 -- 01:12:05
require_once("config.php");
require_once("func/func.php");

if(isset($_POST['noneDB_secretKey']) && $_POST['noneDB_secretKey']!=null && $noneDB_secretKey==$_POST['noneDB_secretKey']){

    if(isset($_POST['noneDB_db']) && $_POST['noneDB_db']!=null){
        if(isset($_POST['noneDB_data']) && $_POST['noneDB_data']!=null){
            if(noneDB_checkDB($_POST['noneDB_db'])){
                if(noneDB_insert($_POST['noneDB_data'], $_POST['noneDB_db'])){
                    $noneDB_jsonData=json_encode(noneDB_resultFunc(true, "inserted"));
                }else{
                    $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "insert failed"));
                }
            }else{
                if($noneDB_autoCreate){
                    // TODO
                    if(noneDB_createDB($_POST['noneDB_db'])){
                        if(noneDB_insert($_POST['noneDB_data'], $_POST['noneDB_db'])){
                            $noneDB_jsonData=json_encode(noneDB_resultFunc(true, "inserted"));
                        }else{
                            $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "insert failed"));
                        }
                    }else{
                        $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "database create failed"));
                    }

                }else{
                    $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "database not found"));
                }
            }
        }else{
            $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "data field is empty"));
        }
    }else{
        $noneDB_jsonData=json_encode(noneDB_resultFunc(false, "db field is empty"));
    }

}else{

    if(isset($_POST['noneDB_secretKey']) && $_POST['noneDB_secretKey']!=null){
        $result="Please set the secret key, we recommended read the documentation.";
    }
    if(isset($_POST['noneDB_secretKey']) && $noneDB_secretKey!=$_POST['noneDB_secretKey']){
        $result="Secret key does not match.";
    }
    if(!isset($_POST['noneDB_secretKey'])){
        $result="Secret key parameter is not found.";
    }
        $noneDB_jsonData=json_encode(noneDB_resultFunc(false, $result));

}

if(isset($_GET['type']) && $noneDB_apiActive==true && $_GET['type']=="api"){

    if($noneDB_apiHeaderJson===true){
        header("Content-type: application/json; charset=utf-8");
    }
        echo $noneDB_jsonData;

}
?>