<?php

  $xml = simplexml_load_file('drop/'.$file_to_open);
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

//item options
if($item_type == '1' || $item_type == '2' || $item_type == '3' || $item_type == '4' || $item_type == '5' || $item_type == '6' || $item_type == '7' || $item_type == '9' || $item_type == 'A' || $item_type == 'B')
{

 //random excellent
   if($item_excellent == '1')
   {
    $item_excellent_new = mt_rand(1,6);
    $item_excellent_new = str_pad($item_excellent_new, 2, "0", STR_PAD_LEFT);
    $item_code = substr_replace($item_code, $item_excellent_new, 34, 2);
   }


 //random level (0,1,2) - the higher level, the lower chance (always 0 for excellent items)
   if(mt_rand(0,2) < 1)       //33%
    $item_level += 1;
   else if(mt_rand(0,9) < 1)  //10%
    $item_level += 2;
   if($item_excellent > 0)
    $item_level = 0;
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


//random skill (always)
if(($item_type == '1' || $item_type == '2' || $item_type == '9'))
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
 if(mt_rand(0,2)<1)  //33%
 $item_code = substr_replace($item_code, '1', 15, 1);
}
?>