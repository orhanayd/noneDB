<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB();
/**
 * you can find like this;
 *  array("username"=>"orhan")
 * or
 *  array("key"=>[2,4,5])
 *  returns only 0,2,3 keys 
 */
$filter = array("username"=>"orhanayd");
$test = $noneDB -> find("your_dbname", $filter, false);
echo json_encode($test);
?>