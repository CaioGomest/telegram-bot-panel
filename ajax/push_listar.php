<?php
declare(strict_types=1);

// GET: aparelhos do vendedor logado (sem endereço nem chaves). "endpoint_hash" (sha256 do endereço) deixa o navegador reconhecer "este aparelho".
require_once __DIR__ . '/_push.php';

[$id_usuario] = pushAjaxInicio('GET');
$aparelhos = [];
foreach (pushListarAparelhos($pdo, $id_usuario) as $a) {
    $aparelhos[] = [
        'id' => (int) $a['id'],
        'endpoint_hash' => (string) $a['endpoint_hash'],
        'plataforma' => (string) $a['plataforma'],
        'apelido' => (string) $a['apelido'],
        'pref_pix_gerado' => (bool) $a['pref_pix_gerado'],
        'pref_pix_pago' => (bool) $a['pref_pix_pago'],
        'ativo' => (bool) $a['ativo'],
        'visto_ha_s' => $a['visto_ha_s'] === null ? null : max(0, (int) $a['visto_ha_s']),
        'envio_ha_s' => $a['envio_ha_s'] === null ? null : max(0, (int) $a['envio_ha_s']),
    ];
}
pushResponder(['sucesso' => true, 'ativo' => pushAtivo(true), 'aparelhos' => $aparelhos]);
