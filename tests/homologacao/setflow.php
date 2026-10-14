<?php
// Grava um fluxo de teste (flows.php) no bot de teste e o conecta. Uso: php setflow.php NOME ['{"patch":"json"}']
require '/tmp/pt/common.php';
require '/tmp/pt/flows.php';
$nome = $argv[1] ?? '';
if (!function_exists($nome) || !in_array($nome, FLUXOS_DISPONIVEIS, true)) { echo "fluxo desconhecido: $nome\n"; exit(1); }
[$modo, $dados] = $nome();
function mescla(array $base, array $patch): array {
    foreach ($patch as $k => $v) {
        if (substr((string) $k, -1) === '!') { $base[substr($k, 0, -1)] = $v; continue; }
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && array_keys($v) !== range(0, count($v) - 1)) { $base[$k] = mescla($base[$k], $v); }
        else { $base[$k] = $v; }
    }
    return $base;
}
if (!empty($argv[2])) { $dados = mescla($dados, json_decode($argv[2], true) ?: []); }
$i = ids();
$id = (int) q1("SELECT id FROM fluxos WHERE id_usuario = ? AND nome = 'HOMOLOG TESTE'", [$i['uid']]);
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($id) {
    $pdo->prepare("UPDATE fluxos SET modo = ?, dados_fluxograma = ? WHERE id = ?")->execute([$modo, $json, $id]);
} else {
    $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?, 'HOMOLOG TESTE', 'teste', ?, ?)")->execute([$i['uid'], $modo, $json]);
    $id = (int) $pdo->lastInsertId();
}
$pdo->prepare("UPDATE bots SET id_fluxo_conectado = ? WHERE id = ?")->execute([$id, $i['bot']]);
$i['fluxo'] = $id; file_put_contents('/tmp/pt/ids.json', json_encode($i));
echo "fluxo $nome ($modo) gravado id=$id\n";
