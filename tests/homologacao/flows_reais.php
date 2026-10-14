<?php
// Fluxos de TESTE PARA PAGAR DE VERDADE (conta caiogomesteodoro, bot real), em vários cenários. Cada função devolve [nome, modo, dados].
// Usado por: tests/homologacao/g8.sh (com pagamento e grupo simulados) e por zz_cria_fluxos_reais.php (cria na conta real).
// Preços no mínimo que a OmegaPayments aceita hoje com o split atual (R$ 2,10); ofertas/bônus nunca deixam o Pix abaixo disso.

const PRECO_TESTE = 2.10;

// ---------- Guiado ----------
function fr_guiado_base(string $grupo, string $nome, string $boas_vindas, array $planos, array $extra = []): array
{
    $dados = [
        'ativo' => true,
        'suporte' => '',
        'suporte_cor' => '',
        'cache_chat_id' => '',
        'boas_vindas' => ['mensagem' => $boas_vindas, 'midia_tipo' => 'none', 'midia_path' => '', 'texto_cta' => 'Ver os planos de teste', 'cor_cta' => 'success'],
        'planos' => $planos,
        'ofertas' => [
            'upsell' => ['ativo' => false, 'id_plano_destino' => '', 'desconto_percentual' => 0, 'mensagem' => '', 'texto_aceitar' => 'Quero', 'cor_aceitar' => 'success', 'texto_recusar' => 'Agora não', 'cor_recusar' => ''],
            'downsell' => ['ativo' => false, 'id_plano_destino' => '', 'desconto_percentual' => 0, 'mensagem' => '', 'texto_aceitar' => 'Aceito', 'cor_aceitar' => 'primary', 'texto_recusar' => 'Não, obrigado', 'cor_recusar' => ''],
            'order_bump' => ['ativo' => false, 'nome' => 'Bônus', 'valor_extra' => 1.0, 'mensagem' => '', 'texto_aceitar' => 'Adicionar', 'cor_aceitar' => 'success', 'texto_recusar' => 'Sem o bônus', 'cor_recusar' => ''],
        ],
        'previas' => ['ativo' => false, 'texto_botao' => '🎬 Aula grátis', 'midias' => [], 'apagar_apos' => 30, 'rever' => 'diario', 'proteger' => false, 'mensagem_junto' => '', 'mensagem_depois' => '', 'botao_planos' => true],
        'packs' => ['ativo' => false, 'texto_botao' => '📦 Aulas avulsas', 'mensagem' => 'Escolha uma aula:', 'itens' => []],
        'assinatura' => [
            'avisos_antes' => ['ativo' => false, 'dias' => [5, 2, 0], 'midia_tipo' => 'none', 'midia_path' => '', 'mensagem' => ''],
            'avisos_no_dia' => ['ativo' => false, 'horarios' => ['10:00', '20:00']],
            'ao_expirar' => ['ativo' => false, 'midia_tipo' => 'none', 'midia_path' => '', 'mensagem' => ''],
            'renovacao' => ['desconto' => 10],
            'acoes' => ['expulsar' => true],
        ],
        'pagamentos' => ['msg_instrucoes' => '', 'msg_confirmado' => '✅ Pagamento aprovado! (' . $nome . ') Se o plano tem grupo, o link de entrada vem logo abaixo.', 'mostrar_copiar' => true, 'mostrar_qrcode' => true, 'qrcode_definido' => true, 'mostrar_confirmar' => true],
    ];
    foreach ($extra as $k => $v) {
        $dados[$k] = is_array($v) && isset($dados[$k]) && is_array($dados[$k]) ? array_replace_recursive($dados[$k], $v) : $v;
    }
    return ['nome' => $nome, 'modo' => 'basico', 'dados' => $dados];
}

