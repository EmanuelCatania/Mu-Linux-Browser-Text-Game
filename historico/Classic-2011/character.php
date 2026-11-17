<?php
session_start();
include('function.login.php');


$count_chars = 0;
$active_char = 1;

if(isset($_SESSION['user_id']))
{
include("database.php");
global $conn;

$user_id = $_SESSION['user_id'];

$query = "SELECT active_char FROM users WHERE id='$user_id'";
$result = mysql_query($query,$conn);
$dbarray = mysql_fetch_array($result);
$active_char= $dbarray[0];

$query = "SELECT * FROM characters WHERE owner='$user_id'";
$result = mysql_query($query,$conn);

$count_chars = mysql_num_rows($result);

if($count_chars>0)
{
$row = mysql_fetch_assoc($result);
$ch1_name = $row['name'];
if(strlen($ch1_name)>9)
$ch1_name = substr($ch1_name,0,8).".";
$ch1_level = $row['level'];
$ch1_class = $row['class'];
}
if($count_chars>1)
{
$row = mysql_fetch_assoc($result);
$ch2_name = $row['name'];
if(strlen($ch2_name)>9)
$ch2_name = substr($ch2_name,0,8).".";
$ch2_level = $row['level'];
$ch2_class = $row['class'];
}
if($count_chars>2)
{
$row = mysql_fetch_assoc($result);
$ch3_name = $row['name'];
if(strlen($ch3_name)>9)
$ch3_name = substr($ch3_name,0,8).".";
$ch3_level = $row['level'];
$ch3_class = $row['class'];
}

}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN">
<html>
<head>
<?php include('function.title.php'); ?>
<link href="common.css" rel="stylesheet" type="text/css" />
<link href="character.css" rel="stylesheet" type="text/css"/>

<script src="jquery.js" type="text/javascript"></script>
<script src="jquery.alerts.js" type="text/javascript"></script>
<script src="jquery.drag.drop.js" type="text/javascript"></script>
<script type="text/javascript" src="overlib.js"></script>

</head>
<body>
<table class = "parent_table" cellspacing="0">
	<tr>
		<td colspan="2" class="parent_table_top"><div class="parent_table_top_ins"><?php include('top_navigation.php'); ?></div></td>
	</tr>
<tr><td class="parent_table_left"><?php include('navigation.php'); ?></td><td class="parent_table_middle">
<div class="div_border">

<table class="main_table" cellspacing="0"><tr><td><div id="character_selection">

<div class="blocker"></div>

<div class="main_view">
    <div class="window">
        <div class="image_reel">
            <a><img src="images/01.gif" alt="" /></a>
            <a><img src="images/02.gif" alt="" /></a>
            <a><img src="images/03.gif" alt="" /></a>
            <a><img src="images/04.gif" alt="" /></a>
        </div>
    </div>
    <div class="paging">
        <a href="#" rel="1">Dark Knight</a>
        <a href="#" rel="2">Dark Wizard</a>
        <a href="#" rel="3">Fairy Elf</a>
        <a href="#" rel="4">Summoner</a>
    </div>

    <div class="stats" id="stats">
<table class="stats_table">
<tr><td>Strength:</td><td>28</td></tr>
<tr><td>Agility:</td><td>20</td></tr>
<tr><td>Stamina:</td><td>25</td></tr>
<tr><td>Energy:</td><td>10</td></tr>
</table>
    </div>

    <div class="stats_overlay">
    </div>

    <div class="stats_overlay_buttons">
        <table cellspacing="0"><tr><td id="sob1"><input class="input_name" type="text" id="cname" maxlength='12' /></td><td id="sob2"></td><td><a href="#" id="ok_button">ok</a></td><td id="sob3"></td><td><a href="#" id="cancel_button">cancel</a></td></tr></table>
    </div>


</div>

<?php
if(isset($ch1_name))
echo "<div class='character_avatar avatar1' id='av1'>".$ch1_name."<br/><img src='images/".$ch1_class.".gif' width='100' height='100'/><br/>Level: ".$ch1_level."</div>";

if(isset($ch2_name))
echo "<div class='character_avatar avatar2' id='av2'>".$ch2_name."<br/><img src='images/".$ch2_class.".gif' width='100' height='100'/><br/>Level: ".$ch2_level."</div>";

if(isset($ch3_name))
echo "<div class='character_avatar avatar3' id='av3'>".$ch3_name."<br/><img src='images/".$ch3_class.".gif' width='100' height='100'/><br/>Level: ".$ch3_level."</div>";
?>

<table id="character_selection3"><tr><td><button type="button" id="create_button">create</button></td><td id="character_selection3_middle"></td><td><button type="button" id="select_button">select</button></td><td><button type="button" id="delete_button">delete</button></td></tr></table>


