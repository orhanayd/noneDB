<?php
function noneDB_resultFunc($status, $content){
    global $noneDB_requestShow;
    global $noneDB_secretKeyShow;
    global $noneDB_showDataField;
    global $noneDB_showDBField;
    $requestData=[];
    // if requestShow is true we show all request data
    if($noneDB_requestShow===true){
        // if true status
        if($status){
            // if secretKeyShow is true we show secret key in the result request data
            if($noneDB_secretKeyShow!=true){
                unset($_POST['noneDB_secretKey']);
            }
            if($noneDB_showDataField!=true){
                unset($_POST['noneDB_data']);
            }
            if($noneDB_showDBField!=true){
                unset($_POST['noneDB_db']);
            }
            // request data
            $request=array(
                "get"=>$_GET,
                "post"=>$_POST
            );
            // push to the result.
            array_push($requestData, $request);
        }
    }
    // result data
    $result=array(
        "status"=>$status,
        "time"=> time(),
        "content"=>$content,
        "request"=>$requestData
    ); 
    return $result;
}

function noneDB_hashCreate($arg){
    global $noneDB_secretKey;
    return hash_pbkdf2("sha256", $arg, $noneDB_secretKey, 1000, 20);
}

function noneDB_checkDB($db){
    global $noneDB_secretKey;
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    if(file_exists("data/".$prefix."_".$dbFile.".json")){
        return true;
    }
    return false;
}

function noneDB_createDB($arg){
    global $noneDB_secretKey;
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile = fopen('data/'.$prefix.'_'.noneDB_createDB($arg).'.json', 'w');
    fclose($dbFile);
}
?>