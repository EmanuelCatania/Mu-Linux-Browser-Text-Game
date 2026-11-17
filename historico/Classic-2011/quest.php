<?php
session_start();
include('function.login.php');

if($_SESSION['char_id']=='0')
{
 header("Location: character.php");
 exit();
}


include('player_stats.php');

$user_id = $_SESSION['user_id'];
$char_id = $_SESSION['char_id'];
$char_name = $_SESSION['char_name'];
$query = "SELECT level, quest, quest_target, quest_score, quest_done FROM characters WHERE id='$char_id'";
$result1 = mysql_query($query,$conn);
$row = mysql_fetch_assoc($result1);

$player_level = $row['level'];
$quest = $row['quest'];
$quest_target = $row['quest_target'];
$quest_score = $row['quest_score'];
$quest_done = json_decode($row['quest_done']);


$xml = simplexml_load_file('npc32/quest.xml');


if(isset($_GET['action']))
{
/////////////////////RESTORE HP/MP//////////////////////////////////
 if($_GET['action'] == 'restore')
 {
  include('function.common.php');
  include('player_stats.php');
  include('calculate_stats.php');

   $query = "UPDATE characters set hp_cur='$player_hp_final', mp_cur='$player_mp_final' WHERE id='$char_id'";
   if(!mysql_query($query, $conn))
   exit('mysql_error');


 header("Location: quest.php?restore=ok");
 exit();
 }
/////////////////////RESTORE HP/MP END////////////////////////////


/////////////////////GIVE UP//////////////////////////////////
 else if($_GET['action'] == 'giveup')
 {
   $query = "UPDATE characters set quest=0, quest_target=0, quest_score=0 WHERE id='$char_id'";
   if(!mysql_query($query, $conn))
   exit('mysql_error');

 header("Location: quest.php");
 exit();
 }
/////////////////////GIVE UP END////////////////////////////


/////////////////////ACCEPT//////////////////////////////////
 else if($_GET['action'] == 'accept' && isset($_GET['id']))
 {
  $new_quest = mysql_real_escape_string($_GET['id']);
  $quest_find = array();
  foreach($xml as $value)
  {
    if($value->getName() == 'quest' && $value['id'] == $new_quest && $value['lmin'] <= $player_level && $value['lmax'] >= $player_level && !in_array($new_quest, $quest_done))
   {
    foreach($value as $key)
    $quest_find[$key->getName()] = (string)$key;
   }
  }
if(count($quest_find)>0)
{
   $new_quest_id = $quest_find['id'];
   $new_quest_target = $quest_find['target'];
   $query = "UPDATE characters set quest='$new_quest_id', quest_target='$new_quest_target', quest_score=0 WHERE id='$char_id'";
   if(!mysql_query($query, $conn))
   exit('mysql_error');

 header("Location: quest.php");
 exit();
}
else
 exit('quest error');
 }
/////////////////////ACCEPT END////////////////////////////


/////////////////////REWARD//////////////////////////////////
 if($_GET['action'] == 'reward')
 {
  $quest_find = array();
  foreach($xml as $value)
  {
    if($value->getName() == 'quest' && $value['id'] == $quest)
   {
    foreach($value as $key)
    $quest_find[$key->getName()] = (string)$key;
   }
  }

if(count($quest_find)<1)
exit('quest error');

if($quest_target == $quest_find['target'] && $quest_score >= $quest_find['amount']) //check if player killed req.amount of correct monsters
{
 if($quest_find['reward'] == 'level')
 {
  //level up
  $player_exp_next = ($player_level+9)*$player_level*$player_level;
  array_push($quest_done, (int)$quest);
  $quest_done = json_encode($quest_done);
  $query = "UPDATE characters SET level=level+1, points=points+5, experience='$player_exp_next', quest=0, quest_target=0, quest_score=0, quest_done='$quest_done' WHERE id='$char_id'";
  if(!mysql_query($query, $conn))
  exit('mysql_error');

  header("Location: quest.php?reward=level");
  exit();
 }
 else //reward = item
 {
$item_code = $quest_find['reward'];
$item_size = intval(substr($item_code,2,2));

include('function.inventory.php');
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
{
  header("Location: quest.php?reward=error");
  exit();
}

$array_to_occupy = array_to_occupy($check, $item_size);
 for($i=0; $i<count($array_to_occupy); $i++)
 {
  $e = $array_to_occupy[$i];
  $current_inventory[$e] = 1;
 }
 $current_inventory[$array_to_occupy[0]] = $item_code;


 //saving updated inventory
 $current_inventory = json_encode($current_inventory);
  array_push($quest_done, (int)$quest);
  $quest_done = json_encode($quest_done);
 $query = "UPDATE characters SET inventory='$current_inventory', quest=0, quest_target=0, quest_score=0, quest_done='$quest_done' WHERE id='$char_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');

  header("Location: quest.php?reward=item");
  exit();
 }

}
else
exit('quest error');

 }
/////////////////////REWARD END////////////////////////////
}


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN">
<html>
<head>
<?php include('function.title.php'); ?>
<link href="common.css" rel="stylesheet" type="text/css" />
<link href="market.css" rel="stylesheet" type="text/css"/>

<script src="jquery.js" type="text/javascript"></script>
<script src="jquery.alerts.js" type="text/javascript"></script>

<script type="text/javascript" src="overlib.js"></script>
<script type="text/javascript" src="functions.overlib.js"></script>
<script type="text/javascript" src="functions.common.js"></script>

</head>
<body>
<table class = "parent_table" cellspacing="0">
	<tr>
		<td colspan="2" class="parent_table_top"><div class="parent_table_top_ins"><?php include('top_navigation.php'); ?></div></td>
	</tr>
