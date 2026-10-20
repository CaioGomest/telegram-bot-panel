<?php
// Somente leitura, roda na homologacao via stdin. Devolve os fluxos do usuario 36 no formato de exportacao do painel.
chdir(getenv('HOME') . '/domains/stackcode.com.br/public_html/telegram');
require_once 'conexao.php';
$pdo = $GLOBALS['pdo'];
$ids = [1262, 1263, 1264, 1265];
$out = [];
foreach ($ids as $id) {
    $st = $pdo->prepare("SELECT id, nome, descricao, link_suporte, modo, dados_fluxograma, atualizado_em FROM fluxos WHERE id = ? AND id_usuario = 36");
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) continue;
    $out[] = [
        'versao' => 1,
        'modo' => ($f['modo'] ?? 'avancado') === 'basico' ? 'basico' : 'avancado',
        'id_origem' => (int)$f['id'],
        'nome' => (string)$f['nome'],
        'descricao' => (string)($f['descricao'] ?? ''),
        'link_suporte' => (string)($f['link_suporte'] ?? ''),
        'dados_fluxograma' => json_decode($f['dados_fluxograma'] ?? '{}', true),
        'atualizado_em' => $f['atualizado_em'],
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
