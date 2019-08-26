<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
/**
 * getDBs(true) => returns all existing database information.
 * getDBs("your_dbname") => just return your_dbname information.
 */
$test = $noneDB -> getDBs(true);
echo json_encode($test);
?>