/** 1) Planos (com e sem grupo, de 30 min a vitalício), ofertas encadeadas e packs. Avisos genéricos (sem a aba Assinatura). */
function fr_guiado_planos(string $grupo): array
{
    $p = static fn(string $id, string $nome, int $dias, string $un, string $g, string $cor = '') => ['id' => $id, 'nome' => $nome, 'valor' => PRECO_TESTE, 'dias_acesso' => $dias, 'unidade_acesso' => $un, 'id_grupo' => $g, 'cor' => $cor];
    return fr_guiado_base($grupo, 'TESTE REAL 1 · Guiado — planos, ofertas e packs com grupo',
        "🧪 *TESTE REAL 1 — Guiado*\nCada plano custa R$ 2,10 e testa uma situação:\n1) 30 minutos com grupo (vence e remove rápido)\n2) Mensal com grupo\n3) Vitalício com grupo\n4) Sem grupo (controle: paga e não recebe link)\n\nToque abaixo para escolher.",
        [
            $p('t1_30min', '1) 30 minutos · com grupo', 30, 'minutos', $grupo, 'primary'),
            $p('t1_mensal', '2) Mensal · com grupo', 30, 'dias', $grupo, 'success'),
            $p('t1_vital', '3) Vitalício · com grupo', 36500, 'dias', $grupo),
            $p('t1_semgrupo', '4) Sem grupo · controle', 30, 'dias', '', 'danger'),
            ['id' => 't1_trim', 'nome' => '5) Trimestral · alvo do upsell', 'valor' => 2.10 * 2, 'dias_acesso' => 90, 'unidade_acesso' => 'dias', 'id_grupo' => $grupo, 'cor' => ''],
        ],
        [
            'ofertas' => [
                // upsell leva ao trimestral (R$ 4,20 − 15% = R$ 3,57); downsell leva ao plano de 30 min (R$ 2,10); bônus soma R$ 1,00
                'upsell' => ['ativo' => true, 'id_plano_destino' => 't1_trim', 'desconto_percentual' => 15, 'mensagem' => '🔝 Leve o TRIMESTRAL com 15% de desconto?', 'texto_aceitar' => 'Quero o trimestral', 'texto_recusar' => 'Fico no que escolhi'],
                'downsell' => ['ativo' => true, 'id_plano_destino' => 't1_30min', 'desconto_percentual' => 0, 'mensagem' => '⬇️ Quer começar pelo plano de 30 minutos?', 'texto_aceitar' => 'Começar por 30 min', 'texto_recusar' => 'Não, obrigado'],
                'order_bump' => ['ativo' => true, 'nome' => 'Bônus de teste', 'valor_extra' => 1.0, 'mensagem' => '🎁 Adicione o bônus por só R$ 1,00?'],
            ],
            'packs' => ['ativo' => true, 'texto_botao' => '📦 Aulas avulsas', 'mensagem' => 'Escolha uma aula:', 'itens' => [
                ['id' => 'k1', 'ativo' => true, 'emoji' => '📷', 'nome' => 'Aula 1 · com grupo', 'preco' => PRECO_TESTE, 'descricao' => 'Pack com entrega no grupo.', 'midia_tipo' => 'none', 'midia_path' => '', 'id_grupo' => $grupo],
                ['id' => 'k2', 'ativo' => true, 'emoji' => '🎧', 'nome' => 'Aula 2 · sem grupo', 'preco' => PRECO_TESTE, 'descricao' => 'Pack sem grupo (controle).', 'midia_tipo' => 'none', 'midia_path' => '', 'id_grupo' => ''],
            ]],
        ]);
}

