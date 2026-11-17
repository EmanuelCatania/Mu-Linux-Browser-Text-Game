<?php
session_start();
if(!isset($_SESSION['user_id']))
exit('error');


if(!isset($_GET['id']))
exit('error');

if(!is_numeric($_GET['id']))
exit('error');

$item_id = intval($_GET['id']);

$user_id = $_SESSION['user_id'];
$char_id = $_SESSION['char_id'];
include("database.php");
global $conn;

$query = "SELECT item_code, owner_id, pstore_id, price FROM market WHERE id='$item_id'";
$result = mysql_query($query,$conn);

if(!mysql_num_rows($result))
exit('id_error');

$dbarray = mysql_fetch_array($result);
$item_code = $dbarray[0];
$item_size = intval(substr($item_code,2,2));

$item_owner = $dbarray[1];
if($item_owner == $user_id)
exit('your_item');

$item_pstore = $dbarray[2];//what cell number item is in pstore of seller
$item_price = $dbarray[3];


///////////////////////////defining functions/////////////////////
include('function.inventory.php');
///////////////////////////defining functions end/////////////////


//////////////////loading arrays//////////////////////////////
//loading inventory
$query = "SELECT inventory FROM characters WHERE id='$char_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$current_inventory = $dbarray[0];
$current_inventory = json_decode($current_inventory);


//loading store into json array
$query = "SELECT pstore FROM users WHERE id='$item_owner'";
$result = mysql_query($query,$conn);
if(!mysql_num_rows($result))
exit('mysql_error');

$dbarray = mysql_fetch_array($result);
$current_store = $dbarray[0];
$current_store = json_decode($current_store);
//////////////////loading arrays//////////////////////////////

$query = "SELECT zen FROM users WHERE id='$user_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$player_zen = $dbarray[0];

if($item_price > $player_zen)
exit('no_money');

$check = check_space_in_inventory($current_inventory, $item_size);
if($check === 'no_space')
exit('no_space');

$array_to_occupy = array_to_occupy($check, $item_size);


 for($i=0; $i<count($array_to_occupy); $i++)
 {
  $e = $array_to_occupy[$i];
  $current_inventory[$e] = 1;
 }
 $current_inventory[$array_to_occupy[0]] = $item_code;


//////////decreasing zen/increasing for seller///////////////
$query = "UPDATE users SET zen=zen-'$item_price' WHERE id='$user_id'";
if(!mysql_query($query, $conn))
exit('mysql_error');

$query = "UPDATE users SET zen=zen+'$item_price' WHERE id='$item_owner'";
if(!mysql_query($query, $conn))
exit('mysql_error');
//////////decreasing zen/increasing for seller///////////////


//////////deleting from market sold item///////////////
$query = "DELETE from market WHERE id='$item_id'";
if(!mysql_query($query, $conn))
exit('mysql_error');
//////////deleting from market sold item///////////////


//////////deleting sold item frol seller's personal store///////////////
$new_array_to_occupy = array_to_occupy($item_pstore, $item_size);

 for($i=0; $i<count($new_array_to_occupy); $i++)
 {
  $current_store[$new_array_to_occupy[$i]] = 0;
 }
//////////deleting sold item frol seller's personal store///////////////


 //saving updated inventory
 $current_inventory = json_encode($current_inventory);
 $query = "UPDATE characters SET inventory='$current_inventory' WHERE id='$char_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');

 $current_store = json_encode($current_store);
 $query = "UPDATE users SET pstore='$current_store' WHERE id='$item_owner'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');


echo '!'.$player_zen;
?>