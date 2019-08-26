<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
$filter = array("username"=>"like");
$test = $noneDB -> delete("orhan", $filter, false);
echo json_encode($test);
?>