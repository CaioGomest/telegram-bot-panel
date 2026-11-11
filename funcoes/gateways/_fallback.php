<?php
declare(strict_types=1);

require_once __DIR__ . '/_registro.php';
require_once __DIR__ . '/_falhas.php';

/**
 * Preço mínimo que o gateway aceita agora (com o split da plataforma). 0 = sem mínimo conhecido. Vem de 'preco_minimo' no .meta.php do gateway.
 */
function gatewayPrecoMinimo(string $nome, ?array $split): float {
    $gateway = registroGateway($nome);
    $minimo = $gateway['preco_minimo'] ?? null;
    return is_callable($minimo) ? (float) $minimo($split) : 0.0;
}

/**
 * Gera o Pix pelo primeiro gateway do usuário que conseguir, na ordem escolhida por ele (usuarios_gateways.prioridade; getUserGateways já devolve
 * ordenado). Gateway que falha (erro da API, timeout, exceção, resposta sem código Pix) ou que não serve para esta cobrança (credencial incompleta, não faz
 * Pix recorrente, valor abaixo do mínimo dele) é pulado e vale o próximo — o cliente do bot nunca fica sabendo.
 *
 * $pedido: valor, eh_recorrente, periodicidade, id_produto, nome_produto, expiracao_segundos, nome_usuario, documento, id_dono.
 *
 * @return array{sucesso: bool, resp: array, gateway: ?array, split_data: ?array, pix_copia_cola: string, txid: string, id_assinatura: ?string, recorrente_oficial: bool, tentativas: string[]}
 */
function gerarPixComFallback(array $gateways, array $pedido): array {
    $resultado = [
        'sucesso' => false, 'resp' => ['sucesso' => false], 'gateway' => null, 'split_data' => null,
        'pix_copia_cola' => '', 'txid' => '', 'id_assinatura' => null, 'recorrente_oficial' => false, 'tentativas' => [],
    ];
    $valor = (float) $pedido['valor'];
    $recorrente = !empty($pedido['eh_recorrente']);
    $falhas_api = [];   // gateways que responderam erro/caíram (não conta quem foi só pulado por configuração)
    $ids_gateway = [];  // nome => id do gateway, para gravar a falha em gateway_falhas

    foreach ($gateways as $gw) {
        $nome = (string) ($gw['gateway_nome'] ?? '');
        $ids_gateway[$nome] = (int) ($gw['gateway_id'] ?? 0);

        if (registroGateway($nome) === null) {
            $resultado['tentativas'][] = "Provedor $nome não suportado";
            continue;
        }
        if ($recorrente && ($gw['tipo_conta'] ?? 'pj') === 'pf') {
            $resultado['tentativas'][] = "Gateway {$nome} é conta PF, não suporta PIX Recorrente";
            continue;
        }
        if ($recorrente && empty(registroGateway($nome)['aceita_recorrencia'])) {
            $resultado['tentativas'][] = "Gateway {$nome} não suporta PIX Recorrente";
            continue;
        }
        if (!gatewayCredenciaisCompletas($nome, $gw)) {
            $resultado['tentativas'][] = "Gateway {$nome} não configurado completamente";
            continue;
        }
        $provedor = resolveGatewayProvider($nome, $gw);
        if (!$provedor) {
            $resultado['tentativas'][] = "Provedor $nome não suportado";
            continue;
        }

        garantirConexaoBanco();   // a tentativa anterior pode ter levado mais que o wait_timeout (20 s) do MySQL
        $split_data = null;
        $split_gateway = getGatewaySplit($nome);
        if ($split_gateway) {
            $split_data = [[
                'chave' => $split_gateway['chave_pix_split'],
                'valor' => $split_gateway['taxa_split'],
                'tipo'  => $split_gateway['tipo_split'] ?? 'percentual',
            ]];
        }
        $minimo = gatewayPrecoMinimo($nome, $split_gateway);
        if ($valor + 0.00001 < $minimo) {
            $resultado['tentativas'][] = "Gateway {$nome} não aceita Pix abaixo de R$ " . number_format($minimo, 2, ',', '.');
            continue;
        }

        try {
            if ($recorrente) {
                $payload = $provedor->montaPayloadAssinatura($valor, strtolower((string) ($pedido['periodicidade'] ?? 'mensal')), (string) $pedido['id_produto'], (string) $pedido['nome_produto'], (string) $pedido['nome_usuario'], (string) $pedido['documento'], $split_data);
                $resp = $provedor->criarAssinatura($payload);
            } else {
                $payload = $provedor->montaPayloadCobranca($valor, (string) ($gw['chave_pix'] ?? ''), $split_data, (int) $pedido['expiracao_segundos'], (string) $pedido['nome_usuario'], (string) $pedido['documento']);
                $resp = $provedor->criarCobranca($payload);
            }
            if (!($resp['sucesso'] ?? false)) {
                $erro = (string) ($resp['erro'] ?? 'desconhecido');
                $resultado['tentativas'][] = "{$nome} " . ($recorrente ? 'criarAssinatura' : 'criarCobranca') . " falhou: " . $erro;
                $falhas_api[$nome] = $erro;
                continue;
            }
            $pix_copia_cola = (string) ($resp['dados']['pixCopiaECola'] ?? '');
            $txid = (string) ($resp['dados']['txid'] ?? '');
            if ($pix_copia_cola === '' || $txid === '') {
                $resultado['tentativas'][] = "{$nome} respondeu sem o código Pix ou sem o identificador da transação";
                $falhas_api[$nome] = 'resposta sem código Pix';
                continue;
            }
        } catch (\Throwable $e) {
            $resultado['tentativas'][] = "{$nome} causou exceção: " . $e->getMessage();
            $falhas_api[$nome] = 'exceção';
            continue;
        }

        $resultado['sucesso'] = true;
        $resultado['resp'] = $resp;
        $resultado['gateway'] = $gw;
        $resultado['split_data'] = $split_data;
        $resultado['pix_copia_cola'] = $pix_copia_cola;
        $resultado['txid'] = $txid;
        $resultado['recorrente_oficial'] = $recorrente;
        $resultado['id_assinatura'] = $recorrente ? ($resp['dados']['subscriptionId'] ?? null) : null;
        break;
    }

    if (!$resultado['sucesso']) {
        $resultado['resp'] = ['sucesso' => false, 'erro' => implode(' | ', $resultado['tentativas']) ?: 'Nenhum gateway disponível'];
    }
    if ($falhas_api) {
        $id_dono = (int) ($pedido['id_dono'] ?? 0);
        $para_registro = [];
        foreach ($falhas_api as $nome => $erro) {
            $para_registro[] = ['id_gateway' => $ids_gateway[$nome] ?? 0, 'motivo' => $erro];
        }
        registrarFalhasGateway($id_dono, $para_registro, $resultado['txid']);   // txid vazio = nenhum gateway atendeu
        if ($resultado['sucesso']) {
            avisarFallbackUsado($id_dono, $falhas_api, (string) $resultado['gateway']['gateway_nome']);
        } else {
            avisarSemGatewayDisponivel($id_dono, $falhas_api);
        }
    }
    return $resultado;
}

