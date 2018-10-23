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

function noneDB_checkDB($arg){
    global $noneDB_secretKey;
    global $noneDB_dbFolder;
    global $noneDB_autoCreateDB;

    if($noneDB_autoCreateDB){
        noneDB_createDB($_POST['noneDB_db']);
    }

    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($arg);
    if(file_exists($noneDB_dbFolder."/".$prefix."_".$dbFile.".json")){
        return true;
    }else{
        return false;
    }
}

function noneDB_createDB($arg){
    global $noneDB_secretKey;
    global $noneDB_version;
    global $noneDB_dbFolder;
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $time=time();
    $dbRaw=array(
        "config"=>array(
           "dbName"=>$arg, "version"=>$noneDB_version, "createdDate"=>$time
        ),
        "data"=>array()
    );
    $dbFile = fopen($noneDB_dbFolder.'/'.$prefix.'_'.noneDB_hashCreate($arg).'.json', 'w');
    fwrite($dbFile, json_encode($dbRaw));
    fclose($dbFile);
    return true;
}

function noneDB_process($process, $data, $db){
    if(noneDB_checkDB($db)){
        if($process=="insert"){
            return noneDB_insert($data, $db);
        }
        if($process=="update"){
    
        }
        if($process=="find"){
    
        }
    }
    return false;
}

function noneDB_insert($data, $db){
    global $noneDB_secretKey;
    global $noneDB_version;
    global $noneDB_dbFolder;
    $millisecond=round(microtime(true) * 1000);
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    $handle = fopen($noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json', "r");
    $contents = json_decode(fread($handle, filesize($noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json')));
    fclose($handle);

    $dataDB=$contents->data;
    $config=$contents->config;

    $data=json_decode($data);
    array_push($dataDB, $data);
    $dbRaw=array(
        "config"=>$config,
        "data"=>$dataDB
    );
    var_dump()
    $dbFile = fopen($noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json', 'w');
    flock($dbFile, LOCK_EX);
    fwrite($dbFile, json_encode($dbRaw));
    flock($dbFile, LOCK_UN);
    fclose($dbFile);
    return true;
}
?>