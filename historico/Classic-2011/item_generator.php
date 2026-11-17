<?php
if(!isset($i))
exit('error');


//////////////////////////////Sunday event////////////////////////////////////////
if(date("D",time()) == 'Sun' && mt_rand(0,49)<1) //2% on Sunday
{
 $drop_file = '0';
 $_SESSION['enemies'][$i]['item'] = "H.11.3.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.cherry_blossom_box";
}
//////////////////////////////Sunday event end////////////////////////////////////////


//////////////////////////////golden monsters////////////////////////////////////////
else if($_SESSION['enemies'][$i]['name'] == 'golden_budge_dragon')
{
 $drop_file = '0';
 $_SESSION['enemies'][$i]['item'] = "H.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_luck";
}

else if($_SESSION['enemies'][$i]['name'] == 'golden_goblin' || $_SESSION['enemies'][$i]['name'] == 'golden_rabbit' || $_SESSION['enemies'][$i]['name'] == 'golden_knight' || $_SESSION['enemies'][$i]['name'] == 'golden_titan')
{
 $drop_file = '0';
 $_SESSION['enemies'][$i]['item'] = "H.11.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun";
}

else if($_SESSION['enemies'][$i]['name'] == 'golden_devil' || $_SESSION['enemies'][$i]['name'] == 'golden_lizard_king')
{
 $drop_file = '0';
 $_SESSION['enemies'][$i]['item'] = "H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun";
}
//////////////////////////////golden monsters end////////////////////////////////////


//////////////////////////////drop regular item////////////////////////////////////////
else if(mt_rand(0,3)<1) //25%
{
if($_SESSION['enemies'][$i]['level']<11)
$drop_file = 'regular1.xml';

else if($_SESSION['enemies'][$i]['level']>10 && $_SESSION['enemies'][$i]['level']<21)
$drop_file = 'regular1.5.xml';

else if($_SESSION['enemies'][$i]['level']>20 && $_SESSION['enemies'][$i]['level']<31)
$drop_file = 'regular2.xml';

else if($_SESSION['enemies'][$i]['level']>30 && $_SESSION['enemies'][$i]['level']<41)
$drop_file = 'regular2.5.xml';

else if($_SESSION['enemies'][$i]['level']>40 && $_SESSION['enemies'][$i]['level']<51)
$drop_file = 'regular3.xml';

else if($_SESSION['enemies'][$i]['level']>50 && $_SESSION['enemies'][$i]['level']<61)
$drop_file = 'regular4.xml';

else if($_SESSION['enemies'][$i]['level']>60 && $_SESSION['enemies'][$i]['level']<71)
$drop_file = 'regular5.xml';

else if($_SESSION['enemies'][$i]['level']>70 && $_SESSION['enemies'][$i]['level']<81)
$drop_file = 'regular6.xml';

else if($_SESSION['enemies'][$i]['level']>80 && $_SESSION['enemies'][$i]['level']<91)
$drop_file = 'regular7.xml';

else if($_SESSION['enemies'][$i]['level']>90 && $_SESSION['enemies'][$i]['level']<101)
$drop_file = 'regular8.xml';

else
$drop_file = 'regular8.xml';
}
//////////////////////////////drop regular item end////////////////////////////////////////


////////////////////////////////drop skills/////////////////////////////////////////
else if(mt_rand(0,99)<1 && $_SESSION['enemies'][$i]['level']<121)  //1%
{
if($_SESSION['enemies'][$i]['level']<31)
$drop_file = 'skills1.xml';

else if($_SESSION['enemies'][$i]['level']>30 && $_SESSION['enemies'][$i]['level']<51)
$drop_file = 'skills2.xml';

else if($_SESSION['enemies'][$i]['level']>50 && $_SESSION['enemies'][$i]['level']<71)
$drop_file = 'skills3.xml';

else if($_SESSION['enemies'][$i]['level']>70 && $_SESSION['enemies'][$i]['level']<91)
$drop_file = 'skills4.xml';

else
$drop_file = 'skills5.xml';
}
////////////////////////////////drop skills end/////////////////////////////////////////


