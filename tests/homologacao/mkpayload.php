<?php
// Gera o corpo JSON de salvar_fluxo a partir de um fluxo de flows.php. Uso: php mkpayload.php NOME ['patch'] [nome] [id]
require '/tmp/pt/flows.php';
function mescla(array $base, array $patch): array {
    foreach ($patch as $k => $v) {
        if (substr((string) $k, -1) === '!') { $base[substr($k, 0, -1)] = $v; continue; }
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && array_keys($v) !== range(0, count($v) - 1)) { $base[$k] = mescla($base[$k], $v); }
        else { $base[$k] = $v; }
    }
    return $base;
}
[$modo, $dados] = ($argv[1])();
if (!empty($argv[2])) $dados = mescla($dados, json_decode($argv[2], true) ?: []);
$corpo = ['nome' => $argv[3] ?? 'Fluxo API', 'descricao' => 'via API', 'modo' => $modo, 'dados_fluxograma' => $dados];
if (!empty($argv[4])) $corpo['id'] = (int) $argv[4];
echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
