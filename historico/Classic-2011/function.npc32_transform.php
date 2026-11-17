<?php
session_start();
if(!isset($_SESSION['user_id']))
exit('error');
$user_id = $_SESSION['user_id'];
include("database.php");
global $conn;

if(!isset($_GET['id']))
exit('error');
$npc32_id = $_GET['id'];

//loading npc32 into array
$query = "SELECT npc32_kind, npc32, zen FROM users WHERE id='$user_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$npc32_kind = $dbarray[0];
$current_npc32 = $dbarray[1];
$player_zen = intval($dbarray[2]);

 $npc32_price = 1000;
  if($player_zen < $npc32_price)
  exit('no_money');

$mix_result = 'success'; //default

$current_npc32 = json_decode($current_npc32);

$npc32_count = array();
for($i=0; $i<count($current_npc32); $i++)
{
  if($current_npc32[$i]!='0' && $current_npc32[$i]!='1')
  array_push($npc32_count, $current_npc32[$i]);
}

if(count($npc32_count)<1)
exit('error');

$return_item = array();
$return_item[0] = 0;

include('function.common.php');


///////////////////////chaos machine///////////////////////
if($npc32_id=='chaos_machine')
{
 $item44 = 0;
 $item44_rate = 0;
 $item44_exc = 0;
 $item44_exc_rate = 0;
 $item44_return = 0;
 $item44_chaos = 0;
 $item44_chaos_return = 0;
 $item49 = 0;
 $item49_level = 0;
 $item49_luck = 0;
 $item49_return = 0;
 $bless = 0;
 $soul = 0;
 $chaos = 0;
 $loch = 0;
 $creation = 0;
 $wings = 0;
 $wings_level = 0;
 $wings_return = 0;
 $archangel = 0;
 $bone = 0;
 $archangel_level = 0;
 $bone_level = 0;
 $uniria = 0;
 $unknown_item = 0;

for($i=0; $i<count($npc32_count); $i++)
{
 $item_code = $npc32_count[$i];
 parse_item_code($item_code);
  if($item_type == '8' && $item_sub_type == '1')
  {
   $wings += 1;
   $wings_level = $item_level;
   $wings_return = $item_code;
  }
  else if($item_type == 'E' && $item_sub_type == '1')
   $chaos += 1;
  else if($item_type == 'E' && $item_sub_type == '2')
   $creation += 1;
  else if($item_type == 'D' && $item_sub_type == '1')
   $bless += 1;
  else if($item_type == 'D' && $item_sub_type == '2')
   $soul += 1;
  else if($item_type == 'C' && $item_sub_type == '3')
   $uniria += 1;
  else if($item_type == 'I' && $item_sub_type == '4')
   {
    $bone += 1;
    $bone_level = $item_level;
   }
  else if($item_type == 'I' && $item_sub_type == '5')
   {
    $archangel += 1;
    $archangel_level = $item_level;
   }
  else if($item_type == 'E' && $item_sub_type == '7')
   $loch += 1;
  else if(($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7' || $item_type == '8' || $item_type == '9') && $item_level > 8)
   {
    $item49 +=1;
    $item49_level = $item_level;
    $item49_luck = $item_luck;
    $item49_return = $item_code;
   }
  else if(($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7'|| $item_type == '9') && ($item_level > 3 && $item_level < 9) && $item_excellent > 0)
   {
    $item44_exc +=1;
    $item44_exc_rate += floor($item_effect/10*($item_level + ceil($item_excellent/100)*3));
   }
  else if(($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7'|| $item_type == '9') && ($item_level > 3 && $item_level < 9) && $item_option > 0 && $item_excellent == 0)
   {
    $item44 +=1;
    $item44_rate += floor($item_effect/10*($item_level + ceil($item_excellent/100)*3));
    if($item44_return == '0')
    $item44_return = $item_code; //remember only the first item to return it on fail

    if($item_name == 'chaos_dragon_axe' || $item_name == 'chaos_lighting_staff' || $item_name == 'chaos_nature_bow')
    {
     $item44_chaos +=1;
     $item44_chaos_return = $item_code;
    }
   }
  else
   $unknown_item +=1;
}


if($unknown_item > 0)
exit('error');

///////////////////////fruits///////////////////////
else if($creation == 1 && $chaos == 1 && count($npc32_count) == 2)
{
  $tmp = mt_rand(0,3);
  if($tmp==0)
  $return_item[0] = "J.11.A.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.strength_fruit";
  else if($tmp==1)
  $return_item[0] = "J.11.B.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.agility_fruit";
  else if($tmp==2)
  $return_item[0] = "J.11.C.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.stamina_fruit";
  else if($tmp==3)
  $return_item[0] = "J.11.D.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.energy_fruit";

  $fail = mt_rand(0,4);
  if($fail == 1)
  $return_item[0] = 0; //20% of fail
}
///////////////////////fruits end///////////////////////


///////////////////////cloack of invisibility///////////////////////
else if($bone == 1 && $archangel == 1 && $chaos == 1 && count($npc32_count) == 3 && $archangel_level == $bone_level)
{
 $return_item[0] = "K.22.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.cloak_of_invisibility";
 $item_level_new = str_pad($archangel_level, 2, "0", STR_PAD_LEFT);
 $return_item[0] = substr_replace($return_item[0], $item_level_new, 12, 2);

  $fail = mt_rand(0,4);
  if($fail == 1)
  $return_item[0] = 0; //20% of fail
}
///////////////////////cloack of invisibility end///////////////////////


///////////////////////dinorant///////////////////////
else if($uniria == 10 && $chaos == 1 && count($npc32_count) == 11)
{
 $return_item[0] = "C.11.4.0000.00.0.4110.0000.00.2.0.00.00.0.255.255.000000.horn_of_dinorant";

  $fail = mt_rand(0,4);
  if($fail == 1)
  $return_item[0] = 0; //20% of fail
}
///////////////////////dinorant end///////////////////////


///////////////////////10-13 items///////////////////////
else if($item49 == 1 && $item49_level == 9 && $bless == 1 && $soul == 1 && $chaos == 1 && count($npc32_count) == 4)
{
 if($item49_luck > 0)
  $fail = mt_rand(0,3); // 75% success
 else
  $fail = mt_rand(0,1); // 50% success

  if($fail == 1)
  {
   $return_item[0] = substr_replace($item49_return, '00', 12, 2);
   $mix_result = 'mix_fail';
  }
  else
  {
   $return_item[0] = substr_replace($item49_return, '10', 12, 2);
   $mix_result = 'success';
  }
}

else if($item49 == 1 && $item49_level == 10 && $bless == 2 && $soul == 2 && $chaos == 1 && count($npc32_count) == 6)
{
 if($item49_luck > 0)
  $fail = mt_rand(0,3); // 75% success
 else
  $fail = mt_rand(0,1); // 50% success

  if($fail == 1)
  {
   $return_item[0] = substr_replace($item49_return, '00', 12, 2);
   $mix_result = 'mix_fail';
  }
  else
  {
   $return_item[0] = substr_replace($item49_return, '11', 12, 2);
   $mix_result = 'success';
  }
}

else if($item49 == 1 && $item49_level == 11 && $bless == 3 && $soul == 3 && $chaos == 1 && count($npc32_count) == 8)
{
 if($item49_luck > 0)
  $fail = mt_rand(0,3); // 75% success
 else
  $fail = mt_rand(0,1); // 50% success

  if($fail == 1)
  {
   $return_item[0] = substr_replace($item49_return, '00', 12, 2);
   $mix_result = 'mix_fail';
  }
  else
  {
   $return_item[0] = substr_replace($item49_return, '12', 12, 2);
   $mix_result = 'success';
  }
}

else if($item49 == 1 && $item49_level == 12 && $bless == 4 && $soul == 4 && $chaos == 1 && count($npc32_count) == 10)
{
 if($item49_luck > 0)
  $fail = mt_rand(0,3); // 75% success
 else
  $fail = mt_rand(0,1); // 50% success

  if($fail == 1)
  {
   $return_item[0] = substr_replace($item49_return, '00', 12, 2);
   $mix_result = 'mix_fail';
  }
  else
  {
   $return_item[0] = substr_replace($item49_return, '13', 12, 2);
   $mix_result = 'success';
  }
}
///////////////////////10-13 items end///////////////////////


///////////////////////chaos weapon///////////////////////
else if($item44 > 0 && $chaos > 0 && $item49 == 0 && $wings == 0 && $item44_chaos == 0)
{
  $chaos_weapon_new = mt_rand(1,3);
  if($chaos_weapon_new == 1)
  $return_item[0] = "2.24.2.0130.00.0.1335.2132.15.0.0.00.00.0.080.080.000000.chaos_dragon_axe";
  else if($chaos_weapon_new == 2)
  $return_item[0] = "2.24.3.0048.00.0.1155.2042.14.0.0.00.00.0.070.070.000000.chaos_lighting_staff";
  else if($chaos_weapon_new == 3)
  $return_item[0] = "2.24.8.0106.00.0.1110.2357.03.0.0.00.00.0.068.068.000000.chaos_nature_bow";

  $mix_result = 'success';

//random level 0-4

   $chaos_level = 0;
   if(mt_rand(0,1) < 1)       //50%
    $chaos_level = 1;
   else if(mt_rand(0,2) < 1)  //33%
    $chaos_level = 2;
   else if(mt_rand(0,4) < 1) //20%
    $chaos_level = 3;
   else if(mt_rand(0,9) < 1) //10%
    $chaos_level = 4;
  $chaos_level = str_pad($chaos_level, 2, "0", STR_PAD_LEFT);
  $return_item[0] = substr_replace($return_item[0], $chaos_level, 12, 2);

//random option 0-2

   $chaos_option = 0;
   if(mt_rand(0,1) < 1)  //50%
    $chaos_option = 1;
   else if(mt_rand(0,9) < 1) //10%
    $chaos_option = 2;

  $return_item[0] = substr_replace($return_item[0], $chaos_option, 32, 1);

//random luck
  $chaos_luck = mt_rand(0,1);
  $return_item[0] = substr_replace($return_item[0], $chaos_luck, 15, 1);

//if fail
  $item44_rate = $item44_rate + $bless*4 + $soul*3 + $chaos*2;
  if($item44_rate > 90)
  $item44_rate = 90; //90% success rate maximum
  $fail = mt_rand(1,100);
  if($fail > $item44_rate)
  {
   $return_item[0] = substr_replace($item44_return, '00', 12, 2); //leave only 1 item and degrade it to level 0
   $mix_result = 'mix_fail';
  }
}
///////////////////////chaos weapon end///////////////////////


///////////////////////wings 1///////////////////////
else if($item44_chaos > 0 && $chaos > 0 && $wings == 0 && $item49 == 0)
{
  $wings_new = mt_rand(1,4);
  if($wings_new == 1)
  $return_item[0] = "8.42.1.0010.00.0.4180.0000.01.0.0.00.00.0.200.200.000000.satan_wings";
  else if($wings_new == 2)
  $return_item[0] = "8.42.1.0010.00.0.4180.0000.02.0.0.00.00.0.200.200.000000.heaven_wings";
  else if($wings_new == 3)
  $return_item[0] = "8.42.1.0010.00.0.4180.0000.03.0.0.00.00.0.200.200.000000.fairy_wings";
  else if($wings_new == 4)
  $return_item[0] = "8.42.1.0010.00.0.4180.0000.04.0.0.00.00.0.200.200.000000.mystery_wings";

  $mix_result = 'success';

//random option 0-2
  $wings_option = mt_rand(0,2);
  $return_item[0] = substr_replace($return_item[0], $wings_option, 32, 1);

//random luck
  $wings_luck = mt_rand(0,1);
  $return_item[0] = substr_replace($return_item[0], $wings_luck, 15, 1);

//if fail
  $item44_rate = $item44_rate + $bless*4 + $soul*3 + $chaos*2;
  if($item44_rate > 90)
  $item44_rate = 90; //90% success rate maximum
  $fail = mt_rand(1,100);
  if($fail > $item44_rate)
  {
   $return_item[0] = substr_replace($item44_chaos_return, '00', 12, 2); //leave only 1 item and degrade it to level 0
   $mix_result = 'mix_fail';
  }
}
///////////////////////wings 1 end///////////////////////


///////////////////////wings 2///////////////////////
else if($loch == 1 && $chaos == 1 && $wings == 1 && $item49 == 0 && $item44 == 0 && $bless == 0 && $soul == 0)
{
  $wings_new = mt_rand(1,4);
  if($wings_new == 1)
  $return_item[0] = "8.53.2.0030.00.0.4215.0000.21.0.0.00.00.0.220.220.000000.dragon_wings";
  else if($wings_new == 2)
  $return_item[0] = "8.53.2.0030.00.0.4215.0000.22.0.0.00.00.0.220.220.000000.archangel_wings";
  else if($wings_new == 3)
  $return_item[0] = "8.53.2.0030.00.0.4215.0000.23.0.0.00.00.0.220.220.000000.spirit_wings";
  else if($wings_new == 4)
  $return_item[0] = "8.53.2.0030.00.0.4215.0000.24.0.0.00.00.0.220.220.000000.despair_wings";

  $mix_result = 'success';

//random option 0-2
  $wings_option = mt_rand(0,2);
  $return_item[0] = substr_replace($return_item[0], $wings_option, 32, 1);

//random luck
  $wings_luck = mt_rand(0,1);
  $return_item[0] = substr_replace($return_item[0], $wings_luck, 15, 1);

//random option c
  if(mt_rand(1,3) == 1) //33%
  {
   $option_c = mt_rand(1,3);
   $return_item[0] = substr_replace($return_item[0], $option_c, 30, 1);
  }

//if fail
  $item44_exc_rate = 10*($wings_level+1) + $item44_exc_rate + 2;
  if($item44_exc_rate > 90)
  $item44_exc_rate = 90; //90% success rate maximum
  $fail = mt_rand(1,100);
  if($fail > $item44_exc_rate)
  {
   $return_item[0] = substr_replace($wings_return, '00', 12, 2); //leave only 1 item and degrade it to level 0
   $mix_result = 'mix_fail';
  }
}
///////////////////////wings 2 end///////////////////////


 else
 exit('error');

}
///////////////////////chaos machine end///////////////////////


////////////////////////////////////////////
else if($npc32_id=='cblossom')
{
  if(count($npc32_count)!=5)
  exit('error');

  $golden = 0;
  $red = 0;
  $white = 0;

  for($i=0; $i<count($npc32_count); $i++)
  {
  if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == '1')
   $golden +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == '2')
   $red +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == '3')
   $white +=1;
  }

  if($golden == 5)
   $return_item[0] = "E.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_chaos";
  else if($red == 5)
   $return_item[0] = "D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless";
  else if($white == 5)
   $return_item[0] = "D.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul";
  else
   exit('error');
}

////////////////////////////////////////////
else if($npc32_id=='lahap')
{
 if(count($npc32_count) == 1)
 {
  $item_code = $npc32_count[0];
  $item_type = substr($item_code,0,1);
  $item_sub_type = substr($item_code,5,1);
  $item_level = intval(substr($item_code,12,2));

 if($item_type=='H' && $item_sub_type =='3') //cherry blossom box
 {
  $file_to_open = 'cherry_blossom_box.xml';
  include('include.open_box.php');
  $return_item[0] = $item_code;
  $tmp = mt_rand(0,9);
  if($tmp < 1)
  $return_item[0] = 0; //10% of fail
 }

 else if($item_type=='H' && $item_sub_type =='1') //box of luck
 {
  $file_to_open = 'box_of_luck.xml';
  include('include.open_box.php');
  $return_item[0] = $item_code;
  $tmp = mt_rand(0,9);
  if($tmp < 1)
  $return_item[0] = 0; //10% of fail
 }

 else if($item_type=='H' && $item_sub_type =='2' && $item_level == '1') //box of kundun +1
 {
  $file_to_open = 'box_of_kundun1.xml';
  include('include.open_box.php');
  $return_item[0] = $item_code;
  $tmp = mt_rand(0,9);
  if($tmp < 1)
  $return_item[0] = 0; //10% of fail
 }

 else if($item_type=='H' && $item_sub_type =='2' && $item_level == '2') //box of kundun +2
 {
  $file_to_open = 'box_of_kundun2.xml';
  include('include.open_box.php');
  $return_item[0] = $item_code;
  $tmp = mt_rand(0,9);
  if($tmp < 1)
  $return_item[0] = 0; //10% of fail
 }

 else if($item_type=='E' && $item_sub_type =='4') //bless x 10
 {
  for($i=0; $i<10; $i++)
  {
   $return_item[$i] = "D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless";
  }
 }

 else if($item_type=='E' && $item_sub_type =='5') //soul x 10
 {
  for($i=0; $i<10; $i++)
  {
   $return_item[$i] = "D.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul";
  }
 }
 else
  exit('error');
 }
  else if(count($npc32_count)==10)
 {
  $bless10 = 0;
  $soul10 = 0;
  for($i=0; $i<count($npc32_count); $i++)
  {
  if(substr($npc32_count[$i],0,1) == 'D' && substr($npc32_count[$i],5,1) == '1')
   $bless10 +=1;
  else if(substr($npc32_count[$i],0,1) == 'D' && substr($npc32_count[$i],5,1) == '2')
   $soul10 +=1;
  }
  if($bless10 == 10)
   $return_item[0] = "E.11.4.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless_x_10";
  else if($soul10 == 10)
   $return_item[0] = "E.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul_x_10";
  else
   exit('error');
 }
  else if(count($npc32_count)==5) // creating lost map
 {
  $kundun1 = 0;
  $kundun2 = 0;
  $kundun3 = 0;
  $kundun4 = 0;
  $kundun5 = 0;
  $kundun6 = 0;
  $kundun7 = 0;
  for($i=0; $i<count($npc32_count); $i++)
  {
  if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '1')
   $kundun1 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '2')
   $kundun2 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '3')
   $kundun3 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '4')
   $kundun4 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '5')
   $kundun5 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '6')
   $kundun6 +=1;
  else if(substr($npc32_count[$i],0,1) == 'I' && substr($npc32_count[$i],5,1) == 'A' && substr($npc32_count[$i],13,1) == '7')
   $kundun7 +=1;
  }
  if($kundun1 == 5)
   $return_item[0] = "K.11.5.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun2 == 5)
   $return_item[0] = "K.11.5.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun3 == 5)
   $return_item[0] = "K.11.5.0000.03.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun4 == 5)
   $return_item[0] = "K.11.5.0000.04.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun5 == 5)
   $return_item[0] = "K.11.5.0000.05.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun6 == 5)
   $return_item[0] = "K.11.5.0000.06.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else if($kundun7 == 5)
   $return_item[0] = "K.11.5.0000.07.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map";
  else
   exit('error');
 }
  else
  exit('error');
}

