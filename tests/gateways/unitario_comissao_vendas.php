<?php
declare(strict_types=1);

/**
 * A comissão da plataforma (vendas.comissao_admin) é gravada em TODO lugar que cria uma venda com cobrança: Pix do fluxo (funcoes/fluxo_blocos.php), renovação por
 * cron (cron/cron_renovacao.php) e ciclo 2+ de assinatura criado pela OmegaPayments (webhook_omegapayments.php). Teste estático (sem banco): cada INSERT tem a coluna
 * comissao_admin, o mesmo número de colunas e de valores, e o código não lê mais as colunas/tabelas do split do InfoPago (removidas em 25/10/2026).
 *   php tests/gateways/unitario_comissao_vendas.php   (sai com código 1 se algo falhar)
 */

$raiz = dirname(__DIR__, 2);
$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

/** Colunas e valores de cada "INSERT INTO vendas (...) VALUES (...)" do arquivo (junta as partes de string concatenadas em PHP). */
function insertsDeVendas(string $fonte): array
{
    $fonte = preg_replace('/"\s*\.\s*\(\$[^?]*?\?\s*\'[^\']*\'\s*:\s*\'\'\)\s*\.\s*"/', '', $fonte);   // trechos condicionais (", link_entrega" / ", ?") ficam de fora: são opcionais
    $achados = [];
    if (preg_match_all('/INSERT INTO vendas\s*\((.*?)\)\s*(?:"\s*\.[^V]*?)?VALUES\s*\((.*?)\)\s*(?:"|\')/s', $fonte, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $colunas = array_values(array_filter(array_map('trim', explode(',', preg_replace('/\s+/', ' ', $x[1])))));
            $valores = array_values(array_filter(array_map('trim', explode(',', preg_replace('/\s+/', ' ', $x[2])))));
            $achados[] = [$colunas, $valores];
        }
    }
    return $achados;
}

foreach (['funcoes/fluxo_blocos.php' => 'Pix do fluxo', 'cron/cron_renovacao.php' => 'renovação por cron', 'webhook_omegapayments.php' => 'ciclo de assinatura pela Omega'] as $arquivo => $quem) {
    $fonte = (string) file_get_contents($raiz . '/' . $arquivo);
    $inserts = insertsDeVendas($fonte);
    confere("$quem ($arquivo): tem 1 INSERT de venda", count($inserts) === 1, (string) count($inserts));
    foreach ($inserts as [$colunas, $valores]) {
        confere("$quem: o INSERT grava comissao_admin", in_array('comissao_admin', $colunas, true), implode(',', $colunas));
        confere("$quem: o número de colunas (" . count($colunas) . ") bate com o de valores (" . count($valores) . ')', count($colunas) === count($valores));
    }
}

echo PHP_EOL . "--- nada lê mais o split do InfoPago" . PHP_EOL;
$proibidos = ['split_status', 'split_valor', 'split_em', 'vendas_splits', 'usuarios_splits', 'usuario_split_id'];
$permitidos = ['admin/atualiza_banco.php' => 'migração que remove', 'admin/transacoes.php' => 'comentário'];
$leitores = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $arq) {
    $rel = str_replace('\\', '/', substr($arq->getPathname(), strlen($raiz) + 1));
    if (!preg_match('/\.(php|js|sql)$/', $rel) || preg_match('#^(tests|anotacoes|node_modules|resetar)/#', $rel) || isset($permitidos[$rel])) {
        continue;
    }
    $conteudo = (string) file_get_contents($arq->getPathname());
    foreach ($proibidos as $p) {
        if (str_contains($conteudo, $p)) {
            $leitores[] = "$rel ($p)";
        }
    }
}
confere('nenhum arquivo do painel (fora a migração) cita as colunas/tabelas removidas', $leitores === [], implode(', ', $leitores));
$transacoes = (string) file_get_contents($raiz . '/admin/transacoes.php');
confere('Admin > Transações não lê split_status, vendas_splits nem o split atual da gateway (taxa_split/chave_pix_split)', !preg_match('/split_status\s*(=|IS|FROM)|FROM vendas_splits|g\.taxa_split|g\.chave_pix_split|v\.split_/', $transacoes));
confere('Admin > Transações mostra a comissão gravada na venda (comissao_admin)', str_contains($transacoes, 'celulaComissao') && str_contains($transacoes, 'v.comissao_admin'));
$instalacao = (string) file_get_contents($raiz . '/instalacao.php') . (string) file_get_contents($raiz . '/deploy/schema.sql');
confere('instalação nova e schema.sql já nascem sem as colunas e tabelas do split antigo', !preg_match('/split_status|split_valor|split_em|vendas_splits|usuarios_splits/', $instalacao));

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
