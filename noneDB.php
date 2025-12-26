<?php
/**
 * https://github.com/orhanayd/noneDB
 * 
 * first this project start date 15.11.2018
 * noneDB - 20.08.2019 -- Orhan AYDOĞDU
 * OPEN SOURCE <3
 * please read firstly noneDB documents if your data is important ::)))
 * but every data is important for us <3 
 * created with love <3  || ORHAN AYDOGDU || www.orhanaydogdu.com.tr || info@orhanaydogdu.com.tr
 * Respect for Labour - Emeğe Saygı
 */

ini_set('memory_limit', '-1');

class noneDB {
    
    private $dbDir=__DIR__."/"."db/"; // please change this path and don't fotget end with / 
    private $secretKey="nonedb_123"; // please change this secret key! and don't share anyone or anywhere!!
    private $autoCreateDB=true; // if you want to auto create your db true or false

    /**
     * hash to db name for security
     */
    private function hashDBName($dbname){
        return hash_pbkdf2("sha256", $dbname, $this->secretKey, 1000, 20);
    }


    /**
     * check db
     * if auto create db is true will be create db
     * if auto create db is false and is not in db dir return false
     * @param string $dbname
     */
    function checkDB($dbname=null){
        if(!$dbname){
            return false;
        }
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        // Sanitize sonrası boş string kontrolü
        if($dbname === ''){
            return false;
        }
        /**
         * if db dir is not in project folder will be create.
         */
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }

        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        /**
         * check db is in db folder?
         */
        if(file_exists($fullDBPath)){
            return true;
        }

