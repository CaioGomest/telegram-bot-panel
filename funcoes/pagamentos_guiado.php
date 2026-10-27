<?php
declare(strict_types=1);

/**
 * Aba Pagamentos do Fluxo Guiado, modelo novo (26/10/2026, "versao" = 2 em dados_fluxograma.pagamentos): a mensagem do Pix gerado e a do pagamento aprovado
 * deixam de ser uma frase só e passam a ter layout, mídias (até 3), texto com formatação e variáveis, mensagens antes do código e dos botões, botões com texto
 * próprio, QR Code antes/junto/depois do texto e botão de acesso na aprovação. Fluxo salvo antes disso (sem "versao") segue EXATAMENTE como sempre.
 *
 * Tudo que decide "o que é enviado, em que ordem" mora em funções PURAS (pagamentoPlanoPix, pagamentoPlanoAprovacao): sem banco, sem Telegram, testáveis.
 * Os envios (pagamentoEnviarPix, pagamentoEnviarAprovacao) só executam o plano. A prévia do editor (assets/edicao_fluxo_basico.js) refaz o mesmo plano em
 * JavaScript; tests/guiado/unitario_pagamentos_previa.js confere que os dois dão o mesmo resultado.
 *
 * Texto: o dono escreve um subconjunto de HTML do Telegram (b, i, u, s, code, pre, blockquote, tg-spoiler) e variáveis entre chaves. Tudo o mais é escapado
 * (nada de <a>, de atributo, de tag que o Telegram não conhece) e as tags são fechadas/balanceadas, porque o Telegram recusa a mensagem inteira com HTML torto.
 */

const PAGAMENTO_MIDIAS_MAXIMO = 3;
const PAGAMENTO_TEXTO_MAXIMO = 3000;
const PAGAMENTO_CURTO_MAXIMO = 300;          // "mensagem antes do código" / "antes dos botões"
const PAGAMENTO_BOTAO_MAXIMO = 40;           // texto de botão
const PAGAMENTO_LEGENDA_MAXIMO = 1024;       // limite do Telegram para legenda de foto

const PAGAMENTO_TEXTO_PIX_PADRAO = "⏳ <b>Aguardando pagamento...</b>\nSeu acesso será liberado automaticamente em até 1 minuto após a confirmação do pagamento.\n\n👇 <b>Toque no código abaixo para copiar e pague no app do seu banco:</b>";
const PAGAMENTO_TEXTO_APROVADO_PADRAO = "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";
const PAGAMENTO_LEGENDA_QR = 'Escaneie o QR Code para pagar.';

/** nome da tag escrita pelo dono => tag que vai para o Telegram */
const PAGAMENTO_TAGS = [
    'b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 'ins' => 'u', 's' => 's', 'strike' => 's', 'del' => 's',
    'code' => 'code', 'pre' => 'pre', 'blockquote' => 'blockquote', 'tg-spoiler' => 'tg-spoiler',
];
const PAGAMENTO_QR_POSICOES = ['antes', 'junto', 'depois', 'nao'];
const PAGAMENTO_CODIGO_FORMATOS = ['inline', 'bloco', 'simples'];
const PAGAMENTO_LAYOUTS = ['padrao', 'compacto'];

function pagamentoEscapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "Bom dia", "Boa tarde" ou "Boa noite" pela hora (0–23). */
function pagamentoSaudacao(?int $hora = null): string
{
    $hora = $hora ?? (int) date('G');
    return $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
}