/** 2) Assinatura: avisos antes/no dia, mensagem ao expirar, renovação com desconto, carência. (Para testar o tempo passando, o dono pede para adiantar o vencimento.) */
function fr_guiado_assinatura(string $grupo): array
{
    return fr_guiado_base($grupo, 'TESTE REAL 2 · Guiado — assinatura, avisos e renovação',
        "🧪 *TESTE REAL 2 — Assinatura*\nPlanos de R$ 2,10 com grupo. Aqui valem os avisos de vencimento (5, 2 e 0 dias), a mensagem ao expirar, a renovação com 10% de desconto e a carência de 2 dias antes da remoção.",
        [
            ['id' => 't2_sem', 'nome' => 'Semanal · 7 dias com grupo', 'valor' => PRECO_TESTE, 'dias_acesso' => 7, 'unidade_acesso' => 'dias', 'id_grupo' => $grupo, 'cor' => 'primary'],
            ['id' => 't2_men', 'nome' => 'Mensal · 30 dias com grupo', 'valor' => PRECO_TESTE, 'dias_acesso' => 30, 'unidade_acesso' => 'dias', 'id_grupo' => $grupo, 'cor' => 'success'],
        ],
        ['assinatura' => [
            'avisos_antes' => ['ativo' => true, 'dias' => [5, 2, 0], 'mensagem' => '{saudacao}, {nome}! Seu plano {plano} expira {prazo} ({data_expiracao}). Renove para não perder o acesso.'],
            'avisos_no_dia' => ['ativo' => true, 'horarios' => ['10:00', '20:00']],
            'ao_expirar' => ['ativo' => true, 'mensagem' => '{nome}, seu plano {plano} acabou. Renove agora com 10% de desconto!'],
            'renovacao' => ['desconto' => 10],
            'acoes' => ['expulsar' => true],
        ]]);
}

// ---------- Visual (n8n) ----------
function fr_op(string $id, string $tipo, string $titulo, int $col, int $lin, array $props = [], array $saidas = ['output_1']): array
{
    $outs = [];
    foreach ($saidas as $s) {
        $outs[$s] = ['label' => $s];
    }
    return [$id, ['top' => 40 + $lin * 170, 'left' => 30 + $col * 290, 'properties' => ['inputs' => ['input_1' => ['label' => 'Entrada']], 'outputs' => $outs, 'class' => 'no-' . $tipo, 'title' => $titulo, 'type' => $tipo] + $props]];
}
function fr_fluxo(string $nome, array $ops, array $links): array
{
    $operators = [];
    foreach ($ops as [$id, $op]) {
        $operators[$id] = $op;
    }
    $l = [];
    foreach ($links as [$de, $con, $para]) {
        $l[] = ['fromOperator' => $de, 'fromConnector' => $con, 'toOperator' => $para, 'toConnector' => 'input_1'];
    }
    return ['nome' => $nome, 'modo' => 'avancado', 'dados' => ['operators' => $operators, 'links' => $l]];
}
function fr_msg(string $id, string $titulo, int $col, int $lin, string $texto): array { return fr_op($id, 'message', $titulo, $col, $lin, ['conteudo' => $texto]); }
function fr_pix(string $id, string $titulo, int $col, int $lin, string $grupo, array $extra = []): array
{
    return fr_op($id, 'pix', $titulo, $col, $lin, $extra + [
        'nome' => 'Produto teste', 'valor' => PRECO_TESTE, 'tipo_cobranca' => 'unica', 'expiracao_minutos' => 15, 'dias_acesso' => 30, 'unidade_acesso' => 'dias',
        'mostrar_copiar' => true, 'mostrar_qrcode' => true, 'mostrar_confirmar' => true, 'msg_instrucoes' => '', 'msg_confirmado' => '', 'id_grupo' => $grupo,
    ], ['output_pago', 'output_nao_pago']);
}