<canvas id="pixie"></canvas>
<div id="char_sel_back"></div>

</div></td></tr><tr><td><div id="character_selection2">

<div id="js_output">Click "create" button to create a new character. You can create up to three characters. Name requirements: English alphabet letters and numbers only, no spaces. Length: from 3 to 12 symbols. Click on character's avatar to select it for setting as active or deleting.</div>

</div></td></tr><tr><td></td></tr></table>
</div>
</td></tr></table>

<script type="text/javascript">

$(document).ready(function()
{
var count_chars = <?php echo $count_chars;?>;
var active_av = "<?php echo $active_char;?>";
var active_create = 1;
var new_active_av = 0;


function deleting_confirmed(r, character) {
if(r)
{
if (window.ActiveXObject)
  xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
else
  xmlhttp=new XMLHttpRequest();

 xmlhttp.onreadystatechange=function()
  {
if(xmlhttp.readyState==4 && xmlhttp.status>400)
{
  jAlert('Server error', 'Message');
}
else if (xmlhttp.readyState==4 && xmlhttp.status==200)
    {
 if(xmlhttp.responseText!="ok")
 {
jAlert('id error', 'Message');
}
else
{
$('#' + character).remove();

if(character == 'av1' && count_chars>1)
{
$('#av2').removeClass('avatar2');
$('#av2').addClass('avatar1');
$('#av2').attr('id', 'av1');

$('#av3').removeClass('avatar3');
$('#av3').addClass('avatar2');
$('#av3').attr('id', 'av2');
}

if(character == 'av2' && count_chars>2)
{
$('#av3').removeClass('avatar3');
$('#av3').addClass('avatar2');
$('#av3').attr('id', 'av2');
}

count_chars-=1;
if(character == active_av || character == new_active_av)
{
 active_av = 'av1';
 new_active_av = 'av1';
 $(".avatar_active").remove();
 $('#av1').append('<img class="avatar_active" src="images/active.gif" width="59" height="23"/>');
}

}
    }
  }
xmlhttp.open("GET","function.char.php?action=delete" + "&char=" + character + "&rand=" + Math.random(),true);
xmlhttp.send();
}
}


function select_character(character) {
if (window.ActiveXObject)
  xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
else
  xmlhttp=new XMLHttpRequest();

 xmlhttp.onreadystatechange=function()
  {
if(xmlhttp.readyState==4 && xmlhttp.status>400)
{
  jAlert('Server error', 'Message');
}
else if (xmlhttp.readyState==4 && xmlhttp.status==200)
    {
 if(xmlhttp.responseText!="ok")
 {
jAlert('id error', 'Message');
}
else
{
var char_number = character.slice(2,3);
jAlert('Character #' + char_number + ' is set as your active character', 'Message');
active_av = new_active_av;
}
    }
  }
xmlhttp.open("GET","function.char.php?action=set_active" + "&char=" + character + "&rand=" + Math.random(),true);
xmlhttp.send();

}


function create_character(cname, cclass) {
if (window.ActiveXObject)
  xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
else
  xmlhttp=new XMLHttpRequest();

 xmlhttp.onreadystatechange=function()
  {
if(xmlhttp.readyState==4 && xmlhttp.status>400)
{
  jAlert('Server error', 'Message');
}
else if (xmlhttp.readyState==4 && xmlhttp.status==200)
    {
 if(xmlhttp.responseText!="ok")
 {
  if(xmlhttp.responseText=="char_taken")
  jAlert('This character name is already taken', 'Message');
  else
  jAlert('id error', 'Message');
}
else
{

if(cname.length>9)
cname = cname.substring(0,8) + '.';

if(count_chars>1)
{
av_to_update = 'av3';
$('#character_selection').append("<div class='character_avatar avatar3' id='av3'>" + cname + "<br/><img src='images/" + cclass + ".gif' width='100' height='100'/><br/>Level: 1</div>");
}
else if(count_chars>0)
{
av_to_update = 'av2';
$('#character_selection').append("<div class='character_avatar avatar2' id='av2'>" + cname + "<br/><img src='images/" + cclass + ".gif' width='100' height='100'/><br/>Level: 1</div>");
}
else
{
av_to_update = 'av1';
$('#character_selection').append("<div class='character_avatar avatar1' id='av1'>" + cname + "<br/><img src='images/" + cclass + ".gif' width='100' height='100'/><br/>Level: 1</div>");
$('.avatar1').append('<img class="avatar_active" src="images/active.gif" width="59" height="23"/>');
active_av = 'av1';
new_active_av = 'av1';
}
count_chars +=1;

    $("#"+av_to_update).append('<img class="shadow" src="images/icon-shadow.png" width="110" height="36"/>');

    $("#"+av_to_update).click(function(){
    $(".avatar_active").remove();
    $(this).append('<img class="avatar_active" src="images/active.gif" width="59" height="23"/>');
    new_active_av = this.id;

    });

    $("#"+av_to_update).hover(function() {
    	var e = this;
        $(e).stop().animate({ marginTop: "-14px" }, 250, function() {
        	$(e).animate({ marginTop: "-10px" }, 250);
        });
        $(e).find("img.shadow").stop().animate({ width: "80%", height: "24px", marginLeft: "12px", marginTop: "16px", opacity: 0.15 }, 250);
    },function(){
    	var e = this;
        $(e).stop().animate({ marginTop: "4px" }, 250, function() {
        	$(e).animate({ marginTop: "0px" }, 250);
        });
        $(e).find("img.shadow").stop().animate({ width: "100%", height: "36px", marginLeft: "0px", marginTop: "0px", opacity: 0.50 }, 250);
    });

active_av = new_active_av;
}
    }
  }
xmlhttp.open("GET","function.char.php?action=create" + "&char=" + cname + "&cclass=" + cclass + "&rand=" + Math.random(),true);
xmlhttp.send();

}


    $(".character_avatar").append('<img class="shadow" src="images/icon-shadow.png" width="110" height="36"/>');

    $(".character_avatar").click(function(){
    $(".avatar_active").remove();
    $(this).append('<img class="avatar_active" src="images/active.gif" width="59" height="23"/>');
    new_active_av = this.id;

    });

/////////setting initial active character///////////////////
if(count_chars > 0)
{
    $('#' + active_av).append('<img class="avatar_active" src="images/active.gif" width="59" height="23"/>');
}
/////////setting initial active character end///////////////////

 
    $(".character_avatar").hover(function() {
    	var e = this;
        $(e).stop().animate({ marginTop: "-14px" }, 250, function() {
        	$(e).animate({ marginTop: "-10px" }, 250);
        });
        $(e).find("img.shadow").stop().animate({ width: "80%", height: "24px", marginLeft: "12px", marginTop: "16px", opacity: 0.15 }, 250);
    },function(){
    	var e = this;
        $(e).stop().animate({ marginTop: "4px" }, 250, function() {
        	$(e).animate({ marginTop: "0px" }, 250);
        });
        $(e).find("img.shadow").stop().animate({ width: "100%", height: "36px", marginLeft: "0px", marginTop: "0px", opacity: 0.50 }, 250);
    });

//On Click
$('#create_button').click(function() {
if(count_chars<3)
{
 $('.main_view').css('visibility', 'visible');
 $('.blocker').css('visibility', 'visible');
}
else
jAlert('You cannot create more than 3 characters', 'Message');
});

$('#select_button').click(function() {
if(count_chars<1)
jAlert('No characters to select', 'Message');
else if(new_active_av == '0' || new_active_av == active_av)
jAlert('This character is set already as active', 'Message');
else
select_character(new_active_av);
});

$('#delete_button').click(function() {
if(count_chars<1)
jAlert('No characters to delete', 'Message');
else
{
if(new_active_av=='0')
new_active_av = active_av;
jConfirm('Do you want to delete this character?', 'Confirm deleting', function(r) {deleting_confirmed(r, new_active_av);});
}
});

$('#ok_button').click(function() {
var cname = $('#cname').val();
if(cname == '')
 jAlert('Input character name', 'Message');
else if(count_chars>2)
 jAlert('You cannot create more than 3 characters', 'Message');
else
{
 create_character(cname, active_create);
 $('.main_view').css('visibility', 'hidden');
 $('.blocker').css('visibility', 'hidden');
}
});

$('#cancel_button').click(function() {
$('.main_view').css('visibility', 'hidden');
$('.blocker').css('visibility', 'hidden');
});

rotate = function(){
    var triggerID = $active.attr("rel") - 1; //Get number of times to slide
    var image_reelPosition = triggerID * imageWidth; //Determines the distance the image reel needs to slide

active_create = $active.attr("rel");

    $(".paging a").removeClass('selected'); //Remove all active class
    $active.addClass('selected'); //Add active class (the $active is declared in the rotateSwitch function)

    //Slider Animation
    $(".image_reel").animate({
        left: -image_reelPosition
    }, 500 );

  document.getElementById('stats').innerHTML = stats_data[triggerID];

}; 

var stats_data = Array();
stats_data[0] = '<table class="stats_table"><tr><td>Strength:</td><td>28</td></tr><tr><td>Agility:</td><td>20</td></tr><tr><td>Stamina:</td><td>25</td></tr><tr><td>Energy:</td><td>10</td></tr></table>';

stats_data[1] = '<table class="stats_table"><tr><td>Strength:</td><td>18</td></tr><tr><td>Agility:</td><td>18</td></tr><tr><td>Stamina:</td><td>15</td></tr><tr><td>Energy:</td><td>30</td></tr></table>';

stats_data[2] = '<table class="stats_table"><tr><td>Strength:</td><td>22</td></tr><tr><td>Agility:</td><td>25</td></tr><tr><td>Stamina:</td><td>20</td></tr><tr><td>Energy:</td><td>15</td></tr></table>';

stats_data[3] = '<table class="stats_table"><tr><td>Strength:</td><td>21</td></tr><tr><td>Agility:</td><td>21</td></tr><tr><td>Stamina:</td><td>18</td></tr><tr><td>Energy:</td><td>23</td></tr></table>';

//Show the paging and activate its first link
$(".paging").show();
$(".paging a:first").addClass("selected");

//Get size of the image, how many images there are, then determin the size of the image reel.
var imageWidth = $(".window").width();
var imageSum = $(".image_reel img").size() + 1;
var imageReelWidth = imageWidth * imageSum;

//Adjust the image reel to its new size
$(".image_reel").css({'width' : imageReelWidth});

//On Click
$(".paging a").click(function() {
    $active = $(this); //Activate the clicked paging
    rotate(); //Trigger rotation immediately
    return false; //Prevent browser jump to link anchor
});



});


