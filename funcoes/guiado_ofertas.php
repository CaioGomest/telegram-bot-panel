<?php
declare(strict_types=1);

/**
 * Peças do modo Guiado compartilhadas entre o webhook do Telegram (webhook.php), o webhook da OmegaPayments e o cron do Pix: envio de mensagem com mídia,
 * planos, oferta com botões Aceitar/Recusar e as ofertas DEPOIS do pagamento (Upsell e Order Bump; 21/10/2026, pedido do cliente: "o upsell deveria aparecer
 * depois que o cara fez o Pix"). Antes essas funções ficavam em webhook.php, que as outras duas entradas não carregam.
 * Precisa de funcoes/fluxo_blocos.php (requisicaoTelegram, resolverCaminhoUploadSeguro) já carregado.
 */

require_once __DIR__ . '/entrega.php';
require_once __DIR__ . '/remarketing_ofertas.php';   // venda que veio de campanha de remarketing com order bump próprio (ver ofertasPosPagamentoBasico)

/**
 * Cache de mídia: o Telegram só aceita upload "do zero" no primeiro envio; depois reaproveita o
 * file_id. Quando o fluxo tem um canal/grupo de cache, a mídia sobe uma vez pra lá, o file_id fica
 * guardado em storage/cache_midia.json (chave = bot + arquivo + data de modificação, então trocar a
 * mídia gera um file_id novo) e os envios seguintes pros leads usam só o file_id.
 */
function obterFileIdCacheMidia(string $token, string $id_cache, string $metodo, string $campo, string $caminho): ?string {
    $arquivo_cache = dirname(__DIR__) . '/storage/cache_midia.json';
    $chave = sha1($token . '|' . $id_cache . '|' . $caminho . '|' . (string) @filemtime($caminho));
    $mapa = is_file($arquivo_cache) ? (json_decode((string) file_get_contents($arquivo_cache), true) ?: []) : [];
    if (!empty($mapa[$chave])) {
        return (string) $mapa[$chave];
    }
    $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_cache], [$campo => $caminho]);
    $resultado = $resposta['result'] ?? [];
    $file_id = $campo === 'photo' ? (end($resultado['photo'])['file_id'] ?? null) : ($resultado['video']['file_id'] ?? null);
    if (!$file_id) {
        return null;
    }
    $mapa[$chave] = $file_id;
    @file_put_contents($arquivo_cache, json_encode($mapa), LOCK_EX);
    return $file_id;
}
/**
 * Mensagem do modo Guiado (boas-vindas e ofertas) com foto ou vídeo opcional. Com mídia, o texto vai na legenda junto com o teclado; legenda
 * aceita no máximo 1024 caracteres, então acima disso a mídia vai sozinha e o texto (com o teclado) segue numa mensagem normal (até 4096).
 * Mídia que sumiu do servidor ou que o Telegram recusa: o lead não pode ficar sem a mensagem e sem os botões, então o texto vai mesmo assim.
 */
function enviarMensagemComMidiaBasico(string $token, $id_chat, string $mensagem, string $teclado, string $midia_tipo, string $midia_bruta, string $id_cache = ''): void {
    $midia_path = resolverCaminhoUploadSeguro($midia_bruta);
    $botoes = $teclado !== '' ? ['reply_markup' => $teclado] : [];  // teclado vazio = mensagem sem botão (Boas-vindas com o CTA desligado)
    if (in_array($midia_tipo, ['image', 'video'], true) && $midia_path) {
        $metodo = $midia_tipo === 'image' ? 'sendPhoto' : 'sendVideo';
        $campo = $midia_tipo === 'image' ? 'photo' : 'video';
        $legenda_cabe = mb_strlen($mensagem) <= 1024;
        $parametros = $legenda_cabe ? ['chat_id' => $id_chat, 'caption' => $mensagem] + $botoes : ['chat_id' => $id_chat];
        $file_id = $id_cache !== '' ? obterFileIdCacheMidia($token, $id_cache, $metodo, $campo, $midia_path) : null;
        $enviou = false;
        if ($file_id) {
            $resposta = requisicaoTelegram($token, $metodo, $parametros + [$campo => $file_id]);
            $enviou = (bool) ($resposta['ok'] ?? false);
        }
        if (!$enviou) {
            $resposta_envio = requisicaoTelegram($token, $metodo, $parametros, [$campo => $midia_path]);
            $enviou = (bool) ($resposta_envio['ok'] ?? false);
        }
        if (!$enviou) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => mb_substr($mensagem, 0, 4096)] + $botoes);
            return;
        }
        if (!$legenda_cabe) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => mb_substr($mensagem, 0, 4096)] + $botoes);
        }
        return;
    }
    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => mb_substr($mensagem, 0, 4096)] + $botoes);
}
/** O botão CTA das boas-vindas é opcional ("mostrar_cta"); fluxo antigo, sem o campo, continua com o botão. */
function boasVindasMostraCtaBasico(array $boas_vindas): bool {
    return ($boas_vindas['mostrar_cta'] ?? true) !== false;
}
/** O Telegram aceita no máximo 10 itens num álbum (sendMediaGroup). */
const MIDIAS_MAXIMO_BASICO = 10;
/**
 * Mídias de um item do Guiado (boas-vindas, Upsell, Downsell...), na ordem: `midias` (lista de {tipo, path}, 23/10/2026) ou, em fluxo salvo antes disso, o par
 * midia_tipo/midia_path. Só entram foto e vídeo com caminho preenchido.
 */
