<?php
if(!isset($player_class))
exit('error');
?>


$('.div_border').css({
overflow: 'visible'
});


if(js_current_equipment[70]!='0')
{
  var item_code = js_current_equipment[70];
  var item_size = Number(item_code.slice(2,4));
if(item_size == 11)
set_margin = 16;
else
set_margin = 0;
$('#70>img').css({
margin: set_margin
});
}

if(js_current_equipment[72]!='0')
{
  var item_code = js_current_equipment[72];
  var item_size = Number(item_code.slice(2,4));

 if(item_size == 42)
 {
  set_margin = -16;
  $('#72>img').css({
  marginLeft: set_margin
  });
 }
 else if(item_size == 53)
 {
  set_margin_left = -32;
  set_margin = -16;
  $('#72>img').css({
  margin: set_margin,
  marginLeft: set_margin_left
  });
 }
 
}

if(js_current_equipment[73]!='0')
{
  var item_code = js_current_equipment[73];
  var item_size = Number(item_code.slice(2,4));
if(item_size == 11 || item_size == 12 || item_size == 13 || item_size == 14)
set_margin = 16;
else
set_margin = 0;
$('#73>img').css({
marginLeft: set_margin
});
}

if(js_current_equipment[75]!='0')
{
  var item_code = js_current_equipment[75];
  var item_size = Number(item_code.slice(2,4));
if(item_size == 32 || item_size == 33)
set_margin = -16;
else
set_margin = 0;
$('#75>img').css({
marginLeft: set_margin
});
}

if(js_current_equipment[76]!='0')
{
  var item_code = js_current_equipment[76];
  var item_size = Number(item_code.slice(2,4));
if(item_size == 11 || item_size == 12 || item_size == 13 || item_size == 14)
set_margin = 16;
else
set_margin = 0;
$('#76>img').css({
marginLeft: set_margin
});
}