<?php
// Fluxos de teste. Cada função devolve [modo, dados_fluxograma].
// Chaves terminadas em "!" no patch substituem a lista inteira (ver setflow.php).

const G1 = '-1001000000001';
const G2 = '-1001000000002';

// ---------------- Guiado ----------------
function guiado_base(): array {
    return ['basico', [
        'ativo' => true,
        'suporte' => '@suportehomolog',
        'suporte_cor' => 'danger',
        'cache_chat_id' => '',
        'boas_vindas' => ['mensagem' => "Oi! 👋 Bem-vindo ao *Teste* (guiado).", 'midia_tipo' => 'none', 'midia_path' => '', 'texto_cta' => 'Quero aprender', 'cor_cta' => 'success'],
        'planos' => [
            ['id' => 'p_mensal', 'nome' => 'Mensal', 'valor' => 19.9, 'dias_acesso' => 30, 'unidade_acesso' => 'dias', 'id_grupo' => G1, 'cor' => 'primary'],
            ['id' => 'p_anual', 'nome' => 'Anual', 'valor' => 149.0, 'dias_acesso' => 365, 'unidade_acesso' => 'dias', 'id_grupo' => G1, 'cor' => 'success'],
            ['id' => 'p_horas', 'nome' => 'Passe 2h', 'valor' => 5.0, 'dias_acesso' => 2, 'unidade_acesso' => 'horas', 'id_grupo' => G1, 'cor' => ''],
            ['id' => 'p_min', 'nome' => 'Passe_30*min', 'valor' => 3.0, 'dias_acesso' => 30, 'unidade_acesso' => 'minutos', 'id_grupo' => G2, 'cor' => ''],
        ],
        'ofertas' => [
            'upsell' => ['ativo' => false, 'id_plano_destino' => 'p_anual', 'desconto_percentual' => 15, 'mensagem' => 'Leve o ANUAL com 15% off! 🎓', 'texto_aceitar' => 'Quero o anual', 'cor_aceitar' => 'success', 'texto_recusar' => 'Fico no que escolhi', 'cor_recusar' => ''],
            'downsell' => ['ativo' => false, 'id_plano_destino' => 'p_mensal', 'desconto_percentual' => 0, 'mensagem' => 'Comece pelo mensal, sem compromisso.', 'texto_aceitar' => 'Começar pelo mensal', 'cor_aceitar' => 'primary', 'texto_recusar' => 'Não, obrigado', 'cor_recusar' => ''],
            'order_bump' => ['ativo' => false, 'nome' => 'Checklist PDF', 'valor_extra' => 7.9, 'mensagem' => 'Adicione o Checklist por só R$ 7,90.', 'texto_aceitar' => 'Adicionar', 'cor_aceitar' => 'success', 'texto_recusar' => 'Sem o PDF', 'cor_recusar' => ''],
        ],
        'previas' => ['ativo' => false, 'texto_botao' => '🎬 Aula grátis', 'midias' => [['tipo' => 'image', 'path' => 'uploads/t_foto.png'], ['tipo' => 'video', 'path' => 'uploads/t_video.mp4'], ['tipo' => 'audio', 'path' => 'uploads/t_audio.mp3']], 'apagar_apos' => 5, 'rever' => 'diario', 'proteger' => true, 'mensagem_junto' => 'Aqui vai uma prévia:', 'mensagem_depois' => 'Gostou? Veja os planos 👇', 'botao_planos' => true],
        'packs' => ['ativo' => false, 'texto_botao' => '📦 Aulas avulsas', 'mensagem' => 'Escolha uma aula:', 'itens' => [
            ['id' => 'k1', 'ativo' => true, 'emoji' => '📷', 'nome' => 'Aula 1 — Exposição', 'preco' => 9.9, 'descricao' => 'Triângulo da exposição.', 'midia_tipo' => 'video', 'midia_path' => 'uploads/t_video.mp4', 'id_grupo' => G1],
            ['id' => 'k2', 'ativo' => true, 'emoji' => '🌅', 'nome' => 'Aula_2 *Luz*', 'preco' => 14.9, 'descricao' => '', 'midia_tipo' => 'none', 'midia_path' => '', 'id_grupo' => G2],
            ['id' => 'k3', 'ativo' => false, 'emoji' => '🔒', 'nome' => 'Aula 3 (em breve)', 'preco' => 12, 'descricao' => '', 'midia_tipo' => 'none', 'midia_path' => '', 'id_grupo' => ''],
            ['id' => 'k4', 'ativo' => true, 'emoji' => '🖼', 'nome' => 'Pack foto', 'preco' => 6.5, 'descricao' => 'Fotos.', 'midia_tipo' => 'image', 'midia_path' => 'uploads/t_foto.jpg', 'id_grupo' => ''],
            ['id' => 'k5', 'ativo' => true, 'emoji' => '🎧', 'nome' => 'Pack áudio', 'preco' => 4.5, 'descricao' => 'Áudios.', 'midia_tipo' => 'audio', 'midia_path' => 'uploads/t_audio.mp3', 'id_grupo' => ''],
        ]],
        'assinatura' => [
            'avisos_antes' => ['ativo' => false, 'dias' => [5, 2, 0], 'midia_tipo' => 'none', 'midia_path' => '', 'mensagem' => '{saudacao}, {nome}! Seu plano {plano} expira {prazo} ({data_expiracao}). Faltam {dias} dia(s).'],
            'avisos_no_dia' => ['ativo' => false, 'horarios' => ['10:00', '20:00']],
            'ao_expirar' => ['ativo' => false, 'midia_tipo' => 'none', 'midia_path' => '', 'mensagem' => '{nome}, seu plano {plano} acabou. Renove!'],
            'renovacao' => ['desconto' => 10],
            'acoes' => ['expulsar' => true],
        ],
        'pagamentos' => ['msg_instrucoes' => '', 'msg_confirmado' => '', 'mostrar_copiar' => true, 'mostrar_qrcode' => false, 'mostrar_confirmar' => true],
    ]];
}

