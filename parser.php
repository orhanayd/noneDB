<?php
$time_start = microtime(true);

$file=json_decode(file_get_contents("data/12ef2be0440efb2ab333_2c0caacaa5216d1e958c.json"));

foreach($file->data as $key=>$raw){
    echo $key."-".$raw->adSoyad."<br>";
}
$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
echo '<b>Total Execution Time:</b> '.number_format($execution_time, 2).' Sec';

?>