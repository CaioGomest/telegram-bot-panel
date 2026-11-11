<?php
declare(strict_types=1);

/**
 * Peças do modo Guiado compartilhadas entre o webhook do Telegram (webhook.php), o webhook da OmegaPayments e o cron do Pix: envio de mensagem com mídia,
 * planos, oferta com botões Aceitar/Recusar e as ofertas DEPOIS do pagamento (Upsell e Order Bump; 21/10/2026, pedido do cliente: "o upsell deveria aparecer
 * depois que o cara fez o Pix"). Antes essas funções ficavam em webhook.php, que as outras duas entradas não carregam.
 * Precisa de funcoes/fluxo_blocos.php (requisicaoTelegram, resolverCaminhoUploadSeguro) já carregado.
 */

require_once __DIR__ . '/entrega.php';
require_once __DIR__ . '/video_info.php';   // parametrosFormatoVideoTelegram(): width/height/duration do vídeo no álbum
require_once __DIR__ . '/remarketing_ofertas.php';   // venda que veio de campanha de remarketing com order bump próprio (ver ofertasPosPagamentoBasico)

/**
 * Cache de mídia: o Telegram só aceita upload "do zero" no primeiro envio; depois reaproveita o
 * file_id. Quando o fluxo tem um canal/grupo de cache, a mídia sobe uma vez pra lá, o file_id fica
 * guardado em storage/cache_midia.json (chave = bot + arquivo + data de modificação, então trocar a
 * mídia gera um file_id novo) e os envios seguintes pros leads usam só o file_id.
 */
function obterFileIdCacheMidia(string $token, string $id_cache, string $metodo, string $campo, string $caminho): ?string {
    $guardado = lerFileIdCacheMidia($token, $id_cache, $caminho);
    if ($guardado !== null) {
        return $guardado;
    }
    $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_cache], [$campo => $caminho]);
    $file_id = fileIdDeMensagemTelegram(is_array($resposta['result'] ?? null) ? $resposta['result'] : [], $campo);
    guardarFileIdCacheMidia($token, $id_cache, $caminho, $file_id);
    return $file_id;
}

/**
 * Cache SEM canal (05/11/2026, o bot demorava para mandar o "front": sem canal de cache o vídeo das boas-vindas, de 3 a 9 MB, subia inteiro do servidor para o
 * Telegram a CADA /start, e o lead só recebia a mensagem depois do upload; um upload lento chegou a 17 s). Agora o 1º envio de uma mídia a um lead guarda o
 * file_id que o Telegram devolve, e os seguintes mandam só o file_id (sem upload). O file_id é do bot, vale para qualquer chat dele; a chave tem bot + arquivo +
 * data de modificação, então trocar a mídia sobe de novo. Se o Telegram recusar o file_id guardado, o envio cai no upload de sempre.
 */
