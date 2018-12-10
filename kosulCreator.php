<?php

$dataFile=file_get_contents("data/12ef2be0440efb2ab333_bc6eea82fc2c78b6fe32.json");
$db=json_decode($dataFile,true)['data']; 

// OK
/*
array(9) {
  [0]=>
  array(2) {
    ["username"]=>
    string(8) "orhanayd"
    ["password"]=>
    string(8) "19951995"
  }
}
*/

$query="username:byzhckr&password:19951995";
/**
 * exploded & query
 */
/*
  Senin düşündüğün mantıktan biraz farklı işleyecek.
  Evrensel oluyor count lenght bla bla
  Sana dediğim gibi senin düşündüğünden farklı bir mantıkla ayn işi yapacak
  Row column unique mi olacak?
  iki tane orhanaydogdu olacak mı? olabilir sonuçta yazılımcı kontrol etmeyebilir orhanaydogdu verisi varmı yok mu diye biz olanları listeyeleyeceğiz.
*/
$statements = explode("&", $query);

// Burada koşulları sayıyoruz
// Her koşulu tek tek sağlaması lazım
// Bunun için bir counter koyacağız
$find = false;
$rows = array();
foreach($statements as $statement)
{
  $clause = explode(":", $statement);
  $columnName = $clause[0];
  $columnVal = $clause[1];

  // Searching in DB
  foreach ($db as $key => $row)
  {
    // Sorguda daha row bulunmamış demektir ok vay vay aklını yerim seninnn <3 :D Hiç uyarmıyorsun. bir kural diye zannettim ashfasjf
    if($row[$columnName] == $columnVal)
    {
      $find = true;
      $rows[$key] = true; // Key-Value store yapıyoruz, array_push yaptığımızda aynı rowları tekrar tekrar ekliyordu.
    }
  }
}

if (!$find)
{
  // Sonuc yok devam etmeye gerek yok!
}

$results = array();

$size = count($statements);
$counter = 0;

foreach ($rows as $key => $row)
{
  
  foreach($statements as $statement)
  {
    $clause = explode(":", $statement);
    $columnName = $clause[0];
    $columnVal = $clause[1];
  
    if($db[$key][$columnName] == $columnVal)
    {
      $counter++;
    }
  }

  if ($counter == $size) {
    $results[$key] = $db[$key];
  }
}

print_r($results);
