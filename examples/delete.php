<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
/**
 * you can delete like this;
 *  array("username"=>"orhan")
 * or
 *  array("key"=>[2,4,5])
 *  just delete only 0.2,3 keys
 */
$filter = array("username"=>"orhanayd");
$test = $noneDB -> delete("your_dbname", $filter);
echo json_encode($test);
?>