function midiasDoItemBasico(array $item): array {
    $bruta = is_array($item['midias'] ?? null) ? $item['midias'] : [];
    if (!$bruta && (string) ($item['midia_path'] ?? '') !== '') {
        $bruta = [['tipo' => $item['midia_tipo'] ?? '', 'path' => $item['midia_path']]];
    }
    $lista = [];
    foreach ($bruta as $m) {
        if (!is_array($m) || !in_array($m['tipo'] ?? '', ['image', 'video'], true) || (string) ($m['path'] ?? '') === '') {
            continue;
        }
        $lista[] = ['tipo' => (string) $m['tipo'], 'path' => (string) $m['path']];
    }
    return array_slice($lista, 0, MIDIAS_MAXIMO_BASICO);
}
function midiasBoasVindasBasico(array $boas_vindas): array {
    return midiasDoItemBasico($boas_vindas);
}
/** Foto ou vídeo solto, sem legenda nem botão (usado quando o álbum não pode ser enviado). Devolve se o Telegram aceitou. */
function enviarMidiaSoltaBasico(string $token, $id_chat, string $tipo, string $caminho, string $id_cache = ''): bool {
    $metodo = $tipo === 'image' ? 'sendPhoto' : 'sendVideo';
    $campo = $tipo === 'image' ? 'photo' : 'video';
    $file_id = $id_cache !== '' ? obterFileIdCacheMidia($token, $id_cache, $metodo, $campo, $caminho) : null;
    if ($file_id) {
        $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat, $campo => $file_id]);
        if ($resposta['ok'] ?? false) {
            return true;
        }
    }
    $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat], [$campo => $caminho]);
    return (bool) ($resposta['ok'] ?? false);
}
/**
 * Várias fotos/vídeos das boas-vindas viram um álbum (sendMediaGroup, até 10 itens, foto e vídeo juntos). O álbum não aceita botão, por isso o texto com o
 * CTA segue logo depois, numa mensagem normal (quem chama). Mídia que sumiu do servidor é pulada. Se o Telegram recusar o álbum, cada item vai solto;
 * se nem resposta veio (tempo esgotado), nada é reenviado para o lead não receber tudo em dobro.
 */
function enviarAlbumBasico(string $token, $id_chat, array $midias, string $id_cache = ''): void {
    $itens = [];
    foreach ($midias as $m) {
        $caminho = resolverCaminhoUploadSeguro((string) ($m['path'] ?? ''));
        if ($caminho) {
            $itens[] = ['tipo' => (string) $m['tipo'], 'caminho' => $caminho];
        }
    }
    if (count($itens) < 2) {
        foreach ($itens as $it) {
            enviarMidiaSoltaBasico($token, $id_chat, $it['tipo'], $it['caminho'], $id_cache);
        }
        return;
    }
    $media = [];
    $arquivos = [];
    foreach ($itens as $i => $it) {
        $eh_video = $it['tipo'] === 'video';
        $file_id = $id_cache !== '' ? obterFileIdCacheMidia($token, $id_cache, $eh_video ? 'sendVideo' : 'sendPhoto', $eh_video ? 'video' : 'photo', $it['caminho']) : null;
        $entrada = ['type' => $eh_video ? 'video' : 'photo', 'media' => $file_id ?: 'attach://midia' . $i];
        if (!$file_id) {
            $arquivos['midia' . $i] = $it['caminho'];
        }
        if ($eh_video) {
            $entrada['supports_streaming'] = true;
        }
        $media[] = $entrada;
    }
    $resposta = requisicaoTelegram($token, 'sendMediaGroup', ['chat_id' => $id_chat, 'media' => json_encode($media)], $arquivos);
    if (($resposta['ok'] ?? false) || ($resposta['description'] ?? '') === 'resposta inválida') {
        return;
    }
    foreach ($itens as $it) {
        enviarMidiaSoltaBasico($token, $id_chat, $it['tipo'], $it['caminho'], $id_cache);
    }
}
/**
 * Mensagem (com os botões) com 0, 1 ou várias mídias: 1 = foto/vídeo com o texto na legenda e os botões, como sempre; 2 ou mais = álbum e, logo depois, o texto
 * com os botões numa mensagem normal (álbum não aceita botão). Usado por Boas-vindas, Upsell, Downsell e Order Bump.
 */
function enviarMensagemComMidiasBasico(string $token, $id_chat, string $mensagem, string $teclado, array $midias, string $id_cache = ''): void {
    if (count($midias) > 1) {
        enviarAlbumBasico($token, $id_chat, $midias, $id_cache);
        enviarMensagemComMidiaBasico($token, $id_chat, $mensagem, $teclado, 'none', '', $id_cache);
        return;
    }
    enviarMensagemComMidiaBasico($token, $id_chat, $mensagem, $teclado, $midias[0]['tipo'] ?? 'none', $midias[0]['path'] ?? '', $id_cache);
}
function enviarBoasVindasBasico(string $token, $id_chat, array $boas_vindas, string $id_cache = ''): void {
    $mensagem = trim((string) ($boas_vindas['mensagem'] ?? '')) ?: 'Bem-vindo(a)!';
    $texto_cta = trim((string) ($boas_vindas['texto_cta'] ?? '')) ?: 'Ver Planos';
    $teclado = boasVindasMostraCtaBasico($boas_vindas)
        ? json_encode(['inline_keyboard' => [[['text' => $texto_cta, 'callback_data' => 'basico::ver_planos'] + estiloBotaoBasicoValor((string) ($boas_vindas['cor_cta'] ?? ''))]]])
        : '';
    enviarMensagemComMidiasBasico($token, $id_chat, $mensagem, $teclado, midiasBoasVindasBasico($boas_vindas), $id_cache);
}
/**
 * Cor por botão (não mais uma cor "geral" por papel): cada plano, cada oferta e o CTA de
 * boas-vindas/suporte guardam o próprio valor de cor dentro do próprio registro
 * (plano['cor'], oferta['cor_aceitar'/'cor_recusar'], etc.), em vez de um mapa único de
 * papel->cor compartilhado por todos os botões daquele papel.
 */
