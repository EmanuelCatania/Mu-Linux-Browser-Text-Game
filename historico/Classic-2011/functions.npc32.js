function npc32_transform() {
var npc32_price = 1000;

if (window.ActiveXObject)
  xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
else
  xmlhttp=new XMLHttpRequest();

 xmlhttp.onreadystatechange=function()
  {
if(xmlhttp.readyState==4 && xmlhttp.status>400)
{
 $('#npc32_output').text('Improper item combination');
 $('#js_output').text('Server error');
}
else if (xmlhttp.readyState==4 && xmlhttp.status==200)
    {
 if(xmlhttp.responseText.slice(0,1)!='[')
{
 $('#npc32_output').text('Improper item combination');

if(xmlhttp.responseText=="no_space")
 $('#js_output').text('Clear 5x3 space in inventory');
else if(xmlhttp.responseText=="no_money")
 $('#js_output').text('Not enough money');
else if(xmlhttp.responseText=="mix_fail")
 {
  $('#npc32_output').text('No items');
  $('#js_output').text('Item combination failed');
  document.getElementById("zen_value").innerHTML = Number(document.getElementById("zen_value").innerHTML) - npc32_price;
  for(i=250; i<282; i++)
   {
    document.getElementById(i).innerHTML = '';
    $('#'+i).removeClass('occupied');
    js_current_npc32[i] = 0;
   }
 }
else
  $('#js_output').text('Id error');
}
else
{
 $('#npc32_output').text('Improper item combination');
 var js_new_npc32 = eval('(' + xmlhttp.responseText + ')');
var npc32_first = js_new_npc32.shift();
if(npc32_first == 'mix_fail')
 {
  $('#js_output').text('Item combination failed');
  document.getElementById("zen_value").innerHTML = Number(document.getElementById("zen_value").innerHTML) - npc32_price;
 }
else
 {
  $('#js_output').text('Item combination success');
  document.getElementById("zen_value").innerHTML = Number(document.getElementById("zen_value").innerHTML) - npc32_price;
 }

var js_array_250 = new Array();
 for(i=0; i<250; i++)
 {
  js_array_250[i]=0;
 }
js_current_npc32 = js_array_250.concat(js_new_npc32);

for(i=250; i<282; i++)
{
if(js_current_npc32[i]=='0')
 {
  document.getElementById(i).innerHTML = '';
  $('#'+i).removeClass('occupied');
 }
else
 {
  $('#'+i).addClass('occupied');
  document.getElementById(i).innerHTML = '';
  if(js_current_npc32[i]!=1)
{
  document.getElementById(i).innerHTML = load_items(js_current_npc32[i], 0, player_level, player_class, player_strength, player_agility, player_energy, player_stamina);
  target_id = '#' + i + '>img';
  drag_init(target_id);
}
 }
}

 if(npc32_id!='chaos_machine')
 {
  document.getElementById('npc32_inventory_ani').innerHTML = '<img src="' + mix_button_ani.src + '"/>';
  setTimeout("document.getElementById('npc32_inventory_ani').innerHTML =''",600);
 }
}
    }
  }
xmlhttp.open("GET","function.npc32_transform.php?id=" + npc32_id + "&rand=" + Math.random(),true);
xmlhttp.send();
}


