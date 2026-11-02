<?php
// Imprime os CREATE TABLE de esquemaRedirecionamento() separados por ";;" (usado pelo rodar.js para comparar com o deploy/schema.sql).
require_once __DIR__ . '/../../funcoes/redirecionadores.php';
echo implode("\n;;\n", esquemaRedirecionamento());
