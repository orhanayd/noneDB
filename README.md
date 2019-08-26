# noneDB

noneDB is **free** and **open source** noSQL database for php projects. **No any installations**!, Just **include** and **GO**.

  - Fast,
  - Stable,
  - Secure,
  - Awesome for small projects.

# What can I do now!

  - insert,
  - find, 
  - update,
  - delete
  ***extra features:***
  - limit


# Examples:
#### ***-Insert one array:***
```php
<?php
    include("noneDB.php");
    $noneDB = new noneDB;
    $data = array("username"=>"orhanayd", "password"=>"123456");
    /**
     * $data will be insert to your_dbname 
     */
    $insert = $noneDB -> insert("your_dbname", $data);
    echo json_encode($insert);
?>
```
***Response:***
``` json
{
    "n": 1
}
```
#### ***-Insert multiple array:***
```php
<?php
    include("noneDB.php");
    $noneDB = new noneDB;
    $data = array(
    array("username"=>"orhanayd", "password"=>"123456"), 
    array("username"=>"kemalataturk", "password"=>"1234567");
    );
    /**
     * $data will be insert to your_dbname 
     */
    $insert = $noneDB -> insert("your_dbname", $data);
    echo json_encode($insert);
?>
```
***Response:***
```json
{
    "n": 2
}
```
#### ***-Find records:*** 
 (Like "SELECT" query in sql)
```php
<?php
include("noneDB.php");
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
```
***Result:***
```json
[
    {
    "username": "orhanayd", 
    "password": "123456", 
    "key": 0
    }
]
```

#### ***- Update record(s)***
```php
<?php
include("noneDB.php");
$noneDB = new noneDB();
/**
 * array(
 *  array of one => search criteria
 *  array("set"=> new values or new keys)
 * )
 * 
 * note: you can update by key for example;
 * array(
 *  array("key"=>[0,2,3]),
 *  array("set"=>array("newkey"=>"newvalue", "oldkey"=>"newvalue"))
 * )
 * it will only update keys 0,2,3.
 * 
 */
$update = array(
    array("username"=>"orhanayd"),
    array("set"=>array(
        "password"=>"123456789"
    ))
);
$test = $noneDB -> update("your_dbname", $update);
echo json_encode($test);
?>
```
***Result:***
```json
{
    "n": 1
}
```

## Additional features:

##### Extract a slice of the array (Like “LIMIT” query in sql):
for example:
```php
<?php
include("noneDB.php");
$noneDB = new noneDB();
$filter = array("username"=>"orhanayd");
$test = $noneDB -> find("your_dbname", $filter, false);
/**
* $test is result array
* 10 is how much limit for result
*/
$test = $noneDB -> limit($test, 10); // Limit
/**
* will returns only 10 array.
*/
echo json_encode($test);
?>
```

### Todos

 - distinct function
 - sort function
 - count function
 - search with like condition function

License
----

MIT

**Free Software, Hell Yeah!**
