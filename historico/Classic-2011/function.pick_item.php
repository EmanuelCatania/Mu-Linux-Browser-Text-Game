<?php
session_start();
if(!isset($_SESSION['user_id']))
exit('error');

if(!isset($_GET['id']))
exit('error');

if(!is_numeric($_GET['id']))
exit('error');
$id = intval($_GET['id']);

if(!isset($_SESSION['enemies'][$id]['item']))
exit('error');
$item_code = $_SESSION['enemies'][$id]['item'];

if($item_code == 'no item' || $item_code == 'taken')
exit('error');
$item_size = intval(substr($item_code,2,2));


$user_id = $_SESSION['user_id'];
$char_id = $_SESSION['char_id'];
include("database.php");
global $conn;


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
//////////////////loading arrays//////////////////////////////

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


 //saving updated inventory
 $current_inventory = json_encode($current_inventory);
 $query = "UPDATE characters SET inventory='$current_inventory' WHERE id='$char_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');

$_SESSION['enemies'][$id]['item'] = 'taken';

echo 'ok';
?>