        /**
         * if auto create db is true will be create db.
         */
        if($this->autoCreateDB){
            return $this->createDB($dbname);
        }
        return false;
    }

    /**
     * create db function
     * @param string $dbname
     */
    public function createDB($dbname){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }
        if(!file_exists($fullDBPath)){
            $infoDB = fopen($fullDBPath."info", "a+");
            fwrite($infoDB, time());
            fclose($infoDB);
            $dbFile=fopen($fullDBPath, 'a+');
            fwrite($dbFile, json_encode(array("data"=>[])));
            fclose($dbFile);
            return true;
        }
        return false;
    }


    /**
     * Convert bytes to human readable format
     * @param float $bytes
     * @return string
     */
    private function fileSizeConvert($bytes){
        $bytes = floatval($bytes);
        $arBytes = array(
            0 => array("UNIT" => "TB", "VALUE" => pow(1024, 4)),
            1 => array("UNIT" => "GB", "VALUE" => pow(1024, 3)),
            2 => array("UNIT" => "MB", "VALUE" => pow(1024, 2)),
            3 => array("UNIT" => "KB", "VALUE" => 1024),
            4 => array("UNIT" => "B", "VALUE" => 1),
        );
        $result = "0 B";
        foreach($arBytes as $arItem){
            if($bytes >= $arItem["VALUE"]){
                $result = $bytes / $arItem["VALUE"];
                $result = str_replace(".", "," , strval(round($result, 2)))." ".$arItem["UNIT"];
                break;
            }
        }
        return $result;
    }

    public function getDBs($info=false){
        // Handle three cases: false (names only), true (with metadata), string (specific db)
        $withMetadata = false;
        $specificDb = null;

        if(is_bool($info)){
            $withMetadata = $info;
        }else{
            $specificDb = preg_replace("/[^A-Za-z0-9' -]/", '', $info);
            $this->checkDB($specificDb);
        }

        // If specific database requested
        if($specificDb !== null){
            $dbnameHashed=$this->hashDBName($specificDb);
            $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$specificDb.".nonedbinfo";
            $fullDBPath=$this->dbDir.$dbnameHashed."-".$specificDb.".nonedb";
            if(file_exists($fullDBPathInfo)){
                $dbInfo = fopen($fullDBPathInfo, "r");
                $db= array("name"=>$specificDb, "createdTime"=>(int)fgets($dbInfo), "size"=>$this->fileSizeConvert(filesize($fullDBPath)));
                fclose($dbInfo);
                return $db;
            }
            return false;
        }

        // List all databases
        $dbs = [];
        if(!file_exists($this->dbDir)){
            return $dbs;
        }
        foreach(new DirectoryIterator($this->dbDir) as $item) {
            if(!$item->isDot() && $item->isFile()) {
                $filename = $item->getFilename();
                $parts = explode('-', $filename, 2);
                if(count($parts) < 2) continue;

                $dbb = explode('.', $parts[1]);
                if(count($dbb) < 2 || $dbb[1] !== "nonedb") continue;

                $dbname = $dbb[0];

                if($withMetadata){
                    $dbnameHashed=$this->hashDBName($dbname);
                    $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$dbname.".nonedbinfo";
                    $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
                    if(file_exists($fullDBPathInfo)){
                        $dbInfo = fopen($fullDBPathInfo, "r");
                        $dbs[]= array("name"=>$dbname, "createdTime"=>(int)fgets($dbInfo), "size"=>$this->fileSizeConvert(filesize($fullDBPath)));
                        fclose($dbInfo);
                    }
                }else{
                    $dbs[]= $dbname;
                }
            }
         }
        return $dbs;
    }

    
    /**
     * limit function
     * @param array $array Extract a slice of the array
     * @param integer $limit
     */
    public function limit($array, $limit=0){
        if(!is_array($array) || !is_int($limit) || $limit <= 0){
            return false;
        }
        // Multidimensional array kontrolü
        if(count($array) === count($array, COUNT_RECURSIVE)) {
            return false;
        }
        return array_slice($array, 0, $limit);
    }


    /**
     * get data from db file
     * @param string $fullDBPath
     * @param int $retryCount
     */
    private function getData($fullDBPath, $retryCount = 0){
        clearstatcache(true, $fullDBPath);
        if (is_readable($fullDBPath)) {
            $size = filesize($fullDBPath);
            if($size === 0){
                return array("data" => []);
            }
            $dosya = fopen( $fullDBPath, "rb" );
            if( $dosya === false ) {
                    return false;
            }
            $dbContents = json_decode(fread( $dosya, $size), true);
            fclose($dosya);
            return $dbContents;
        }else{
            if($retryCount >= 5){
                return false;
            }
            usleep(10000); // 10ms bekle
            return $this->getData($fullDBPath, $retryCount + 1);
        }
    }

    /**
     * insert data to db file
     * @param string $fullDBPath is db path with file name
     * @param array $buffer is full data
     * @param int $retryCount
     */
    private function insertData($fullDBPath, $buffer, $retryCount = 0){
        clearstatcache(true, $fullDBPath);
        if (is_writable($fullDBPath)) {
            file_put_contents($fullDBPath, json_encode($buffer), LOCK_EX);
            return true;
        }else{
            if($retryCount >= 5){
                return false;
            }
            usleep(10000); // 10ms bekle
            return $this->insertData($fullDBPath, $buffer, $retryCount + 1);
        }
    }

    /**
     * read db all data
     * @param string $dbname
     * @param mixed $filters 0 for all, array for filter
     */
    public function find($dbname, $filters=0){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!$this->checkDB($dbname)){
            return false;
        }
        $rawData = $this->getData($fullDBPath);
        if($rawData === false || !isset($rawData['data'])){
            return false;
        }
        $dbContents = $rawData['data'];

        // Return all records if filter is integer (0) or empty array
        if(is_int($filters) || (is_array($filters) && count($filters) === 0)){
            // Add 'key' field to each record for consistency
            $result = [];
            foreach($dbContents as $index => $record){
                if($record !== null){
                    $record['key'] = $index;
                    $result[] = $record;
                }
            }
            return $result;
        }

        if(is_array($filters)){
            $absResult=[];
            $result=[];
            $filterKeys = array_keys($filters);

            // Handle key-based search
            if(count($filterKeys) > 0 && $filterKeys[0]==="key"){
                if(is_array($filters['key'])){
                    foreach($filters['key'] as $index=>$key){
                        if(isset($dbContents[(int)$key]) && $dbContents[(int)$key] !== null){
                            $result[$index]=$dbContents[(int)$key];
                            $result[$index]['key']=(int)$key;
                        }
                    }
                }else{
                    // Check if key exists and is not null before accessing
                    $keyIndex = (int)$filters['key'];
                    if(isset($dbContents[$keyIndex]) && $dbContents[$keyIndex] !== null){
                        $result[]=$dbContents[$keyIndex];
                        $result[0]['key']=$keyIndex;
                    }
                }
                return $result;
            }

            // Handle field-based search
            $count = count($dbContents);
            for ($i=0; $i<$count; $i++){
                $add=true;
                $raw=[];
                foreach($filters as $key=>$value){
                    if($dbContents[$i]===null){
                        $add=false;
                        break;
                    }
                    if(!array_key_exists($key, $dbContents[$i])){
                        $add=false;
                        break;
                    }
                    if($dbContents[$i][$key]!==$value){
                        $add=false;
                        break;
                    }
                }
                if($add){
                    $raw=$dbContents[$i];
                    $raw['key']=$i;
                    $absResult[]=$raw;
                }
            }
            $result=$absResult;
        }
        return $result;
    }

    /**
     * Check if 'key' exists at top level of data (not nested)
     * @param array $data
     * @return bool
     */
    private function hasReservedKeyField($data){
        return is_array($data) && array_key_exists("key", $data);
    }

    /**
     * Check if array is a list of records (numeric keys with array values)
     * @param array $data
     * @return bool
     */
    private function isRecordList($data){
        if(!is_array($data) || count($data) === 0){
            return false;
        }
        // Check if first key is numeric and value is array
        $keys = array_keys($data);
        if(!is_int($keys[0])){
            return false;
        }
        // Check if first element is an array (a record)
        return is_array($data[$keys[0]]);
    }

    /**
     * insert to db
     * @param string $dbname
     * @param array $data
     */
    public function insert($dbname, $data){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response=array("n"=>0);
        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        if(!is_array($data)){
            $main_response['error']="insert data must be array";
            return $main_response;
        }

        $buffer = $this->getData($fullDBPath);
        if($buffer === false){
            $buffer = array("data" => []);
        }

        // Check if data is a list of records or a single record
        if($this->isRecordList($data)){
            // Multiple records to insert
            $countData = 0;
            foreach($data as $item){
                if(!is_array($item)){
                    continue;
                }
                if($this->hasReservedKeyField($item)){
                    $main_response['error']="You cannot set key name to key";
                    return $main_response;
                }
                $buffer['data'][]=$item;
                $countData++;
            }
            $this->insertData($fullDBPath, $buffer);
            return array("n"=>$countData);
        }else{
            // Single record to insert
            if($this->hasReservedKeyField($data)){
                $main_response['error']="You cannot set key name to key";
                return $main_response;
            }
            $buffer['data'][]=$data;
            $this->insertData($fullDBPath, $buffer);
            return array("n"=>1);
        }
    }

    /**
     * delete function
     * @param string $dbname
     * @param array $data
     */
    public function delete($dbname, $data){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response=array("n"=>0);
        if(!is_array($data)){
            $main_response['error']="Please check your delete paramters";
            return $main_response;
        }
        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        $find=$this->find($dbname, $data);
        if($find === false){
            return $main_response;
        }
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
            if($buffer === false){
                return $main_response;
            }
            foreach($find as $row){
               $buffer['data'][$row['key']]=null;
            }
            $this->insertData($fullDBPath, $buffer);
            $main_response['n']=count($find);
        }
        return $main_response;
    }

    /**
     * update function
     * @param string $dbname
     * @param array $data
     */
    public function update($dbname, $data){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response=array("n"=>0);
        if(!is_array($data) || count($data) === count($data, COUNT_RECURSIVE) || !isset($data[1]['set']) || array_key_exists("key", $data[1]['set'])){
            $main_response['error']="Please check your update paramters";
            return $main_response;
        }
        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        $find=$this->find($dbname, $data[0]);
        if($find === false){
            return $main_response;
        }
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
            if($buffer === false){
                return $main_response;
            }
            foreach($find as $row){
                foreach($data[1]['set'] as $key=>$set){
                    $buffer['data'][$row['key']][$key]=$set;
                }
            }
            $this->insertData($fullDBPath, $buffer);
            $main_response['n']=count($find);
        }
        return $main_response;
    }
}
?>