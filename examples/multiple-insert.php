<?php
header('Content-Type: application/json');
include("../noneDB.php");
$noneDB = new noneDB;

$data = [];

for ($x = 0; $x <= 100000; $x++) {
    $data[]=array("username"=>"orhan", "password"=>"19951995");
}

$insert = $noneDB -> insert("orhan", $data);

echo json_encode($insert);
?>