/**
 * O gateway principal caiu mas outro atendeu: o dono é avisado no sino (no máximo 1 aviso por hora por usuário, para não lotar quando o gateway
 * fica fora do ar) e o motivo vai para logs/vendas_debug.log. Nunca lança.
 */
function avisarFallbackUsado(int $id_dono, array $falhas_api, string $usado): void {
    $resumo = [];
    foreach ($falhas_api as $nome => $erro) {
        $resumo[] = "$nome (" . mb_substr($erro, 0, 80) . ')';
    }
    @file_put_contents(dirname(__DIR__, 2) . '/logs/vendas_debug.log', '[' . date('Y-m-d H:i:s') . "] FALLBACK DE GATEWAY (dono id_usuario={$id_dono}): " . implode('; ', $resumo) . " -> gerado por $usado\n", FILE_APPEND);
    registrarAvisoGatewayLimitado($id_dono, 'Gateway com falha', 'O Pix não saiu por ' . implode(', ', array_keys($falhas_api)) . "; foi gerado por $usado. Confira as credenciais do gateway que falhou.");
}

/** Nenhum gateway do usuário conseguiu gerar o Pix (o cliente do bot recebeu "não foi possível gerar"): aviso no sino, no máximo 1 por hora. */
function avisarSemGatewayDisponivel(int $id_dono, array $falhas_api): void {
    registrarAvisoGatewayLimitado($id_dono, 'Pix não gerado', 'Nenhum gateway conseguiu gerar o Pix (' . implode(', ', array_keys($falhas_api)) . '). O cliente do bot não recebeu o código. Confira as credenciais em Gateways.');
}

/** Aviso de gateway no sino do dono, com no máximo 1 por título por hora (gateway fora do ar não pode lotar o sino). Nunca lança. */
function registrarAvisoGatewayLimitado(int $id_dono, string $titulo, string $texto): void {
    if ($id_dono <= 0) {
        return;
    }
    try {
        global $pdo;
        require_once __DIR__ . '/../log.php';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM atividades WHERE id_usuario = ? AND titulo = ? AND criado_em > ?');
        $stmt->execute([$id_dono, $titulo, date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $stmt->fetchColumn() === 0) {
            registrarAtividade($id_dono, 'sistema', $titulo, $texto);
        }
    } catch (\Throwable $e) {
        error_log('[gateway] aviso: ' . $e->getMessage());
    }
}

/**
 * Menor preço de Pix que ALGUM gateway usável do usuário aceita: o editor não deixa salvar plano/pack/oferta que nenhum deles geraria (com o fallback, o
 * plano só precisa servir a um gateway da fila). Sem nenhum gateway configurado, vale o da OmegaPayments, como sempre foi.
 */
function precoMinimoPixDoUsuario(int $id_usuario): float {
    $minimos = [];
    foreach (getUserGateways($id_usuario, true) as $gw) {
        $nome = (string) ($gw['gateway_nome'] ?? '');
        if (gatewayCredenciaisCompletas($nome, $gw)) {
            $minimos[] = gatewayPrecoMinimo($nome, getGatewaySplit($nome));
        }
    }
    return $minimos ? min($minimos) : precoMinimoOmegaPayments(getGatewaySplit('omegapayments'));
}
