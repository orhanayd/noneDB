<?php
// OPEN SOURCE <3
// please read firstly noneDB documents if your data is important ::))) 
// but every data is important for us <3 
// created with love <3  || ORHAN AYDOGDU || www.orhanaydogdu.com.tr || info@orhanaydogdu.com.tr

$noneDB_version="0.1";

/////////////--- DB CONFIG ---\\\\\\\\\\\\\

//secret key
$noneDB_secretKey="demo";

// noneDB db folder
$noneDB_dbFolder="data";

// if you change to true all request will show in the api and your request data is not secure and maybe stolenable,  we recommend this variable set to false
$noneDB_requestShow=false; 

// if requestShow is true your secretKey maybe stolenable. we recommend this variable set to false for secure your data
$noneDB_secretKeyShow=false; 

// if showDataField is true your data field is not secure and maybe stolenable, we recommend this variable set to false
$noneDB_showDataField=false;

// if showDBField is true your db information field will show in the api and is not secure true option maybe stolenable, we recommend this variable set to false
$noneDB_showDBField=false;

// api on/off || if api is true please use `type=api` get parameter
$noneDB_apiActive=true;

// api result header set to json header
$noneDB_apiHeaderJson=true;

// auto create db
$noneDB_autoCreateDB=true;

?>