<?php
declare(strict_types=1);

/**
 * Teste unitário do que o bot MANDA no modo Guiado (webhook.php): boas-vindas e ofertas (upsell, downsell, order bump) com foto/vídeo opcional,
 * e o botão Recusar escondido no Upsell. Sem banco, sem servidor, sem Telegram: as funções são extraídas do webhook.php (que não pode ser
 * incluído, porque executa a requisição na hora) e rodam com um Telegram falso que só registra as chamadas.
 * Rodar: php tests/guiado/unitario_oferta_midia.php   (sai com código 1 se algo falhar)
 */

$fonte = (string) file_get_contents(__DIR__ . '/../../webhook.php');

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

foreach (['estiloBotaoBasicoValor', 'enviarMensagemComMidiaBasico', 'enviarBoasVindasBasico', 'enviarOfertaBasico', 'avancarOfertasBasico', 'planoDaOfertaBasico', 'localizarPlanoBasico', 'planoRecorrenteBasico', 'periodicidadePlanoBasico',
    'boasVindasMostraCtaBasico', 'enviarListaPlanosBasico', 'urlSuporteBasico', 'downsellNovoFormatoBasico', 'planosDoDownsellBasico', 'planoDoDownsellPorVariante', 'enviarDownsellBasico', 'gerarPixBasico', 'executarFluxoBasico'] as $f) {
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
chk('escolheu o plano: o Upsell sai como foto (up.jpg) com legenda UP, só com o botão aceitar, via cache', ($c['metodo'] ?? '') === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'UP' && count(botoes($c)) === 1 && ($c['p']['photo'] ?? '') === 'FILEID_photo' && ($GLOBALS['cache_chamadas'][0][2] ?? '') === '/var/www/painel/uploads/up.jpg');
zerar();
avancarOfertasBasico('T', 123, $dados, 'dn', 'p1');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('Downsell sai como vídeo (dn.mp4) com legenda DN e os 2 botões', ($c['metodo'] ?? '') === 'sendVideo' && ($c['p']['caption'] ?? '') === 'DN' && count(botoes($c)) === 2 && ($GLOBALS['cache_chamadas'][0][2] ?? '') === '/var/www/painel/uploads/dn.mp4');
zerar();
avancarOfertasBasico('T', 123, $dados, 'bp', 'p1', 'o');
$c = $GLOBALS['chamadas'][0] ?? [];
chk('Order Bump sai como foto (bp.jpg) com legenda BP e os 2 botões', ($c['metodo'] ?? '') === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'BP' && count(botoes($c)) === 2 && ($GLOBALS['cache_chamadas'][0][2] ?? '') === '/var/www/painel/uploads/bp.jpg');
zerar();
avancarOfertasBasico('T', 123, $dados, 'bp', 'p1', 'o');
$dados_sem = $dados;
unset($dados_sem['ofertas']['order_bump']['midia_path'], $dados_sem['ofertas']['order_bump']['midia_tipo']);
zerar();
avancarOfertasBasico('T', 123, $dados_sem, 'bp', 'p1', 'o');
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
chk('só o plano que o cliente já escolheu estava marcado: o downsell é pulado e vai direto ao Order Bump', metodos() === 'sendPhoto' && ($c['p']['caption'] ?? '') === 'BP');
$inativo = $dados_novo;
$inativo['ofertas']['downsell']['ativo'] = false;
zerar();
avancarOfertasBasico('T', 123, $inativo, 'dn', 'p2');
chk('downsell desligado: pula direto para o Order Bump', ($GLOBALS['chamadas'][0]['p']['caption'] ?? '') === 'BP');

echo "\n--- DOWNSELL NOVO: o clique no plano leva ao Order Bump e ao Pix com o preço certo\n";
zerar();
executarFluxoBasico('T', 123, $dados_novo, 'basico::of::dn::p2::p1');
$c = $GLOBALS['chamadas'][0] ?? [];
$b = tecladoDe($c)[0] ?? [];
chk('tocou no plano "Semanal" (p1): aparece o Order Bump levando junto a variante d1', ($c['p']['caption'] ?? '') === 'BP' && ($b[0]['callback_data'] ?? '') === 'basico::of::bp::p2::d1::1' && ($b[1]['callback_data'] ?? '') === 'basico::of::bp::p2::d1::0', json_encode($b));
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
chk('botão "aceitar" antigo (::1): segue para o Order Bump com a variante "d"', ($b[0]['callback_data'] ?? '') === 'basico::of::bp::p1::d::1', json_encode($b));
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::dn::p1::0');
$b = tecladoDe($GLOBALS['chamadas'][0] ?? [])[0] ?? [];
chk('botão "recusar" antigo (::0): segue para o Order Bump com a variante "o"', ($b[0]['callback_data'] ?? '') === 'basico::of::bp::p1::o::1', json_encode($b));
zerar();
executarFluxoBasico('T', 123, $dados, 'basico::of::bp::p1::d::0');
chk('Pix do downsell antigo (variante "d"): plano Semanal R$ 8,00', abs(($GLOBALS['chamadas'][0]['p']['valor'] ?? 0) - 8.0) < 0.001 && ($GLOBALS['chamadas'][0]['p']['nome'] ?? '') === 'Semanal');
zerar();
avancarOfertasBasico('T', 123, $dados, 'dn', 'p1');
chk('downsell antigo continua saindo com Aceitar e Recusar', count(tecladoDe($GLOBALS['chamadas'][0] ?? [])[0] ?? []) === 2);


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

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
