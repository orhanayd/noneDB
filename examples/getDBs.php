<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
$test = $noneDB -> getDBs(true);
echo json_encode($test);
?>