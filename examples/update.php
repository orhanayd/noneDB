<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
$update = array(
    array("username"=>"orhanayd"),
    array("set"=>array(
        "sifre"=>"orhanayd95s"
    ))
);
$test = $noneDB -> update("orhan", $update);
echo json_encode($test);
?>