/** 3) Menu de cenários do Editor Visual: Pix único com grupo, ofertas encadeadas + Pix, esperas (delay) e sorteio. */
function fr_visual_menu(string $grupo): array
{
    return fr_fluxo('TESTE REAL 3 · n8n — menu de cenários (Pix, ofertas, delay, sorteio)', [
        fr_op('start', 'start', 'Início', 0, 0),
        fr_msg('oi', 'Boas-vindas', 1, 0, "🧪 TESTE REAL 3 — Editor Visual\nCada caminho abaixo custa no máximo alguns reais e testa uma situação. Escolha:"),
        fr_op('menu', 'botoes', 'Menu', 2, 0, ['texto' => 'Qual cenário você quer testar?', 'botoes' => [['texto' => '1 · Pix único + grupo', 'cor' => 'success'], ['texto' => '2 · Ofertas + Pix', 'cor' => 'primary'], ['texto' => '3 · Esperas (delay)', 'cor' => ''], ['texto' => '4 · Sorteio A/B', 'cor' => '']], 'sumir_apos_clique' => false], ['output_0', 'output_1', 'output_2', 'output_3']),
        // caminho 1: Pix único (R$ 2,10, 30 dias, grupo) → pago: mensagem + botão do grupo | não pago: espera 1 min + lembrete
        fr_msg('c1_intro', '1 · Intro', 0, 1, 'Cenário 1: Pix único de R$ 2,10 com acesso de 30 dias ao grupo.'),
        fr_pix('c1_pix', '1 · Pix único', 1, 1, $grupo, ['nome' => 'Acesso 30 dias (visual)', 'msg_confirmado' => '✅ Aprovado no Editor Visual!']),
        fr_msg('c1_pago', '1 · Pago', 2, 1, '🎉 Pagamento confirmado. Entre no grupo pelo botão abaixo.'),
        fr_op('c1_grp', 'grupo', '1 · Entrar no grupo', 3, 1, ['id_grupo' => $grupo, 'texto_botao' => 'Entrar no grupo VIP']),
        fr_msg('c1_exp', '1 · Expirou', 2, 2, '⏰ O Pix expirou sem pagamento. Mande /start para tentar de novo.'),
        // caminho 2: upsell → (aceito|recusado → downsell) → bump → Pix (valor base R$ 4,20) → pago: mensagem + grupo
        fr_msg('c2_intro', '2 · Intro', 0, 3, 'Cenário 2: oferta extra, oferta menor e bônus mudam o valor do Pix (base R$ 4,20).'),
        fr_op('c2_up', 'upsell', '2 · Upsell', 1, 3, ['mensagem' => '🔝 Upsell: 30% de desconto no combo?', 'desconto_percentual' => 30, 'valor_extra' => 0, 'texto_aceitar' => 'Quero', 'texto_recusar' => 'Agora não'], ['output_aceito', 'output_recusado']),
        fr_op('c2_dn', 'downsell', '2 · Downsell', 2, 4, ['mensagem' => '⬇️ Downsell: 10% de desconto?', 'desconto_percentual' => 10, 'valor_extra' => 0, 'texto_aceitar' => 'Aceito', 'texto_recusar' => 'Não'], ['output_aceito', 'output_recusado']),
        fr_op('c2_bp', 'order_bump', '2 · Order bump', 3, 3, ['nome' => 'Bônus visual', 'mensagem' => '🎁 Adicionar o bônus por +R$ 1,00?', 'desconto_percentual' => 0, 'valor_extra' => 1, 'texto_aceitar' => 'Levar', 'texto_recusar' => 'Dispenso'], ['output_aceito', 'output_recusado']),
        fr_pix('c2_pix', '2 · Pix do combo', 4, 3, $grupo, ['nome' => 'Combo visual', 'valor' => PRECO_TESTE * 2, 'msg_confirmado' => '✅ Combo aprovado!']),
        fr_msg('c2_pago', '2 · Pago', 5, 3, '🎉 Combo pago! Seu acesso ao grupo foi liberado.'),
        fr_msg('c2_exp', '2 · Expirou', 5, 4, '⏰ Pix do combo expirou.'),
        // caminho 3: esperas curta (5 s, digitando), longa (2 min, retomada pelo cron)
        fr_msg('c3_a', '3 · Antes', 0, 5, 'Cenário 3: vou esperar 5 segundos ("digitando…") e depois 2 minutos (retomado pelo cron).'),
        fr_op('c3_d1', 'delay', '3 · Espera 5 s', 1, 5, ['delay_min' => 5, 'delay_max' => 5, 'delay_unidade' => 'seg', 'delay_digitando' => true]),
        fr_msg('c3_b', '3 · Depois de 5 s', 2, 5, '⏱ Passaram 5 segundos.'),
        fr_op('c3_d2', 'delay', '3 · Espera 2 min', 3, 5, ['delay_min' => 2, 'delay_max' => 2, 'delay_unidade' => 'min', 'delay_digitando' => false]),
        fr_msg('c3_c', '3 · Depois de 2 min', 4, 5, '⏱ Passaram 2 minutos (o cron retomou o fluxo).'),
        // caminho 4: sorteio 50/50
        fr_op('c4_sort', 'randomizer', '4 · Sorteio', 1, 6, ['caminhos' => [['peso' => 50], ['peso' => 50]]], ['output_path_0', 'output_path_1']),
        fr_msg('c4_a', '4 · Caminho A', 2, 6, '🅰️ Você caiu no caminho A.'),
        fr_msg('c4_b', '4 · Caminho B', 2, 7, '🅱️ Você caiu no caminho B.'),
    ], [
        ['start', 'output_1', 'oi'], ['oi', 'output_1', 'menu'],
        ['menu', 'output_0', 'c1_intro'], ['menu', 'output_1', 'c2_intro'], ['menu', 'output_2', 'c3_a'], ['menu', 'output_3', 'c4_sort'],
        ['c1_intro', 'output_1', 'c1_pix'], ['c1_pix', 'output_pago', 'c1_pago'], ['c1_pago', 'output_1', 'c1_grp'], ['c1_pix', 'output_nao_pago', 'c1_exp'],
        ['c2_intro', 'output_1', 'c2_up'], ['c2_up', 'output_aceito', 'c2_bp'], ['c2_up', 'output_recusado', 'c2_dn'], ['c2_dn', 'output_aceito', 'c2_bp'], ['c2_dn', 'output_recusado', 'c2_bp'],
        ['c2_bp', 'output_aceito', 'c2_pix'], ['c2_bp', 'output_recusado', 'c2_pix'], ['c2_pix', 'output_pago', 'c2_pago'], ['c2_pix', 'output_nao_pago', 'c2_exp'],
        ['c3_a', 'output_1', 'c3_d1'], ['c3_d1', 'output_1', 'c3_b'], ['c3_b', 'output_1', 'c3_d2'], ['c3_d2', 'output_1', 'c3_c'],
        ['c4_sort', 'output_path_0', 'c4_a'], ['c4_sort', 'output_path_1', 'c4_b'],
    ]);
}