function estiloBotaoBasicoValor(string $valor): array {
    return in_array($valor, ['primary', 'success', 'danger'], true) ? ['style' => $valor] : [];
}
function urlSuporteBasico(string $suporte): string {
    $usuario = ltrim(trim($suporte), '@');
    $usuario = preg_replace('#^(https?://)?(t\.me|telegram\.me)/#i', '', $usuario);
    return preg_match('/^[A-Za-z0-9_]{4,32}$/', (string) $usuario) ? 'https://t.me/' . $usuario : '';
}
/** Periodicidades da assinatura recorrente (Pix recorrente nativo do gateway) aceitas num plano do Guiado, com o sufixo mostrado no botão. */
const PERIODICIDADES_BASICO = ['semanal' => '/semana', 'mensal' => '/mês', 'trimestral' => '/trimestre', 'semestral' => '/semestre', 'anual' => '/ano'];
function planoRecorrenteBasico(?array $plano): bool {
    return $plano !== null && ($plano['cobranca'] ?? 'unica') === 'recorrente';
}
function periodicidadePlanoBasico(array $plano): string {
    $p = strtolower((string) ($plano['periodicidade'] ?? 'mensal'));
    return isset(PERIODICIDADES_BASICO[$p]) ? $p : 'mensal';
}
function localizarPlanoBasico(array $planos, string $id_plano): ?array {
    foreach ($planos as $p) {
        if ((string) ($p['id'] ?? '') === $id_plano) {
            return $p;
        }
    }
    return null;
}

function planoDaOfertaBasico(array $dados_basico, string $tipo): ?array {
    $oferta = $dados_basico['ofertas'][$tipo] ?? [];
    if (empty($oferta['ativo'])) {
        return null;
    }
    if ($tipo === 'upsell' && (string) ($oferta['id_plano_destino'] ?? '') === ID_PLANO_UPSELL_PROPRIO_BASICO) {
        return planoProprioDoUpsellBasico($oferta);
    }
    return localizarPlanoBasico($dados_basico['planos'] ?? [], (string) ($oferta['id_plano_destino'] ?? ''));
}

function enviarOfertaBasico(string $token, $id_chat, array $oferta, string $prefixo_callback, string $texto_padrao, string $id_cache = '', bool $permite_esconder_recusar = false): void {
    $mensagem = trim((string) ($oferta['mensagem'] ?? '')) ?: $texto_padrao;
    $aceitar = trim((string) ($oferta['texto_aceitar'] ?? '')) ?: 'Sim, quero!';
    $recusar = trim((string) ($oferta['texto_recusar'] ?? '')) ?: 'Não, obrigado';
    $botoes = [['text' => $aceitar, 'callback_data' => $prefixo_callback . '::1'] + estiloBotaoBasicoValor((string) ($oferta['cor_aceitar'] ?? ''))];
    // Só o Upsell pode esconder o botão Recusar (no Downsell e no Order Bump o cliente ficaria sem como seguir para o Pix).
    if (!($permite_esconder_recusar && !empty($oferta['esconder_recusar']))) {
        $botoes[] = ['text' => $recusar, 'callback_data' => $prefixo_callback . '::0'] + estiloBotaoBasicoValor((string) ($oferta['cor_recusar'] ?? ''));
    }
    $teclado = json_encode(['inline_keyboard' => [$botoes]]);
    enviarMensagemComMidiasBasico($token, $id_chat, $mensagem, $teclado, midiasDoItemBasico($oferta), $id_cache);
}

// ===================== Downsell =====================

/**
 * Downsell no formato novo: sem botões Aceitar/Recusar, o cliente vê um botão para cada plano que o dono marcou (ofertas.downsell.planos_oferecidos).
 * Devolve os planos marcados que ainda existem, com a POSIÇÃO de cada um na lista salva como chave (a posição vai no callback_data: "p<posição>").
 * Fluxo antigo (id_plano_destino + Aceitar/Recusar, sem planos_oferecidos): devolve [] e segue o caminho antigo (downsellNovoFormatoBasico = false).
 */
function downsellNovoFormatoBasico(array $dados_basico): bool {
    return is_array($dados_basico['ofertas']['downsell']['planos_oferecidos'] ?? null);
}
/**
 * Planos de UMA sequência do Downsell (0 = a 1ª, que é o próprio ofertas.downsell), com a posição do botão como chave: sequência * 100 + posição na sequência.
 * Sequência com "Criar planos" só tem os planos criados (a partir da posição +50); sem isso, só os planos marcados da aba Planos (posições 0 a 49).
 * Na 1ª sequência as posições são as de sempre (0, 1, 2...), então botões de mensagens já enviadas continuam valendo.
 */
function planosDaSequenciaDownsellBasico(array $dados_basico, int $indice): array {
    $sequencia = sequenciasDownsellBasico($dados_basico)[$indice] ?? null;
    if ($sequencia === null) {
        return [];
    }
    $base = $indice * DOWNSELL_POSICAO_SEQUENCIA;
    $planos = [];
    if (!empty($sequencia['criar_planos'])) {
        foreach (planosProprioDoDownsellBasico($sequencia, $indice) as $i => $plano) {
            $planos[$base + DOWNSELL_POSICAO_PROPRIOS + $i] = $plano;
        }
        return $planos;
    }
    foreach (array_slice(array_values((array) ($sequencia['planos_oferecidos'] ?? [])), 0, DOWNSELL_POSICAO_PROPRIOS) as $posicao => $id) {
        $plano = localizarPlanoBasico($dados_basico['planos'] ?? [], (string) $id);
        if ($plano) {
            $planos[$base + $posicao] = $plano;
        }
    }
    return $planos;
}
/** Todos os planos do Downsell (de todas as sequências), pela posição do botão: é o que o toque do cliente procura. */
function planosDoDownsellBasico(array $dados_basico): array {
    $oferta = $dados_basico['ofertas']['downsell'] ?? [];
    if (empty($oferta['ativo']) || !downsellNovoFormatoBasico($dados_basico)) {
        return [];
    }
    $planos = [];
    foreach (array_keys(sequenciasDownsellBasico($dados_basico)) as $indice) {
        $planos += planosDaSequenciaDownsellBasico($dados_basico, $indice);
    }
    return $planos;
}
/** Oferta (mensagem, mídia, desconto, entrega) da sequência a que pertence a posição do botão; posição desconhecida cai no ofertas.downsell. */
function ofertaDaPosicaoDownsellBasico(array $dados_basico, int $posicao): array {
    return sequenciasDownsellBasico($dados_basico)[intdiv($posicao, DOWNSELL_POSICAO_SEQUENCIA)] ?? ($dados_basico['ofertas']['downsell'] ?? []);
}
/**
 * Planos do downsell que o cliente ainda não escolheu (o plano que ele já pediu não é repetido na oferta). $indice = só os de uma sequência; null = de todas.
 * Plano criado no Downsell nunca é "o que ele já escolheu": o escolhido antes do Pix é sempre um plano da aba Planos.
 */
