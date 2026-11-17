<?php


///////////golden invasion timer///////////////

date_default_timezone_set('UTC');
$cur_time = time();
$cur_hour = (int)date("H",$cur_time);


if($cur_hour == 0 || $cur_hour == 8 || $cur_hour == 16)
{
 $golden_close = strtotime('today') + ($cur_hour+1)*60*60;
 $golden_status = 'ENDS IN '.gmdate("H:i:s", ($golden_close - $cur_time));
}

else if($cur_hour < 8)
 $golden_status = 'STARTS IN '.gmdate("H:i:s", (strtotime('today')+8*60*60 - $cur_time));
else if($cur_hour > 16)
 $golden_status = 'STARTS IN '.gmdate("H:i:s", (strtotime('tomorrow') - $cur_time));
else
 $golden_status = 'STARTS IN '.gmdate("H:i:s", (strtotime('today')+16*60*60 - $cur_time));


///////////golden invasion timer end///////////////
?>

<div id="top_nav_back"></div>
<div id="overDiv" style="position:absolute; text-align:center; visibility:hidden; z-index:1000;"></div>

<?php
$logout = 0;
if(isset($_GET['action']))
{
 if($_GET['action'] == 'logout')
 $logout = 1;
}

if(isset($_SESSION['user_id']) && $logout != 1)
{
echo '<div id="golden_invasion_back"></div><a href="#"><div id="golden_invasion">';
echo '<span class="yellow">GOLDEN INVASION</span><br/>'.$golden_status.'</div></a>';
}
?>
<div id="top_nav_online_back"></div>
<div id="top_nav_online"><a href="whoisonline.php"><?php include('function.online.php'); ?></a></div>

<script type="text/javascript">
g_open = 0;

function check_golden() {

if (window.ActiveXObject)
  xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
else
  xmlhttp=new XMLHttpRequest();

 xmlhttp.onreadystatechange=function()
{
if(xmlhttp.readyState==4 && xmlhttp.status>400)
 alert('Server error');
else if (xmlhttp.readyState==4 && xmlhttp.status==200)
{
 overlib(xmlhttp.responseText, WIDTH, 360, HEIGHT, 400, FIXX, 680, FIXY, 54, STICKY);
}
}
xmlhttp.open("GET","function.golden.php?rand=" + Math.random(),true);
xmlhttp.send();

}


$('#golden_invasion').click(function(){
event.preventDefault();
  if( $("#overDiv").css('visibility') == 'hidden')
   check_golden();
  else
  {
   nd();
   nd();
  }
});
</script>