/** 4) Assinatura recorrente de verdade (Pix recorrente do OmegaPayments): semanal R$ 2,10 com grupo. O ciclo 2 só acontece ~7 dias depois. */
function fr_visual_recorrente(string $grupo): array
{
    return fr_fluxo('TESTE REAL 4 · n8n — assinatura recorrente semanal com grupo', [
        fr_op('start', 'start', 'Início', 0, 0),
        fr_msg('oi', 'Boas-vindas', 1, 0, "🧪 TESTE REAL 4 — Assinatura recorrente\nAssinatura SEMANAL de R$ 2,10 com acesso ao grupo. Depois do 1º pagamento, o gateway gera uma nova cobrança a cada 7 dias (o bot manda o Pix da renovação por aqui)."),
        fr_pix('pix', 'Pix recorrente semanal', 2, 0, $grupo, ['nome' => 'Assinatura semanal (visual)', 'tipo_cobranca' => 'recorrente', 'periodicidade' => 'semanal', 'msg_confirmado' => '✅ Assinatura ativa!']),
        fr_msg('pago', 'Pago', 3, 0, '🎉 Assinatura semanal ativa. Entre no grupo pelo botão abaixo.'),
        fr_op('grp', 'grupo', 'Entrar no grupo', 4, 0, ['id_grupo' => $grupo, 'texto_botao' => 'Entrar no grupo VIP']),
        fr_msg('exp', 'Não pago', 3, 1, '⏰ A assinatura não foi paga. Mande /start para tentar de novo.'),
    ], [['start', 'output_1', 'oi'], ['oi', 'output_1', 'pix'], ['pix', 'output_pago', 'pago'], ['pago', 'output_1', 'grp'], ['pix', 'output_nao_pago', 'exp']]);
}

function fluxos_reais(string $grupo): array
{
    return [fr_guiado_planos($grupo), fr_guiado_assinatura($grupo), fr_visual_menu($grupo), fr_visual_recorrente($grupo)];
}