<tr><td class="parent_table_left"><?php include('navigation.php'); ?></td><td class="parent_table_middle">
<div class="div_border">
<table class="main_table" cellspacing="0">

<tr><td id="main_table_td2">

<table cellspacing="1" class="npc32_table">

<tr><td id="pstore_title">
<div id="pstore_title_ins">
Quest
</div>
</td></tr>
<tr><td id="guild_main">

<table cellspacing="0" id="guild_ins"><tr><td>
<div class="guild_header">Shadow Phantom Soldier</div>
<img src="images/quest.jpg" alt="" />
</td><td id="guild_separator"></td><td>


<div class="guild_header">Active Quest</div><div class="guild_data"><br/>

<?php
if(isset($_GET['reward']))
{
 if($_GET['reward'] == 'level')
 echo '<div id="note"><img width="58px" height="50px" src="images/level.gif"/></div>';
 else if($_GET['reward'] == 'item')
 echo '<div id="note">item added</div>';
 else if($_GET['reward'] == 'error')
 echo '<div id="note">no space in inventory</div>';
}
else if($quest < 1)
 echo "none";
else
{
$quest_active = array();
foreach($xml as $value)
{
  if($value->getName() == 'quest' && $value['id'] == $quest)
  {
   foreach($value as $key)
   $quest_active[$key->getName()] = (string)$key;
  }
}
if(count($quest_active)>0)
{
 if($quest_score > $quest_active['amount'])
  $quest_score = $quest_active['amount'];

 echo '<b>'.$quest_active['title'].'</b><br/><br/><b>target:</b><br/>'.$quest_active['target'].' ('.$quest_score.'/'.$quest_active['amount'].')<br/><br/>';
if($quest_active['reward'] != 'level') //item
{
 $item_name = substr($quest_active['reward'],57);
 echo '<b>reward:</b><br/><div class="defeated_item"><a href="#" onmouseout="nd()" id="'.$i.'" class="defeated_item_ins" name="'.$quest_active['reward'].'">['.$item_name.']</a></div>';
}
else
{
 echo '<b>reward:</b><br/>level up<br/>'; //level
}

if($quest_score >= $quest_active['amount'])
{
echo <<<EOT
<table width="100%"><tr><td>
<form method="post" name="cookie" action="quest.php?action=giveup">
<input type="submit" name="submit" value="give up" />
</form>
</td><td>
<form method="post" name="cookie" action="quest.php?action=reward">
<input type="submit" name="submit" value="accept" />
</form>
</td></tr></table>
EOT;
}
else
{
echo <<<EOT
<br/>
<form method="post" name="cookie" action="quest.php?action=giveup">
<input type="submit" name="submit" value="give up" />
</form>
EOT;
}

}
else
 echo 'error loading quest';
}
?>

</div>


</td></tr></table>

</td></tr>
<tr><td>
<div id="quest_desc_back"><div id="quest_desc">
Welcome to the world of Mu. We have been waiting for a hero like you to arrive and put a stop to the chaos brought on to the Mu realm by Kundun.
</div></div>

</td></tr>
</table>

</td><td id="main_table_td3">

<br/>
<div class="mcheck">
<br/>
<div class="mcheck_head">
Available Quests
</div><div class="quest_list">


<?php
//list of quests
$quest_list = array();
$tmp_count = 0;
foreach($xml as $value)
{
  if($value->getName() == 'quest' && $value['lmin'] <= $player_level && $value['lmax'] >= $player_level && !in_array($value['id'], $quest_done))
  {
   $quest_list[$tmp_count] = array();
   foreach($value as $key)
   $quest_list[$tmp_count][$key->getName()] = (string)$key;

   $tmp_count +=1;
  }
}

if(count($quest_list) > 0)
{
 echo '<br/>';
 for($i=0;$i<count($quest_list); $i++)
 {
  echo '&nbsp;'.$quest_list[$i]['id'].'. <a href="#" class="quest_list_item" id="'.$i.'">'.$quest_list[$i]['title'].'</a><br/><br/>';
 }
}
else
echo '&nbsp;none<br/>';

?>

<br/>
</div>
</div>

<div id="hp_restore">
<?php
if(isset($_GET['restore']))
{
 echo "<b>HP & MP restored</b>";
}
else
echo <<<EOT
Restore HP & MP
<br/>
<form method="post" name="cookie" action="quest.php?action=restore">
<input type="submit" name="submit" value="restore" />
</form>
EOT;
?>
</div>

</td></tr></table>
</div>
</td></tr></table>

<script type="text/javascript">

var js_quest_list = <?php echo json_encode($quest_list);?>;


$('.quest_list_item').click(function(){

var temp = '<b>' + js_quest_list[this.id]['id'] + '. ' + js_quest_list[this.id]['title'] + '</b><br/>' + js_quest_list[this.id]['description'] + '<br/><br/>' + '<div id="accept_button"><form method="post" name="cookie" action="quest.php?action=accept&id='+js_quest_list[this.id]['id']+'"><input type="submit" name="submit" value="accept" /></form></div>';

  $('#quest_desc').html(temp);
});

var player_class = <?php echo $player_class;?>;
var player_level = <?php echo $player_level;?>;

var player_strength = <?php echo $player_strength;?>;
var player_agility = <?php echo $player_agility;?>;
var player_stamina = <?php echo $player_stamina;?>;
var player_energy = <?php echo $player_energy;?>;

$('.defeated_item_ins').mouseover(function() {
  var desc = load_items(this.name, 5, player_level, player_class, player_strength, player_agility, player_energy, player_stamina);
  desc = desc.replace(/&quot;/g,'"');
  overlib(desc,ADAPTIVE_WIDTH, 180,320,2);
});
</script>

</body>
</html>