////////////////////////////////////////////
else if($npc32_id=='osbourne')
{
  if(count($npc32_count) > 1)
  exit('error');

 $item_code = $npc32_count[0];
 parse_item_code($item_code);

if(($item_type=='1' || $item_type=='2' || $item_type=='9') && $item_option!=0 && $item_excellent == 0 && $item_level>3)
 {
  $tmp = mt_rand(0,4); //20%
  if($tmp == 1)
   $return_item[0] = "D.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.lower_refining_stone";
  else
   exit('mix_fail');
 }
else if(($item_type=='1' || $item_type=='2' || $item_type=='9') && $item_excellent!= 0)
 {
  $tmp = mt_rand(0,1); //50%
  if($tmp == 1)
   $return_item[0] = "D.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.higher_refining_stone";
  else
   exit('mix_fail');
 }
else
exit('error');
}

////////////////////////////////////////////
else if($npc32_id=='jerridon')
{
  if(count($npc32_count) > 1)
  exit('error');

 $item_code = $npc32_count[0];
 parse_item_code($item_code);

if($item_harmony!=0)
 {
  $return_item[0] = substr_replace($item_code, '00', 37, 2);
 }
else
exit('error');
}

////////////////////////////////////////////
else
exit('error'); //unknown npc32
////////////////////////////////////////////