// ---------------- Visual (Editor de blocos) ----------------
function fx_op(string $id, string $tipo, array $props = [], array $saidas = ['output_1']): array {
    $outs = [];
    foreach ($saidas as $s) $outs[$s] = ['label' => $s];
    return [$id, ['top' => 40, 'left' => 20, 'properties' => ['inputs' => ['input_1' => ['label' => 'Entrada']], 'outputs' => $outs, 'class' => 'no-' . $tipo, 'title' => $id, 'type' => $tipo] + $props]];
}
function fx_flow(array $ops, array $links): array {
    $operators = [];
    foreach ($ops as [$id, $op]) $operators[$id] = $op;
    $l = [];
    foreach ($links as [$de, $con, $para]) $l[] = ['fromOperator' => $de, 'fromConnector' => $con, 'toOperator' => $para, 'toConnector' => 'input_1'];
    return ['avancado', ['operators' => $operators, 'links' => $l]];
}
function fx_msg(string $id, string $texto): array { return fx_op($id, 'message', ['conteudo' => $texto]); }
function fx_start(): array { return fx_op('start', 'start'); }
function fx_pix(string $id = 'pix', array $extra = []): array {
    return fx_op($id, 'pix', $extra + ['nome' => 'Produto Visual', 'valor' => 29.9, 'tipo_cobranca' => 'unica', 'expiracao_minutos' => 15, 'dias_acesso' => 30, 'unidade_acesso' => 'dias', 'mostrar_copiar' => true, 'mostrar_qrcode' => false, 'mostrar_confirmar' => true, 'msg_instrucoes' => '', 'msg_confirmado' => '', 'id_grupo' => G1], ['output_pago', 'output_nao_pago']);
}