/////////////////////////fireflies effect/////////////////////////
var canvasSupported = !!window.HTMLCanvasElement;
if(canvasSupported)
{
var WIDTH;
var HEIGHT;
var canvas;
var con;
var g;
var pxs = new Array();
var rint = 40;

$(document).ready(function(){
  WIDTH = 810;
  HEIGHT = 520;
	$('#container').width(WIDTH).height(HEIGHT);
	canvas = document.getElementById('pixie');
	$(canvas).attr('width', WIDTH).attr('height',HEIGHT);
	con = canvas.getContext('2d');
	for(var i = 0; i < 16; i++) {
		pxs[i] = new Circle();
		pxs[i].reset();
	}
	setInterval(draw,rint);
});

function draw() {
	con.clearRect(0,0,WIDTH,HEIGHT);
	for(var i = 0; i < pxs.length; i++) {
		pxs[i].fade();
		pxs[i].move();
		pxs[i].draw();
	}
}

function Circle() {
	this.s = {ttl:8000, xmax:5, ymax:2, rmax:10, rt:1, xdef:960, ydef:540, xdrift:4, ydrift: 4, random:true, blink:true};

	this.reset = function() {
		this.x = (this.s.random ? WIDTH*Math.random() : this.s.xdef);
		this.y = (this.s.random ? HEIGHT*Math.random() : this.s.ydef);
		this.r = ((this.s.rmax-1)*Math.random()) + 1;
		this.dx = (Math.random()*this.s.xmax) * (Math.random() < .5 ? -1 : 1);
		this.dy = (Math.random()*this.s.ymax) * (Math.random() < .5 ? -1 : 1);
		this.hl = (this.s.ttl/rint)*(this.r/this.s.rmax);
		this.rt = Math.random()*this.hl;
		this.s.rt = Math.random()+1;
		this.stop = Math.random()*.2+.4;
		this.s.xdrift *= Math.random() * (Math.random() < .5 ? -1 : 1);
		this.s.ydrift *= Math.random() * (Math.random() < .5 ? -1 : 1);
	}

	this.fade = function() {
		this.rt += this.s.rt;
	}

	this.draw = function() {
		if(this.s.blink && (this.rt <= 0 || this.rt >= this.hl)) this.s.rt = this.s.rt*-1;
		else if(this.rt >= this.hl) this.reset();
		var newo = 1-(this.rt/this.hl);
		con.beginPath();
		con.arc(this.x,this.y,this.r,0,Math.PI*2,true);
		con.closePath();
		var cr = this.r*newo;
		g = con.createRadialGradient(this.x,this.y,0,this.x,this.y,(cr <= 0 ? 1 : cr));
		g.addColorStop(0.0, 'rgba(238,180,28,'+newo+')');
		g.addColorStop(this.stop, 'rgba(238,180,28,'+(newo*.2)+')');
		g.addColorStop(1.0, 'rgba(238,180,28,0)');
		con.fillStyle = g;
		con.fill();
	}

	this.move = function() {
		this.x += (this.rt/this.hl)*this.dx;
		this.y += (this.rt/this.hl)*this.dy;
		if(this.x > WIDTH || this.x < 0) this.dx *= -1;
		if(this.y > HEIGHT || this.y < 0) this.dy *= -1;
	}

	this.getX = function() { return this.x; }
	this.getY = function() { return this.y; }
}
}
////////////////////fireflies effect end//////////////////////////

</script>


</body>
</html>


