<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB;
$data = array("username"=>"orhanayd", "sifre"=>"19951995");
$insert = $noneDB -> insert("your_dbname", $data);
echo json_encode($insert);
?>