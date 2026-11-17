<?php

$conn = mysql_connect("localhost", "root", "test") or die(mysql_error());
mysql_select_db('muonline', $conn) or die(mysql_error());

?>
