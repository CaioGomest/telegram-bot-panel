<?php
require '/tmp/pt/common.php';
foreach (q($argv[1]) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
