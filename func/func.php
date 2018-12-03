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
        "desc"=>$content,
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
    if(is_null($arg)){
        return array(
            "status"=>false,
            "desc"=>"database name is null!"
        );
    }
    if(!file_exists($noneDB_dbFolder)){
        return array(
            "status"=>false,
            "desc"=>"database main folder not found!"
        );
    }
    if(file_exists($noneDB_dbFolder."/".$prefix."_".$dbFile.".json")){
        return true;
    }else{
        if($noneDB_autoCreateDB){
            $createDB=noneDB_createDB($_POST['noneDB_db']);
            if($createDB['status']){
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
        throw new Exception('database folder not found!');
        return array(
            "status"=>false,
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
                "status"=>$inserter['status'],
                "desc"=>$inserter['desc']
            );
        }
        if($process=="update"){
    
        }
        if($process=="find"){
    
        }
    }else{
        
    }
    throw new Exception('Beklenmeyen hata.');
    return false;
}
function getFile($local){
    global $noneDB_databaseSize;
    $status=false;
    $result;
    $fileSize=number_format(filesize($local)/1024/1024,2);
    $maxSize =number_format($noneDB_databaseSize, 2);
    if($fileSize>$maxSize){
        throw new Exception('Database reached max size.');
        return array(
            "status"=>$status,
            "result"=>"Database reached max size."
        );
    }else{
        $getFile=file_get_contents($local);
        if(is_null($getFile)){
            $result=getFile($local);
        }else{
            $status=true;
            $result=$getFile;
        }
    }
    return array(
        "status"=>$status,
        "result"=>$result
    );
}

function noneDB_insert($data, $db){
    global $noneDB_secretKey, $noneDB_version, $noneDB_dbFolder;
    $millisecond=round(microtime(true) * 1000);
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    $dbLocation = $noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json';
    if(!file_exists($dbLocation)){
        throw new Exception('Failed to retrieve data from database, please check your database configration.');
        return array(
            "status"=>false,
            "desc"=>"Failed to retrieve data from database, please check your database configration."
        );
    }
    $handle = fopen($dbLocation, "r+");
    if(!$handle){
        return noneDB_insert($data, $db);
    }

        $dbGet=getFile($dbLocation);
        if(!$dbGet['status']){
            throw new Exception($dbGet['result']);
            return array(
                "status"=>false,
                "desc"=>$dbGet['result']
            );        }
        while(!flock($handle, LOCK_EX)) {  // acquire an exclusive lock
            // waiting to lock the file
        }

        $contents = json_decode($dbGet['result']);
        if(!is_object($contents)){
            throw new Exception('Failed to retrieve data from database, please check your database configration.');
            return array(
                "status"=>false,
                "desc"=>"Failed to retrieve data from database-1"
            );
        }
        if(!is_array($contents->data)){
            throw new Exception('Failed to retrieve data from database-2');
            return array(
                "status"=>false,
                "desc"=>"Failed to retrieve data from database-2"
            );
        }
        $dataDB=$contents->data;
        $config=$contents->config;
        if(!is_array($data)){
            $data=json_decode($data);
        }
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
            "status"=>true,
            "desc"=>"inserted"
        );
}
?>