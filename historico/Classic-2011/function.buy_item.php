<?php
session_start();
if(!isset($_SESSION['user_id']))
exit('error');


include('function.inventory.php');


if(!isset($_GET['id']))
exit('error');

if(!isset($_GET['shop']))
exit('error');
else
$shop_id = $_GET['shop'];

if(!file_exists('shop/'.$shop_id.'.xml'))
exit('Wrong shop id.');

if(!is_numeric($_GET['id']))
exit('error');


$id_clicked = intval($_GET['id']);
if($id_clicked > 119)
exit('error1');

include('function.common.php');

//loading shop into array
$xml = simplexml_load_file('shop/'.$shop_id.'.xml');
$i=0;
foreach($xml->children() as $child)
 {
  $shop_inventory[$i]= "$child";
  $i++;
 }

//loading inventory
$user_id = $_SESSION['user_id'];
$char_id = $_SESSION['char_id'];
include("database.php");
global $conn;
$query = "SELECT inventory FROM characters WHERE id='$char_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$current_inventory = $dbarray[0];
$current_inventory = json_decode($current_inventory);

//loading zen
$query = "SELECT zen FROM users WHERE id='$user_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$player_zen = intval($dbarray[0]);


if($shop_inventory[$id_clicked] == '0' || $shop_inventory[$id_clicked] == '1')
{
 exit('error0');
}

 //calculate item price
 $item_to_buy = $shop_inventory[$id_clicked];

parse_item_code($item_to_buy);

$base_price = 50;
$base_price += $item_effect*2;
if($item_type=='8')
 $base_price = $item_effect*150;
else if($item_type=='C' || $item_type=='J')
 $base_price = 500;
else if($item_type=='D' || $item_type=='E')
 $base_price = 1000;
else if($item_type=='F')
 $base_price = $item_effect*2;
$buying_price = ($base_price*(1 + $item_level + $item_luck + $item_option + ceil($item_excellent/100)*10));


if($player_zen < $buying_price)
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
$current_inventory[$array_to_occupy[0]] = $item_to_buy;


 //saving updated inventory
 $current_inventory = json_encode($current_inventory);
 $query = "UPDATE characters SET inventory='$current_inventory' WHERE id='$char_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');

//saving updated money
$query = "UPDATE users SET zen=zen-'$buying_price' WHERE id='$user_id'";
if(!mysql_query($query, $conn))
exit('mysql_error');


echo json_encode($array_to_occupy);
?>

