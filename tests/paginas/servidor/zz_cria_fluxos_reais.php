<?php
// Cria (ou atualiza, pelo nome) os fluxos de "TESTE REAL" na conta do dono e conecta o escolhido ao bot.
// Uso (na pasta telegram/ do servidor, junto de flows_reais.php): php zz_cria_fluxos_reais.php <id_usuario> <id_bot> <id_grupo> [indice_a_conectar 0-3]
chdir(__DIR__); require_once 'conexao.php'; require_once 'flows_reais.php';
[$uid, $bot, $grupo] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0), (string) ($argv[3] ?? '')];
$conectar = (int) ($argv[4] ?? 0);
if (!$uid || !$bot || $grupo === '') { echo "uso: php zz_cria_fluxos_reais.php uid bot grupo [indice]\n"; exit(1); }
if ((int) $pdo->query("SELECT COUNT(*) FROM bots WHERE id = $bot AND id_usuario = $uid")->fetchColumn() !== 1) { echo "bot nao e desse usuario\n"; exit(1); }
$ids = [];
foreach (fluxos_reais($grupo) as $i => $f) {
    $json = json_encode($f['dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = $pdo->prepare('SELECT id FROM fluxos WHERE id_usuario = ? AND nome = ?'); $s->execute([$uid, $f['nome']]);
    $id = (int) $s->fetchColumn();
    if ($id) { $pdo->prepare('UPDATE fluxos SET modo = ?, dados_fluxograma = ? WHERE id = ?')->execute([$f['modo'], $json, $id]); }
    else { $pdo->prepare('INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?,?,?,?,?)')->execute([$uid, $f['nome'], 'Fluxo de teste com pagamento real', $f['modo'], $json]); $id = (int) $pdo->lastInsertId(); }
    $ids[$i] = $id; echo "$i => fluxo $id ({$f['modo']}) {$f['nome']}\n";
}
$pdo->prepare('UPDATE bots SET id_fluxo_conectado = ? WHERE id = ? AND id_usuario = ?')->execute([$ids[$conectar], $bot, $uid]);
echo "bot $bot conectado ao fluxo {$ids[$conectar]}\n";
