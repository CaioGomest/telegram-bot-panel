<?php
// Roda na VPS como www-data (stdin). Fotografia das configurações globais e do gateway: hash + tamanho de cada valor (sem expor segredos),
// e o valor em claro só das chaves que não são segredo. Serve para provar que um teste de "salvar" restaurou tudo.
chdir('/var/www/painel');
require_once 'conexao.php';
$pdo = $GLOBALS['pdo'];
$claro = ['nome_sistema', 'cor_primaria', 'dominios_redirecionamento', 'url_base', 'smtp_host', 'smtp_porta', 'smtp_seguranca', 'smtp_usuario', 'email_remetente', 'email_remetente_nome', 'google_client_id', 'logo', 'favicon'];
$out = ['configuracoes' => [], 'gateways' => []];
foreach ($pdo->query("SELECT chave, valor FROM configuracoes ORDER BY chave")->fetchAll() as $l) {
    $v = (string) $l['valor'];
    $out['configuracoes'][$l['chave']] = ['sha1' => sha1($v), 'tam' => strlen($v)] + (in_array($l['chave'], $claro, true) ? ['valor' => $v] : []);
}
foreach ($pdo->query("SELECT id, nome, ativo, taxa_split, tipo_split, chave_pix_split FROM gateways ORDER BY id")->fetchAll() as $g) {
    $out['gateways'][] = ['id' => (int) $g['id'], 'nome' => $g['nome'], 'ativo' => (int) $g['ativo'], 'taxa_split' => $g['taxa_split'], 'tipo_split' => $g['tipo_split'],
        'chave_sha1' => sha1((string) $g['chave_pix_split']), 'chave_tam' => strlen((string) $g['chave_pix_split'])];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