function planosDoDownsellSemOEscolhidoBasico(array $dados_basico, string $id_plano, ?int $indice = null): array {
    $planos = $indice === null ? planosDoDownsellBasico($dados_basico) : (downsellNovoFormatoBasico($dados_basico) && !empty($dados_basico['ofertas']['downsell']['ativo']) ? planosDaSequenciaDownsellBasico($dados_basico, $indice) : []);
    return array_filter($planos, function ($p) use ($id_plano) { return (string) ($p['id'] ?? '') !== $id_plano; });
}
function enviarDownsellBasico(string $token, $id_chat, array $dados_basico, array $planos, string $id_plano_original, string $id_cache = '', int $indice = 0): void {
    $oferta = sequenciasDownsellBasico($dados_basico)[$indice] ?? ($dados_basico['ofertas']['downsell'] ?? []);
    $desconto_oferta = max(0.0, min(100.0, (float) ($oferta['desconto_percentual'] ?? 0)));
    $mensagem = trim((string) ($oferta['mensagem'] ?? '')) ?: (count($planos) > 1 ? 'Que tal uma destas opções?' : 'Que tal esta opção?');
    $botoes = [];
    foreach ($planos as $posicao => $plano) {
        $desconto = !empty($plano['exclusivo_oferta']) ? 0.0 : $desconto_oferta;   // plano criado no Downsell vale pelo valor digitado
        $valor = round((float) ($plano['valor'] ?? 0) * (1 - $desconto / 100), 2);
        $rotulo = trim((string) ($plano['nome'] ?? 'Plano')) . ' — R$ ' . number_format($valor, 2, ',', '.') . (planoRecorrenteBasico($plano) ? PERIODICIDADES_BASICO[periodicidadePlanoBasico($plano)] : '')
            . ($desconto > 0 ? ' (-' . rtrim(rtrim(number_format($desconto, 2, ',', ''), '0'), ',') . '%)' : '');
        $botoes[] = [['text' => $rotulo, 'callback_data' => 'basico::of::dn::' . $id_plano_original . '::p' . $posicao] + estiloBotaoBasicoValor((string) ($plano['cor'] ?? ''))];
    }
    enviarMensagemComMidiasBasico($token, $id_chat, $mensagem, json_encode(['inline_keyboard' => $botoes]), midiasDoItemBasico($oferta), $id_cache);
}

// ---------- Downsell com temporizador (depois do Pix, se o lead não pagar) ----------
//
// ofertas.downsell.quando = 'depois_pix' (ausente/'antes_pix' = comportamento antigo: a oferta sai NO LUGAR do Pix, na hora). Nesse modo o lead recebe o Pix
// normal e cada Pix do plano escolhido vira uma linha em `downsell_agenda`: passados `espera_minutos` sem pagar, o cron de 1 minuto manda o downsell; com
// `envios` > 1 repete a cada `intervalo_minutos`, até o lead pagar (ou tocar num plano do downsell, que gera o Pix com desconto).
// A precisão é a do cron (~1 minuto): por isso o temporizador é em minutos (as prévias, que precisam de segundos, apagam pelo webhook).

const DOWNSELL_ESPERA_MINUTOS_PADRAO = 10;
const DOWNSELL_INTERVALO_MINUTOS_PADRAO = 30;
const DOWNSELL_MAXIMO_MINUTOS = 1440;
const DOWNSELL_MAXIMO_ENVIOS = 5;

function downsellDepoisDoPixBasico(array $dados_basico): bool {
    $oferta = $dados_basico['ofertas']['downsell'] ?? [];
    return !empty($oferta['ativo']) && downsellNovoFormatoBasico($dados_basico) && ($oferta['quando'] ?? '') === 'depois_pix';
}

/** Temporizador já limitado: 'espera' = minutos até o 1º envio, 'envios' = quantas vezes manda, 'intervalo' = minutos entre um envio e o seguinte. */
function temporizadorDownsellBasico(array $oferta): array {
    $limitar = function ($valor, int $padrao, int $maximo): int {
        $n = (int) $valor;
        return $n < 1 ? $padrao : min($maximo, $n);
    };
    return [
        'espera' => $limitar($oferta['espera_minutos'] ?? 0, DOWNSELL_ESPERA_MINUTOS_PADRAO, DOWNSELL_MAXIMO_MINUTOS),
        'envios' => $limitar($oferta['envios'] ?? 0, 1, DOWNSELL_MAXIMO_ENVIOS),
        'intervalo' => $limitar($oferta['intervalo_minutos'] ?? 0, DOWNSELL_INTERVALO_MINUTOS_PADRAO, DOWNSELL_MAXIMO_MINUTOS),
    ];
}

/** Para a contagem do lead (novo Pix recomeça do zero; tocar num plano do downsell encerra a sequência). */
function cancelarDownsellsDoLeadBasico(PDO $pdo, int $bot_id, string $id_telegram): void {
    try {
        $pdo->prepare("UPDATE downsell_agenda SET status = 'cancelado' WHERE bot_id = ? AND id_telegram = ? AND status = 'pendente'")->execute([$bot_id, $id_telegram]);
    } catch (\Throwable $e) {
        error_log('[downsell] cancelar: ' . $e->getMessage());   // ex.: tabela ainda não criada (Admin > Atualizar Banco)
    }
}

