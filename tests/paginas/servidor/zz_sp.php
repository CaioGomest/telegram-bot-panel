<?php
chdir(__DIR__); require_once 'conexao.php';
foreach ($pdo->query("SELECT nome,ativo,tipo_split,taxa_split,IF(IFNULL(chave_pix_split,'')='','sem chave','tem chave') chave FROM gateways") as $r) echo 'gateway: ', json_encode($r), "\n";
foreach ($pdo->query("SELECT gateway_nome,tipo_split,taxa_split,IF(chave_pix_split='','sem chave','tem chave') chave,descricao FROM usuarios_splits WHERE id_usuario=36") as $r) echo 'split do usuario 36: ', json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
