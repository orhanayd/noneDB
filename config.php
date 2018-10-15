<?php
// OPEN SOURCE <3
// please read firstly noneDB documents if your data is important ::))) 
// but every data is important for us <3 
// created with love <3  || ORHAN AYDOGDU || www.orhanaydogdu.com.tr || info@orhanaydogdu.com.tr

/////////////--- DB CONFIG ---\\\\\\\\\\\\\
//secret key
$noneDB_secretKey="a015852";

// if you change to true, your request data is not secure maybe stolenable
$noneDB_requestShow=true; 

// if requestShow is true we recommend this variable set to false because your secretKey maybe stolenable. 
$noneDB_secretKeyShow=false; 

// if showDataField is true your data field is not secure maybe stolenable
$noneDB_showDataField=false;

// if showDBField is true your db information field is not secure maybe stolenable
$noneDB_showDBField=false;

// api on/off || if api is true please use `type=api` get parameter
$noneDB_apiActive=true;

// api result header set to json header
$noneDB_apiHeaderJson=true;

// auto create db
$noneDB_autoCreate=true;

?>