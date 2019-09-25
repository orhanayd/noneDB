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
        /**
         * if db dir is not in project folder will be create.
         */
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }
        if($dbname===null){
            return true;
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


    public function getDBs($info=false){
        if(!is_bool($info)){
            $info =  preg_replace("/[^A-Za-z0-9' -]/", '', $info);
        }else{
            $info = null;
        }
        $this->checkDB($info);
        function FileSizeConvert($bytes){
            $bytes = floatval($bytes);
                $arBytes = array(
                    0 => array(
                        "UNIT" => "TB",
                        "VALUE" => pow(1024, 4)
                    ),
                    1 => array(
                        "UNIT" => "GB",
                        "VALUE" => pow(1024, 3)
                    ),
                    2 => array(
                        "UNIT" => "MB",
                        "VALUE" => pow(1024, 2)
                    ),
                    3 => array(
                        "UNIT" => "KB",
                        "VALUE" => 1024
                    ),
                    4 => array(
                        "UNIT" => "B",
                        "VALUE" => 1
                    ),
                );

            foreach($arBytes as $arItem){
                if($bytes >= $arItem["VALUE"])
                {
                    $result = $bytes / $arItem["VALUE"];
                    $result = str_replace(".", "," , strval(round($result, 2)))." ".$arItem["UNIT"];
                    break;
                }
            }
            return $result;
        }
        
        if(is_string($info)){
            $dbnameHashed=$this->hashDBName($info);
            $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$info.".nonedbinfo";
            $fullDBPath=$this->dbDir.$dbnameHashed."-".$info.".nonedb";
            if(file_exists($fullDBPathInfo)){
                $dbInfo = fopen($fullDBPathInfo, "r");
                $db= array("name"=>$info, "createdTime"=>(int)fgets($dbInfo), "size"=>FileSizeConvert(filesize($fullDBPath)));
                fclose($dbInfo);
                return $db;
            }
            return false;
        }
        $dbs = [];
        foreach(new DirectoryIterator($this->dbDir) as $item) {
            if(!$item->isDot() && $item->isFile()) {
                if($info){
                    $dbb= explode('.', explode('-', $item->getFilename())[1]);
                    if($dbb[1]==="nonedb"){
                        $dbname = $dbb[0];
                        $dbnameHashed=$this->hashDBName($dbname);
                        $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$dbname.".nonedbinfo";
                        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
                        $dbInfo = fopen($fullDBPathInfo, "r");
                        $dbs[]= array("name"=>$dbname, "createdTime"=>(int)fgets($dbInfo), "size"=>FileSizeConvert(filesize($fullDBPath)));
                        fclose($dbInfo);
                    }
                }else{
                    $dbb= explode('.', explode('-', $item->getFilename())[1]);
                    if($dbb[1]==="nonedb"){
                        $db = $dbb[0];
                        $dbs[]= $db;
                    }
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
        if($limit===0 && !is_int($limit) && !is_array($array)){
            return false;
        }
        $arrayCount=count($array);
        if($arrayCount === count($array, COUNT_RECURSIVE)) {
            return false;
        }
        return array_slice($array, 0, $limit);
    }


    /**
     * get data from db file
     * @param string $fullDBPath
     */
    private function getData($fullDBPath){
        if (is_readable($fullDBPath)) {
            $dosya = fopen( $fullDBPath, "rb" );
            if( $dosya === false ) {
                    return false;
            }
            $dbContents = json_decode(fread( $dosya, filesize($fullDBPath)), true);
            fclose($dosya);
            return $dbContents;
        }else{
            return $this->getData($fullDBPath);
        }
    }

    /**
     * insert data to db file
     * @param string $fullDBPath is db path with file name
     * @param string $buffer is full data
     */
    private function insertData($fullDBPath, $buffer){
        if (is_writable($fullDBPath)) {
            file_put_contents($fullDBPath, json_encode($buffer));
            return true;
        }else{
            return $this->insertData($fullDBPath);
        }

    }

    /**
     * read db all data
     */
    public function find($dbname, $filters=0, $typeCheck=true){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!$this->checkDB($dbname)){
            return false;
        }
        $dbContents = $this->getData($fullDBPath)['data'];
        if(is_int($filters)){
            return $dbContents;
        }
        if(is_array($filters)){
            $absResult=[]; // right result;
            $result=[];
                $countFilter=count($filters);
                if(array_keys($filters)[0]==="key"){
                    if(is_array($filters['key'])){
                        foreach($filters['key'] as $index=>$key){
                            if(isset($dbContents[(int)$key])){
                                $result[$index]=$dbContents[(int)$key];
                                $result[$index]['key']=(int)$key;                
                            }
                        }
                    }else{
                        $result[]=$dbContents[(int)$filters['key']];
                        $result[0]['key']=(int)$filters['key'];
                    }
                    return $result;
                }
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
        if(is_array($data) || is_object($data)){
            $countData=count($data);
            if($countData === count($data, COUNT_RECURSIVE)) {
                // if array is not multidimensional
                if(array_key_exists("key", $data)){
                    $main_response['error']="You cannot set key name to key";
                    return $main_response;
                }
                $buffer = $this->getData($fullDBPath);
                $buffer['data'][]=$data;
                $this->insertData($fullDBPath, $buffer);
                return array("n"=>1);
            }else{
                // if array is multidimensional
                $directInsert = false;
                $buffer = $this->getData($fullDBPath);
                foreach($data as $item){
                    if(is_array($item)){
                        if(array_key_exists("key", $item)){
                            $main_response['error']="You cannot set key name to key";
                            return $main_response;
                        }
                        $buffer['data'][]=$item;
                    }else{
                        $directInsert = true;
                        break;
                    }
                }
                if($directInsert){
                    if(array_key_exists("key", $data)){
                        $main_response['error']="You cannot set key name to key";
                        return $main_response;
                    }
                    $buffer['data'][]=$data;
                    $countData = 1;
                }
                $this->insertData($fullDBPath, $buffer);
                return array("n"=>$countData);
            }
        }
        $main_response['error']="insert data must be array or object";
        return $main_response;
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
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
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
        if(is_array($data)!==true || !count($data) === count($data, COUNT_RECURSIVE) || array_key_exists("key", $data[1]['set']) || !array_key_exists("set", $data[1])){
            $main_response['error']="Please check your update paramters";
            return $main_response;
        }
        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        $find=$this->find($dbname, $data[0]);
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
            foreach($find as $row){
                foreach($data[1]['set'] as $key=>$set){
                    if(array_key_exists($key, $buffer['data'][$row['key']])){
                        $buffer['data'][$row['key']][$key]=$set;
                    }else{
                        $buffer['data'][$row['key']][]=$set;
                    }
                }
            }
            $this->insertData($fullDBPath, $buffer);
            $main_response['n']=count($find);
        }
        return $main_response;
    }
}
?>