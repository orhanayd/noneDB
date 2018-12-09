<?php

$dataFile=file_get_contents("data/12ef2be0440efb2ab333_bc6eea82fc2c78b6fe32.json");
$obje=json_decode($dataFile,false)->data;

$query="username:orhanayd&password:19951995";
/**
 * exploded & query
 */
$explodeMultiple=explode("&", $query);
foreach($explodeMultiple as $value){
    //echo $value;
    $queryExplode=explode(":", $value);
    var_dump($queryExplode);
}

?>