/** Chamada logo depois de gerar o Pix do plano escolhido. Nunca lança: falhar aqui não pode atrapalhar a entrega do Pix. */
function agendarDownsellBasico(PDO $pdo, int $bot_id, string $id_telegram, int $id_venda, string $id_plano, array $dados_basico): void {
    if ($id_venda <= 0 || !downsellDepoisDoPixBasico($dados_basico) || !planosDoDownsellSemOEscolhidoBasico($dados_basico, $id_plano)) {
        return;
    }
    try {
        $espera = temporizadorDownsellBasico(sequenciasDownsellBasico($dados_basico)[0] ?? $dados_basico['ofertas']['downsell'])['espera'];
        cancelarDownsellsDoLeadBasico($pdo, $bot_id, $id_telegram);   // novo Pix recomeça a contagem
        $pdo->prepare("INSERT INTO downsell_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)")
            ->execute([$bot_id, $id_telegram, $id_venda, $id_plano, $espera]);
    } catch (\Throwable $e) {
        error_log('[downsell] agendar: ' . $e->getMessage());
    }
}

/**
 * Resolve uma linha vencida da agenda. Devolve [enviou, minutos até o próximo envio]: minutos null = a sequência acabou (lead já pagou, downsell
 * desligado ou sem plano sobrando, fluxo trocado, ou foi o último envio).
 */
function enviarDownsellAgendadoBasico(PDO $pdo, array $linha): array {
    $pagou = $pdo->prepare("SELECT 1 FROM vendas WHERE bot_id = ? AND id_telegram = ? AND status = 'pago' AND id >= ? LIMIT 1");
    $pagou->execute([$linha['bot_id'], $linha['id_telegram'], $linha['venda_id']]);
    if ($pagou->fetchColumn()) {
        return [false, null];
    }
    $fluxo = $pdo->prepare("SELECT f.modo, f.dados_fluxograma FROM bots b JOIN fluxos f ON f.id = b.id_fluxo_conectado WHERE b.id = ?");
    $fluxo->execute([$linha['bot_id']]);
    $fluxo = $fluxo->fetch(PDO::FETCH_ASSOC);
    $dados = $fluxo && ($fluxo['modo'] ?? '') === 'basico' ? json_decode((string) $fluxo['dados_fluxograma'], true) : null;
    if (!is_array($dados) || ($dados['ativo'] ?? true) === false || !downsellDepoisDoPixBasico($dados)) {
        return [false, null];
    }
    $id_cache = (string) ($dados['cache_chat_id'] ?? '');
    $sequencias = sequenciasDownsellBasico($dados);
    if (count($sequencias) > 1) {
        // Várias sequências: a coluna enviados é a posição da próxima; cada uma tem o próprio tempo, contado do envio anterior (a 1ª, do Pix). Sequência sem plano
        // para oferecer é pulada (conta como passo) e a seguinte segue no horário dela.
        $indice = (int) $linha['enviados'];
        if ($indice >= count($sequencias)) {
            return [false, null];
        }
        $proxima = ($indice + 1) < count($sequencias) ? temporizadorDownsellBasico($sequencias[$indice + 1])['espera'] : null;
        $candidatos = planosDoDownsellSemOEscolhidoBasico($dados, (string) $linha['id_plano'], $indice);
        if (!$candidatos) {
            return [false, $proxima];
        }
        enviarDownsellBasico((string) $linha['token'], $linha['id_telegram'], $dados, $candidatos, (string) $linha['id_plano'], $id_cache, $indice);
        return [true, $proxima];
    }
    $candidatos = planosDoDownsellSemOEscolhidoBasico($dados, (string) $linha['id_plano']);
    if (!$candidatos) {
        return [false, null];
    }
    enviarDownsellBasico((string) $linha['token'], $linha['id_telegram'], $dados, $candidatos, (string) $linha['id_plano'], $id_cache);
    $temporizador = temporizadorDownsellBasico($dados['ofertas']['downsell']);
    return [true, ((int) $linha['enviados'] + 1) < $temporizador['envios'] ? $temporizador['intervalo'] : null];
}

/** Usado pelo cron de 1 minuto: manda os downsells que venceram. Cada linha é reivindicada com UPDATE antes de enviar (nunca manda duas vezes). */
function processarDownsellsAgendados(PDO $pdo, int $limite = 100): int {
    $pdo->exec("UPDATE downsell_agenda SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL 15 MINUTE");
    $stmt = $pdo->prepare("SELECT a.*, b.token FROM downsell_agenda a JOIN bots b ON b.id = a.bot_id
                           WHERE a.status = 'pendente' AND a.proximo_em <= NOW() ORDER BY a.proximo_em ASC LIMIT " . (int) $limite);
    $stmt->execute();
    $enviados = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        garantirConexaoBanco();
        $pdo = $GLOBALS['pdo'];
        $reivindicou = $pdo->prepare("UPDATE downsell_agenda SET status = 'processando' WHERE id = ? AND status = 'pendente'");
        $reivindicou->execute([$linha['id']]);
        if ($reivindicou->rowCount() === 0) {
            continue;
        }
        try {
            [$enviou, $minutos_ate_o_proximo] = enviarDownsellAgendadoBasico($pdo, $linha);
        } catch (\Throwable $e) {
            error_log('[downsell] envio da agenda ' . $linha['id'] . ': ' . $e->getMessage());
            [$enviou, $minutos_ate_o_proximo] = [false, null];
        }
        $enviados += $enviou ? 1 : 0;
        if ($minutos_ate_o_proximo === null) {
            $pdo->prepare("UPDATE downsell_agenda SET status = 'concluido', enviados = enviados + ? WHERE id = ?")->execute([$enviou ? 1 : 0, $linha['id']]);
            continue;
        }
        $pdo->prepare("UPDATE downsell_agenda SET status = 'pendente', enviados = enviados + 1, proximo_em = NOW() + INTERVAL ? MINUTE WHERE id = ?")
            ->execute([$minutos_ate_o_proximo, $linha['id']]);
    }
    $pdo->exec("DELETE FROM downsell_agenda WHERE status IN ('concluido', 'cancelado') AND criado_em < NOW() - INTERVAL 7 DAY");
    return $enviados;
}

