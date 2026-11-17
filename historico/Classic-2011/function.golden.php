<?php

session_start();
if(!isset($_SESSION['char_name']))
exit('error no char');

include("database.php");
global $conn;

$query = 'SELECT * FROM golden';
$result = mysql_query($query, $conn) or trigger_error("SQL", E_USER_ERROR);


echo '<br/>GOLDEN INVASION STATUS<br/>-----------------------------------<br/>';

date_default_timezone_set('UTC');
$cur_time = time();
$cur_hour = (int)date("H",$cur_time);

if(mysql_num_rows($result)>0)
{
 echo '<table width="100%" id="golden_table">';
while($row = mysql_fetch_assoc($result))
{
 $monster = ucwords(str_replace('_',' ',$row["monster"]));
 $map = ucwords(str_replace('_',' ',$row["map"]));
 $defeated = ucwords(str_replace('_',' ',$row["defeated"]));
 $encounter = $row["encounter"];

 if(($cur_hour == 0 || $cur_hour == 8 || $cur_hour == 16) && (time() - $encounter) > (60*60)) //active
  $second_td = '<td class="green">'.$map.'</td>';
 else if((time() - $encounter) > (60*60*9)) //was defeated longer than 9 hours ago
  $second_td = '<td>???</td>';
 else
  $second_td = '<td>'.$defeated.'</td>';

 echo '<tr><td width="50%" class="yellow">'.$monster.'</td>'.$second_td.'</tr>';
}
echo '</table>';
}
else
echo 'empty table';


?>