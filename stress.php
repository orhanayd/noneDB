<?php
set_time_limit(0);
function curl(){
    $ch = curl_init();

curl_setopt($ch, CURLOPT_URL,"http://127.0.0.1/noneDB/nodb.php?type=api");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS,
            "noneDB_secretKey=demo&noneDB_db=orhan&noneDB_data=".'{"adSoyad":"curl", "password": "password123"}'."&noneDB_process=insert");

// In real life you should use something like:
// curl_setopt($ch, CURLOPT_POSTFIELDS, 
//          http_build_query(array('postvar1' => 'value1')));

// Receive server response ...
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

return curl_exec($ch);

curl_close ($ch);
}
$errori=1;
for ($i = 1; $i <= 2; $i++) {
    $curl=json_decode(curl());
    if(!$curl->status){
        echo "<h3>UYARI $errori - ".$curl->content."</h3><br>";
        $errori++;
    }
    if ($i == 498){
        //sleep(3);
        //echo '<meta http-equiv="refresh" content="0;URL=stress.php?rand="'.rand(1,100).'">';
    }
}


?>