// ===================== Order Bump em sequência (Pix não pago) =====================
//
// ofertas.order_bump.quando = 'depois_pix' (23/10/2026, pedido dos clientes: "outro sistema manda vários order bumps"): o lead gera o Pix do plano e não paga;
// o 1º order bump sai depois de `espera_minutos` do 1º item, o 2º depois de `espera_minutos` do 2º item contados do envio anterior, e assim até 5. Cada Pix do
// plano escolhido vira uma linha em `order_bump_agenda` (a coluna `enviados` é a posição do próximo item); o cron de 1 minuto manda e a sequência para
// quando o lead paga qualquer coisa. Aceitar um bump gera um Pix NOVO com plano + extra (basico::bs::..., em webhook.php) e encerra a sequência.
// Sem `quando` (ou 'depois_pagamento') vale o jeito de antes: UM order bump, oferecido depois do pagamento (ofertasPosPagamentoBasico).
// O item 1 também fica espelhado nos campos do próprio order_bump (nome, valor_extra, mensagem...), por isso fluxo e leitores antigos seguem funcionando.

const ORDER_BUMP_MAXIMO = 5;
const ORDER_BUMP_ESPERA_PADRAO_MINUTOS = 10;
const ORDER_BUMP_MAXIMO_MINUTOS = 1440;

/** Itens do Order Bump com valor extra maior que zero (no máximo 5), na ordem da tela. Fluxo salvo antes da sequência (sem `itens`): o próprio order_bump é o único item. */
function itensOrderBumpBasico(array $dados_basico): array {
    $bump = $dados_basico['ofertas']['order_bump'] ?? [];
    $bruto = is_array($bump['itens'] ?? null) && $bump['itens'] ? array_values($bump['itens']) : [$bump];
    $itens = [];
    foreach (array_slice($bruto, 0, ORDER_BUMP_MAXIMO) as $item) {
        if (is_array($item) && (float) ($item['valor_extra'] ?? 0) > 0) {
            $itens[] = $item + ['ativo' => true];
        }
    }
    return $itens;
}
function orderBumpDepoisDoPixBasico(array $dados_basico): bool {
    $bump = $dados_basico['ofertas']['order_bump'] ?? [];
    return !empty($bump['ativo']) && ($bump['quando'] ?? '') === 'depois_pix' && itensOrderBumpBasico($dados_basico) !== [];
}
/** Minutos de espera de um item (contados do Pix, no 1º, ou do envio anterior, nos demais): de 1 a 1440; vazio/inválido volta ao padrão. */
function esperaOrderBumpBasico(array $item): int {
    $n = (int) ($item['espera_minutos'] ?? 0);
    return $n < 1 ? ORDER_BUMP_ESPERA_PADRAO_MINUTOS : min(ORDER_BUMP_MAXIMO_MINUTOS, $n);
}
function textoPadraoOrderBumpBasico(array $item): string {
    $nome = trim((string) ($item['nome'] ?? ''));
    return 'Adicione ' . ($nome !== '' ? $nome : 'um extra') . ' ao seu pedido por mais R$ ' . number_format((float) ($item['valor_extra'] ?? 0), 2, ',', '.') . '.';
}

/** Encerra a sequência pendente do lead (aceitou um bump, ou gerou um Pix novo e a contagem recomeça). */
function cancelarOrderBumpsDoLeadBasico(PDO $pdo, int $bot_id, string $id_telegram): void {
    try {
        $pdo->prepare("UPDATE order_bump_agenda SET status = 'cancelado' WHERE bot_id = ? AND id_telegram = ? AND status = 'pendente'")->execute([$bot_id, $id_telegram]);
    } catch (\Throwable $e) {
        error_log('[order bump] cancelar: ' . $e->getMessage());   // ex.: tabela ainda não criada (Admin > Atualizar Banco)
    }
}

/** Chamada logo depois de gerar o Pix do plano escolhido. Nunca lança: falhar aqui não pode atrapalhar a entrega do Pix. */
function agendarOrderBumpsBasico(PDO $pdo, int $bot_id, string $id_telegram, int $id_venda, string $id_plano, array $dados_basico): void {
    if ($id_venda <= 0 || !orderBumpDepoisDoPixBasico($dados_basico)) {
        return;
    }
    $plano = localizarPlanoBasico($dados_basico['planos'] ?? [], $id_plano);
    if (!$plano || planoRecorrenteBasico($plano)) {
        return;   // assinatura recorrente não recebe order bump: o extra seria cobrado em todo ciclo
    }
    try {
        $espera = esperaOrderBumpBasico(itensOrderBumpBasico($dados_basico)[0]);
        cancelarOrderBumpsDoLeadBasico($pdo, $bot_id, $id_telegram);   // novo Pix recomeça a contagem
        $pdo->prepare("INSERT INTO order_bump_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)")
            ->execute([$bot_id, $id_telegram, $id_venda, $id_plano, $espera]);
    } catch (\Throwable $e) {
        error_log('[order bump] agendar: ' . $e->getMessage());
    }
}

/**
 * Resolve uma linha vencida da agenda. Devolve [enviou, minutos até o próximo envio]: minutos null = a sequência acabou (lead já pagou, order bump desligado
 * ou fora do modo "depois do Pix", fluxo trocado, plano removido, ou foi o último item).
 */
