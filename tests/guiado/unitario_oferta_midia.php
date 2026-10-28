<?php
declare(strict_types=1);

/**
 * Teste unitário do que o bot MANDA no modo Guiado (webhook.php): boas-vindas e ofertas (upsell, downsell, order bump) com foto/vídeo opcional,
 * e o botão Recusar escondido no Upsell. Sem banco, sem servidor, sem Telegram: as funções são extraídas do webhook.php (que não pode ser
 * incluído, porque executa a requisição na hora) e rodam com um Telegram falso que só registra as chamadas.
 * Rodar: php tests/guiado/unitario_oferta_midia.php   (sai com código 1 se algo falhar)
 */

$fonte = (string) file_get_contents(__DIR__ . '/../../webhook.php') . "
" . (string) file_get_contents(__DIR__ . '/../../funcoes/guiado_ofertas.php');   // as funções de oferta/mídia moram em funcoes/guiado_ofertas.php

/** Pega o código de uma função (do "function" até a "}" que fecha) pelo nome, usando o tokenizador (strings e comentários não atrapalham). */
function extrairFuncao(string $fonte, string $nome): string
{
    $tokens = token_get_all($fonte);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $nome) {
            continue;
        }
        $codigo = '';
        $nivel = 0;
        $abriu = false;
        for ($k = $i; $k < $n; $k++) {
            $t = $tokens[$k];
            $texto = is_array($t) ? $t[1] : $t;
            $codigo .= $texto;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $nivel++;
                $abriu = true;
            } elseif ($t === '}') {
                $nivel--;
                if ($abriu && $nivel === 0) {
                    return $codigo;
                }
            }
        }
    }
    throw new RuntimeException('função não encontrada no webhook.php: ' . $nome);
}

// ---- Telegram falso + dependências mínimas ----
$GLOBALS['chamadas'] = [];
$GLOBALS['falhar_midia'] = false;
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['chamadas'][] = ['metodo' => $metodo, 'p' => $parametros, 'arquivos' => $arquivos];
    if ($GLOBALS['falhar_midia'] && in_array($metodo, ['sendPhoto', 'sendVideo'], true)) {
        return ['ok' => false, 'description' => 'Bad Request: wrong file'];
    }
    if ($metodo === 'sendMediaGroup' && !empty($GLOBALS['recusar_album'])) {
        return ['ok' => false, 'description' => 'Bad Request: wrong file'];
    }
    if ($metodo === 'sendMediaGroup' && !empty($GLOBALS['sem_resposta_album'])) {
        return ['ok' => false, 'description' => 'resposta inválida'];   // é o que requisicaoTelegram devolve quando o Telegram não responde (tempo esgotado)
    }
    return ['ok' => true];
}
/** Como a de verdade: só aceita "uploads/<nome>" e devolve o caminho absoluto; qualquer outra coisa (ou arquivo que sumiu) vira null. */
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0 || strpos($caminho, '..') !== false || $caminho === 'uploads/sumiu.jpg') {
        return null;
    }
    return '/var/www/painel/' . $caminho;
}
$GLOBALS['cache_chamadas'] = [];
function obterFileIdCacheMidia(string $token, string $id_cache, string $metodo, string $campo, string $caminho): ?string
{
    $GLOBALS['cache_chamadas'][] = [$id_cache, $metodo, $caminho];
    return 'FILEID_' . $campo;
}
/** Fim do gerarPixBasico de verdade: registra as propriedades do Pix que seriam geradas (valor, nome, plano). */
function processarEEnviarBloco(string $token, $id_chat, array $bloco, string $x): void
{
    $GLOBALS['chamadas'][] = ['metodo' => 'PIX', 'p' => $bloco['properties'], 'arquivos' => []];
    $GLOBALS['ultima_venda_pix_id'] = 901;   // o de verdade grava a venda e deixa o id aqui (funcoes/fluxo_blocos.php)
}
function packsDisponiveis(array $d): bool
{
    return false;
}
function previasMidiasEnviaveis(array $p): bool
{
    return false;
}
const PERIODICIDADES_BASICO = ['semanal' => '/semana', 'mensal' => '/mês', 'trimestral' => '/trimestre', 'semestral' => '/semestre', 'anual' => '/ano'];
// Downsell com temporizador: as funções que falam com o banco são testadas em unitario_downsell_temporizador.php; aqui só não podem derrubar o webhook.
$GLOBALS['pdo'] = null;
$GLOBALS['bot'] = ['id' => 1];
$GLOBALS['downsell_cancelados'] = [];
$GLOBALS['downsell_agendados'] = [];
function cancelarDownsellsDoLeadBasico($pdo, int $bot_id, string $id_telegram): void
{
    $GLOBALS['downsell_cancelados'][] = [$bot_id, $id_telegram];
}
function agendarDownsellBasico($pdo, int $bot_id, string $id_telegram, int $id_venda, string $id_plano, array $dados_basico): void
{
    $GLOBALS['downsell_agendados'][] = [$bot_id, $id_telegram, $id_venda, $id_plano];
}
function garantirConexaoBanco(): void
{
}
// Sequência de Order Bumps: as funções que falam com o banco são testadas em unitario_order_bumps_sequencia.php; aqui só registram a chamada.
$GLOBALS['bump_cancelados'] = [];
$GLOBALS['bump_agendados'] = [];
function cancelarOrderBumpsDoLeadBasico($pdo, int $bot_id, string $id_telegram): void
{
    $GLOBALS['bump_cancelados'][] = [$bot_id, $id_telegram];
}
function agendarOrderBumpsBasico($pdo, int $bot_id, string $id_telegram, int $id_venda, string $id_plano, array $dados_basico): void
{
    $GLOBALS['bump_agendados'][] = [$bot_id, $id_telegram, $id_venda, $id_plano];
}
const MIDIAS_MAXIMO_BASICO = 10;
const ORDER_BUMP_MAXIMO = 5;
require_once dirname(__DIR__, 2) . '/funcoes/entrega.php';   // entregaDoItemBasico(), usada por gerarPixBasico
require_once dirname(__DIR__, 2) . '/funcoes/pagamentos_guiado.php';   // propriedadesPagamentoBasico(), usada por gerarPixBasico

foreach (['estiloBotaoBasicoValor', 'enviarMensagemComMidiaBasico', 'enviarBoasVindasBasico', 'enviarOfertaBasico', 'avancarOfertasBasico', 'planoDaOfertaBasico', 'localizarPlanoBasico', 'planoRecorrenteBasico', 'periodicidadePlanoBasico',
    'boasVindasMostraCtaBasico', 'enviarListaPlanosBasico', 'urlSuporteBasico', 'downsellNovoFormatoBasico', 'planosDoDownsellBasico', 'planosDaSequenciaDownsellBasico', 'ofertaDaPosicaoDownsellBasico', 'planosDoDownsellSemOEscolhidoBasico', 'downsellDepoisDoPixBasico',
    'planoDoDownsellPorVariante', 'enviarDownsellBasico', 'gerarPixBasico', 'executarFluxoBasico',
    'midiasDoItemBasico', 'midiasBoasVindasBasico', 'enviarMidiaSoltaBasico', 'enviarAlbumBasico', 'enviarMensagemComMidiasBasico', 'itensOrderBumpBasico', 'orderBumpDepoisDoPixBasico'] as $f) {
    eval(extrairFuncao($fonte, $f));
}

