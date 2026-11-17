<?php
$page = ucwords(str_replace("_"," ",basename($_SERVER['SCRIPT_NAME'], ".php")));
if($page == 'Index')
{
 echo '<title>Web Mu Online - free browser MMORPG</title>';
 echo '<meta name="description" content="Play free multiplayer browser game based on the classic Korean MMORPG Mu Online. Programmed in PHP, Javascript, and Ajax." />';
}
else
echo '<title>Web Mu Online - '.$page.'</title>';
?>