function enviarOrderBumpAgendadoBasico(PDO $pdo, array $linha): array {
    $pagou = $pdo->prepare("SELECT 1 FROM vendas WHERE bot_id = ? AND id_telegram = ? AND status = 'pago' AND id >= ? LIMIT 1");
    $pagou->execute([$linha['bot_id'], $linha['id_telegram'], $linha['venda_id']]);
    if ($pagou->fetchColumn()) {
        return [false, null];
    }
    $fluxo = $pdo->prepare("SELECT f.modo, f.dados_fluxograma FROM bots b JOIN fluxos f ON f.id = b.id_fluxo_conectado WHERE b.id = ?");
    $fluxo->execute([$linha['bot_id']]);
    $fluxo = $fluxo->fetch(PDO::FETCH_ASSOC);
    $dados = $fluxo && ($fluxo['modo'] ?? '') === 'basico' ? json_decode((string) $fluxo['dados_fluxograma'], true) : null;
    if (!is_array($dados) || ($dados['ativo'] ?? true) === false || !orderBumpDepoisDoPixBasico($dados)) {
        return [false, null];
    }
    $plano = localizarPlanoBasico($dados['planos'] ?? [], (string) $linha['id_plano']);
    $itens = itensOrderBumpBasico($dados);
    $posicao = (int) $linha['enviados'];
    if (!$plano || planoRecorrenteBasico($plano) || !isset($itens[$posicao])) {
        return [false, null];
    }
    $item = $itens[$posicao];
    enviarOfertaBasico((string) $linha['token'], $linha['id_telegram'], $item, 'basico::bs::' . (string) $linha['id_plano'] . '::' . $posicao,
        textoPadraoOrderBumpBasico($item), (string) ($dados['cache_chat_id'] ?? ''));
    return [true, isset($itens[$posicao + 1]) ? esperaOrderBumpBasico($itens[$posicao + 1]) : null];
}

/** Usado pelo cron de 1 minuto: manda os order bumps que venceram. Cada linha é reivindicada com UPDATE antes de enviar (nunca manda duas vezes). */
function processarOrderBumpsAgendados(PDO $pdo, int $limite = 100): int {
    $pdo->exec("UPDATE order_bump_agenda SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL 15 MINUTE");
    $stmt = $pdo->prepare("SELECT a.*, b.token FROM order_bump_agenda a JOIN bots b ON b.id = a.bot_id
                           WHERE a.status = 'pendente' AND a.proximo_em <= NOW() ORDER BY a.proximo_em ASC LIMIT " . (int) $limite);
    $stmt->execute();
    $enviados = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        garantirConexaoBanco();
        $pdo = $GLOBALS['pdo'];
        $reivindicou = $pdo->prepare("UPDATE order_bump_agenda SET status = 'processando' WHERE id = ? AND status = 'pendente'");
        $reivindicou->execute([$linha['id']]);
        if ($reivindicou->rowCount() === 0) {
            continue;
        }
        try {
            [$enviou, $minutos_ate_o_proximo] = enviarOrderBumpAgendadoBasico($pdo, $linha);
        } catch (\Throwable $e) {
            error_log('[order bump] envio da agenda ' . $linha['id'] . ': ' . $e->getMessage());
            [$enviou, $minutos_ate_o_proximo] = [false, null];
        }
        $enviados += $enviou ? 1 : 0;
        if ($minutos_ate_o_proximo === null) {
            $pdo->prepare("UPDATE order_bump_agenda SET status = 'concluido', enviados = enviados + ? WHERE id = ?")->execute([$enviou ? 1 : 0, $linha['id']]);
            continue;
        }
        $pdo->prepare("UPDATE order_bump_agenda SET status = 'pendente', enviados = enviados + 1, proximo_em = NOW() + INTERVAL ? MINUTE WHERE id = ?")
            ->execute([$minutos_ate_o_proximo, $linha['id']]);
    }
    $pdo->exec("DELETE FROM order_bump_agenda WHERE status IN ('concluido', 'cancelado') AND criado_em < NOW() - INTERVAL 7 DAY");
    return $enviados;
}

// ===================== ofertas DEPOIS do pagamento =====================

/** Nomes que marcam uma venda como "oferta pós-pagamento": essas vendas não geram nova oferta (sem laço) e aparecem assim em Vendas. */
const PREFIXO_VENDA_UPGRADE_BASICO = 'Upgrade: ';
const PREFIXO_VENDA_EXTRA_BASICO = 'Extra: ';

/**
 * Chamada logo depois do aviso de "pagamento confirmado" (webhook da Omega, cron do Pix e botão "Já fiz o pagamento"). No modo Guiado, oferece ao cliente que
 * acabou de PAGAR o Upsell (upgrade de plano) e o Order Bump (extra), cada um numa mensagem com Aceitar/Recusar; aceitar gera um Pix NOVO só da oferta
 * (tratado em webhook.php, callback "basico::pos::..."). Nunca lança: uma falha aqui não pode atrapalhar a confirmação do pagamento.
 * Fica de fora: renovação/assinatura, fluxo do editor visual (blocos têm a própria ordem) e as próprias vendas de oferta (sem laço).
 */