$ok = 0;
$falhas = 0;
function chk(string $nome, bool $cond, string $extra = ''): void
{
    global $ok, $falhas;
    $cond ? $ok++ : $falhas++;
    echo ($cond ? 'OK     ' : 'FALHOU ') . $nome . ($cond || $extra === '' ? '' : '  -> ' . $extra) . PHP_EOL;
}
function zerar(bool $falhar_midia = false): void
{
    $GLOBALS['chamadas'] = [];
    $GLOBALS['cache_chamadas'] = [];
    $GLOBALS['falhar_midia'] = $falhar_midia;
}
function metodos(): string
{
    return implode(',', array_map(fn($c) => $c['metodo'], $GLOBALS['chamadas']));
}
function botoes(array $chamada): array
{
    $t = json_decode((string) ($chamada['p']['reply_markup'] ?? '{}'), true);
    return $t['inline_keyboard'][0] ?? [];
}

$oferta_base = ['ativo' => true, 'mensagem' => 'Leve o Anual!', 'texto_aceitar' => 'Quero', 'cor_aceitar' => 'success', 'texto_recusar' => 'Agora não', 'cor_recusar' => 'danger'];

echo "--- Oferta sem mídia (igual a antes)\n";
zerar();
enviarOfertaBasico('T', 123, $oferta_base, 'basico::of::up::p1', 'padrão');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('sem mídia: 1 sendMessage com o texto da oferta', metodos() === 'sendMessage' && ($c['p']['text'] ?? '') === 'Leve o Anual!');
chk('sem mídia: 2 botões (aceitar verde, recusar vermelho) com os callbacks certos', count(botoes($c)) === 2 && botoes($c)[0]['text'] === 'Quero' && botoes($c)[0]['style'] === 'success' && botoes($c)[0]['callback_data'] === 'basico::of::up::p1::1' && botoes($c)[1]['text'] === 'Agora não' && botoes($c)[1]['callback_data'] === 'basico::of::up::p1::0' && botoes($c)[1]['style'] === 'danger');
zerar();
enviarOfertaBasico('T', 123, ['ativo' => true], 'basico::of::dn::p1', 'Texto padrão da oferta');
chk('oferta vazia usa o texto padrão e os botões padrão', ($GLOBALS['chamadas'][0]['p']['text'] ?? '') === 'Texto padrão da oferta' && botoes($GLOBALS['chamadas'][0])[0]['text'] === 'Sim, quero!' && botoes($GLOBALS['chamadas'][0])[1]['text'] === 'Não, obrigado');

echo "\n--- Oferta com foto\n";
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('foto: 1 sendPhoto (e nenhum sendMessage separado)', metodos() === 'sendPhoto');
chk('foto: o texto da oferta vai na legenda e os 2 botões vão junto', ($c['p']['caption'] ?? '') === 'Leve o Anual!' && count(botoes($c)) === 2);
chk('foto: o arquivo enviado é o de uploads/', ($c['arquivos']['photo'] ?? '') === '/var/www/painel/uploads/foto.jpg');

echo "\n--- Oferta com vídeo\n";
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'video', 'midia_path' => 'uploads/v.mp4'], 'basico::of::bp::p1::o', 'padrão');
chk('vídeo: 1 sendVideo com legenda, botões e o arquivo', metodos() === 'sendVideo' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Leve o Anual!' && count(botoes($GLOBALS['chamadas'][0])) === 2 && isset($GLOBALS['chamadas'][0]['arquivos']['video']));

echo "\n--- Legenda grande, mídia recusada, caminho ruim\n";
zerar();
$texto_longo = str_repeat('a', 1500);
enviarOfertaBasico('T', 123, ['mensagem' => $texto_longo, 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão');
chk('texto > 1024: a foto vai sem legenda e o texto, com os botões, segue em mensagem normal', metodos() === 'sendPhoto,sendMessage' && !isset($GLOBALS['chamadas'][0]['p']['caption']) && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === $texto_longo && count(botoes($GLOBALS['chamadas'][1])) === 2);
zerar(true);
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão');
chk('Telegram recusa a foto: o lead recebe o texto e os botões mesmo assim', metodos() === 'sendPhoto,sendMessage' && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Leve o Anual!' && count(botoes($GLOBALS['chamadas'][1])) === 2);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => 'uploads/sumiu.jpg'], 'basico::of::up::p1', 'padrão');
chk('arquivo apagado do servidor: só texto + botões', metodos() === 'sendMessage' && count(botoes($GLOBALS['chamadas'][0])) === 2);
foreach (['config.php', '../config.php', 'uploads/../config.php', '/etc/passwd', ''] as $ruim) {
    zerar();
    enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => $ruim], 'basico::of::up::p1', 'padrão');
    chk('caminho fora de uploads/ ("' . $ruim . '") não vira envio de arquivo', metodos() === 'sendMessage' && $GLOBALS['chamadas'][0]['arquivos'] === []);
}
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'none', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão');
chk('tipo "none" com caminho sobrando: só texto', metodos() === 'sendMessage');
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'audio', 'midia_path' => 'uploads/a.mp3'], 'basico::of::up::p1', 'padrão');
chk('tipo que a oferta não suporta (audio): só texto', metodos() === 'sendMessage');

echo "\n--- Cache de mídia (canal do fluxo): usa o file_id, sem reenviar o arquivo\n";
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão', '-100999');
chk('com canal de cache: sendPhoto com o file_id e sem arquivo anexado', metodos() === 'sendPhoto' && ($GLOBALS['chamadas'][0]['p']['photo'] ?? '') === 'FILEID_photo' && $GLOBALS['chamadas'][0]['arquivos'] === [] && ($GLOBALS['cache_chamadas'][0][0] ?? '') === '-100999');

echo "\n--- Esconder o botão Recusar (só no Upsell)\n";
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['esconder_recusar' => true], 'basico::of::up::p1', 'padrão', '', true);
chk('Upsell com "esconder recusar": só o botão aceitar', count(botoes($GLOBALS['chamadas'][0])) === 1 && botoes($GLOBALS['chamadas'][0])[0]['text'] === 'Quero');
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['esconder_recusar' => true, 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'], 'basico::of::up::p1', 'padrão', '', true);
chk('Upsell com foto e sem recusar: sendPhoto com 1 botão', metodos() === 'sendPhoto' && count(botoes($GLOBALS['chamadas'][0])) === 1);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['esconder_recusar' => false], 'basico::of::up::p1', 'padrão', '', true);
chk('Upsell com a opção desligada: os 2 botões', count(botoes($GLOBALS['chamadas'][0])) === 2);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['esconder_recusar' => true], 'basico::of::dn::p1', 'padrão');
chk('Downsell/Order Bump ignoram a opção (o cliente não ficaria sem como seguir): os 2 botões', count(botoes($GLOBALS['chamadas'][0])) === 2);

echo "\n--- Boas-vindas continuam iguais\n";
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'texto_cta' => 'Ver Planos', 'cor_cta' => 'primary', 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg']);
chk('boas-vindas com foto: sendPhoto, legenda e 1 botão azul para a lista de planos', metodos() === 'sendPhoto' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Oi!' && botoes($GLOBALS['chamadas'][0])[0]['callback_data'] === 'basico::ver_planos' && botoes($GLOBALS['chamadas'][0])[0]['style'] === 'primary');
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => '']);
chk('boas-vindas sem nada: sendMessage "Bem-vindo(a)!" com o botão "Ver Planos"', metodos() === 'sendMessage' && ($GLOBALS['chamadas'][0]['p']['text'] ?? '') === 'Bem-vindo(a)!' && botoes($GLOBALS['chamadas'][0])[0]['text'] === 'Ver Planos');
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'midia_tipo' => 'video', 'midia_path' => 'uploads/v.mp4'], '-100999');
chk('boas-vindas com vídeo e canal de cache: sendVideo com file_id', metodos() === 'sendVideo' && ($GLOBALS['chamadas'][0]['p']['video'] ?? '') === 'FILEID_video');