function arquivoCacheMidia(): string {
    return defined('CACHE_MIDIA_ARQUIVO') ? (string) CACHE_MIDIA_ARQUIVO : dirname(__DIR__) . '/storage/cache_midia.json';
}
function chaveCacheMidia(string $token, string $id_cache, string $caminho): string {
    // Vídeo: sufixo '|v2' (06/11/2026) descarta os file_id guardados antes de o envio levar width/height/duration, que o Telegram mostra em 1:1.
    $versao = preg_match('/\.(mp4|mov|m4v|webm)$/i', $caminho) ? '|v2' : '';
    return sha1($token . '|' . $id_cache . '|' . $caminho . '|' . (string) @filemtime($caminho) . $versao);
}
function lerFileIdCacheMidia(string $token, string $id_cache, string $caminho): ?string {
    $arquivo = arquivoCacheMidia();
    $mapa = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : [];
    $file_id = is_array($mapa) ? ($mapa[chaveCacheMidia($token, $id_cache, $caminho)] ?? '') : '';
    return is_string($file_id) && $file_id !== '' ? $file_id : null;
}
function guardarFileIdCacheMidia(string $token, string $id_cache, string $caminho, ?string $file_id): void {
    if ($file_id === null || $file_id === '') {
        return;
    }
    $arquivo = arquivoCacheMidia();
    $f = @fopen($arquivo, 'c+');   // lê e grava sob a mesma trava: dois envios ao mesmo tempo não apagam a entrada um do outro
    if ($f === false) {
        return;
    }
    if (flock($f, LOCK_EX)) {
        $mapa = json_decode((string) stream_get_contents($f), true);
        $mapa = is_array($mapa) ? $mapa : [];
        $mapa[chaveCacheMidia($token, $id_cache, $caminho)] = $file_id;
        if (count($mapa) > 5000) {
            $mapa = array_slice($mapa, -2500, null, true);   // arquivo que só cresce: guarda as 2.500 entradas mais recentes
        }
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string) json_encode($mapa));
        fflush($f);
        flock($f, LOCK_UN);
    }
    fclose($f);
}
/** file_id da foto (a maior versão) ou do vídeo de uma mensagem devolvida pelo Telegram; null se a mensagem não traz. */
function fileIdDeMensagemTelegram(array $mensagem, string $campo): ?string {
    if ($campo === 'photo') {
        $fotos = is_array($mensagem['photo'] ?? null) ? $mensagem['photo'] : [];
        $maior = $fotos ? end($fotos) : null;
        $file_id = is_array($maior) ? ($maior['file_id'] ?? null) : null;
    } else {
        $file_id = is_array($mensagem['video'] ?? null) ? ($mensagem['video']['file_id'] ?? null) : null;
    }
    return is_string($file_id) && $file_id !== '' ? $file_id : null;
}
/** Depois de um envio com upload que o Telegram aceitou: guarda o file_id da resposta (sendPhoto/sendVideo trazem a mensagem em "result"). */
function guardarFileIdDoEnvioBasico(string $token, string $id_cache, string $caminho, string $campo, array $resposta): void {
    if (!($resposta['ok'] ?? false) || !is_array($resposta['result'] ?? null)) {
        return;
    }
    guardarFileIdCacheMidia($token, $id_cache, $caminho, fileIdDeMensagemTelegram($resposta['result'], $campo));
}
/** O file_id para mandar a mídia sem upload: com canal de cache, sobe para o canal na 1ª vez; sem canal, só usa o que um envio anterior guardou. */
function fileIdDaMidiaBasico(string $token, string $id_cache, string $metodo, string $campo, string $caminho): ?string {
    return $id_cache !== '' ? obterFileIdCacheMidia($token, $id_cache, $metodo, $campo, $caminho) : lerFileIdCacheMidia($token, '', $caminho);
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
        // Video vai SEM legenda e o texto com os botoes logo depois: no iPhone a legenda alarga a bolha e o Telegram recorta o video vertical (teste do Lima, 08/11/2026).
        $legenda_cabe = $midia_tipo !== 'video' && mb_strlen($mensagem) <= 1024;
        $parametros = $legenda_cabe ? ['chat_id' => $id_chat, 'caption' => $mensagem] + $botoes : ['chat_id' => $id_chat];
        $file_id = fileIdDaMidiaBasico($token, $id_cache, $metodo, $campo, $midia_path);
        $enviou = false;
        if ($file_id) {
            $resposta = requisicaoTelegram($token, $metodo, $parametros + [$campo => $file_id]);
            $enviou = (bool) ($resposta['ok'] ?? false);
        }
        if (!$enviou) {
            $resposta_envio = requisicaoTelegram($token, $metodo, $parametros, [$campo => $midia_path]);
            $enviou = (bool) ($resposta_envio['ok'] ?? false);
            guardarFileIdDoEnvioBasico($token, $id_cache, $midia_path, $campo, $resposta_envio);   // o próximo /start manda só o file_id, sem subir o arquivo de novo
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
/**
 * Trava curta por chat para a lista de planos: se a lista saiu há menos de 10 s, o clique seguinte (toques rápidos já enfileirados) não manda outra.
 * Devolve false quando deve ficar quieto. Sem conseguir gravar a trava, manda a lista (é melhor repetir do que deixar o cliente sem planos).
 */
function travarListaPlanosBasico(string $token, $id_chat): bool {
    $pasta = __DIR__ . '/../storage/travas_planos';
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true) && !is_dir($pasta)) {
        return true;
    }
    $f = @fopen($pasta . '/' . md5($token . '|' . $id_chat) . '.trava', 'c+');
    if ($f === false) {
        return true;
    }
    flock($f, LOCK_EX);
    $agora = time();
    $liberada = $agora - (int) stream_get_contents($f) >= 10;
    if ($liberada) {
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string) $agora);
    }
    flock($f, LOCK_UN);
    fclose($f);
    return $liberada;
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
    $file_id = fileIdDaMidiaBasico($token, $id_cache, $metodo, $campo, $caminho);
    if ($file_id) {
        $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat, $campo => $file_id]);
        if ($resposta['ok'] ?? false) {
            return true;
        }
    }
    $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat], [$campo => $caminho]);
    guardarFileIdDoEnvioBasico($token, $id_cache, $caminho, $campo, $resposta);
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
    $subiram = [];   // posições do álbum que subiram como arquivo (o file_id delas é guardado depois)
    foreach ($itens as $i => $it) {
        $eh_video = $it['tipo'] === 'video';
        $file_id = fileIdDaMidiaBasico($token, $id_cache, $eh_video ? 'sendVideo' : 'sendPhoto', $eh_video ? 'video' : 'photo', $it['caminho']);
        $entrada = ['type' => $eh_video ? 'video' : 'photo', 'media' => $file_id ?: 'attach://midia' . $i];
        if (!$file_id) {
            $arquivos['midia' . $i] = $it['caminho'];
            $subiram[$i] = true;
        }
        if ($eh_video) {
            $entrada += parametrosFormatoVideoTelegram($it['caminho']);   // width/height/duration: sem eles o álbum mostra o vídeo quadrado
        }
        $media[] = $entrada;
    }
    $resposta = requisicaoTelegram($token, 'sendMediaGroup', ['chat_id' => $id_chat, 'media' => json_encode($media)], $arquivos);
    if ($resposta['ok'] ?? false) {
        // sendMediaGroup devolve a lista das mensagens, na ordem do álbum
        foreach (array_keys($subiram) as $i) {
            $mensagem = $resposta['result'][$i] ?? null;
            $campo_item = $itens[$i]['tipo'] === 'video' ? 'video' : 'photo';
            guardarFileIdCacheMidia($token, $id_cache, $itens[$i]['caminho'], is_array($mensagem) ? fileIdDeMensagemTelegram($mensagem, $campo_item) : null);
        }
        return;
    }
    if (($resposta['description'] ?? '') === 'resposta inválida') {
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
/** $teclado_planos: com o CTA desligado, os botões dos planos vão na própria mensagem de boas-vindas (vazio = sem botão). */
function enviarBoasVindasBasico(string $token, $id_chat, array $boas_vindas, string $id_cache = '', string $teclado_planos = ''): void {
    $mensagem = trim((string) ($boas_vindas['mensagem'] ?? '')) ?: 'Bem-vindo(a)!';
    $texto_cta = trim((string) ($boas_vindas['texto_cta'] ?? '')) ?: 'Ver Planos';
    $teclado = boasVindasMostraCtaBasico($boas_vindas)
        ? json_encode(['inline_keyboard' => [[['text' => $texto_cta, 'callback_data' => 'basico::ver_planos'] + estiloBotaoBasicoValor((string) ($boas_vindas['cor_cta'] ?? ''))]]])
        : $teclado_planos;
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

/**
 * Plano do upsell da posição $indice da sequência (0 = o próprio ofertas.upsell). Do 2º em diante vale se o 1º upsell está ligado; o plano é o da lista ou o próprio
 * (id "up_exclusivo_<posição + 1>"). Null se o plano sumiu ou está incompleto.
 */
function planoDaSequenciaUpsellBasico(array $dados_basico, int $indice): ?array {
    if ($indice <= 0) {
        return planoDaOfertaBasico($dados_basico, 'upsell');
    }
    $oferta = sequenciasUpsellBasico($dados_basico)[$indice] ?? null;
    if (!is_array($oferta)) {
        return null;
    }
    if ((string) ($oferta['id_plano_destino'] ?? '') === ID_PLANO_UPSELL_PROPRIO_BASICO) {
        return planoProprioDoUpsellBasico($oferta, $indice);
    }
    return localizarPlanoBasico($dados_basico['planos'] ?? [], (string) ($oferta['id_plano_destino'] ?? ''));
}
/** Parte do callback_data do botão do upsell: "up" no 1º (como sempre) e "u2", "u3"... nos seguintes. */
function tipoCallbackUpsellBasico(int $indice): string {
    return $indice <= 0 ? 'up' : 'u' . ($indice + 1);
}
/**
 * Próximo upsell que dá para oferecer, a partir da posição $desde: plano que existe, que não é recorrente, de valor maior que zero e diferente do que o cliente
 * acabou de pagar (pelo nome). Devolve [posição, plano, valor com desconto] ou null.
 */
function proximoUpsellOferecivelBasico(array $dados_basico, int $desde, string $nome_pago): ?array {
    $sequencias = sequenciasUpsellBasico($dados_basico);
    foreach (array_keys($sequencias) as $i) {
        if ($i < $desde) {
            continue;
        }
        $plano = planoDaSequenciaUpsellBasico($dados_basico, $i);
        if (!$plano || planoRecorrenteBasico($plano) || mb_strtolower($nome_pago, 'UTF-8') === mb_strtolower(trim((string) ($plano['nome'] ?? '')), 'UTF-8')) {
            continue;
        }
        $valor = valorComDescontoDaOfertaBasico($sequencias[$i], (float) ($plano['valor'] ?? 0));
        if ($valor > 0) {
            return [$i, $plano, $valor];
        }
    }
    return null;
}
/**
 * Posição do upsell cuja venda "Upgrade: <nome>" acabou de ser paga: o ÚLTIMO da sequência com esse nome de plano (se dois repetem o plano, a oferta não volta ao
 * anterior). -1 se nenhum bate (upsell apagado ou renomeado depois da oferta: a sequência para).
 */
function indiceUpsellPagoBasico(array $dados_basico, string $nome_pago): int {
    $achou = -1;
    foreach (array_keys(sequenciasUpsellBasico($dados_basico)) as $i) {
        $plano = planoDaSequenciaUpsellBasico($dados_basico, $i);
        if ($plano && mb_strtolower($nome_pago, 'UTF-8') === mb_strtolower(trim((string) ($plano['nome'] ?? '')), 'UTF-8')) {
            $achou = $i;
        }
    }
    return $achou;
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
    $mensagem = trim((string) ($oferta['mensagem'] ?? '')) ?: (count($planos) > 1 ? 'Que tal uma destas opções?' : 'Que tal esta opção?');
    $botoes = [];
    foreach ($planos as $posicao => $plano) {
        $oferta_do_plano = !empty($plano['exclusivo_oferta']) ? [] : $oferta;   // plano criado no Downsell vale pelo valor digitado
        $valor = valorComDescontoDaOfertaBasico($oferta_do_plano, (float) ($plano['valor'] ?? 0));
        $texto_desconto = textoDescontoDaOfertaBasico($oferta_do_plano);   // "30%" ou "R$ 5,00"
        $rotulo = trim((string) ($plano['nome'] ?? 'Plano')) . ' — R$ ' . number_format($valor, 2, ',', '.') . (planoRecorrenteBasico($plano) ? PERIODICIDADES_BASICO[periodicidadePlanoBasico($plano)] : '')
            . ($texto_desconto !== '' ? ' (-' . $texto_desconto . ')' : '');
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
const DOWNSELL_MAXIMO_MINUTOS = 10080;   // 7 dias: o maior tempo da lista da tela (assets/edicao_fluxo_basico.js, ESPERA_OPCOES)
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
    if ($id_venda <= 0 || !downsellDepoisDoPixBasico($dados_basico)) {
        return;
    }
    if (!planosDoDownsellSemOEscolhidoBasico($dados_basico, $id_plano)) {
        cancelarDownsellsDoLeadBasico($pdo, $bot_id, $id_telegram);   // a contagem do /start (agendarDownsellSemCliqueBasico) não vale mais: ele já escolheu
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
 * Downsell para quem não toca em nada (04/11/2026, pedido do dono): no /start a contagem já começa, com o tempo da 1ª sequência. Se o lead gerar um Pix,
 * agendarDownsellBasico cancela esta linha e a contagem recomeça do Pix; tocar num plano do downsell também encerra. Sem plano escolhido, id_plano = ''
 * (a oferta mostra todos os planos do Downsell) e venda_id = -id do lead (único por lead, cabe no UNIQUE(venda_id) sem mudar a tabela). Quem já comprou
 * neste bot não entra. Nunca lança: falhar aqui não pode atrapalhar as boas-vindas.
 */
function agendarDownsellSemCliqueBasico(PDO $pdo, int $bot_id, string $id_telegram, array $dados_basico): void {
    if (!downsellDepoisDoPixBasico($dados_basico) || !planosDoDownsellSemOEscolhidoBasico($dados_basico, '')) {
        return;
    }
    try {
        $lead = $pdo->prepare("SELECT id FROM leads WHERE bot_id = ? AND id_telegram = ? ORDER BY id LIMIT 1");
        $lead->execute([$bot_id, $id_telegram]);
        $id_lead = (int) $lead->fetchColumn();
        if ($id_lead <= 0) {
            return;
        }
        $comprou = $pdo->prepare("SELECT 1 FROM vendas WHERE bot_id = ? AND id_telegram = ? AND status = 'pago' LIMIT 1");
        $comprou->execute([$bot_id, $id_telegram]);
        if ($comprou->fetchColumn()) {
            return;
        }
        $espera = temporizadorDownsellBasico(sequenciasDownsellBasico($dados_basico)[0] ?? $dados_basico['ofertas']['downsell'])['espera'];
        cancelarDownsellsDoLeadBasico($pdo, $bot_id, $id_telegram);   // novo /start recomeça a contagem
        $pdo->prepare("DELETE FROM downsell_agenda WHERE venda_id = ?")->execute([-$id_lead]);
        $pdo->prepare("INSERT INTO downsell_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em) VALUES (?, ?, ?, '', NOW() + INTERVAL ? MINUTE)")
            ->execute([$bot_id, $id_telegram, -$id_lead, $espera]);
    } catch (\Throwable $e) {
        error_log('[downsell] agendar sem clique: ' . $e->getMessage());
    }
}

/**
 * Resolve uma linha vencida da agenda. Devolve [enviou, minutos até o próximo envio]: minutos null = a sequência acabou (lead já pagou, downsell
 * desligado ou sem plano sobrando, fluxo trocado, ou foi o último envio).
 */
function enviarDownsellAgendadoBasico(PDO $pdo, array $linha): array {
    if ((int) $linha['venda_id'] > 0) {
        $pagou = $pdo->prepare("SELECT 1 FROM vendas WHERE bot_id = ? AND id_telegram = ? AND status = 'pago' AND id >= ? LIMIT 1");
        $pagou->execute([$linha['bot_id'], $linha['id_telegram'], $linha['venda_id']]);
    } else {
        // Agendado no /start (sem Pix): vale qualquer compra feita depois do /start.
        $pagou = $pdo->prepare("SELECT 1 FROM vendas WHERE bot_id = ? AND id_telegram = ? AND status = 'pago' AND criado_em >= ? LIMIT 1");
        $pagou->execute([$linha['bot_id'], $linha['id_telegram'], $linha['criado_em']]);
    }
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

/**
 * Order Bump PRÓPRIO do plano (05/11/2026, pedido do dono, igual ao da Shark): planos[i].order_bump = {ativo, nome, valor_extra, mensagem, cta, texto_aceitar, texto_recusar,
 * cor_aceitar, cor_recusar, midias (até 3), entrega_tipo ('plano' | 'nenhuma' | 'link' | 'mensagem'), link_entrega, mensagem_entrega}. Ligado, SUBSTITUI o order bump do
 * fluxo para aquele plano (um só item, sem sequência). Desligado ou ausente: vale o do fluxo, como sempre.
 */
function planoTemBumpProprioBasico(?array $plano): bool {
    return is_array($plano) && is_array($plano['order_bump'] ?? null) && !empty($plano['order_bump']['ativo']);
}
/**
 * Como o order bump se comporta para UM plano: ['proprio' => bool, 'quando' => 'depois_pix'|'depois_pagamento'|'', 'itens' => [...]].
 * Do plano: o "quando enviar" e a espera seguem os do fluxo (se o order bump do fluxo estiver ligado); sem isso, depois do Pix, em 10 minutos.
 * Do fluxo: o que sempre valeu (itens com valor extra maior que zero, no máximo 5; fluxo antigo sem `itens`: o próprio order_bump é o único item).
 */
function configOrderBumpDoPlanoBasico(array $dados_basico, ?array $plano = null): array {
    $fluxo = $dados_basico['ofertas']['order_bump'] ?? [];
    if (planoTemBumpProprioBasico($plano)) {
        $b = $plano['order_bump'];
        $fluxo_ligado = !empty($fluxo['ativo']);
        // 06/11/2026 (cliente: "seleciona o plano e gera direto o Pix, sem a opção do order bump"): o order bump do plano é oferecido assim que o cliente escolhe o plano,
        // ANTES do Pix, como na Shark ('antes_pix'). Só segue outro momento se o próprio order bump do plano trouxer 'quando' = depois_pix ou depois_pagamento.
        $quando = (string) ($b['quando'] ?? '');
        $quando = in_array($quando, ['depois_pix', 'depois_pagamento'], true) ? $quando : 'antes_pix';
        if ((int) ($b['espera_minutos'] ?? 0) < 1) {
            $b['espera_minutos'] = $fluxo_ligado ? (int) ($fluxo['espera_minutos'] ?? 0) : 0;   // 0 = padrão (esperaOrderBumpBasico)
        }
        $itens = (float) ($b['valor_extra'] ?? 0) > 0 ? [$b + ['ativo' => true, 'do_plano' => true]] : [];
        return ['proprio' => true, 'quando' => $quando, 'itens' => $itens];
    }
    $bruto = is_array($fluxo['itens'] ?? null) && $fluxo['itens'] ? array_values($fluxo['itens']) : [$fluxo];
    $itens = [];
    if (!empty($fluxo['ativo'])) {
        foreach (array_slice($bruto, 0, ORDER_BUMP_MAXIMO) as $item) {
            if (is_array($item) && (float) ($item['valor_extra'] ?? 0) > 0) {
                $itens[] = $item + ['ativo' => true];
            }
        }
    }
    return ['proprio' => false, 'quando' => (string) ($fluxo['quando'] ?? ''), 'itens' => $itens];
}
/** O plano que o cliente pagou, pelo nome da venda (a venda de order bump junto vem como "Plano + Extra"). */
function planoPagoDaVendaBasico(array $dados_basico, string $nome_pago): ?array {
    $nome_pago = mb_strtolower(trim($nome_pago), 'UTF-8');
    foreach ((array) ($dados_basico['planos'] ?? []) as $p) {
        $nome = mb_strtolower(trim((string) ($p['nome'] ?? '')), 'UTF-8');
        if ($nome !== '' && ($nome_pago === $nome || strpos($nome_pago, $nome . ' + ') === 0)) {
            return $p;
        }
    }
    return null;
}

/** Itens do Order Bump com valor extra maior que zero (no máximo 5), na ordem da tela. Com $plano: o order bump próprio dele (se ligado) ou o do fluxo. */
function itensOrderBumpBasico(array $dados_basico, ?array $plano = null): array {
    $bump = $dados_basico['ofertas']['order_bump'] ?? [];
    if (!planoTemBumpProprioBasico($plano)) {
        // Sem plano próprio: sempre foi assim (não olha se está ligado; quem chama confere `ativo`).
        $bruto = is_array($bump['itens'] ?? null) && $bump['itens'] ? array_values($bump['itens']) : [$bump];
        $itens = [];
        foreach (array_slice($bruto, 0, ORDER_BUMP_MAXIMO) as $item) {
            if (is_array($item) && (float) ($item['valor_extra'] ?? 0) > 0) {
                $itens[] = $item + ['ativo' => true];
            }
        }
        return $itens;
    }
    return configOrderBumpDoPlanoBasico($dados_basico, $plano)['itens'];
}
/** O plano tem order bump próprio para oferecer ANTES do Pix (logo que o cliente toca no plano)? Devolve o item ou null. */
function orderBumpAntesDoPixBasico(array $dados_basico, ?array $plano): ?array {
    // Vale para o bump do PLANO (padrão) e para o do FLUXO com quando = 'antes_pix' (06/11/2026). Assinatura recorrente nunca recebe.
    if ($plano === null || planoRecorrenteBasico($plano)) {
        return null;
    }
    $cfg = configOrderBumpDoPlanoBasico($dados_basico, $plano);
    return $cfg['quando'] === 'antes_pix' ? ($cfg['itens'][0] ?? null) : null;
}
function orderBumpDepoisDoPixBasico(array $dados_basico, ?array $plano = null): bool {
    if (planoTemBumpProprioBasico($plano)) {
        $cfg = configOrderBumpDoPlanoBasico($dados_basico, $plano);
        return $cfg['quando'] === 'depois_pix' && $cfg['itens'] !== [];
    }
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

/** Order bump do plano: a "Mensagem CTA" (opcional) vai numa linha própria no fim do texto da oferta. Sem CTA, o item segue igual. */
function comCtaOrderBumpBasico(array $item): array {
    if (!empty($item['do_plano'])) {   // order bump do plano: botões padrão da Shark quando o dono não escreveu os dele
        $item['texto_aceitar'] = trim((string) ($item['texto_aceitar'] ?? '')) ?: "✅ ADICIONAR";
        $item['texto_recusar'] = trim((string) ($item['texto_recusar'] ?? '')) ?: "❌ NÃO QUERO";
    }
    $cta = trim((string) ($item['cta'] ?? ''));
    if ($cta === '') {
        return $item;
    }
    $item['mensagem'] = (trim((string) ($item['mensagem'] ?? '')) ?: textoPadraoOrderBumpBasico($item)) . "

" . $cta;
    return $item;
}
/**
 * Entrega do order bump do plano quando o extra vai NO MESMO Pix do plano (uma venda só, uma entrega só): vale a do plano e, se o bump tem a própria entrega por
 * mensagem ou link, o texto/endereço segue na mensagem de entrega. Grupo próprio não existe aqui (o editor não oferece). Item do fluxo (sem entrega_tipo) não muda nada.
 */
function entregaDoPixComBumpBasico(array $entrega, array $item): array {
    $tipo = (string) ($item['entrega_tipo'] ?? '');
    $extra = '';
    if ($tipo === 'mensagem') {
        $extra = mensagemEntregaValida($item['mensagem_entrega'] ?? '');
    } elseif ($tipo === 'link') {
        $extra = linkEntregaValido($item['link_entrega'] ?? '');
    }
    if ($extra !== '') {
        $entrega['mensagem_entrega'] = mb_substr(trim(trim((string) ($entrega['mensagem_entrega'] ?? '')) . "

" . $extra), 0, ENTREGA_MENSAGEM_MAX);
    }
    return $entrega;
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
    $plano = localizarPlanoBasico($dados_basico['planos'] ?? [], $id_plano);
    if ($id_venda <= 0 || !$plano || !orderBumpDepoisDoPixBasico($dados_basico, $plano)) {
        return;
    }
    if (planoRecorrenteBasico($plano)) {
        return;   // assinatura recorrente não recebe order bump: o extra seria cobrado em todo ciclo
    }
    try {
        $espera = esperaOrderBumpBasico(itensOrderBumpBasico($dados_basico, $plano)[0]);
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
    if (!is_array($dados) || ($dados['ativo'] ?? true) === false) {
        return [false, null];
    }
    $plano = localizarPlanoBasico($dados['planos'] ?? [], (string) $linha['id_plano']);
    if (!$plano || !orderBumpDepoisDoPixBasico($dados, $plano)) {
        return [false, null];
    }
    $itens = itensOrderBumpBasico($dados, $plano);
    $posicao = (int) $linha['enviados'];
    if (!$plano || planoRecorrenteBasico($plano) || !isset($itens[$posicao])) {
        return [false, null];
    }
    $item = $itens[$posicao];
    enviarOfertaBasico((string) $linha['token'], $linha['id_telegram'], comCtaOrderBumpBasico($item), 'basico::bs::' . (string) $linha['id_plano'] . '::' . $posicao,
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
        $pagou_upgrade = strpos($nome_pago, PREFIXO_VENDA_UPGRADE_BASICO) === 0;
        if (!$pagou_upgrade && strpos($nome_pago, PREFIXO_VENDA_EXTRA_BASICO) === 0) {
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
        $algum_plano_com_bump = false;
        foreach ((array) ($dados['planos'] ?? []) as $p_bump) {
            $algum_plano_com_bump = $algum_plano_com_bump || planoTemBumpProprioBasico(is_array($p_bump) ? $p_bump : null);
        }
        if ($id_chat === '' || (!$ofertas && !$algum_plano_com_bump)) {
            return;
        }

        // Upsell em sequência: depois de pagar o PLANO vem o 1º upsell que dá para oferecer; depois de pagar um UPSELL ("Upgrade: <plano>"), o seguinte da sequência.
        // Sem seguinte, acabou (e um Upgrade pago nunca gera Order Bump: o extra é do plano).
        $pago_do_upsell = $pagou_upgrade ? trim(mb_substr($nome_pago, mb_strlen(PREFIXO_VENDA_UPGRADE_BASICO))) : $nome_pago;
        $desde_upsell = $pagou_upgrade ? indiceUpsellPagoBasico($dados, $pago_do_upsell) + 1 : 0;
        if (!$pagou_upgrade || $desde_upsell > 0) {
            $proximo_upsell = proximoUpsellOferecivelBasico($dados, $desde_upsell, $pago_do_upsell);
            if ($proximo_upsell) {
                [$posicao_upsell, $destino, $valor] = $proximo_upsell;
                enviarOfertaBasico($token, $id_chat, sequenciasUpsellBasico($dados)[$posicao_upsell], 'basico::pos::' . tipoCallbackUpsellBasico($posicao_upsell) . '::' . (string) ($destino['id'] ?? ''),
                    'Quer fazer um upgrade para ' . ($destino['nome'] ?? 'outro plano') . ' por R$ ' . number_format($valor, 2, ',', '.') . '?', $id_cache, true);
            }
        }
        if ($pagou_upgrade) {
            return;
        }
        // Order Bump: extra avulso (novo Pix só do extra). Assinatura recorrente não recebe (o extra seria cobrado todo ciclo).
        // Em "depois do Pix" (sequência de vários bumps) ele já foi oferecido enquanto o Pix estava em aberto, então não repete depois de pagar.
        // Plano com order bump PRÓPRIO: ele substitui o do fluxo (o callback leva o id do plano; "0" é o do fluxo, como antes).
        $plano_pago = planoPagoDaVendaBasico($dados, $nome_pago);
        $bump_do_plano = planoTemBumpProprioBasico($plano_pago) && !planoRecorrenteBasico($plano_pago) ? (itensOrderBumpBasico($dados, $plano_pago)[0] ?? null) : null;
        $bump = $bump_do_plano ?? ($ofertas['order_bump'] ?? []);
        $bump_ligado = $bump_do_plano !== null || (!empty($bump['ativo']) && !planoTemBumpProprioBasico($plano_pago));
        $depois_do_pix = orderBumpDepoisDoPixBasico($dados, $plano_pago);
        $extra = max(0.0, (float) ($bump['valor_extra'] ?? 0));
        // Venda de campanha de remarketing com order bump próprio: o extra da campanha (oferecido antes do Pix) substitui o do fluxo.
        if ($bump_ligado && $extra > 0 && !$depois_do_pix && ($bump_do_plano !== null ? orderBumpAntesDoPixBasico($dados, $plano_pago) === null : (string) ($ofertas['order_bump']['quando'] ?? '') !== 'antes_pix') && !remarketingVendaTemBumpProprio($pdo, (int) ($venda['id'] ?? 0))) {
            enviarOfertaBasico($token, $id_chat, comCtaOrderBumpBasico($bump), 'basico::pos::bp::' . ($bump_do_plano !== null ? (string) ($plano_pago['id'] ?? '0') : '0'),
                $bump_do_plano !== null ? textoPadraoOrderBumpBasico($bump) : 'Adicione um extra ao seu pedido por R$ ' . number_format($extra, 2, ',', '.') . '.', $id_cache);
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
    $posicao_upsell = 0;
    if (preg_match('/^u(\d+)$/', $tipo, $m_up) && (int) $m_up[1] >= 2) {   // "u2", "u3"...: upsell da sequência (o 1º segue como "up")
        $posicao_upsell = (int) $m_up[1] - 1;
        $tipo = 'up';
    }
    if ($tipo === 'up') {
        $oferta_upsell = sequenciasUpsellBasico($dados_basico)[$posicao_upsell] ?? [];
        $plano = planoDaSequenciaUpsellBasico($dados_basico, $posicao_upsell);
        if (!$plano || (string) ($plano['id'] ?? '') !== $id_plano_destino || planoRecorrenteBasico($plano)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
            return;
        }
        $valor = valorComDescontoDaOfertaBasico($oferta_upsell, (float) ($plano['valor'] ?? 0));   // % ou R$ fixo
        $nome = PREFIXO_VENDA_UPGRADE_BASICO . (string) ($plano['nome'] ?? 'Plano');
        $id_produto = 'plano_' . (string) ($plano['id'] ?? '');
        $dias = (int) ($plano['dias_acesso'] ?? 30);
        $unidade = $plano['unidade_acesso'] ?? 'dias';
        $entrega = entregaCompletaDoItemBasico($oferta_upsell, $plano);   // entrega própria do Upsell; sem ela, a do plano de destino
    } elseif ($tipo === 'bp') {
        $bump = $ofertas['order_bump'] ?? [];
        $entrega = ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => ''];
        // "0" = order bump do fluxo; outro valor = id do plano que tem order bump próprio.
        $plano_do_bump = $id_plano_destino !== '0' && $id_plano_destino !== '' ? localizarPlanoBasico($dados_basico['planos'] ?? [], $id_plano_destino) : null;
        if ($plano_do_bump !== null && planoTemBumpProprioBasico($plano_do_bump) && !planoRecorrenteBasico($plano_do_bump)) {
            $bump = itensOrderBumpBasico($dados_basico, $plano_do_bump)[0] ?? [];
            // Venda do extra avulsa: "mesmo delivery do plano" = nada de novo (o plano já foi entregue); link e mensagem do próprio bump valem.
            $tipo_entrega_bump = (string) ($bump['entrega_tipo'] ?? '');
            if ($tipo_entrega_bump === 'link' || $tipo_entrega_bump === 'mensagem') {
                $entrega = entregaCompletaDoItemBasico($bump);
            }
        } elseif (planoTemBumpProprioBasico($plano_do_bump)) {
            $bump = [];
        }
        $valor = round(max(0.0, (float) ($bump['valor_extra'] ?? 0)), 2);
        $do_plano = planoTemBumpProprioBasico($plano_do_bump);   // do plano: já vem resolvido e ligado; do fluxo: precisa estar ligado
        if ($valor <= 0 || (!$do_plano && empty($bump['ativo']))) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
            return;
        }
        $nome = PREFIXO_VENDA_EXTRA_BASICO . (trim((string) ($bump['nome'] ?? '')) ?: 'Extra');
        $id_produto = 'extra_bump';
        $dias = 30;
        $unidade = 'dias';
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