function npc32_predict() {
var npc32_count = new Array();
for(i=250; i<282; i++)
{
if(js_current_npc32[i]!='0' && js_current_npc32[i]!='1')
  npc32_count.push(js_current_npc32[i]);
}


if(npc32_count.length==0)
$('#npc32_output').text('No items');


//////////////////////chaos machine////////////////////////
else if(npc32_id=='chaos_machine')
{
 var item44 = 0;
 var item44_rate = 0;
 var item44_exc = 0;
 var item44_exc_rate = 0;
 var item44_return = 0;
 var item44_chaos = 0;
 var item44_chaos_return = 0;
 var item49 = 0;
 var item49_level = 0;
 var item49_luck = 0;
 var item49_return = 0;
 var bless = 0;
 var soul = 0;
 var chaos = 0;
 var loch = 0;
 var creation = 0;
 var wings = 0;
 var wings_level = 0;
 var wings_return = 0;
 var archangel = 0;
 var bone = 0;
 var archangel_level = 0;
 var bone_level = 0;
 var uniria = 0;
 var unknown_item = 0;

for(i=0; i<npc32_count.length; i++)
{
 var item_code = npc32_count[i];
 parse_item_code(item_code);

  if(item_type == '8' && item_sub_type == '1')
  {
   wings += 1;
   wings_level = item_level;
   wings_return = item_code;
  }
  else if(item_type == 'E' && item_sub_type == '1')
   chaos += 1;
  else if(item_type == 'E' && item_sub_type == '2')
   creation += 1;
  else if(item_type == 'D' && item_sub_type == '1')
   bless += 1;
  else if(item_type == 'D' && item_sub_type == '2')
   soul += 1;
  else if(item_type == 'C' && item_sub_type == '3')
   uniria += 1;
  else if(item_type == 'I' && item_sub_type == '4')
   {
    bone += 1;
    bone_level = item_level;
   }
  else if(item_type == 'I' && item_sub_type == '5')
   {
    archangel += 1;
    archangel_level = item_level;
   }
  else if(item_type == 'E' && item_sub_type == '7')
   loch += 1;
  else if((item_type == '1' || item_type == '2' || item_type == '3' || item_type == '4' || item_type == '5' || item_type == '6' || item_type == '7' || item_type == '8' || item_type == '9') && item_level > 8)
   {
    item49 +=1;
    item49_level = item_level;
    item49_luck = item_luck;
    item49_return = item_code;
   }
  else if((item_type == '1' || item_type == '2' || item_type == '3' || item_type == '4' || item_type == '5' || item_type == '6' || item_type == '7'|| item_type == '9') && (item_level > 3 && item_level < 9) && item_excellent > 0)
   {
    item44_exc += 1;
    item44_exc_rate += Math.floor(item_effect/10*(item_level + Math.ceil(item_excellent/100)*3));
   }
  else if((item_type == '1' || item_type == '2' || item_type == '3' || item_type == '4' || item_type == '5' || item_type == '6' || item_type == '7'|| item_type == '9') && (item_level > 3 && item_level < 9) && item_option > 0 && item_excellent == 0)
   {
    item44 += 1;
    item44_rate += Math.floor(item_effect/10*(item_level + Math.ceil(item_excellent/100)*3));
    if(item44_return == '0')
    item44_return = item_code; //remember only the first item to return it on fail

    if(item_name_source == 'chaos_dragon_axe' || item_name_source == 'chaos_lighting_staff' || item_name_source == 'chaos_nature_bow')
    {
     item44_chaos +=1;
     item44_chaos_return = item_code;
    }
   }
  else
   unknown_item +=1;
}

if(unknown_item > 0)
 $('#npc32_output').text('Improper item combination');

else if(creation == 1 && chaos == 1 && npc32_count.length == 2)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Fruit of Creation<br/>Success: 80%</a>';

else if(bone == 1 && archangel == 1 && chaos == 1 && npc32_count.length == 3 && archangel_level == bone_level)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Cloack of Invisibility + ' + archangel_level + '<br/>Success: 80%</a>';

else if(uniria == 10 && chaos == 1 && npc32_count.length == 11)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Dinorant<br/>Success: 80%</a>';

else if(item49 == 1 && item49_level == 9 && bless == 1 && soul == 1 && chaos == 1 && npc32_count.length == 4 && item49_luck > 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +10 item upgrade<br/>Success: 75%</a>';
else if(item49 == 1 && item49_level == 9 && bless == 1 && soul == 1 && chaos == 1 && npc32_count.length == 4 && item49_luck == 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +10 item upgrade<br/>Success: 50%</a>';

else if(item49 == 1 && item49_level == 10 && bless == 2 && soul == 2 && chaos == 1 && npc32_count.length == 6 && item49_luck > 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +11 item upgrade<br/>Success: 75%</a>';
else if(item49 == 1 && item49_level == 10 && bless == 2 && soul == 2 && chaos == 1 && npc32_count.length == 6 && item49_luck == 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +11 item upgrade<br/>Success: 50%</a>';

else if(item49 == 1 && item49_level == 11 && bless == 3 && soul == 3 && chaos == 1 && npc32_count.length == 8 && item49_luck > 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +12 item upgrade<br/>Success: 75%</a>';
else if(item49 == 1 && item49_level == 11 && bless == 3 && soul == 3 && chaos == 1 && npc32_count.length == 8 && item49_luck == 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +12 item upgrade<br/>Success: 50%</a>';

else if(item49 == 1 && item49_level == 12 && bless == 4 && soul == 4 && chaos == 1 && npc32_count.length == 10 && item49_luck > 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +13 item upgrade<br/>Success: 75%</a>';
else if(item49 == 1 && item49_level == 12 && bless == 4 && soul == 4 && chaos == 1 && npc32_count.length == 10 && item49_luck == 0)
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: +13 item upgrade<br/>Success: 50%</a>';

else if(item44 > 0 && chaos > 0 && item49 == 0 && wings == 0 && item44_chaos == 0)
{
 item44_rate = item44_rate + bless*4 + soul*3 + chaos*2;
 if(item44_rate>90)
 item44_rate = 90;
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Chaos Weapon<br/>Success: ' + item44_rate + '%</a>';
}

else if(item44_chaos > 0 && chaos > 0 && wings == 0 && item49 == 0)
{
 item44_rate = item44_rate + bless*4 + soul*3 + chaos*2;
 if(item44_rate>90)
 item44_rate = 90;
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Wings<br/>Success: ' + item44_rate + '%</a>';
}

else if(loch == 1 && chaos ==1 && wings == 1 && item44 == 0 && item49 == 0 && bless == 0 && soul == 0)
{
 item44_exc_rate = 10*(wings_level+1) + item44_exc_rate + 2;
 if(item44_exc_rate>90)
 item44_exc_rate = 90;
 document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Level 2 Wings<br/>Success: ' + item44_exc_rate + '%</a>';
}


else
 $('#npc32_output').text('Improper item combination');

}
//////////////////////chaos machine end////////////////////////


/////////////////////////////////
else if(npc32_id=='cblossom')
{
  if(npc32_count.length == 5)
 {
  var golden = 0;
  var red = 0;
  var white = 0;

  for(i=0; i<npc32_count.length; i++)
  {
   if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == '1')
    golden += 1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == '2')
    red += 1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == '3')
    white += 1;
  }

  if(golden == 5 || red == 5 || white == 5)
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Cherry Blossom Gift</a>';
  else
   $('#npc32_output').text('Improper item combination');
 }
 else
  $('#npc32_output').text('Improper item combination');
}
/////////////////////////////////
else if(npc32_id=='lahap')
{
  if(npc32_count.length==10) //check for ten jewels bless/soul
 {
  var bless10 = 0;
  var soul10 = 0;
  for(i=0; i<npc32_count.length; i++)
  {
   if(npc32_count[i].slice(0,1) == 'D' && npc32_count[i].slice(5,6) == '1')
   bless10+=1;
   else if(npc32_count[i].slice(0,1) == 'D' && npc32_count[i].slice(5,6) == '2')
   soul10+=1;
  }
  if(bless10 == 10)
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Pack of Jewel of Bless x 10</a>';
  else if(soul10 == 10)
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Pack of Jewel of Soul x 10</a>';
  else
   $('#npc32_output').text('Improper item combination');
 }
 else if(npc32_count.length==1) //pack of ten jewels/box to open
 {
  var item_code = npc32_count[0];
  parse_item_code(item_code);


  if(item_type=='H' && item_sub_type == '3')
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Open the box. Success: 90%</a>'; //cherry blossom

  else if(item_type=='H' && item_sub_type == '1')
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Open the box. Success: 90%</a>'; //box of luck

  else if(item_type=='H' && item_sub_type == '2' && (item_level == '1' || item_level == '2'))
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Open the box. Success: 90%</a>'; //kundun +1 +2

  else if(item_type=='E' && item_sub_type == '4')
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: 10 Jewels of Bless</a>';

  else if(item_type=='E' && item_sub_type == '5')
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: 10 Jewels of Soul</a>';
  else
   $('#npc32_output').text('Improper item combination');
 }
  else if(npc32_count.length==5) //check for 5 symbols of kundun
 {
  var kundun1 = 0;
  var kundun2 = 0;
  var kundun3 = 0;
  var kundun4 = 0;
  var kundun5 = 0;
  var kundun6 = 0;
  var kundun7 = 0;
  for(i=0; i<npc32_count.length; i++)
  {
   if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '1')
   kundun1+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '2')
   kundun2+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '3')
   kundun3+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '4')
   kundun4+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '5')
   kundun5+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '6')
   kundun6+=1;
   else if(npc32_count[i].slice(0,1) == 'I' && npc32_count[i].slice(5,6) == 'A' && npc32_count[i].slice(13,14) == '7')
   kundun7+=1;
  }
  if(kundun1 == 5 || kundun2 == 5 || kundun3 == 5 || kundun4 == 5 || kundun5 == 5 || kundun6 == 5 || kundun7 == 5)
   document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Lost Map</a>';
  else
   $('#npc32_output').text('Improper item combination');
 }
 else
 {
  if(npc32_count.length>0)
   $('#npc32_output').text('Improper item combination');
 }
}
/////////////////////////////////
else if(npc32_id=='osbourne')
{
  if(npc32_count.length>1)
 $('#npc32_output').text('Improper item combination');
  if(npc32_count.length==1)
 {
  var item_code = npc32_count[0];
  parse_item_code(item_code);

if((item_type=='1' || item_type=='2' || item_type=='9') && item_option!=0 && item_excellent == 0 && item_level>3)
  document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Lower Refining Stone<br/>Success: 20%</a>';
else if((item_type=='1' || item_type=='2' || item_type=='9') && item_excellent!= 0)
  document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Higher Refining Stone<br/>Success: 50%</a>';
else
 $('#npc32_output').text('Improper item combination');
 }
}
/////////////////////////////////
else if(npc32_id=='jerridon')
{
  if(npc32_count.length>1)
 $('#npc32_output').text('Improper item combination');
  if(npc32_count.length==1)
 {
  var item_code = npc32_count[0];
  parse_item_code(item_code);
if(item_harmony!='00')
  document.getElementById('npc32_output').innerHTML = '<a class="yellow">Item: Restored Item</a>';
else
 $('#npc32_output').text('Improper item combination');
 }
}
/////////////////////////////////
}