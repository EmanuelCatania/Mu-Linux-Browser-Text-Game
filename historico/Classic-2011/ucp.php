<?php
session_start();
$login_not_required = 1;
include('function.login.php');

if(!isset($_GET['action']))
exit('error');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN">
<html>
<head>
<?php include('function.title.php'); ?>
<link href="common.css" rel="stylesheet" type="text/css" />
<link href="ucp.css" rel="stylesheet" type="text/css" />

<script src="jquery.js" type="text/javascript"></script>
<script src="jquery.alerts.js" type="text/javascript"></script>
<script type="text/javascript" src="overlib.js"></script>

</head>
<body>
<table class = "parent_table" cellspacing="0">
	<tr>
		<td colspan="2" class="parent_table_top"><div class="parent_table_top_ins"><?php include('top_navigation.php'); ?></div></td>
	</tr>
<tr><td class="parent_table_left"><?php include('navigation.php'); ?></td><td class="parent_table_middle">
<div class="div_border">
<div id="ucp_back"></div>
<table class="main_table" cellspacing="0">

<tr><td id="main_table_td2">

<table cellspacing="1" class="ucp_table"><tr><td id="ucp_title">

<div id="ucp_title_ins">
<?php
if($_GET['action']=='login' || $_GET['action']=='login_error' || $_GET['action']=='login_success')
 echo "LOGIN";
else if($_GET['action']=='register' || $_GET['action']=='register_error' || $_GET['action']=='register_taken' || $_GET['action']=='register_success')
 echo "REGISTRATION";
else if($_GET['action']=='logout')
 echo "LOGOUT";
else if($_GET['action']=='defeat')
 echo "You have been defeated";
else if($_GET['action']=='spotfull')
 echo "Spot is overcrowded";
?>

</div></td></tr><tr><td><div id="ucp_main">

<?php
if($_GET['action']=='login' || $_GET['action']=='login_error')
{
if($_GET['action']=='login_error')
echo '<br/><a class="red">Incorrect username or password.</a><br/>';
else
echo '<br/><br/>';

echo <<<EOT
<form method="post" name="cookie" action="process.php?action=login"> 
<table class="form_table"><tr><td>
Username :</td><td><input type="text" name="username" id="username" maxlength="20" />
</td></tr><tr><td>
Password :</td><td><input type="password" name="password" id="password" maxlength="20" />
</td></tr>

<tr><td colspan="2" style="text-align:center;">
<input type="checkbox" name="setcookie" value="setcookie" /> Remember Me
</td></tr>

<tr><td colspan="2" style="text-align:center;">
<input type="submit" name="submit" value="Submit" /> <input type="reset" name="reset" value="Reset" />
</td></tr></table>
</form>
EOT;
}
else if($_GET['action']=='register' || $_GET['action']=='register_error' || $_GET['action']=='register_taken')
{
if($_GET['action']=='register_error')
echo '<br/><a class="red">Incorrect data provided.</a><br/>';
else if($_GET['action']=='register_taken')
echo '<br/><a class="red">This useraname is already taken.</a><br/>';
else
echo '<br/><br/>';

echo <<<EOT
<form method="post" name="cookie" action="process.php?action=register"> 
<table class="form_table"><tr><td>
Username :</td><td><input type="text" name="username" id="username" maxlength="20" />
</td></tr><tr><td>
Password :</td><td><input type="password" name="password" id="password" maxlength="20" />
</td></tr><tr><td>
Password (again):</td><td><input type="password" name="password2" id="password2" maxlength="20" />
</td></tr><tr><td>
Email :</td><td><input type="text" name="email" id="email" maxlength="20" />
</td></tr><tr><td colspan="2" style="text-align:center;">
<input type="submit" name="submit" value="Submit" />&nbsp;<input type="reset" name="reset" value="Reset" />
</td></tr></table>
</form>
EOT;
}
else if($_GET['action']=='logout')
{

 if(isset($_SESSION['char_name']))
 {
  $old_char = $_SESSION['char_name'];
  $query = "DELETE FROM online WHERE char_name='$old_char'";
  if(!mysql_query($query, $conn))
  exit('mysql_error');
 }
session_unset(); 
session_destroy();

if(isset($_COOKIE['mu_username']) && isset($_COOKIE['mu_password']))
{
   setcookie("mu_username", "", time() - 604800); // one week in the past (?)
   setcookie("mu_password", "", time() - 604800);
}

echo '<br/><br/>You have been logged out.';

if(isset($_GET['error']))
echo '<br/><br/>Another user is logged in already<br/>with IP '.$_SERVER['REMOTE_ADDR'];

}
else if($_GET['action']=='login_success')
{
echo '<br/><br/>You have been logged in.'; 
}
else if($_GET['action']=='register_success')
{
echo '<br/><br/>Your account is registered.<br/>You may now log in.'; 
}

else if($_GET['action']=='defeat')
{
 echo '<img width="336px" height="256px" src="images/defeat.jpg"/>';
}

else if($_GET['action']=='spotfull')
{
 echo '<img width="336px" height="256px" src="images/spotfull.jpg"/>';
}
?>

</div></td></tr><tr><td id="js_output">

<?php
if($_GET['action']=='defeat')
 echo "Restore your HP before returning into the battle.";
else if($_GET['action']=='spotfull')
 echo "You cannot enter a spot which has 10 characters already.";
else
 echo "Type in your data";
?>

</td></tr></table>
</td></tr></table>
</div>
</td></tr></table>
</body>
</html>