echo "\n--- A jornada inteira: cada oferta sai com a MÍDIA DELA\n";
$dados = [
    'cache_chat_id' => '-100999',
    'planos' => [['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20], ['id' => 'p2', 'nome' => 'Anual', 'valor' => 200], ['id' => 'p3', 'nome' => 'Semanal', 'valor' => 8]],
    'ofertas' => [
        'upsell' => ['ativo' => true, 'id_plano_destino' => 'p2', 'mensagem' => 'UP', 'midia_tipo' => 'image', 'midia_path' => 'uploads/up.jpg', 'esconder_recusar' => true],
        'downsell' => ['ativo' => true, 'id_plano_destino' => 'p3', 'mensagem' => 'DN', 'midia_tipo' => 'video', 'midia_path' => 'uploads/dn.mp4'],
        'order_bump' => ['ativo' => true, 'valor_extra' => 9.9, 'mensagem' => 'BP', 'midia_tipo' => 'image', 'midia_path' => 'uploads/bp.jpg'],
    ],
];
zerar();
avancarOfertasBasico('T', 123, $dados, 'up', 'p1');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('escolheu o plano: o Upsell NÃO sai antes do Pix (agora é oferecido depois do pagamento); o que aparece é o Downsell, como vídeo (dn.mp4) com legenda DN', ($c['metodo'] ?? '') === 'sendVideo' && ($c['p']['caption'] ?? '') === 'DN' && count(botoes($c)) === 2 && ($GLOBALS['cache_chamadas'][0][2] ?? '') === '/var/www/painel/uploads/dn.mp4', json_encode($c));
zerar();
avancarOfertasBasico('T', 123, $dados, 'bp', 'p1', 'o');
chk('o Order Bump também NÃO sai antes do Pix (depois do pagamento): vai direto para o Pix', metodos() === 'PIX', metodos());
zerar();
enviarOfertaBasico('T', 123, $dados['ofertas']['upsell'], 'basico::pos::up::p2', 'texto padrão', '-100999', true);
$c = $GLOBALS['chamadas'][0] ?? [];
chk('a oferta de upsell (agora pós-pagamento) continua saindo como foto (up.jpg) com legenda UP, só com o botão aceitar, via cache', ($c['metodo'] ?? '') === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'UP' && count(botoes($c)) === 1 && ($GLOBALS['cache_chamadas'][0][2] ?? '') === '/var/www/painel/uploads/up.jpg', json_encode($c));
zerar();
enviarOfertaBasico('T', 123, $dados['ofertas']['order_bump'], 'basico::pos::bp::0', 'texto padrão', '-100999');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('a oferta de order bump (pós-pagamento) continua saindo como foto (bp.jpg) com legenda BP e os 2 botões', ($c['metodo'] ?? '') === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'BP' && count(botoes($c)) === 2, json_encode($c));
$dados_sem = $dados;
unset($dados_sem['ofertas']['order_bump']['midia_path'], $dados_sem['ofertas']['order_bump']['midia_tipo']);
zerar();
enviarOfertaBasico('T', 123, $dados_sem['ofertas']['order_bump'], 'basico::pos::bp::0', 'texto padrão', '-100999');
chk('oferta sem mídia segue como mensagem de texto', metodos() === 'sendMessage');
zerar();
avancarOfertasBasico('T', 123, ['planos' => $dados['planos'], 'ofertas' => []], 'up', 'p1');
chk('sem ofertas ativas: vai direto para o Pix', metodos() === 'PIX');


echo "\n--- DOWNSELL NOVO: sem Aceitar/Recusar, um botão por plano marcado (desconto aplicado)\n";
$dados_novo = $dados;
$dados_novo['ofertas']['downsell'] = ['ativo' => true, 'planos_oferecidos' => ['p1', 'p3', 'pX'], 'desconto_percentual' => 20, 'mensagem' => 'DN2', 'midia_tipo' => 'image', 'midia_path' => 'uploads/dn.jpg'];
function tecladoDe(array $chamada): array
{
    return json_decode((string) ($chamada['p']['reply_markup'] ?? '{}'), true)['inline_keyboard'] ?? [];
}
zerar();
avancarOfertasBasico('T', 123, $dados_novo, 'dn', 'p2');
$c = $GLOBALS['chamadas'][0] ?? [];
$b = tecladoDe($c);
chk('cliente escolheu o Anual: o downsell sai como foto com legenda DN2 e 1 botão por plano marcado que existe (pX apagado some)', ($c['metodo'] ?? '') === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'DN2' && count($b) === 2, metodos() . ' ' . json_encode($b));
chk('cada botão mostra o plano e o preço JÁ com o desconto (20%), um por linha', ($b[0][0]['text'] ?? '') === 'Mensal — R$ 16,00 (-20%)' && ($b[1][0]['text'] ?? '') === 'Semanal — R$ 6,40 (-20%)' && count($b[0]) === 1, json_encode($b, JSON_UNESCAPED_UNICODE));
chk('NÃO tem botão Aceitar nem Recusar (só os planos)', !preg_match('/Sim, quero|Não, obrigado|Aceitar|Recusar/i', json_encode($b, JSON_UNESCAPED_UNICODE)));
chk('os callbacks apontam para a posição do plano na lista (p0, p1) e cabem nos 64 bytes do Telegram', ($b[0][0]['callback_data'] ?? '') === 'basico::of::dn::p2::p0' && ($b[1][0]['callback_data'] ?? '') === 'basico::of::dn::p2::p1' && strlen($b[1][0]['callback_data']) <= 64);
zerar();
avancarOfertasBasico('T', 123, $dados_novo, 'dn', 'p1');
$b = tecladoDe($GLOBALS['chamadas'][0] ?? []);
chk('cliente escolheu o Mensal (que está marcado): ele não é repetido, sobra só o Semanal', count($b) === 1 && strpos($b[0][0]['text'], 'Semanal') === 0, json_encode($b, JSON_UNESCAPED_UNICODE));
$sem_msg = $dados_novo;
unset($sem_msg['ofertas']['downsell']['mensagem']);
zerar();
avancarOfertasBasico('T', 123, $sem_msg, 'dn', 'p1');
chk('sem mensagem: texto padrão no singular com 1 plano', ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Que tal esta opção?', json_encode($GLOBALS['chamadas'][0]['p'] ?? [], JSON_UNESCAPED_UNICODE));
zerar();
avancarOfertasBasico('T', 123, $sem_msg, 'dn', 'p2');
chk('sem mensagem: texto padrão no plural com 2 planos', ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Que tal uma destas opções?');
$so_o_original = $dados_novo;
$so_o_original['ofertas']['downsell']['planos_oferecidos'] = ['p1'];
zerar();
avancarOfertasBasico('T', 123, $so_o_original, 'dn', 'p1');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('só o plano que o cliente já escolheu estava marcado: o downsell é pulado e vai direto ao Pix (o Order Bump agora é depois do pagamento)', metodos() === 'PIX', metodos());
$inativo = $dados_novo;
$inativo['ofertas']['downsell']['ativo'] = false;
zerar();
avancarOfertasBasico('T', 123, $inativo, 'dn', 'p2');
chk('downsell desligado: vai direto ao Pix (sem Order Bump antes)', metodos() === 'PIX', metodos());

echo "\n--- DOWNSELL COM TEMPORIZADOR (quando = depois_pix): o Pix sai primeiro e a oferta fica agendada\n";
$depois_pix = $dados_novo;
$depois_pix['ofertas']['downsell']['quando'] = 'depois_pix';
zerar();
$GLOBALS['downsell_agendados'] = [];
avancarOfertasBasico('T', 123, $depois_pix, 'dn', 'p2');
chk('cliente escolheu o plano: vai direto ao Pix, a oferta NÃO entra no lugar dele', metodos() === 'PIX', metodos());
chk('o Pix do plano escolhido agenda o downsell (bot 1, chat 123, venda 901, plano p2)', $GLOBALS['downsell_agendados'] === [[1, '123', 901, 'p2']], json_encode($GLOBALS['downsell_agendados']));
zerar();
$GLOBALS['downsell_agendados'] = [];
avancarOfertasBasico('T', 123, $dados_novo, 'dn', 'p2');
chk('sem "quando" (downsell salvo antes do temporizador): continua saindo no lugar do Pix, nada agendado', metodos() === 'sendPhoto' && $GLOBALS['downsell_agendados'] === [], metodos());
zerar();
$GLOBALS['downsell_agendados'] = [];
executarFluxoBasico('T', 123, $depois_pix, 'basico::of::bp::p2::d1::0');
chk('Pix de quem aceitou a oferta (variante d1) NÃO agenda outro downsell (sem laço)', metodos() === 'PIX' && $GLOBALS['downsell_agendados'] === [], json_encode($GLOBALS['downsell_agendados']));
$GLOBALS['downsell_cancelados'] = [];
zerar();
executarFluxoBasico('T', 123, $depois_pix, 'basico::of::dn::p2::p1');
chk('tocar num plano da oferta encerra as repetições do temporizador desse lead e segue ao Pix', $GLOBALS['downsell_cancelados'] === [[1, '123']] && metodos() === 'PIX', json_encode($GLOBALS['downsell_cancelados']) . ' ' . metodos());

echo "\n--- DOWNSELL NOVO: o clique no plano leva ao Order Bump e ao Pix com o preço certo\n";
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::dn::p2::p1');
$c = $GLOBALS['chamadas'][0] ?? [];
$b = tecladoDe($c)[0] ?? [];
chk('tocou no plano "Semanal" (p1) do downsell: segue direto ao Pix (sem Order Bump antes)', metodos() === 'PIX', metodos());
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::bp::p2::d1::0');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('recusou o Order Bump: Pix do Semanal com 20%: R$ 6,40, produto "Semanal"', abs(($pix['valor'] ?? 0) - 6.40) < 0.001 && ($pix['nome'] ?? '') === 'Semanal' && ($pix['id_produto'] ?? '') === 'plano_p3', json_encode($pix));
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::bp::p2::d1::1');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('aceitou o Order Bump: Semanal 6,40 + extra 9,90 = R$ 16,30, produto "Semanal + Extra"', abs(($pix['valor'] ?? 0) - 16.30) < 0.001 && ($pix['nome'] ?? '') === 'Semanal + Extra', json_encode($pix));
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::bp::p2::d0::0');
chk('tocou no "Mensal" (p0): Pix de R$ 16,00 (20% sobre 20,00)', abs(($GLOBALS['chamadas'][0]['p']['valor'] ?? 0) - 16.00) < 0.001);
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::dn::p2::p7');
chk('botão de um plano que não está mais na lista (p7): avisa em vez de gerar Pix', metodos() === 'sendMessage' && strpos($GLOBALS['chamadas'][0]['p']['text'] ?? '', 'não está mais disponível') !== false);
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::bp::p2::lixo::0');
chk('variante inventada no callback vira "plano escolhido" (sem desconto): R$ 200,00', abs(($GLOBALS['chamadas'][0]['p']['valor'] ?? 0) - 200.0) < 0.001);
chk('planoDoDownsellPorVariante: d0=Mensal, d1=Semanal, d9=nada', (planoDoDownsellPorVariante($dados_novo, 'd0')['id'] ?? '') === 'p1' && (planoDoDownsellPorVariante($dados_novo, 'd1')['id'] ?? '') === 'p3' && planoDoDownsellPorVariante($dados_novo, 'd9') === null);

echo "\n--- Fluxos ANTIGOS (Downsell com 1 plano + Aceitar/Recusar) continuam como eram\n";
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::dn::p1::1');
$b = tecladoDe($GLOBALS['chamadas'][0] ?? [])[0] ?? [];
chk('botão "aceitar" antigo (::1): segue direto ao Pix', metodos() === 'PIX', metodos());
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::dn::p1::0');
$b = tecladoDe($GLOBALS['chamadas'][0] ?? [])[0] ?? [];
chk('botão "recusar" antigo (::0): segue direto ao Pix', metodos() === 'PIX', metodos());
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::bp::p1::d::0');
chk('Pix do downsell antigo (variante "d"): plano Semanal R$ 8,00', abs(($GLOBALS['chamadas'][0]['p']['valor'] ?? 0) - 8.0) < 0.001 && ($GLOBALS['chamadas'][0]['p']['nome'] ?? '') === 'Semanal');
zerar();
avancarOfertasBasico('T', 123, $dados, 'dn', 'p1');
chk('downsell antigo continua saindo com Aceitar e Recusar', count(tecladoDe($GLOBALS['chamadas'][0] ?? [])[0] ?? []) === 2);


echo "\n--- ENTREGA no Pix: plano, Downsell e Upsell aceitos (grupo, link ou nada)\n";
$dados_ent = [
    'planos' => [
        ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20, 'id_grupo' => '-100111'],
        ['id' => 'p2', 'nome' => 'VIP', 'valor' => 100, 'entrega_tipo' => 'link', 'link_entrega' => 'https://area.exemplo.com/vip'],
        ['id' => 'p3', 'nome' => 'Semanal', 'valor' => 8, 'entrega_tipo' => 'nenhuma', 'id_grupo' => '-100333'],
    ],
    'ofertas' => [
        'upsell' => ['ativo' => true, 'id_plano_destino' => 'p2', 'desconto_percentual' => 0],
        'downsell' => ['ativo' => true, 'planos_oferecidos' => ['p1', 'p2'], 'id_plano_destino' => 'p1', 'desconto_percentual' => 10],
    ],
];
function entregaDoPix(array $dados, string $id_plano, string $variante): array
{
    zerar();
    gerarPixBasico('T', 123, $dados, $id_plano, $variante, false);
    $p = $GLOBALS['chamadas'][0]['p'] ?? [];
    return [$p['id_grupo'] ?? '?', $p['link_entrega'] ?? '?'];
}
chk('plano antigo (só id_grupo): Pix entrega no grupo', entregaDoPix($dados_ent, 'p1', 'o') === ['-100111', '']);
chk('plano com link: Pix leva o link e nenhum grupo', entregaDoPix($dados_ent, 'p2', 'o') === ['', 'https://area.exemplo.com/vip']);
chk('plano "nenhuma": o grupo que sobrou guardado NÃO vale', entregaDoPix($dados_ent, 'p3', 'o') === ['', '']);
chk('Downsell sem entrega própria: cada plano oferecido entrega do jeito dele (d0 = grupo, d1 = link)', entregaDoPix($dados_ent, 'p3', 'd0') === ['-100111', ''] && entregaDoPix($dados_ent, 'p3', 'd1') === ['', 'https://area.exemplo.com/vip']);
$d = $dados_ent;
$d['ofertas']['downsell'] += ['entrega_tipo' => 'link', 'link_entrega' => 'https://promo.exemplo.com/down'];
chk('Downsell com link próprio: vale para qualquer plano oferecido, sem o grupo do plano', entregaDoPix($d, 'p3', 'd0') === ['', 'https://promo.exemplo.com/down'] && entregaDoPix($d, 'p3', 'd1') === ['', 'https://promo.exemplo.com/down']);
$d = $dados_ent;
$d['ofertas']['downsell'] += ['entrega_tipo' => 'nenhuma'];
chk('Downsell "nenhuma": só a venda, mesmo com plano que entrega', entregaDoPix($d, 'p3', 'd1') === ['', '']);
$d = $dados_ent;
$d['ofertas']['downsell'] += ['entrega_tipo' => 'grupo', 'id_grupo' => '-100888'];
chk('Downsell com grupo próprio', entregaDoPix($d, 'p3', 'd1') === ['-100888', '']);
$d = $dados_ent;
$d['ofertas']['downsell'] += ['entrega_tipo' => 'plano'];
chk('Downsell "igual ao plano": a entrega do plano oferecido', entregaDoPix($d, 'p3', 'd1') === ['', 'https://area.exemplo.com/vip']);
$d = $dados_ent;
$d['ofertas']['upsell'] += ['entrega_tipo' => 'grupo', 'id_grupo' => '-100777'];
chk('Upsell aceito pelo botão antigo (variante "u") com grupo próprio', entregaDoPix($d, 'p1', 'u') === ['-100777', '']);
chk('Upsell sem entrega própria: a do plano de destino (link)', entregaDoPix($dados_ent, 'p1', 'u') === ['', 'https://area.exemplo.com/vip']);

echo "\n--- DOWNSELL EM SEQUÊNCIA e PLANO CRIADO NO DOWNSELL: Pix, desconto e entrega (item 38)\n";
$dados_seq = [
    'planos' => [
        ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20, 'id_grupo' => '-100111'],
        ['id' => 'p2', 'nome' => 'VIP', 'valor' => 100, 'entrega_tipo' => 'link', 'link_entrega' => 'https://area.exemplo.com/vip'],
    ],
    'ofertas' => ['downsell' => ['ativo' => true, 'quando' => 'depois_pix', 'planos_oferecidos' => ['p1', 'p2'], 'id_plano_destino' => 'p1', 'desconto_percentual' => 10,
        'sequencias_extras' => [
            ['planos_oferecidos' => ['p2'], 'desconto_percentual' => 50, 'entrega_tipo' => 'link', 'link_entrega' => 'https://promo.exemplo.com/seq2'],
            ['criar_planos' => true, 'desconto_percentual' => 90, 'planos_proprios' => [
                ['nome' => 'Relâmpago', 'valor' => 9.9, 'dias_acesso' => 3, 'unidade_acesso' => 'horas', 'entrega_tipo' => 'link', 'link_entrega' => 'https://x.exemplo.com/a'],
                ['nome' => 'Bônus', 'valor' => 5, 'dias_acesso' => 7, 'entrega_tipo' => 'mensagem', 'mensagem_entrega' => 'Oi {nome}'],
            ]],
        ]]],
];
function pixDoDownsell(array $dados, string $id_plano, string $variante): array
{
    zerar();
    gerarPixBasico('T', 123, $dados, $id_plano, $variante, false);
    return $GLOBALS['chamadas'][0]['p'] ?? [];
}
$px = pixDoDownsell($dados_seq, 'p1', 'd1');
chk('1ª sequência: o VIP sai com o desconto dela (10%): R$ 90,00, e entrega pelo plano (link do VIP)', ($px['valor'] ?? 0) == 90.0 && ($px['link_entrega'] ?? '') === 'https://area.exemplo.com/vip' && ($px['id_grupo'] ?? '?') === '', json_encode($px));
$px = pixDoDownsell($dados_seq, 'p1', 'd100');
chk('2ª sequência: o VIP sai com o desconto DELA (50%): R$ 50,00, e entrega pelo link da sequência (não pelo do plano)', ($px['valor'] ?? 0) == 50.0 && ($px['link_entrega'] ?? '') === 'https://promo.exemplo.com/seq2', json_encode($px));
$px = pixDoDownsell($dados_seq, 'p1', 'd250');
chk('plano criado no Downsell: vale o valor digitado (R$ 9,90, sem os 90% da sequência), com o nome, o prazo (3 horas) e o id dele', ($px['valor'] ?? 0) == 9.9 && ($px['nome'] ?? '') === 'Relâmpago' && ($px['dias_acesso'] ?? 0) === 3 && ($px['unidade_acesso'] ?? '') === 'horas' && ($px['id_produto'] ?? '') === 'plano_dn3_1', json_encode($px));
chk('plano criado: entrega pela própria entrega (link dele, sem grupo)', ($px['link_entrega'] ?? '') === 'https://x.exemplo.com/a' && ($px['id_grupo'] ?? '?') === '' && ($px['mensagem_entrega'] ?? '?') === '');
$px = pixDoDownsell($dados_seq, 'p1', 'd251');
chk('outro plano criado, com entrega por mensagem: R$ 5,00, o texto vai na venda e não há grupo nem link', ($px['valor'] ?? 0) == 5.0 && ($px['mensagem_entrega'] ?? '') === 'Oi {nome}' && ($px['link_entrega'] ?? '?') === '' && ($px['id_grupo'] ?? '?') === '', json_encode($px));
$px = pixDoDownsell($dados_seq, 'p1', 'd7');
chk('posição que não existe mais: cai no plano que o cliente escolheu, sem desconto nenhum (R$ 20,00)', ($px['valor'] ?? 0) == 20.0 && ($px['id_grupo'] ?? '') === '-100111', json_encode($px));
zerar();
executarFluxoBasico('T', 123, $dados_seq, 'basico::of::dn::p1::p250');
chk('toque no botão do plano criado (p250): gera o Pix dele e para as repetições da sequência', ($GLOBALS['chamadas'][0]['metodo'] ?? '') === 'PIX' && ($GLOBALS['chamadas'][0]['p']['valor'] ?? 0) == 9.9 && $GLOBALS['downsell_cancelados'] !== [], metodos());
zerar();
executarFluxoBasico('T', 123, $dados_seq, 'basico::of::dn::p1::p299');
chk('botão de plano criado que foi apagado depois (posição que não existe): avisa que a opção não está mais disponível e não gera Pix', metodos() === 'sendMessage' && strpos((string) ($GLOBALS['chamadas'][0]['p']['text'] ?? ''), 'não está mais disponível') !== false, metodos());

// Várias fotos/vídeos numa sequência extra (item 39): álbum e, logo depois, o texto da sequência com o botão do plano na posição dela
$midias_seq = [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'], ['tipo' => 'image', 'path' => 'uploads/c.jpg']];
$dados_album = $dados_seq;
$dados_album['ofertas']['downsell']['sequencias_extras'][0] += ['mensagem' => 'Segunda chance', 'midias' => $midias_seq];
zerar();
enviarDownsellBasico('T', 123, $dados_album, planosDaSequenciaDownsellBasico($dados_album, 1), 'p1', '', 1);
$album_seq = json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true);
chk('2ª sequência com 3 mídias: 1 álbum (foto, vídeo, foto) e depois a mensagem de texto', metodos() === 'sendMediaGroup,sendMessage' && array_column($album_seq, 'type') === ['photo', 'video', 'photo'], metodos());
chk('2ª sequência com álbum: o texto da sequência e o botão do plano (posição 100) vão DEPOIS do álbum', ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Segunda chance' && (botoes($GLOBALS['chamadas'][1])[0]['callback_data'] ?? '') === 'basico::of::dn::p1::p100', json_encode($GLOBALS['chamadas'][1]['p'] ?? []));
zerar();
enviarDownsellBasico('T', 123, $dados_seq, planosDaSequenciaDownsellBasico($dados_seq, 1), 'p1', '', 1);
chk('2ª sequência sem mídia: só a mensagem com o botão (sem álbum)', metodos() === 'sendMessage', metodos());

echo "\n--- BOAS-VINDAS: botão CTA opcional (sem o botão, a lista de planos vem logo depois)\n";
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'mostrar_cta' => false]);
chk('CTA desligado, só texto: 1 sendMessage SEM nenhum botão', metodos() === 'sendMessage' && !isset($GLOBALS['chamadas'][0]['p']['reply_markup']) && ($GLOBALS['chamadas'][0]['p']['text'] ?? '') === 'Oi!', json_encode($GLOBALS['chamadas'][0] ?? []));
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'mostrar_cta' => false, 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg']);
chk('CTA desligado com foto: sendPhoto com legenda e SEM botão', metodos() === 'sendPhoto' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Oi!' && !isset($GLOBALS['chamadas'][0]['p']['reply_markup']));
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => $texto_longo, 'mostrar_cta' => false, 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg']);
chk('CTA desligado, texto > 1024: foto sem legenda + texto em mensagem normal, nenhuma das duas com botão', metodos() === 'sendPhoto,sendMessage' && !isset($GLOBALS['chamadas'][0]['p']['reply_markup']) && !isset($GLOBALS['chamadas'][1]['p']['reply_markup']));
zerar(true);
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'mostrar_cta' => false, 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg']);
chk('CTA desligado e o Telegram recusa a foto: o texto vai mesmo assim, sem botão', metodos() === 'sendPhoto,sendMessage' && !isset($GLOBALS['chamadas'][1]['p']['reply_markup']) && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Oi!');
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'mostrar_cta' => true, 'texto_cta' => 'Quero ver']);
chk('mostrar_cta = true: o botão aparece com o texto configurado', (botoes($GLOBALS['chamadas'][0])[0]['text'] ?? '') === 'Quero ver');
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!']);
chk('fluxo antigo (sem o campo mostrar_cta): continua com o botão "Ver Planos"', (botoes($GLOBALS['chamadas'][0])[0]['text'] ?? '') === 'Ver Planos');

echo "\n--- /start de ponta a ponta\n";
$dados_cta = $dados;
$dados_cta['boas_vindas'] = ['mensagem' => 'Bem-vindo', 'mostrar_cta' => false];
zerar();
executarFluxoBasico('T', 123, $dados_cta, '/start');
$b = array_map(fn($l) => $l[0]['text'] ?? '', tecladoDe($GLOBALS['chamadas'][1] ?? []));
chk('CTA desligado: o /start manda a mensagem (sem botão) e em seguida a lista "Escolha um plano:" com os 3 planos', metodos() === 'sendMessage,sendMessage' && !isset($GLOBALS['chamadas'][0]['p']['reply_markup']) && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Escolha um plano:' && count($b) === 3 && strpos($b[0], 'Mensal') === 0, metodos() . ' ' . json_encode($b, JSON_UNESCAPED_UNICODE));
chk('os botões da lista levam ao plano certo (basico::plano::<id>)', (tecladoDe($GLOBALS['chamadas'][1] ?? [])[0][0]['callback_data'] ?? '') === 'basico::plano::p1');
$dados_cta['boas_vindas']['mostrar_cta'] = true;
zerar();
executarFluxoBasico('T', 123, $dados_cta, '/start');
chk('CTA ligado: o /start manda só a mensagem com o botão (a lista só vem ao tocar nele)', metodos() === 'sendMessage' && (botoes($GLOBALS['chamadas'][0])[0]['callback_data'] ?? '') === 'basico::ver_planos');
$dados_cta['boas_vindas']['mostrar_cta'] = false;
$dados_cta['planos'] = [];
zerar();
executarFluxoBasico('T', 123, $dados_cta, '/start');
chk('CTA desligado e fluxo sem plano: a lista avisa "Nenhum plano disponível no momento."', ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Nenhum plano disponível no momento.');
$dados_cta['planos'] = $dados['planos'];
$dados_cta['ativo'] = false;
zerar();
executarFluxoBasico('T', 123, $dados_cta, '/start');
chk('fluxo desligado: só o aviso de indisponível (nem boas-vindas nem lista)', metodos() === 'sendMessage' && strpos($GLOBALS['chamadas'][0]['p']['text'] ?? '', 'indisponível') !== false);

echo "\n--- BOAS-VINDAS com várias mídias: álbum (sendMediaGroup) e depois o texto com o botão\n";
$bv_varias = ['mensagem' => 'Oi!', 'texto_cta' => 'Ver Planos', 'cor_cta' => 'primary', 'midias' => [
    ['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'], ['tipo' => 'image', 'path' => 'uploads/c.jpg'],
]];
zerar();
enviarBoasVindasBasico('T', 123, $bv_varias);
$album = $GLOBALS['chamadas'][0] ?? [];
$itens_album = json_decode((string) ($album['p']['media'] ?? '[]'), true);
chk('3 mídias: 1 sendMediaGroup e depois 1 sendMessage (o álbum não aceita botão)', metodos() === 'sendMediaGroup,sendMessage', metodos());
chk('o álbum leva foto, vídeo e foto, na ordem, anexando cada arquivo de uploads/', array_column($itens_album, 'type') === ['photo', 'video', 'photo'] && array_column($itens_album, 'media') === ['attach://midia0', 'attach://midia1', 'attach://midia2']
    && ($album['arquivos']['midia1'] ?? '') === '/var/www/painel/uploads/b.mp4' && count($album['arquivos']) === 3, json_encode($album));
chk('o vídeo do álbum é marcado para streaming', ($itens_album[1]['supports_streaming'] ?? false) === true && !isset($itens_album[0]['supports_streaming']));
chk('o texto vai depois, em mensagem normal, com o botão azul para a lista de planos', ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Oi!' && (botoes($GLOBALS['chamadas'][1])[0]['callback_data'] ?? '') === 'basico::ver_planos' && (botoes($GLOBALS['chamadas'][1])[0]['style'] ?? '') === 'primary');
zerar();
enviarBoasVindasBasico('T', 123, $bv_varias, '-100999');
$itens_album = json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true);
chk('com canal de cache: o álbum usa os file_id e não anexa arquivo nenhum', array_column($itens_album, 'media') === ['FILEID_photo', 'FILEID_video', 'FILEID_photo'] && $GLOBALS['chamadas'][0]['arquivos'] === [], json_encode($GLOBALS['chamadas'][0] ?? []));
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'mostrar_cta' => false] + $bv_varias);
chk('CTA desligado com álbum: o texto segue sem botão', metodos() === 'sendMediaGroup,sendMessage' && !isset($GLOBALS['chamadas'][1]['p']['reply_markup']));
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'midias' => [['tipo' => 'image', 'path' => 'uploads/a.jpg']]]);
chk('lista com 1 mídia só: como sempre, foto com legenda e botão (sem álbum)', metodos() === 'sendPhoto' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Oi!' && count(botoes($GLOBALS['chamadas'][0])) === 1);
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'midias' => [], 'midia_tipo' => 'image', 'midia_path' => 'uploads/a.jpg']);
chk('fluxo salvo antes (só midia_tipo/midia_path) continua mandando a foto com legenda', metodos() === 'sendPhoto');
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'midias' => [['tipo' => 'image', 'path' => 'uploads/sumiu.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'], ['tipo' => 'image', 'path' => 'config.php']]]);
chk('mídia apagada e caminho fora de uploads/ são puladas: sobrando 1, vai solta (sem álbum) e depois o texto', metodos() === 'sendVideo,sendMessage', metodos());
$tantas = [];
for ($i = 0; $i < 14; $i++) {
    $tantas[] = ['tipo' => 'image', 'path' => 'uploads/f' . $i . '.jpg'];
}
zerar();
enviarBoasVindasBasico('T', 123, ['mensagem' => 'Oi!', 'midias' => $tantas]);
chk('mais de 10 mídias: o álbum leva só as 10 primeiras (limite do Telegram)', count(json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true)) === 10);
chk('midiasBoasVindasBasico ignora tipo inválido ("audio", "none") e item sem caminho', midiasBoasVindasBasico(['midias' => [['tipo' => 'audio', 'path' => 'uploads/x.mp3'], ['tipo' => 'none', 'path' => 'uploads/x.jpg'], ['tipo' => 'image', 'path' => ''], 'lixo', ['tipo' => 'image', 'path' => 'uploads/ok.jpg']]]) === [['tipo' => 'image', 'path' => 'uploads/ok.jpg']]);
// Telegram recusa o álbum (ex.: um vídeo grande demais): cada item vai solto, para o lead não ficar sem nada
$GLOBALS['recusar_album'] = true;
zerar();
enviarBoasVindasBasico('T', 123, $bv_varias);
chk('Telegram recusa o álbum: os 3 itens vão soltos e depois o texto com o botão', metodos() === 'sendMediaGroup,sendPhoto,sendVideo,sendPhoto,sendMessage', metodos());
$GLOBALS['recusar_album'] = false;
$GLOBALS['sem_resposta_album'] = true;
zerar();
enviarBoasVindasBasico('T', 123, $bv_varias);
chk('álbum sem resposta (tempo esgotado): não reenvia nada solto (evita mandar tudo em dobro), o texto segue', metodos() === 'sendMediaGroup,sendMessage', metodos());
$GLOBALS['sem_resposta_album'] = false;