function ofertasPosPagamentoBasico(PDO $pdo, string $token, array $venda): void
{
    // Entrega por mensagem: o texto da entrega do plano/oferta vai logo depois do aviso de pagamento aprovado. Vale para toda venda que o tenha (inclusive o Upgrade
    // do Upsell e o 1º pagamento de assinatura), por isso vem ANTES das travas abaixo, que só dizem respeito a ofertas.
    if (function_exists('pagamentoEnviarMensagemEntrega')) {
        pagamentoEnviarMensagemEntrega($pdo, $token, $venda);
    }
    try {
        if (!empty($venda['id_operador_fluxo']) || !empty($venda['venda_pai_id']) || (string) ($venda['tipo_cobranca'] ?? '') === 'assinatura') {
            return;
        }
        $nome_pago = trim((string) ($venda['nome_produto'] ?? ''));
        if (strpos($nome_pago, PREFIXO_VENDA_UPGRADE_BASICO) === 0 || strpos($nome_pago, PREFIXO_VENDA_EXTRA_BASICO) === 0) {
            return;
        }
        $id_fluxo = (int) ($venda['id_fluxo'] ?? 0);
        if ($id_fluxo > 0) {
            $st = $pdo->prepare('SELECT modo, dados_fluxograma FROM fluxos WHERE id = ?');
            $st->execute([$id_fluxo]);
        } else {
            $st = $pdo->prepare('SELECT f.modo, f.dados_fluxograma FROM bots b JOIN fluxos f ON f.id = b.id_fluxo_conectado WHERE b.id = ?');
            $st->execute([(int) ($venda['bot_id'] ?? 0)]);
        }
        $fluxo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fluxo || ($fluxo['modo'] ?? '') !== 'basico') {
            return;
        }
        $dados = json_decode((string) $fluxo['dados_fluxograma'], true);
        if (!is_array($dados) || ($dados['ativo'] ?? true) === false) {
            return;
        }
        $id_chat = (string) ($venda['id_telegram'] ?? '');
        $id_cache = (string) ($dados['cache_chat_id'] ?? '');
        $ofertas = $dados['ofertas'] ?? [];
        if ($id_chat === '' || !$ofertas) {
            return;
        }

        // Upsell: só se o plano de destino existe e não é o que o cliente acabou de pagar.
        $destino = planoDaOfertaBasico($dados, 'upsell');
        if ($destino && !planoRecorrenteBasico($destino) && mb_strtolower($nome_pago, 'UTF-8') !== mb_strtolower(trim((string) ($destino['nome'] ?? '')), 'UTF-8')) {
            $desconto = descontoDaOfertaBasico($ofertas['upsell']);
            $valor = round((float) ($destino['valor'] ?? 0) * (1 - $desconto / 100), 2);
            if ($valor > 0) {
                enviarOfertaBasico($token, $id_chat, $ofertas['upsell'], 'basico::pos::up::' . (string) ($destino['id'] ?? ''),
                    'Quer fazer um upgrade para ' . ($destino['nome'] ?? 'outro plano') . ' por R$ ' . number_format($valor, 2, ',', '.') . '?', $id_cache, true);
            }
        }
        // Order Bump: extra avulso (novo Pix só do extra). Assinatura recorrente não recebe (o extra seria cobrado todo ciclo).
        // Em "depois do Pix" (sequência de vários bumps) ele já foi oferecido enquanto o Pix estava em aberto, então não repete depois de pagar.
        $bump = $ofertas['order_bump'] ?? [];
        $extra = max(0.0, (float) ($bump['valor_extra'] ?? 0));
        // Venda de campanha de remarketing com order bump próprio: o extra da campanha (oferecido antes do Pix) substitui o do fluxo.
        if (!empty($bump['ativo']) && $extra > 0 && !orderBumpDepoisDoPixBasico($dados) && !remarketingVendaTemBumpProprio($pdo, (int) ($venda['id'] ?? 0))) {
            enviarOfertaBasico($token, $id_chat, $bump, 'basico::pos::bp::0',
                'Adicione um extra ao seu pedido por R$ ' . number_format($extra, 2, ',', '.') . '.', $id_cache);
        }
    } catch (\Throwable $e) {
        error_log('[ofertas pós-pagamento] ' . $e->getMessage());
    }
}

/**
 * Aceitou a oferta DEPOIS do pagamento: gera um Pix NOVO só da oferta, pelo mesmo caminho do Pix do Guiado (processarEEnviarBloco).
 * Upsell: o plano de destino (com o desconto da oferta), nome "Upgrade: <plano>", com o prazo do plano de destino e a entrega do Upsell (por padrão, a do plano).
 * Order Bump: o valor extra, nome "Extra: <nome>", sem grupo (é avulso). As vendas com esses nomes não geram nova oferta.
 */
function gerarPixOfertaPosBasico(string $token, $id_chat, array $dados_basico, string $tipo, string $id_plano_destino): void {
    $pagamentos = $dados_basico['pagamentos'] ?? [];
    $ofertas = $dados_basico['ofertas'] ?? [];
    if ($tipo === 'up') {
        $plano = planoDaOfertaBasico($dados_basico, 'upsell');
        if (!$plano || (string) ($plano['id'] ?? '') !== $id_plano_destino || planoRecorrenteBasico($plano)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
            return;
        }
        $desconto = descontoDaOfertaBasico($ofertas['upsell']);
        $valor = round((float) ($plano['valor'] ?? 0) * (1 - $desconto / 100), 2);
        $nome = PREFIXO_VENDA_UPGRADE_BASICO . (string) ($plano['nome'] ?? 'Plano');
        $id_produto = 'plano_' . (string) ($plano['id'] ?? '');
        $dias = (int) ($plano['dias_acesso'] ?? 30);
        $unidade = $plano['unidade_acesso'] ?? 'dias';
        $entrega = entregaCompletaDoItemBasico($ofertas['upsell'] ?? [], $plano);   // entrega própria do Upsell; sem ela, a do plano de destino
    } elseif ($tipo === 'bp') {
        $bump = $ofertas['order_bump'] ?? [];
        $valor = round(max(0.0, (float) ($bump['valor_extra'] ?? 0)), 2);
        if (empty($bump['ativo']) || $valor <= 0) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
            return;
        }
        $nome = PREFIXO_VENDA_EXTRA_BASICO . (trim((string) ($bump['nome'] ?? '')) ?: 'Extra');
        $id_produto = 'extra_bump';
        $dias = 30;
        $unidade = 'dias';
        $entrega = ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => ''];
    } else {
        return;
    }
    if ($valor <= 0) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Não foi possível gerar o pagamento desta oferta. Fale com o suporte.']);
        return;
    }
    processarEEnviarBloco($token, $id_chat, ['properties' => [
        'type' => 'pix',
        'nome' => $nome,
        'valor' => $valor,
        'tipo_cobranca' => 'unica',
        'periodicidade' => null,
        'id_produto' => $id_produto,
        'expiracao_minutos' => 15,
        'dias_acesso' => $dias,
        'unidade_acesso' => $unidade,
        'id_grupo' => $entrega['id_grupo'],
        'link_entrega' => $entrega['link_entrega'],
        'mensagem_entrega' => $entrega['mensagem_entrega'],
        // Aba Pagamentos do Guiado: os campos de sempre e, no modelo novo, a configuração completa (funcoes/pagamentos_guiado.php).
    ] + propriedadesPagamentoBasico($pagamentos)], '');
}
