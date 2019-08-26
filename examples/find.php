<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
$filter = array("username"=>"orhan");
$test = $noneDB -> find("orhan", $filter, false);
$test = count($test);
echo json_encode($test);
?>