/** Todas as mídias e blocos "simples" em sequência, sem esperar ninguém. */
function v_sequencia(): array {
    return fx_flow([
        fx_start(),
        fx_msg('m1', 'Olá! Mensagem simples ✅'),
        fx_op('foto', 'image', ['image_path' => 'uploads/t_foto.png', 'caption' => 'Foto com legenda', 'mode' => 'foto', 'spoiler' => true, 'auto_delete' => true, 'auto_delete_seconds' => 40]),
        fx_op('doc', 'image', ['image_path' => 'uploads/t_foto.jpg', 'caption' => 'Imagem como documento', 'mode' => 'documento']),
        fx_op('foto2', 'image', ['image_path' => 'uploads/t_foto.jpg', 'caption' => '', 'mode' => 'foto']),
        fx_op('vid', 'video', ['video_path' => 'uploads/t_video.mp4', 'caption' => 'Vídeo', 'spoiler' => true, 'auto_delete' => true, 'auto_delete_seconds' => 60]),
        fx_op('aud', 'audio', ['audio_path' => 'uploads/t_audio.mp3', 'caption' => 'Áudio', 'auto_delete' => true, 'auto_delete_seconds' => 90]),
        fx_op('lnk', 'link', ['url' => 'https://exemplo.com/oferta', 'texto_botao' => 'Abrir oferta']),
        fx_op('grp', 'grupo', ['id_grupo' => G1, 'texto_botao' => 'Entrar no VIP']),
        fx_msg('fim', 'Fim da sequência 🏁'),
    ], [['start', 'output_1', 'm1'], ['m1', 'output_1', 'foto'], ['foto', 'output_1', 'doc'], ['doc', 'output_1', 'foto2'], ['foto2', 'output_1', 'vid'], ['vid', 'output_1', 'aud'], ['aud', 'output_1', 'lnk'], ['lnk', 'output_1', 'grp'], ['grp', 'output_1', 'fim']]);
}

/** Botões (3 botões, cores, sumir após clique) + cada saída leva a uma mensagem diferente. */
function v_botoes(): array {
    return fx_flow([
        fx_start(),
        fx_op('b', 'botoes', ['texto' => 'Escolha:', 'botoes' => [['texto' => 'Opção A', 'cor' => 'success'], ['texto' => 'Opção B', 'cor' => 'danger'], 'Opção C legado'], 'sumir_apos_clique' => true], ['output_0', 'output_1', 'output_2']),
        fx_msg('ra', 'Você escolheu A'), fx_msg('rb', 'Você escolheu B'), fx_msg('rc', 'Você escolheu C'),
    ], [['start', 'output_1', 'b'], ['b', 'output_0', 'ra'], ['b', 'output_1', 'rb'], ['b', 'output_2', 'rc']]);
}

/** Ofertas encadeadas: upsell → (aceito|recusado) downsell → bump → Pix. */
function v_ofertas(): array {
    return fx_flow([
        fx_start(),
        fx_op('up', 'upsell', ['mensagem' => 'Upsell: 30% off!', 'desconto_percentual' => 30, 'valor_extra' => 0, 'texto_aceitar' => 'Quero', 'texto_recusar' => 'Agora não'], ['output_aceito', 'output_recusado']),
        fx_op('dn', 'downsell', ['mensagem' => 'Downsell: 10% off', 'desconto_percentual' => 10, 'valor_extra' => 0, 'texto_aceitar' => 'Aceito', 'texto_recusar' => 'Não'], ['output_aceito', 'output_recusado']),
        fx_op('bp', 'order_bump', ['nome' => 'Bônus ebook', 'mensagem' => 'Leve o ebook por +R$ 5', 'desconto_percentual' => 0, 'valor_extra' => 5, 'texto_aceitar' => 'Levar', 'texto_recusar' => 'Dispenso'], ['output_aceito', 'output_recusado']),
        fx_pix('pix', ['nome' => 'Combo_Visual*', 'valor' => 100.0]),
        fx_msg('pago', '🎉 Pago! Seguindo o fluxo.'),
        fx_msg('exp', '⏰ Pix expirou.'),
    ], [['start', 'output_1', 'up'], ['up', 'output_aceito', 'bp'], ['up', 'output_recusado', 'dn'], ['dn', 'output_aceito', 'bp'], ['dn', 'output_recusado', 'bp'], ['bp', 'output_aceito', 'pix'], ['bp', 'output_recusado', 'pix'], ['pix', 'output_pago', 'pago'], ['pix', 'output_nao_pago', 'exp']]);
}

