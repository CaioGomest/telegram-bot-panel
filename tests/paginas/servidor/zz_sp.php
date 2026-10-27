<?php
chdir(__DIR__); require_once 'conexao.php';
foreach ($pdo->query("SELECT nome,ativo,tipo_split,taxa_split,IF(IFNULL(chave_pix_split,'')='','sem chave','tem chave') chave FROM gateways") as $r) echo 'gateway: ', json_encode($r), "\n";