for($i=0; $i<count($current_npc32); $i++)
{
 $current_npc32[$i] = 0;
}

if($return_item[0] === 0)
{
 $npc32_kind = 0;

 $npc32_to_record = json_encode($current_npc32);
 $query = "UPDATE users SET npc32_kind='$npc32_kind', npc32='$npc32_to_record', zen=zen-$npc32_price WHERE id='$user_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');
 exit('mix_fail');
}
else if(count($return_item) > 1)
{
 for($i=0; $i<count($return_item); $i++)
 {
  $current_npc32[$i] = $return_item[$i];
 }
}
else
{
 $item_size = intval(substr($return_item[0],2,2));
 $check = 0;
 switch($item_size)
 {
 case '11':
 $array_to_occupy = array($check);
 break;

 case '12':
 $array_to_occupy = array($check, $check+8);
 break;

 case '13':
 $array_to_occupy = array($check, $check+8, $check+16);
 break;

 case '14':
 $array_to_occupy = array($check, $check+8, $check+16, $check+24);
 break;

 case '22':
 $array_to_occupy = array($check, $check+1, $check+8, $check+9);
 break;

 case '23':
 $array_to_occupy = array($check, $check+1, $check+8, $check+9, $check+16, $check+17);
 break;

 case '24':
 $array_to_occupy = array($check, $check+1, $check+8, $check+9, $check+16, $check+17, $check+24, $check+25);
 break;

 case '42':
 $array_to_occupy = array($check, $check+1, $check+2, $check+3, $check+8, $check+9, $check+10, $check+11);
 break;

 case '53':
 $array_to_occupy = array($check, $check+1, $check+2, $check+3, $check+4, $check+8, $check+9, $check+10, $check+11, $check+12, $check+16, $check+17, $check+18, $check+19, $check+20);
 break;

 case '32':
 $array_to_occupy = array($check, $check+1, $check+2, $check+8, $check+9, $check+10);
 break;

 case '33':
 $array_to_occupy = array($check, $check+1, $check+2, $check+8, $check+9, $check+10, $check+16, $check+17, $check+18);
 break;
 }

 for($i=0; $i<count($array_to_occupy); $i++)
 {
  $e = $array_to_occupy[$i];
  $current_npc32[$e] = 1;
 }
 $current_npc32[0] = $return_item[0];
}

 $npc32_to_record = json_encode($current_npc32);
 $query = "UPDATE users SET npc32_kind='$npc32_kind', npc32='$npc32_to_record', zen=zen-$npc32_price WHERE id='$user_id'";
 if(!mysql_query($query,$conn))
 exit('mysql_error');

 array_unshift($current_npc32, $mix_result);

echo json_encode($current_npc32);

?>