echo "\n--- UPSELL e DOWNSELL com várias mídias: álbum e depois o texto com os botões\n";
$midias_3 = [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'], ['tipo' => 'image', 'path' => 'uploads/c.jpg']];
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => $midias_3], 'basico::pos::up::p2', 'padrão', '', true);
$album = json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true);
chk('Upsell com 3 mídias: 1 álbum (foto, vídeo, foto) e depois 1 mensagem de texto', metodos() === 'sendMediaGroup,sendMessage' && array_column($album, 'type') === ['photo', 'video', 'photo'], metodos());
chk('Upsell: o texto da oferta e os 2 botões (aceitar/recusar, com cores e callbacks) vão na mensagem DEPOIS do álbum', ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Leve o Anual!' && count(botoes($GLOBALS['chamadas'][1])) === 2 && botoes($GLOBALS['chamadas'][1])[0]['callback_data'] === 'basico::pos::up::p2::1' && botoes($GLOBALS['chamadas'][1])[0]['style'] === 'success' && botoes($GLOBALS['chamadas'][1])[1]['style'] === 'danger');
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => $midias_3, 'esconder_recusar' => true], 'basico::pos::up::p2', 'padrão', '', true);
chk('Upsell com álbum e "esconder recusar": só o botão aceitar na mensagem de texto', metodos() === 'sendMediaGroup,sendMessage' && count(botoes($GLOBALS['chamadas'][1])) === 1);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => $midias_3], 'basico::pos::up::p2', 'padrão', '-100999', true);
chk('Upsell com álbum e canal de cache: o álbum usa os file_id (nenhum arquivo anexado)', array_column(json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true), 'media') === ['FILEID_photo', 'FILEID_video', 'FILEID_photo'] && $GLOBALS['chamadas'][0]['arquivos'] === []);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => [['tipo' => 'image', 'path' => 'uploads/a.jpg']]], 'basico::pos::up::p2', 'padrão');
chk('Upsell com lista de 1 mídia: como sempre (foto com legenda e botões, sem álbum)', metodos() === 'sendPhoto' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'Leve o Anual!' && count(botoes($GLOBALS['chamadas'][0])) === 2);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midia_tipo' => 'image', 'midia_path' => 'uploads/a.jpg'], 'basico::pos::up::p2', 'padrão');
chk('Upsell salvo antes (só midia_tipo/midia_path): continua foto com legenda e botões', metodos() === 'sendPhoto');
zerar();
$GLOBALS['recusar_album'] = true;
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => $midias_3], 'basico::pos::up::p2', 'padrão');
chk('Telegram recusa o álbum do Upsell: os 3 itens vão soltos e a oferta com os botões chega do mesmo jeito', metodos() === 'sendMediaGroup,sendPhoto,sendVideo,sendPhoto,sendMessage' && count(botoes($GLOBALS['chamadas'][4])) === 2, metodos());
$GLOBALS['recusar_album'] = false;
$dados_dn = $dados;
$dados_dn['ofertas']['downsell'] = ['ativo' => true, 'planos_oferecidos' => ['p1', 'p3'], 'desconto_percentual' => 20, 'mensagem' => 'DN3', 'midias' => [['tipo' => 'video', 'path' => 'uploads/d1.mp4'], ['tipo' => 'image', 'path' => 'uploads/d2.jpg']]];
zerar();
avancarOfertasBasico('T', 123, $dados_dn, 'dn', 'p2');
$b = tecladoDe($GLOBALS['chamadas'][1] ?? []);
chk('Downsell com 2 mídias: álbum e depois a mensagem com 1 botão por plano marcado (com o desconto) e o texto DN3', metodos() === 'sendMediaGroup,sendMessage' && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'DN3' && count($b) === 2 && ($b[0][0]['text'] ?? '') === 'Mensal — R$ 16,00 (-20%)' && ($b[1][0]['callback_data'] ?? '') === 'basico::of::dn::p2::p1', metodos() . ' ' . json_encode($b, JSON_UNESCAPED_UNICODE));
$dados_dn['ofertas']['downsell']['midias'] = [['tipo' => 'video', 'path' => 'uploads/d1.mp4']];
zerar();
avancarOfertasBasico('T', 123, $dados_dn, 'dn', 'p2');
chk('Downsell com 1 mídia: como sempre (vídeo com legenda e os botões dos planos)', metodos() === 'sendVideo' && ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'DN3' && count(tecladoDe($GLOBALS['chamadas'][0])) === 2);
chk('midiasDoItemBasico vale para qualquer item: lista tem prioridade sobre o par antigo e o limite é 10', midiasDoItemBasico(['midias' => [['tipo' => 'video', 'path' => 'uploads/x.mp4']], 'midia_tipo' => 'image', 'midia_path' => 'uploads/velha.jpg']) === [['tipo' => 'video', 'path' => 'uploads/x.mp4']]
    && count(midiasDoItemBasico(['midias' => array_fill(0, 15, ['tipo' => 'image', 'path' => 'uploads/f.jpg'])])) === 10 && midiasDoItemBasico([]) === []);
