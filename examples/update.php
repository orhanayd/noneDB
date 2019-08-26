<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
/**
 * array(
 *  array of one => search criteria
 *  array("set"=> new values or new keys)
 * )
 * 
 * note: you can update by key for example;
 * array(
 *  array("key"=>[0,2,3]),
 *  array("set"=>array("newkey"=>"newvalue", "oldkey"=>"newvalue"))
 * )
 * it will only update keys 0,2,3.
 * 
 */
$update = array(
    array("username"=>"orhanayd"),
    array("set"=>array(
        "sifre"=>"123456789"
    ))
);
$test = $noneDB -> update("your_dbname", $update);
echo json_encode($test);
?>