/** Sorteio com pesos 70/30 (caminho 0 e 1). */
function v_sorteio(): array {
    return fx_flow([
        fx_start(),
        fx_op('sort', 'randomizer', ['caminhos' => [['peso' => 70], ['peso' => 30]]], ['output_path_0', 'output_path_1']),
        fx_msg('c0', 'CAMINHO_0'), fx_msg('c1', 'CAMINHO_1'),
    ], [['start', 'output_1', 'sort'], ['sort', 'output_path_0', 'c0'], ['sort', 'output_path_1', 'c1']]);
}

/** Delays: curto inline (2s), médio no webhook (7s com "digitando"), longo agendado (3 min → cron). */
function v_delays(): array {
    return fx_flow([
        fx_start(),
        fx_msg('a', 'ANTES do delay curto'),
        fx_op('d1', 'delay', ['delay_min' => 2, 'delay_max' => 2, 'delay_unidade' => 'seg', 'delay_digitando' => true]),
        fx_msg('b', 'DEPOIS do delay curto'),
        fx_op('d2', 'delay', ['delay_min' => 7, 'delay_max' => 7, 'delay_unidade' => 'seg', 'delay_digitando' => true]),
        fx_msg('c', 'DEPOIS do delay médio'),
        fx_op('d3', 'delay', ['delay_min' => 3, 'delay_max' => 3, 'delay_unidade' => 'min', 'delay_digitando' => false]),
        fx_msg('d', 'DEPOIS do delay longo (cron)'),
    ], [['start', 'output_1', 'a'], ['a', 'output_1', 'd1'], ['d1', 'output_1', 'b'], ['b', 'output_1', 'd2'], ['d2', 'output_1', 'c'], ['c', 'output_1', 'd3'], ['d3', 'output_1', 'd']]);
}

/** Pix único simples com pós-pagamento (msg, grupo) e pós-expiração (msg). */
function v_pix(): array {
    return fx_flow([
        fx_start(),
        fx_msg('m1', 'Vai pagar?'),
        fx_pix('pix', ['nome' => 'Produto Visual', 'valor' => 29.9, 'mostrar_qrcode' => true, 'msg_instrucoes' => 'Pague em *15 min*: copie o código.', 'msg_confirmado' => 'Aprovado! 🎉']),
        fx_msg('pago1', '✅ Pós-pagamento 1'),
        fx_op('grp', 'grupo', ['id_grupo' => G2, 'texto_botao' => 'Entrar no grupo pós-pago']),
        fx_msg('pago2', '✅ Pós-pagamento 2 (fim)'),
        fx_msg('exp1', '⏰ Pix expirou sem pagamento'),
    ], [['start', 'output_1', 'm1'], ['m1', 'output_1', 'pix'], ['pix', 'output_pago', 'pago1'], ['pago1', 'output_1', 'grp'], ['grp', 'output_1', 'pago2'], ['pix', 'output_nao_pago', 'exp1']]);
}

/** Pix recorrente (assinatura). */
function v_recorrente(): array {
    return fx_flow([
        fx_start(),
        fx_pix('pix', ['nome' => 'Assinatura VIP', 'valor' => 49.9, 'tipo_cobranca' => 'recorrente', 'periodicidade' => 'mensal', 'mostrar_qrcode' => false]),
        fx_msg('pago', '✅ Assinatura ativa'),
        fx_msg('exp', '⏰ Assinatura não paga'),
    ], [['start', 'output_1', 'pix'], ['pix', 'output_pago', 'pago'], ['pix', 'output_nao_pago', 'exp']]);
}

/** Mensagem/blocos "estranhos": mensagem vazia, bloco inexistente no link, ciclo infinito (limite de passos). */
function v_estranho(): array {
    return fx_flow([
        fx_start(),
        fx_msg('vazia', '   '),
        fx_msg('ok1', 'Chegou depois da vazia'),
        fx_msg('loop1', 'loop A'),
        fx_msg('loop2', 'loop B'),
    ], [['start', 'output_1', 'vazia'], ['vazia', 'output_1', 'ok1'], ['ok1', 'output_1', 'loop1'], ['loop1', 'output_1', 'loop2'], ['loop2', 'output_1', 'loop1']]);
}

