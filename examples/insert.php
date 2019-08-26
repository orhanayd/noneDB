<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB;
$data = array("username"=>"orhanayd", "sifre"=>"19951995");
/**
 * $data will be insert to your_dbname 
 */
$insert = $noneDB -> insert("your_dbname", $data);
echo json_encode($insert);
?>