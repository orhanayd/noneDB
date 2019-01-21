<?php

/**
 * result function
 */
function noneDB_resultFunc($status, $content){
    global $noneDB_requestShow;
    $requestData=[];
    // if requestShow is true we show all request data
    if($noneDB_requestShow===true){
        // if true status
        if($status){
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

/**
 * hash create function 
 */
function noneDB_hashCreate($arg){
    global $noneDB_secretKey;
    return hash_pbkdf2("sha256", $arg, $noneDB_secretKey, 1000, 20);
}


/**
 * check db function
 */
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
            $createDB=noneDB_createDB($arg);
            if($createDB['status']){
                return true;
            }else{
                return $createDB['desc'];
            }
        }
        throw new Exception('database not found!');
        return array(
            "status"=>false,
            "desc"=>"database not found!"
        );
    }
}

/**
 * create db function 
 */
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
    return array(
        "status"=>true,
        "desc"=>"database created"
    );
}


/**
 * find function, this function only get data
 */
function noneDB_findFunc($db){
    global $noneDB_secretKey;
    global $noneDB_dbFolder;
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    $dbLocation = $noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json';
    $dataFile=file_get_contents($dbLocation);
    return json_decode($dataFile,false)->data;
}


/**
 * db process driver
 */
function noneDB_process($process, $data, $db){
    global $noneDB_secretKey;
    global $noneDB_dbFolder;
    $checkDB=noneDB_checkDB($db);
    if($checkDB){
        if($process=="insert"){
            /**
             * @data array
             * @db database name
             */
            $inserter=noneDB_insert($data, $db);
            return noneDB_resultFunc($inserter['status'], $inserter['desc']);
        }elseif($process=="update"){
    
        }elseif($process=="find"){
            return noneDB_findFunc($db);
        }elseif($process=="status"){
            global $noneDB_databaseSize;
            $prefix=noneDB_hashCreate($noneDB_secretKey);
            $dbFile=noneDB_hashCreate($db);
            $dbLocation = $noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json';
            $getFile=getDB($dbLocation);
            return noneDB_resultFunc(true, array(
                "item"=>count(json_decode($getFile['result'])->data),
                "size"=>$getFile['size'],
                "remain"=>number_format($noneDB_databaseSize-$getFile['size'], 2)
            ));
        }elseif($process=="delete"){
            /**
             * @data number of record id (integer)
             * @db database name
             */
            $process=noneDB_deleteRecord($data, $db);
            return noneDB_resultFunc($process['status'], $process['desc']);
        }else{
            return noneDB_resultFunc(false, "Process not found");
        }
    }
    throw new Exception('Beklenmeyen hata.');
    return false;
}

function noneDB_deleteRecord($id, $db){
    global $noneDB_secretKey, $noneDB_version, $noneDB_dbFolder;
    $prefix=noneDB_hashCreate($noneDB_secretKey);
    $dbFile=noneDB_hashCreate($db);
    $dbLocation = $noneDB_dbFolder.'/'.$prefix.'_'.$dbFile.'.json';
    $status=false;
    $result;
    $desc;
    if(is_numeric($id)){
        $db=noneDB_findFunc($db);
        unset($db[$key]);
        if(unlink($dbLocation)){
            $fh = fopen($dbLocation, 'a');
            fwrite($fh, json_encode($db));
            fclose($fh);
            $status=true;
            $result=$db;
            $desc="Deleted";
        }else{
            return noneDB_deleteRecord($id, $db);
        }
    }else{
        $desc="Check Delete Parameter (DATA(ID))";
    }
    return array(
        "status"=>$status,
        "result"=>$result,
        "desc"=>$desc
    );
}


/**
 * get db data with file_get_contents
 */
function getDB($local){
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
            $result=getDB($local);
        }else{
            $status=true;
            $result=$getFile;
        }
    }
    return array(
        "status"=>$status,
        "result"=>$result,
        "size"=>$fileSize
    );
}


/**
* INSERT FUNCTION
* @data array
* @db database name
*/
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

        $dbGet=getDB($dbLocation);

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
            throw new Exception('Failed to retrieve data from database-1');
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
        //$data=array("username"=>"orhanayd");
        //var_dump($data);
        if($data=="" || is_null($data)){
            throw new Exception('data is null');
            return array(
                "status"=>false,
                "desc"=>"data is null"
            );
        }
        if(!is_array($data)){
            if(is_object(json_decode($data))){
               $data=json_decode($data, false); 
            }else{
                throw new Exception('data must be object');
                return array(
                    "status"=>false,
                    "desc"=>"data must be object"
                );
            }
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