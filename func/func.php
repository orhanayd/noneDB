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

    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($arg);
    if(!file_exists($noneDB_dbFolder)){
        return array(
            "result"=>false,
            "desc"=>"database main folder not found!"
        );
    }
    if(file_exists($noneDB_dbFolder."/".$prefix."_".$dbFile.".json")){
        return true;
    }else{
        if($noneDB_autoCreateDB){
            $createDB=noneDB_createDB($_POST['noneDB_db']);
            if($createDB['result']){
                return true;
            }else{
                return $createDB['desc'];
            }
        }
        return false;
    }
}

function noneDB_createDB($arg){
    global $noneDB_secretKey;
    global $noneDB_version;
    global $noneDB_dbFolder;
    if(!file_exists($noneDB_dbFolder)){
        return array(
            "result"=>false,
            "desc"=>"database folder not found!"
        );
    }
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
    $checkDB=noneDB_checkDB($db);
    if($checkDB){
        if($process=="insert"){
            $inserter=noneDB_insert($data, $db);
            return array(
                "result"=>$inserter['result'],
                "desc"=>$inserter['desc']
            );
        }
        if($process=="update"){
    
        }
        if($process=="find"){
    
        }
    }else{
        
    }
    return false;
}
function getFile($local){
    $getFile=file_get_contents($local);
    if(is_null($getFile)){
        return getFile($local);
    }else{
        return $getFile;
    }
}

function noneDB_insert($data, $db){
    global $noneDB_secretKey, $noneDB_version, $noneDB_dbFolder;
    $millisecond=round(microtime(true) * 1000);
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    $dbLocation = $noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json';
    if(!file_exists($dbLocation)){
        return array(
            "result"=>false,
            "desc"=>"Failed to retrieve data from database, please check your database configration."
        );
    }
    $handle = fopen($dbLocation, "r+");
    if(!$handle){
        return noneDB_insert($data, $db);
    }

        $dbGet=getFile($dbLocation);

        while(!flock($handle, LOCK_EX)) {  // acquire an exclusive lock
            // waiting to lock the file
        }

        $contents = json_decode($dbGet);
        $dataDB=$contents->data;
        $config=$contents->config;

        if(!is_array($dataDB)){
            return array(
                "result"=>false,
                "desc"=>"Failed to retrieve data from database"
            );
        }
        $data=json_decode($data);
        array_push($dataDB, $data);
        $dbRaw=array(
            "config"=>$config,
            "data"=>$dataDB
        );

        ftruncate($handle, 0);      // truncate file
        fwrite($handle, json_encode($dbRaw));
        fflush($handle);            // flush output before releasing the lock
        flock($handle, LOCK_UN);    // release the lock
        fclose($handle);
        return array(
            "result"=>true,
            "desc"=>"inserted"
        );
}
?>