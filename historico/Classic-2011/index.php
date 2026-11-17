<?php
session_start();
$login_not_required = 1;
include('function.login.php');

$navigation = 'news';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN">
<html>
<head>
<?php include('function.title.php'); ?>
<link href="common.css" rel="stylesheet" type="text/css" />
<link href="ucp.css" rel="stylesheet" type="text/css" />

<script src="jquery.js" type="text/javascript"></script>
<script type="text/javascript" src="overlib.js"></script>

</head>
<body>
<table class = "parent_table" cellspacing="0">
	<tr>
		<td colspan="2" class="parent_table_top"><div class="parent_table_top_ins"><?php include('top_navigation.php'); ?></div></td>
	</tr>
<tr><td class="parent_table_left"><?php include('navigation.php'); ?></td><td class="parent_table_middle">
<div class="div_border">

<table cellspacing="0" class="about_table" id="about1">
<tr><td><b>TriperMU Web (Season 1 Episode 2 - Jun 18, 2015)</b><hr/>

<div id="mu_logo"><img src="images/mu_online.png" width="132px" height="180px" /></div>

<b>TriperMu Online Browser</b> is an independent experimental web browser multiplayer game, based on the classic MMORPG <a href="http://muonline.webzen.com">[Mu Online]</a> by Korean company Webzen. The project's goal is to convert all main concepts and mechanics of the game into a standalone light-weight web browser RPG.
<br/><br/>
Supported browsers: IE, Chrome, Firefox, Opera, Safari.
<br/><br/>
Discussion/feedback: <a href="http://tripermu.mmoasia.ml/forum">TriperMU Forum</a>
</td>
<td>

<?php
echo '<div id="navigation1">TOP 10 [level]</div><div id="top10"><ul>';
$query = "SELECT * FROM characters ORDER BY experience DESC LIMIT 0, 10"; //actual result
$result3 = mysql_query($query,$conn);
$pos = 1;
if(mysql_num_rows($result3)>0)
{
 while($row = mysql_fetch_assoc($result3))
 {
  $tmp_name = $row['name'];
  $tmp_level = $row['level'];
  if($pos != mysql_num_rows($result3))
  echo "<li>".$pos.". ".$tmp_name."<div class='top10level'>".$tmp_level."</div></li>";
  else
  echo "<li style='border-bottom:none;'>".$pos.". ".$tmp_name."<div class='top10level'>".$tmp_level."</div></li>";
  $pos +=1;
 }
}
echo "</ul><div id='rank_all'>[<a href='ranking.php'>view all</a>]</div></div>";
?>

</td>
</tr></table>

<table cellspacing="0" class="about_table" id="about2">
<tr><td><b>GAME SCREENSHOTS</b><hr/>

<div class="screenshot" id="mu1"><a  class="screenshot1" href="images/mu1.jpg"><img src="images/mu1t.jpg" alt="Mu Online 1" title="Mu Online screenshot 1" /></a><hr/>character creation</div>
<div class="screenshot" id="mu2"><a class="screenshot1" href="images/mu2.jpg"><img src="images/mu2t.jpg" alt="Mu Online 2" title="Mu Online screenshot 2" /></a><hr/>inventory</div>
<div class="screenshot" id="mu3"><a class="screenshot1" href="images/mu3.jpg"><img src="images/mu3t.jpg" alt="Mu Online 3" title="Mu Online screenshot 3" /></a><hr/>Chaos Machine</div>
<div class="screenshot" id="mu4"><a class="screenshot1" href="images/mu4.jpg"><img src="images/mu4t.jpg" alt="Mu Online 4" title="Mu Online screenshot 4" /></a><hr/>battle screen</div>

</td></tr></table>

<div id="about_footer">TriperMu Online Browser - 2015</div>

</div>
</td></tr></table>

<script type="text/javascript">
jQuery(document).ready(function($) {
    $('.screenshot1').click(function(e) {
        e.preventDefault();
        var image_href = $(this).attr("href");
        if ($('#lightbox').length > 0) {
            $('#lightbox_content').html('<img src="' + image_href + '" />');
            $('#lightbox').show();
        }
        else {
            var lightbox = '<div id="lightbox">' + '<div id="lightbox_content">' + '<img src="' + image_href +'" />' + '</div>' + '</div>';
            $('body').append(lightbox);
        }
    });

    $('#lightbox').live('click', function() {
        $('#lightbox').hide();
    });
});
</script>

</body>
</html>