zerar();
enviarOfertaBasico('T', 123, $oferta_base + ['midias' => $midias_3], 'basico::pos::bp::0', 'padrão');
chk('o mesmo vale para o Order Bump (usa a mesma função de oferta) se algum dia ele ganhar a lista', metodos() === 'sendMediaGroup,sendMessage');

echo "\n--- ORDER BUMP em sequência (Pix não pago): clique no botão e o Pix com plano + extra\n";
$dados_seq = $dados;
$dados_seq['ofertas']['order_bump'] = ['ativo' => true, 'quando' => 'depois_pix', 'valor_extra' => 9.9, 'nome' => 'Bônus 1', 'itens' => [
    ['nome' => 'Bônus 1', 'valor_extra' => 9.9, 'espera_minutos' => 5],
    ['nome' => 'Bônus 2', 'valor_extra' => 14.5, 'espera_minutos' => 15],
    ['nome' => 'Sem valor', 'valor_extra' => 0, 'espera_minutos' => 10],
]];
chk('itensOrderBumpBasico: só os itens com valor extra > 0, na ordem', array_column(itensOrderBumpBasico($dados_seq), 'nome') === ['Bônus 1', 'Bônus 2']);
chk('orderBumpDepoisDoPixBasico: ligado + quando=depois_pix + algum item válido', orderBumpDepoisDoPixBasico($dados_seq) && !orderBumpDepoisDoPixBasico($dados) && !orderBumpDepoisDoPixBasico(array_replace_recursive($dados_seq, ['ofertas' => ['order_bump' => ['ativo' => false]]])));
zerar();
$GLOBALS['bump_cancelados'] = [];
$GLOBALS['bump_agendados'] = [];
$GLOBALS['downsell_agendados'] = [];
executarFluxoBasico('T', 123, $dados_seq, 'basico::bs::p1::1::1');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('aceitou o 2º bump: um Pix só, com o valor do plano Mensal (20,00) + o extra do bump 2 (14,50) = R$ 34,50, produto "Mensal + Bônus 2"', metodos() === 'PIX' && abs(($pix['valor'] ?? 0) - 34.50) < 0.001 && ($pix['nome'] ?? '') === 'Mensal + Bônus 2', json_encode($pix));
chk('aceitar encerra a sequência do lead e o Pix novo NÃO agenda downsell nem order bump (sem laço)', $GLOBALS['bump_cancelados'] === [[1, '123']] && $GLOBALS['bump_agendados'] === [] && $GLOBALS['downsell_agendados'] === [], json_encode([$GLOBALS['bump_cancelados'], $GLOBALS['bump_agendados'], $GLOBALS['downsell_agendados']]));
chk('a entrega do Pix é a do plano (o bump não troca grupo/link)', array_key_exists('id_grupo', $pix) && ($pix['unidade_acesso'] ?? '') === 'dias');
zerar();
$GLOBALS['bump_cancelados'] = [];
executarFluxoBasico('T', 123, $dados_seq, 'basico::bs::p1::0::0');
chk('recusou: só "Tudo bem!", nenhum Pix e a sequência segue (nada cancelado)', metodos() === 'sendMessage' && ($GLOBALS['chamadas'][0]['p']['text'] ?? '') === 'Tudo bem! 🙌' && $GLOBALS['bump_cancelados'] === []);
foreach (['basico::bs::p1::7::1' => 'posição que não existe', 'basico::bs::pX::0::1' => 'plano que não existe', 'basico::bs::p1::2::1' => 'item sem valor extra (a posição 2 deixou de existir)'] as $callback => $quem) {
    zerar();
    executarFluxoBasico('T', 123, $dados_seq, $callback);
    chk("botão de $quem: avisa que a oferta não está mais disponível e não gera Pix", metodos() === 'sendMessage' && strpos($GLOBALS['chamadas'][0]['p']['text'] ?? '', 'não está mais disponível') !== false, metodos());
}
zerar();
executarFluxoBasico('T', 123, array_replace_recursive($dados_seq, ['ofertas' => ['order_bump' => ['quando' => 'depois_pagamento']]]), 'basico::bs::p1::0::1');
chk('dono voltou o order bump para "depois do pagamento" depois do envio: o botão antigo avisa e não gera Pix', metodos() === 'sendMessage');
$dados_rec = $dados_seq;
$dados_rec['planos'][0]['cobranca'] = 'recorrente';
zerar();
executarFluxoBasico('T', 123, $dados_rec, 'basico::bs::p1::0::1');
chk('plano virou assinatura recorrente: o extra não é cobrado (avisa e não gera Pix)', metodos() === 'sendMessage');
zerar();
$GLOBALS['bump_agendados'] = [];
executarFluxoBasico('T', 123, $dados_seq, 'basico::of::bp::p1::o::0');
chk('o Pix normal do plano escolhido agenda a sequência (bot 1, chat 123, venda 901, plano p1)', metodos() === 'PIX' && $GLOBALS['bump_agendados'] === [[1, '123', 901, 'p1']], metodos() . ' ' . json_encode($GLOBALS['bump_agendados']));
zerar();
$GLOBALS['bump_agendados'] = [];
executarFluxoBasico('T', 123, $dados_seq, 'basico::of::bp::p1::d::0');
chk('Pix de quem aceitou o downsell antigo (variante d) não agenda a sequência', metodos() === 'PIX' && $GLOBALS['bump_agendados'] === [], metodos() . ' ' . json_encode($GLOBALS['bump_agendados']));