/** Grupo com problemas: sem id (erro configurado) e link sem URL. */
function v_grupo_erro(): array {
    return fx_flow([
        fx_start(),
        fx_op('g1', 'grupo', ['id_grupo' => '', 'texto_botao' => 'Entrar']),
        fx_op('l1', 'link', ['url' => '', 'texto_botao' => '']),
        fx_op('l2', 'link', ['url' => 'https://exemplo.com', 'texto_botao' => '']),
        fx_op('g2', 'grupo', ['id_grupo' => G1, 'texto_botao' => '']),
    ], [['start', 'output_1', 'g1'], ['g1', 'output_1', 'l1'], ['l1', 'output_1', 'l2'], ['l2', 'output_1', 'g2']]);
}


/** Funil visual completo: boas-vindas, foto, escolha (Comprar | Depois), ofertas, Pix, ramos PAGO / NÃO PAGO com delay, ramo 'Depois' com delay. */
function v_completo(): array {
    return fx_flow([
        fx_start(),
        fx_msg('oi', 'Olá! Bem-vindo ao funil completo 👋'),
        fx_op('foto', 'image', ['image_path' => 'uploads/t_foto.png', 'caption' => 'Veja o que você vai receber', 'mode' => 'foto']),
        fx_op('esc', 'botoes', ['texto' => 'Quer garantir agora?', 'botoes' => ['Comprar', 'Depois'], 'sumir_apos_clique' => true], ['output_0', 'output_1']),
        fx_op('up', 'upsell', ['mensagem' => 'Leve o combo com 20% off!', 'desconto_percentual' => 20, 'valor_extra' => 0, 'texto_aceitar' => 'Quero', 'texto_recusar' => 'Não'], ['output_aceito', 'output_recusado']),
        fx_op('bp', 'order_bump', ['nome' => 'Bônus', 'mensagem' => 'Adicione o bônus por R$ 10', 'valor_extra' => 10, 'texto_aceitar' => 'Sim', 'texto_recusar' => 'Não'], ['output_aceito', 'output_recusado']),
        fx_pix('pix', ['nome' => 'Combo', 'valor' => 50.0, 'mostrar_qrcode' => true]),
        fx_msg('pago', '🎉 Compra aprovada!'),
        fx_op('grp', 'grupo', ['id_grupo' => G2, 'texto_botao' => 'Entrar no VIP']),
        fx_op('dnp', 'delay', ['delay_min' => 2, 'delay_max' => 2, 'delay_unidade' => 'min', 'delay_digitando' => false]),
        fx_msg('pos', '📚 Seu material chegou (depois de 2 min)'),
        fx_msg('exp', '⏰ Seu Pix expirou'),
        fx_op('dne', 'delay', ['delay_min' => 1, 'delay_max' => 1, 'delay_unidade' => 'min', 'delay_digitando' => false]),
        fx_msg('remk', '💬 Ainda dá tempo! Mande /start para tentar de novo'),
        fx_msg('dep', 'Sem problemas, te aviso depois ⏳'),
        fx_op('dnd', 'delay', ['delay_min' => 5, 'delay_max' => 5, 'delay_unidade' => 'min', 'delay_digitando' => false]),
        fx_msg('lemb', '🔔 Lembrete: sua oferta continua válida!'),
    ], [
        ['start', 'output_1', 'oi'], ['oi', 'output_1', 'foto'], ['foto', 'output_1', 'esc'],
        ['esc', 'output_0', 'up'], ['esc', 'output_1', 'dep'],
        ['up', 'output_aceito', 'bp'], ['up', 'output_recusado', 'bp'],
        ['bp', 'output_aceito', 'pix'], ['bp', 'output_recusado', 'pix'],
        ['pix', 'output_pago', 'pago'], ['pago', 'output_1', 'grp'], ['grp', 'output_1', 'dnp'], ['dnp', 'output_1', 'pos'],
        ['pix', 'output_nao_pago', 'exp'], ['exp', 'output_1', 'dne'], ['dne', 'output_1', 'remk'],
        ['dep', 'output_1', 'dnd'], ['dnd', 'output_1', 'lemb'],
    ]);
}

const FLUXOS_DISPONIVEIS = ['v_completo', 'guiado_base', 'v_sequencia', 'v_botoes', 'v_ofertas', 'v_sorteio', 'v_delays', 'v_pix', 'v_recorrente', 'v_estranho', 'v_grupo_erro'];