////////////////////////////////drop parts/////////////////////////////////////////
else if(mt_rand(0,99)<1 && $_SESSION['enemies'][$i]['level']>20 && $_SESSION['enemies'][$i]['level']<121)  //1%
{
if($_SESSION['enemies'][$i]['level']<36)
$drop_file = 'parts1.xml';

else if($_SESSION['enemies'][$i]['level']>35 && $_SESSION['enemies'][$i]['level']<51)
$drop_file = 'parts2.xml';

else if($_SESSION['enemies'][$i]['level']>50 && $_SESSION['enemies'][$i]['level']<61)
$drop_file = 'parts3.xml';

else if($_SESSION['enemies'][$i]['level']>60 && $_SESSION['enemies'][$i]['level']<71)
$drop_file = 'parts4.xml';

else if($_SESSION['enemies'][$i]['level']>70 && $_SESSION['enemies'][$i]['level']<81)
$drop_file = 'parts5.xml';

else if($_SESSION['enemies'][$i]['level']>80 && $_SESSION['enemies'][$i]['level']<91)
$drop_file = 'parts6.xml';

else
$drop_file = 'parts7.xml';
}
////////////////////////////////drop parts end/////////////////////////////////////////


////////////////////////////////drop rings/////////////////////////////////////////
else if(mt_rand(0,499)<1 && $_SESSION['enemies'][$i]['level']>20)  //0,2%
{
if($_SESSION['enemies'][$i]['level']<71)
$drop_file = 'rings1.xml';

else
$drop_file = 'rings2.xml';
}
////////////////////////////////drop rings end/////////////////////////////////////////


////////////////////////////////drop jewels/////////////////////////////////////////
else if(mt_rand(0,499)<1)  //0,2%
{
if($_SESSION['enemies'][$i]['level']<71)
$drop_file = 'jewels1.xml';

else
$drop_file = 'jewels2.xml';
}
////////////////////////////////drop jewels end/////////////////////////////////////////


else
$drop_file = '0';


if($drop_file !== '0')
{
 $xml = simplexml_load_file('drop/'.$drop_file);
 $a=0;
 $droplist = array();
 foreach($xml->children() as $child)
 {
  $droplist[$a]= "$child";
  $a++;
 }
$tmp = mt_rand(0, count($droplist)-1);
$item_code = $droplist[$tmp];

if(!function_exists('parse_item_code'))
include('function.common.php');

parse_item_code($item_code);


if($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7' || $item_type == '9' || $item_type == 'A' || $item_type == 'B')
{
 //random level (0,1,2) - the higher level, the lower chance
   if(mt_rand(0,2) < 1)       //33%
    $item_level += 1;
   else if(mt_rand(0,9) < 1)  //10%
    $item_level += 2;

   $item_level_new = str_pad($item_level, 2, "0", STR_PAD_LEFT);
   $item_code = substr_replace($item_code, $item_level_new, 12, 2);


 //random option
   $item_option_new = 0;
if($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7' || $item_type == '9')
{
   if(mt_rand(0,4) < 1)  //20%
    $item_option_new = 1;
   else if(mt_rand(0,19) < 1) //5%
    $item_option_new = 2;
}
if($item_type == 'A' || $item_type == 'B')
{
   if(mt_rand(0,2) < 1)  //33%
    $item_option_new = 1;
   else if(mt_rand(0,4) < 1) //20%
    $item_option_new = 2;
   else if(mt_rand(0,9) < 1) //10%
    $item_option_new = 3;
   else if(mt_rand(0,49) < 1)//2%
    $item_option_new = 4;
}
   $item_code = substr_replace($item_code, $item_option_new, 32, 1);
}


//random skill (25%)
if(($item_type == '1' || $item_type == '2' || $item_type == '9') && mt_rand(0,3)<1)
{
 $rand_skill = 0;

  if(($item_type == '1' || $item_type == '2') && $item_sub_type == '1')
  $rand_skill = mt_rand(3,6); //sword skills

  else if(($item_type == '1' || $item_type == '2') && $item_sub_type == '2')
  $rand_skill = 7; //falling slash for axe

  else if($item_type == '2' && ($item_sub_type == '8' || $item_sub_type == '9'))
  $rand_skill = 9; //triple shot for bow/crossbow

  else if($item_type == '9')
  $rand_skill = 8; //defense for shield

  else if($item_type == '1' && $item_sub_type == '5' && $item_effect == 23) //summoner books
  $rand_skill = 'A';
  else if($item_type == '1' && $item_sub_type == '5' && $item_effect == 29)
  $rand_skill = 'B';
  else if($item_type == '1' && $item_sub_type == '5' && $item_effect == 36)
  $rand_skill = 'C';

  $item_code = substr_replace($item_code, $rand_skill, 30, 1);
}


if($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7' || $item_type == '9')
{ // no luck for rings and pendants
 if(mt_rand(0,3)<1)  //25%
 $item_code = substr_replace($item_code, '1', 15, 1);
}

$_SESSION['enemies'][$i]['item'] = $item_code;
}

?>