echo "\n--- ABA PAGAMENTOS: o Pix do Guiado leva as opções escolhidas (modelo novo) ou o de sempre (fluxo antigo)\n";
$dados_pg = $dados;
$dados_pg['pagamentos'] = ['versao' => 2, 'msg_instrucoes' => 'Pague {nome}', 'qr_exibicao' => 'junto', 'btn_copiar' => ['ativo' => false], 'msg_confirmado' => 'Valeu!'];
zerar();
executarFluxoBasico('T', 123, $dados_pg, 'basico::of::bp::p1::o::0');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('Pix do Guiado com a aba Pagamentos no modelo novo: leva pix_v2 e a configuração normalizada (QR junto, copiar desligado)', !empty($pix['pix_v2']) && ($pix['pg']['qr_exibicao'] ?? '') === 'junto' && ($pix['pg']['btn_copiar']['ativo'] ?? true) === false && ($pix['pg']['msg_instrucoes'] ?? '') === 'Pague {nome}' && $pix['mostrar_copiar'] === false && $pix['msg_confirmado'] === 'Valeu!', json_encode($pix));
$dados_antigo = $dados;
$dados_antigo['pagamentos'] = ['msg_instrucoes' => 'Texto antigo', 'mostrar_copiar' => false, 'mostrar_qrcode' => false, 'qrcode_definido' => true];
zerar();
executarFluxoBasico('T', 123, $dados_antigo, 'basico::of::bp::p1::o::0');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('fluxo SEM versao (salvo antes): o Pix não leva pix_v2 nem pg, só os campos de sempre', !isset($pix['pix_v2']) && !isset($pix['pg']) && $pix['msg_instrucoes'] === 'Texto antigo' && $pix['mostrar_copiar'] === false && $pix['mostrar_qrcode'] === false, json_encode($pix));
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::bp::p1::o::0');
$pix = $GLOBALS['chamadas'][0]['p'] ?? [];
chk('fluxo sem nada em pagamentos: padrões de sempre (copiar e confirmar ligados, QR ligado)', !isset($pix['pix_v2']) && $pix['mostrar_copiar'] === true && $pix['mostrar_confirmar'] === true && $pix['mostrar_qrcode'] === true, json_encode($pix));

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
