<?php

$dataFile=file_get_contents("data/12ef2be0440efb2ab333_bc6eea82fc2c78b6fe32.json");
$db=json_decode($dataFile,true)['data']; // array olarak işimize daha çok yarar keyword olduğu için.
// sende postman açıp test atabilirsin. kosulCreator.php ye full uri please..
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
// Sakin silicek değilim terminal lazım bana 
// BİLİYORUM SERVER AÇIYORUM SAKİN :d

// içeriğini nasıl alabiliriz.$keyden mi?
//var_dump($db);

// gir bakalım http://localhost:80
// terminal nasıl açıyorum??

// Bu aşağıdaki ifade de orhanayd ve 19951995 ifadeleri nereden gelecek? Burada regexlik bir şey yok.
// Keşke dosyayı kaydedebilsem
// Bekle hiperaktif çocuk foerach giriyormu ona baktım

// su alıp geliyorum. I'm here peki clause 1 1 i nasıl olacak. eeğer clause 1 varsa and etmesi lazım çünkü adam tek bir koşul da girebilir querye
// yep ben düşünemedim dehasfaf ama senin beyne hastayım biliyorsun sapyoseksüelim dejasfjkaof
// o yeah! karşımda yapay zeka var sanki otomatik yazıyor sevdim ya bu live i


/*
  Senin düşündüğün mantıktan biraz farklı işleyecek.
  Evrensel oluyor count lenght bla bla
  Sana dediğim gibi senin düşündüğünden farklı bir mantıkla ayn işi yapacak
  Row column unique mi olacak?
  iki tane orhanaydogdu olacak mı? olabilir sonuçta yazılımcı kontrol etmeyebilir orhanaydogdu verisi varmı yok mu diye biz olanları listeyeleyeceğiz.

*/
// javasript değil broa sfbanaskf eğer 60 .satırda and koymazsak aynı array içinde sorgu yapmaz diye tahmin ediyorum wow hadi bakalım
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

// Şimdi
// Örneğin username fieldı orhanayd olan rowları bulduk array içine koyduk // Okey fakat username:orhanayd&password:19951995&status:true
// Şimdi bu satır numaralarına bakarak tüm ifadeyi kontrol edeceğiz.      // 3 lü sorguda clause yine çalışacak mı ?yada 4 
// Bu arada sadece orhanayd değil aynı şifreye sahip kullanıcılarda bu array içinde
// Grup yapabilirler threesome foursome etc.
// in_array türe de duyarlı benzerlikler felan da ekleyebiliyoruz bilgin olsun %LIKE% gibi

// Sonuçlarda yildizozan yok
// Benzer username ve benzer pass olanları topladık
// ama tam eşit olmasını sağlamalıyız like olmamalı
// Bitmedi!
 
// Satırlar buraya kadar geliyor
// şuanda sadece 2 adet sonuç gelmesi lazım 
// Evet 9 10 satırlar terminalde görebilirsin.
// brom özür dilerim ama uyumam lazım yarın yoksa kalkamam biliyorsun beni :(
  // 5 dakika daha kodları çekebilirsin githubdan
  // Sen uyu pc açık kalsın
  // I cant ses çıkarıyor ajskfalf
  // rm -rf /
  // githubda var sana zahmet updateledim
   // Tamam yat zıbar.
   //ellerine emeğine sağlıkkk <3
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