function pagamentoValorTexto(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * Variáveis que o texto aceita. Valor comum é escapado; ['html' => ...] entra como está (só o código Pix, já formatado por nós).
 * @return array<string, string|array{html: string}>
 */
function pagamentoVariaveis(string $nome, string $plano, float $valor, ?int $hora = null): array
{
    $primeiro = trim((string) preg_split('/\s+/u', trim($nome), 2)[0]);
    return [
        'nome' => $primeiro !== '' ? $primeiro : 'Cliente',
        'plano' => $plano,
        'valor' => pagamentoValorTexto($valor),
        'saudacao' => pagamentoSaudacao($hora),
    ];
}

/**
 * Texto do dono -> HTML que o Telegram aceita: tags permitidas balanceadas, resto escapado, {variável} trocada (e escapada). Variável desconhecida fica como está.
 * @param array<string, string|array{html: string}> $variaveis
 */
function pagamentoHtmlSeguro(string $texto, array $variaveis = [], int $maximo = PAGAMENTO_TEXTO_MAXIMO): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    if (mb_strlen($texto) > $maximo) {
        $texto = mb_substr($texto, 0, $maximo);
    }
    $partes = preg_split('~(</?[A-Za-z][A-Za-z0-9-]*\s*>)~', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
    $saida = '';
    $pilha = [];
    foreach ($partes as $i => $parte) {
        if ($parte === '') {
            continue;
        }
        if ($i % 2 === 1 && preg_match('~^<(/?)([A-Za-z][A-Za-z0-9-]*)\s*>$~', $parte, $m) && isset(PAGAMENTO_TAGS[strtolower($m[2])])) {
            $tag = PAGAMENTO_TAGS[strtolower($m[2])];
            if ($m[1] === '') {
                $saida .= "<$tag>";
                $pilha[] = $tag;
                continue;
            }
            $achou = null;
            for ($k = count($pilha) - 1; $k >= 0; $k--) {
                if ($pilha[$k] === $tag) {
                    $achou = $k;
                    break;
                }
            }
            if ($achou === null) {
                continue;   // fechamento sem abertura: some
            }
            for ($k = count($pilha) - 1; $k >= $achou; $k--) {
                $saida .= '</' . $pilha[$k] . '>';
            }
            $pilha = array_slice($pilha, 0, $achou);
            continue;
        }
        // texto comum (ou "tag" que não é permitida: vira texto escapado)
        foreach (preg_split('/(\{[a-z_]+\})/', $parte, -1, PREG_SPLIT_DELIM_CAPTURE) as $j => $pedaco) {
            if ($j % 2 === 1 && array_key_exists(trim($pedaco, '{}'), $variaveis)) {
                $valor = $variaveis[trim($pedaco, '{}')];
                $saida .= is_array($valor) ? (string) ($valor['html'] ?? '') : pagamentoEscapar((string) $valor);
            } else {
                $saida .= pagamentoEscapar($pedaco);
            }
        }
    }
    for ($k = count($pilha) - 1; $k >= 0; $k--) {
        $saida .= '</' . $pilha[$k] . '>';
    }
    return $saida;
}

/** HTML do Telegram -> texto puro (para a mensagem de reserva quando o Telegram recusa a formatação e para medir a legenda). */
function pagamentoHtmlParaTexto(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Lista de mídias {tipo, path}: só foto/vídeo com caminho preenchido (e, se informado, válido), até 3. */
function pagamentoMidias($bruto, ?callable $caminho_valido = null): array
{
    $lista = [];
    foreach (is_array($bruto) ? $bruto : [] as $m) {
        if (!is_array($m) || !in_array($m['tipo'] ?? '', ['image', 'video'], true) || (string) ($m['path'] ?? '') === '') {
            continue;
        }
        if ($caminho_valido !== null && !$caminho_valido((string) $m['path'])) {
            continue;
        }
        $lista[] = ['tipo' => (string) $m['tipo'], 'path' => (string) $m['path']];
    }
    return array_slice($lista, 0, PAGAMENTO_MIDIAS_MAXIMO);
}

function pagamentoTextoCurto($valor, string $padrao, int $maximo): string
{
    $t = trim((string) $valor);
    $t = mb_substr($t, 0, $maximo);
    return $t !== '' ? $t : $padrao;
}

/**
 * Configuração da aba Pagamentos já normalizada (tudo no tipo e nos limites certos, com os padrões). Serve para gravar (api.php) e para ler (bot).
 * Campos antigos que o resto do sistema ainda lê (mostrar_copiar, mostrar_qrcode, mostrar_confirmar) vão junto, sempre coerentes com os novos.
 */
function pagamentoConfig(array $p, ?callable $caminho_valido = null): array
{
    $item = static function ($bruto, string $texto_padrao, int $max, bool $ativo_padrao) {
        $bruto = is_array($bruto) ? $bruto : [];
        return ['ativo' => array_key_exists('ativo', $bruto) ? !empty($bruto['ativo']) : $ativo_padrao, 'texto' => pagamentoTextoCurto($bruto['texto'] ?? '', $texto_padrao, $max)];
    };
    $layout = in_array($p['layout'] ?? '', PAGAMENTO_LAYOUTS, true) ? (string) $p['layout'] : 'padrao';
    $qr = in_array($p['qr_exibicao'] ?? '', PAGAMENTO_QR_POSICOES, true) ? (string) $p['qr_exibicao'] : 'depois';
    $formato = in_array($p['codigo_formato'] ?? '', PAGAMENTO_CODIGO_FORMATOS, true) ? (string) $p['codigo_formato'] : 'inline';
    $copiar = $item($p['btn_copiar'] ?? null, '📋 Copiar Código', PAGAMENTO_BOTAO_MAXIMO, true);
    $verificar = $item($p['btn_verificar'] ?? null, '✅ Verificar Status', PAGAMENTO_BOTAO_MAXIMO, true);
    return [
        'versao' => 2,
        'layout' => $layout,
        'midias_gerado' => pagamentoMidias($p['midias_gerado'] ?? [], $caminho_valido),
        'msg_instrucoes' => mb_substr(trim((string) ($p['msg_instrucoes'] ?? '')), 0, PAGAMENTO_TEXTO_MAXIMO),
        'qr_exibicao' => $qr,
        'qrcode_definido' => true,
        'mostrar_qrcode' => $qr !== 'nao',
        'codigo_formato' => $formato,
        'codigo_na_mensagem' => !empty($p['codigo_na_mensagem']),
        'antes_codigo' => $item($p['antes_codigo'] ?? null, 'Copie o código abaixo:', PAGAMENTO_CURTO_MAXIMO, false),
        'antes_botoes' => $item($p['antes_botoes'] ?? null, 'Após efetuar o pagamento, clique no botão abaixo', PAGAMENTO_CURTO_MAXIMO, false),
        'btn_copiar' => $copiar,
        'btn_verificar' => $verificar,
        'mostrar_copiar' => $copiar['ativo'],
        'mostrar_confirmar' => $verificar['ativo'],
        'msg_confirmado' => mb_substr(trim((string) ($p['msg_confirmado'] ?? '')), 0, PAGAMENTO_TEXTO_MAXIMO),
        'midias_aprovado' => pagamentoMidias($p['midias_aprovado'] ?? [], $caminho_valido),
        'botao_acesso' => $item($p['botao_acesso'] ?? null, '🔓 Acessar Conteúdo', PAGAMENTO_BOTAO_MAXIMO, true),
    ];
}

function pagamentoModoNovo(array $pagamentos): bool
{
    return (int) ($pagamentos['versao'] ?? 0) >= 2;
}

/**
 * O que a aba Pagamentos entrega ao bloco Pix do Guiado (usado por webhook.php, packs, assinatura e ofertas depois do pagamento): os campos de sempre e,
 * no modelo novo, a marca pix_v2 + a configuração normalizada em "pg".
 */
function propriedadesPagamentoBasico(array $pagamentos): array
{
    $legado = [
        'mostrar_copiar' => $pagamentos['mostrar_copiar'] ?? true,
        'mostrar_qrcode' => !empty($pagamentos['qrcode_definido']) ? !empty($pagamentos['mostrar_qrcode']) : true,
        'mostrar_confirmar' => $pagamentos['mostrar_confirmar'] ?? true,
        'msg_instrucoes' => $pagamentos['msg_instrucoes'] ?? '',
        'msg_confirmado' => $pagamentos['msg_confirmado'] ?? '',
    ];
    if (!pagamentoModoNovo($pagamentos)) {
        return $legado;
    }
    // Modelo novo: os campos de sempre saem da configuração JÁ normalizada (nunca do bruto), para não haver dois valores diferentes para a mesma opção.
    $pg = pagamentoConfig($pagamentos);
    return ['pix_v2' => true, 'pg' => $pg] + [
        'mostrar_copiar' => $pg['mostrar_copiar'], 'mostrar_qrcode' => $pg['mostrar_qrcode'], 'mostrar_confirmar' => $pg['mostrar_confirmar'],
        'msg_instrucoes' => $pg['msg_instrucoes'], 'msg_confirmado' => $pg['msg_confirmado'],
    ];
}

// ===================== Pix gerado =====================

/** O código Pix já formatado (HTML) no formato escolhido: <code> (toque para copiar), <pre> (bloco) ou texto simples. */
function pagamentoCodigoHtml(string $codigo, string $formato): string
{
    $c = pagamentoEscapar($codigo);
    return $formato === 'bloco' ? "<pre>$c</pre>" : ($formato === 'simples' ? $c : "<code>$c</code>");
}

/**
 * Botões do Pix (cada um na própria linha): copiar (copy_text, no máximo 256 caracteres) e verificar (callback). Lista vazia = sem botões.
 * @param array{codigo?: string, txid?: string} $ctx
 */
function pagamentoTeclado(array $pg, array $ctx): array
{
    $linhas = [];
    $codigo = (string) ($ctx['codigo'] ?? '');
    if (!empty($pg['btn_copiar']['ativo']) && $codigo !== '' && strlen($codigo) <= 256) {
        $linhas[] = [['text' => (string) $pg['btn_copiar']['texto'], 'copy_text' => ['text' => $codigo]]];
    }
    if (!empty($pg['btn_verificar']['ativo']) && (string) ($ctx['txid'] ?? '') !== '') {
        $linhas[] = [['text' => (string) $pg['btn_verificar']['texto'], 'callback_data' => 'verificar_pagamento_' . $ctx['txid']]];
    }
    return $linhas;
}

/**
 * O plano do que o cliente recebe quando o Pix é gerado, na ordem. Cada passo:
 *   ['t' => 'midias', 'midias' => [...]]                    fotos/vídeos (uma, ou álbum)
 *   ['t' => 'foto_qr', 'html' => legenda, 'botoes' => bool] foto do QR Code (a legenda pode ser o texto inteiro)
 *   ['t' => 'texto', 'html' => ..., 'botoes' => bool]       mensagem; 'botoes' = leva o teclado do Pix
 * @param array{nome?: string, plano?: string, valor?: float, codigo?: string, txid?: string, tem_qr?: bool, hora?: int|null} $ctx
 */
function pagamentoPlanoPix(array $pg, array $ctx): array
{
    $codigo = (string) ($ctx['codigo'] ?? '');
    $variaveis = pagamentoVariaveis((string) ($ctx['nome'] ?? ''), (string) ($ctx['plano'] ?? ''), (float) ($ctx['valor'] ?? 0), $ctx['hora'] ?? null);
    $codigo_html = $codigo !== '' ? pagamentoCodigoHtml($codigo, (string) $pg['codigo_formato']) : '';
    $variaveis['codigo_pix'] = ['html' => $codigo_html];

    $modelo = (string) $pg['msg_instrucoes'];
    $variavel_no_texto = $codigo !== '' && strpos($modelo, '{codigo_pix}') !== false;
    $codigo_no_texto = $codigo !== '' && ($variavel_no_texto || !empty($pg['codigo_na_mensagem']));

    $corpo = pagamentoHtmlSeguro($modelo !== '' ? $modelo : PAGAMENTO_TEXTO_PIX_PADRAO, $variaveis);
    $cabecalho = $pg['layout'] === 'compacto' ? '' : "✅ <b>Pedido Criado com Sucesso!</b>\n\n🛒 <b>Produto:</b> " . pagamentoEscapar((string) ($ctx['plano'] ?? ''))
        . "\n💲 <b>Valor:</b> " . pagamentoEscapar(pagamentoValorTexto((float) ($ctx['valor'] ?? 0))) . "\n\n";
    $texto = $cabecalho . $corpo;
    if ($codigo_no_texto && !$variavel_no_texto) {
        $texto .= "\n\n" . $codigo_html;
    }

    $tem_botoes = pagamentoTeclado($pg, $ctx) !== [];
    // onde o teclado vai: na mensagem final de "antes dos botões", na que já leva o código, ou na do código
    $botoes_onde = !$tem_botoes ? 'nenhum' : (!empty($pg['antes_botoes']['ativo']) ? 'final' : ($codigo_no_texto ? 'texto' : ($codigo !== '' ? 'codigo' : 'texto')));

    $passos = [];
    $midias = $pg['midias_gerado'] ?? [];
    if ($midias) {
        $passos[] = ['t' => 'midias', 'midias' => $midias];
    }
    $qr = !empty($ctx['tem_qr']) ? (string) $pg['qr_exibicao'] : 'nao';
    $foto = ['t' => 'foto_qr', 'html' => PAGAMENTO_LEGENDA_QR, 'botoes' => false];
    if ($qr === 'antes') {
        $passos[] = $foto;
    }
    if ($qr === 'junto' && mb_strlen(pagamentoHtmlParaTexto($texto)) <= PAGAMENTO_LEGENDA_MAXIMO) {
        $passos[] = ['t' => 'foto_qr', 'html' => $texto, 'botoes' => $botoes_onde === 'texto'];
    } else {
        if ($qr === 'junto') {
            $passos[] = $foto;   // texto grande demais para legenda: foto sozinha e o texto vai logo depois
        }
        $passos[] = ['t' => 'texto', 'html' => $texto, 'botoes' => $botoes_onde === 'texto'];
    }
    if ($qr === 'depois') {
        $passos[] = $foto;
    }
    if (!$codigo_no_texto && $codigo !== '') {
        if (!empty($pg['antes_codigo']['ativo'])) {
            $passos[] = ['t' => 'texto', 'html' => pagamentoHtmlSeguro((string) $pg['antes_codigo']['texto'], $variaveis, PAGAMENTO_CURTO_MAXIMO), 'botoes' => false];
        }
        $passos[] = ['t' => 'texto', 'html' => $codigo_html, 'botoes' => $botoes_onde === 'codigo'];
    }
    if ($botoes_onde === 'final') {
        $passos[] = ['t' => 'texto', 'html' => pagamentoHtmlSeguro((string) $pg['antes_botoes']['texto'], $variaveis, PAGAMENTO_CURTO_MAXIMO), 'botoes' => true];
    }
    return $passos;
}

/** Erro do Telegram que pede para reenviar sem formatação (HTML torto). Resposta inválida/rede NÃO entra: reenviar mandaria em dobro. */
function pagamentoErroDeFormatacao(array $resposta): bool
{
    return !($resposta['ok'] ?? false) && stripos((string) ($resposta['description'] ?? ''), 'parse') !== false;
}

/** sendMessage em HTML; se o Telegram recusar a formatação, reenvia como texto puro (o cliente nunca fica sem a mensagem). */
function pagamentoEnviarTexto(string $token, $id_chat, string $html, array $teclado = []): array
{
    $parametros = ['chat_id' => $id_chat, 'text' => mb_substr($html, 0, 4096), 'parse_mode' => 'HTML'];
    if ($teclado) {
        $parametros['reply_markup'] = json_encode(['inline_keyboard' => $teclado]);
    }
    $resposta = requisicaoTelegram($token, 'sendMessage', $parametros);
    if (pagamentoErroDeFormatacao($resposta)) {
        unset($parametros['parse_mode']);
        $parametros['text'] = mb_substr(pagamentoHtmlParaTexto($html), 0, 4096);
        $resposta = requisicaoTelegram($token, 'sendMessage', $parametros);
    }
    if (!($resposta['ok'] ?? false) && $teclado && ($resposta['description'] ?? '') !== 'resposta inválida') {
        // teclado recusado (ex.: botão de copiar que o app do cliente não conhece): reenvia sem o botão de copiar
        $sem_copiar = array_values(array_filter($teclado, static fn($linha) => !isset($linha[0]['copy_text'])));
        if (count($sem_copiar) < count($teclado)) {
            unset($parametros['reply_markup']);
            if ($sem_copiar) {
                $parametros['reply_markup'] = json_encode(['inline_keyboard' => $sem_copiar]);
            }
            $resposta = requisicaoTelegram($token, 'sendMessage', $parametros);
        }
    }
    return $resposta;
}

/** Foto/vídeo(s) antes do texto: uma solta ou um álbum (até 3). Reaproveita o envio do Guiado (cache de mídia, álbum, caminho seguro). */
function pagamentoEnviarMidias(string $token, $id_chat, array $midias): void
{
    require_once __DIR__ . '/guiado_ofertas.php';
    if (count($midias) > 1) {
        enviarAlbumBasico($token, $id_chat, $midias);
        return;
    }
    foreach ($midias as $m) {
        $caminho = resolverCaminhoUploadSeguro((string) $m['path']);
        if ($caminho) {
            enviarMidiaSoltaBasico($token, $id_chat, (string) $m['tipo'], $caminho);
        }
    }
}

/** Foto do QR Code (PNG gerado aqui, nunca por site de terceiros). Devolve false se não deu para gerar/mandar a foto. */
function pagamentoEnviarFotoQr(string $token, $id_chat, ?string $png, string $legenda_html, array $teclado = []): bool
{
    if ($png === null) {
        return false;
    }
    $arquivo = sys_get_temp_dir() . '/qrpix_' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents($arquivo, $png);
    $parametros = ['chat_id' => $id_chat, 'caption' => $legenda_html, 'parse_mode' => 'HTML'];
    if ($teclado) {
        $parametros['reply_markup'] = json_encode(['inline_keyboard' => $teclado]);
    }
    $resposta = requisicaoTelegram($token, 'sendPhoto', $parametros, ['photo' => $arquivo]);
    if (pagamentoErroDeFormatacao($resposta)) {
        unset($parametros['parse_mode']);
        $parametros['caption'] = pagamentoHtmlParaTexto($legenda_html);
        $resposta = requisicaoTelegram($token, 'sendPhoto', $parametros, ['photo' => $arquivo]);
    }
    @unlink($arquivo);
    return (bool) ($resposta['ok'] ?? false);
}

/**
 * Executa o plano do Pix gerado. $ctx igual ao de pagamentoPlanoPix (sem tem_qr: ele é decidido aqui, pela geração do PNG).
 */
function pagamentoEnviarPix(string $token, $id_chat, array $pg, array $ctx): void
{
    $codigo = (string) ($ctx['codigo'] ?? '');
    $png = ($codigo !== '' && $pg['qr_exibicao'] !== 'nao' && function_exists('qrCodePng')) ? qrCodePng($codigo) : null;
    $ctx['tem_qr'] = $png !== null;
    if ($codigo !== '' && $pg['qr_exibicao'] !== 'nao' && $png === null) {
        @file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', '[' . date('Y-m-d H:i:s') . "] QR CODE NAO GERADO (sem codigo copia e cola ou texto grande demais)\n", FILE_APPEND);
    }
    $teclado = pagamentoTeclado($pg, $ctx);
    foreach (pagamentoPlanoPix($pg, $ctx) as $passo) {
        $botoes = !empty($passo['botoes']) ? $teclado : [];
        if ($passo['t'] === 'midias') {
            pagamentoEnviarMidias($token, $id_chat, $passo['midias']);
        } elseif ($passo['t'] === 'foto_qr') {
            if (!pagamentoEnviarFotoQr($token, $id_chat, $png, (string) $passo['html'], $botoes) && $passo['html'] !== PAGAMENTO_LEGENDA_QR) {
                pagamentoEnviarTexto($token, $id_chat, (string) $passo['html'], $botoes);   // legenda era o texto inteiro: não pode se perder se a foto falhar
            }
        } else {
            pagamentoEnviarTexto($token, $id_chat, (string) $passo['html'], $botoes);
        }
    }
}

// ===================== Pagamento aprovado =====================

/** Linhas de "quanto tempo dura o acesso" (HTML), iguais às de sempre; vazio quando não há link de grupo. */
function pagamentoLinhasValidade(int $tempo_minutos): string
{
    if (function_exists('acessoVitalicio') && acessoVitalicio($tempo_minutos)) {
        return "\n♾️ <b>Acesso vitalício.</b>";
    }
    if ($tempo_minutos < 60) {
        return "\n⏳ <b>Seu acesso expira em {$tempo_minutos} minutos.</b>";
    }
    if ($tempo_minutos < 1440) {
        return "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 60) . ' horas.</b>';
    }
    return "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 1440) . ' dias.</b>';
}

/**
 * O plano da mensagem de pagamento aprovado: ['midias' => [...], 'html' => texto completo, 'botao' => ['texto', 'url'] ou null].
 * O texto do dono vem primeiro; depois o aviso de acesso (grupo ou link) e a validade, como sempre. Com "Botão de Acesso" ligado o endereço vai no botão
 * em vez de no texto. Falha ao gerar o link do grupo continua avisando o cliente.
 * @param array{nome?: string, plano?: string, valor?: float, link_grupo?: ?string, grupo_falhou?: bool, tempo_minutos?: int, link_entrega?: string, hora?: int|null} $ctx
 */
function pagamentoPlanoAprovacao(array $pg, array $ctx): array
{
    $variaveis = pagamentoVariaveis((string) ($ctx['nome'] ?? ''), (string) ($ctx['plano'] ?? ''), (float) ($ctx['valor'] ?? 0), $ctx['hora'] ?? null);
    $modelo = (string) $pg['msg_confirmado'];
    $html = pagamentoHtmlSeguro($modelo !== '' ? $modelo : PAGAMENTO_TEXTO_APROVADO_PADRAO, $variaveis);
    $botao_ativo = !empty($pg['botao_acesso']['ativo']);
    $link_grupo = trim((string) ($ctx['link_grupo'] ?? ''));
    $link_entrega = trim((string) ($ctx['link_entrega'] ?? ''));
    $url = null;

    if ($link_grupo !== '') {
        $url = $link_grupo;
        $html .= "\n\n🚀 <b>Acesso Liberado!</b>\n" . ($botao_ativo
            ? "Toque no botão abaixo para entrar no grupo exclusivo.\n\n⚠️ Este link é válido apenas para você."
            : "Clique no link abaixo para entrar no grupo exclusivo:\n\n" . pagamentoEscapar($link_grupo) . "\n\n⚠️ Este link é válido apenas para você.");
        $html .= pagamentoLinhasValidade((int) ($ctx['tempo_minutos'] ?? 0));
    } elseif (!empty($ctx['grupo_falhou'])) {
        $html .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
    }
    if ($link_entrega !== '') {
        $url = $url ?? $link_entrega;
        if (!$botao_ativo) {
            $html .= "\n\n🔗 <b>Seu acesso:</b>\n" . pagamentoEscapar($link_entrega);
        } elseif ($url === $link_entrega) {
            $html .= "\n\n🔗 <b>Seu acesso está no botão abaixo.</b>";
        }
    }
    $botao = ($botao_ativo && $url !== null) ? ['texto' => (string) $pg['botao_acesso']['texto'], 'url' => $url] : null;
    return ['midias' => $pg['midias_aprovado'] ?? [], 'html' => $html, 'botao' => $botao];
}

/**
 * Manda a mensagem de pagamento aprovado do modelo novo. Devolve false (e não manda nada) quando o fluxo não é Guiado no modelo novo ou a venda é uma
 * renovação/ciclo (esses seguem com o texto de sempre): quem chama então usa o envio antigo.
 * @param ?string $link_grupo link de convite do grupo recém-criado (null se não há grupo ou a criação falhou)
 */
function pagamentoEnviarAprovacao(PDO $pdo, string $token, array $venda, ?string $link_grupo, bool $grupo_falhou, int $tempo_minutos): bool
{
    try {
        if (!empty($venda['venda_pai_id']) || (string) ($venda['tipo_cobranca'] ?? '') === 'assinatura') {
            return false;
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
        $dados = ($fluxo && ($fluxo['modo'] ?? '') === 'basico') ? json_decode((string) $fluxo['dados_fluxograma'], true) : null;
        if (!is_array($dados) || !pagamentoModoNovo((array) ($dados['pagamentos'] ?? []))) {
            return false;
        }
        $pg = pagamentoConfig((array) $dados['pagamentos']);
        $nome = '';
        $st = $pdo->prepare('SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ? LIMIT 1');
        $st->execute([$venda['id_telegram'], $venda['bot_id']]);
        $nome = (string) ($st->fetchColumn() ?: '');
        $plano = trim((string) ($venda['nome_produto'] ?? ''));
        $entrega = function_exists('linkEntregaValido') ? linkEntregaValido($venda['link_entrega'] ?? '') : '';
        $envio = pagamentoPlanoAprovacao($pg, [
            'nome' => $nome, 'plano' => $plano !== '' ? $plano : 'Plano', 'valor' => (float) ($venda['valor'] ?? 0),
            'link_grupo' => $link_grupo, 'grupo_falhou' => $grupo_falhou, 'tempo_minutos' => $tempo_minutos, 'link_entrega' => $entrega,
        ]);
        if ($envio['midias']) {
            pagamentoEnviarMidias($token, $venda['id_telegram'], $envio['midias']);
        }
        $teclado = $envio['botao'] ? [[['text' => $envio['botao']['texto'], 'url' => $envio['botao']['url']]]] : [];
        pagamentoEnviarTexto($token, $venda['id_telegram'], $envio['html'], $teclado);
        return true;
    } catch (\Throwable $e) {
        error_log('[pagamento aprovado] ' . $e->getMessage());
        return false;
    }
}

/**
 * Entrega por mensagem (tipo "Mensagem" do campo Entrega do Guiado, 27/10/2026): manda o texto que o dono escreveu, numa mensagem própria, logo depois do aviso de
 * pagamento aprovado. O texto segue as regras dos textos da aba Pagamentos: só as tags permitidas, variáveis {nome}, {plano}, {valor} e {saudacao}, até 3000 caracteres.
 * Vale para qualquer modelo de aprovação (antigo ou novo). Devolve true se mandou; nunca lança (uma falha aqui não pode atrapalhar a confirmação do pagamento).
 */
function pagamentoEnviarMensagemEntrega(PDO $pdo, string $token, array $venda): bool
{
    try {
        $texto = trim((string) ($venda['mensagem_entrega'] ?? ''));
        if ($texto === '') {
            return false;
        }
        $st = $pdo->prepare('SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ? LIMIT 1');
        $st->execute([$venda['id_telegram'], $venda['bot_id']]);
        $plano = trim((string) ($venda['nome_produto'] ?? ''));
        $html = pagamentoHtmlSeguro($texto, pagamentoVariaveis((string) ($st->fetchColumn() ?: ''), $plano !== '' ? $plano : 'Plano', (float) ($venda['valor'] ?? 0)));
        if (trim($html) === '') {
            return false;
        }
        $resposta = pagamentoEnviarTexto($token, $venda['id_telegram'], $html);
        return (bool) ($resposta['ok'] ?? false);
    } catch (\Throwable $e) {
        error_log('[mensagem de entrega] ' . $e->getMessage());
        return false;
    }
}
