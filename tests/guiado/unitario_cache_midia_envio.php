<?php
declare(strict_types=1);

/**
 * Cache de mídia SEM canal (funcoes/guiado_ofertas.php): o 1º envio sobe o arquivo e guarda o file_id que o Telegram devolve; os seguintes mandam só o file_id.
 * Antes (sem canal de cache) todo /start reenviava o vídeo das boas-vindas inteiro (3 a 9 MB), e o lead só recebia a mensagem depois do upload.
 * Sem rede: Telegram falso. O arquivo de cache é temporário (CACHE_MIDIA_ARQUIVO).
 *   php tests/guiado/unitario_cache_midia_envio.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

$pasta = sys_get_temp_dir() . '/teste_cache_midia_' . bin2hex(random_bytes(3));
mkdir($pasta);
define('CACHE_MIDIA_ARQUIVO', $pasta . '/cache_midia.json');
file_put_contents($pasta . '/video.mp4', str_repeat('v', 2048));
file_put_contents($pasta . '/foto.jpg', str_repeat('f', 512));
file_put_contents($pasta . '/foto2.jpg', str_repeat('g', 512));

// ---- Telegram falso: devolve a mensagem com o file_id de quem subiu (como o de verdade) e recusa file_id "RUIM"
$GLOBALS['chamadas'] = [];
$GLOBALS['recusar_file_id'] = '';
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['chamadas'][] = ['metodo' => $metodo, 'token' => $token, 'p' => $parametros, 'arquivos' => $arquivos];
    $n = count($GLOBALS['chamadas']);
    if ($metodo === 'sendVideo' || $metodo === 'sendPhoto') {
        $campo = $metodo === 'sendVideo' ? 'video' : 'photo';
        if (($parametros[$campo] ?? '') !== '' && ($parametros[$campo] === $GLOBALS['recusar_file_id'])) {
            return ['ok' => false, 'description' => 'Bad Request: wrong file identifier'];
        }
        $id = 'FID_' . $campo . '_' . $n;
        return ['ok' => true, 'result' => $campo === 'video' ? ['video' => ['file_id' => $id]] : ['photo' => [['file_id' => 'peq_' . $n], ['file_id' => $id]]]];
    }
    if ($metodo === 'sendMediaGroup') {
        $itens = json_decode((string) $parametros['media'], true);
        $res = [];
        foreach ($itens as $i => $it) {
            $res[] = $it['type'] === 'video' ? ['video' => ['file_id' => 'ALB_v' . $i . '_' . $n]] : ['photo' => [['file_id' => 'albp' . $i], ['file_id' => 'ALB_f' . $i . '_' . $n]]];
        }
        return ['ok' => true, 'result' => $res];
    }
    return ['ok' => true, 'result' => []];
}
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    $arq = $GLOBALS['pasta_midia'] . '/' . basename($caminho);
    return strpos($caminho, 'uploads/') === 0 && is_file($arq) ? $arq : null;
}
function garantirConexaoBanco(): void
{
}
$GLOBALS['pasta_midia'] = $pasta;
require_once dirname(__DIR__, 2) . '/funcoes/guiado_ofertas.php';

function zerar(): void
{
    $GLOBALS['chamadas'] = [];
    $GLOBALS['recusar_file_id'] = '';
}
function arquivosEnviados(): int
{
    return count(array_filter(array_column($GLOBALS['chamadas'], 'arquivos')));
}
function metodosEnviados(): string
{
    return implode(',', array_column($GLOBALS['chamadas'], 'metodo'));
}
function cacheNoDisco(): array
{
    return is_file(CACHE_MIDIA_ARQUIVO) ? (json_decode((string) file_get_contents(CACHE_MIDIA_ARQUIVO), true) ?: []) : [];
}

$boas = ['mensagem' => 'Bem-vindo', 'midia_tipo' => 'video', 'midia_path' => 'uploads/video.mp4'];

// ---------- 1 vídeo nas boas-vindas, sem canal de cache ----------
enviarBoasVindasBasico('TOKEN-A', 111, $boas, '', '');
confere('1º /start (sem canal): sobe o arquivo (upload) sem legenda e o texto vai em seguida', metodosEnviados() === 'sendVideo,sendMessage' && arquivosEnviados() === 1 && !isset($GLOBALS['chamadas'][0]['p']['caption']) && ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Bem-vindo');
confere('o file_id devolvido pelo Telegram fica guardado em disco', count(cacheNoDisco()) === 1 && array_values(cacheNoDisco())[0] === 'FID_video_1', json_encode(cacheNoDisco()));

zerar();
enviarBoasVindasBasico('TOKEN-A', 222, $boas, '', '');
confere('2º /start, OUTRO lead: manda só o file_id, SEM subir o arquivo', metodosEnviados() === 'sendVideo,sendMessage' && arquivosEnviados() === 0 && ($GLOBALS['chamadas'][0]['p']['video'] ?? '') === 'FID_video_1' && ($GLOBALS['chamadas'][0]['p']['chat_id'] ?? 0) === 222);
confere('a mensagem segue completa (legenda e o resto), só o upload sumiu', ($GLOBALS['chamadas'][1]['p']['text'] ?? '') === 'Bem-vindo');

zerar();
enviarBoasVindasBasico('TOKEN-B', 333, $boas, '', '');
confere('OUTRO bot (token diferente) não usa o file_id do primeiro: sobe o seu', arquivosEnviados() === 1);
confere('cada bot guarda o seu file_id', count(cacheNoDisco()) === 2);

// ---------- mídia trocada ----------
zerar();
touch($pasta . '/video.mp4', time() + 120);
clearstatcache();
enviarBoasVindasBasico('TOKEN-A', 444, $boas, '', '');
confere('o dono trocou o vídeo (data do arquivo mudou): sobe o novo em vez de usar o file_id velho', arquivosEnviados() === 1);
zerar();
enviarBoasVindasBasico('TOKEN-A', 555, $boas, '', '');
confere('e o novo também passa a vir do cache', arquivosEnviados() === 0);

// ---------- file_id que o Telegram recusa ----------
zerar();
$GLOBALS['recusar_file_id'] = array_values(cacheNoDisco())[count(cacheNoDisco()) - 1];
enviarBoasVindasBasico('TOKEN-A', 666, $boas, '', '');
confere('file_id guardado recusado pelo Telegram: cai no upload e o lead recebe a mensagem mesmo assim', metodosEnviados() === 'sendVideo,sendVideo,sendMessage' && arquivosEnviados() === 1);
zerar();
enviarBoasVindasBasico('TOKEN-A', 777, $boas, '', '');
confere('e o file_id novo substitui o recusado (próximo lead já sai sem upload)', arquivosEnviados() === 0);

// ---------- foto ----------
zerar();
$foto = ['mensagem' => 'Oi', 'midia_tipo' => 'image', 'midia_path' => 'uploads/foto.jpg'];
enviarBoasVindasBasico('TOKEN-A', 1, $foto, '', '');
zerar();
enviarBoasVindasBasico('TOKEN-A', 2, $foto, '', '');
confere('foto: pega a MAIOR versão devolvida pelo Telegram e a reusa sem upload', arquivosEnviados() === 0 && strpos((string) ($GLOBALS['chamadas'][0]['p']['photo'] ?? ''), 'FID_photo_') === 0, json_encode($GLOBALS['chamadas'][0] ?? []));

// ---------- álbum (várias mídias) ----------
zerar();
$album = ['mensagem' => 'Veja', 'midias' => [['tipo' => 'image', 'path' => 'uploads/foto2.jpg'], ['tipo' => 'video', 'path' => 'uploads/video.mp4']]];
enviarBoasVindasBasico('TOKEN-C', 10, $album, '', '');
$sobem = count($GLOBALS['chamadas'][0]['arquivos'] ?? []);
confere('álbum, 1ª vez (sem canal): os dois arquivos sobem', $GLOBALS['chamadas'][0]['metodo'] === 'sendMediaGroup' && $sobem === 2, metodosEnviados() . ' ' . $sobem);
zerar();
enviarBoasVindasBasico('TOKEN-C', 11, $album, '', '');
$m = json_decode((string) ($GLOBALS['chamadas'][0]['p']['media'] ?? '[]'), true);
confere('álbum, próximos leads: usa os file_id guardados e não sobe arquivo nenhum', $GLOBALS['chamadas'][0]['metodo'] === 'sendMediaGroup' && arquivosEnviados() === 0 && strpos((string) ($m[0]['media'] ?? ''), 'ALB_f0_') === 0 && strpos((string) ($m[1]['media'] ?? ''), 'ALB_v1_') === 0, json_encode($m));
confere('álbum: o texto com os botões segue depois, como sempre', metodosEnviados() === 'sendMediaGroup,sendMessage');

// ---------- com canal de cache (o jeito antigo continua igual) ----------
zerar();
enviarBoasVindasBasico('TOKEN-D', 20, $boas, '-100999', '');
confere('com canal: sobe UMA vez para o canal e manda o lead pelo file_id', metodosEnviados() === 'sendVideo,sendVideo,sendMessage' && ($GLOBALS['chamadas'][0]['p']['chat_id'] ?? 0) === '-100999' && arquivosEnviados() === 1);
zerar();
enviarBoasVindasBasico('TOKEN-D', 21, $boas, '-100999', '');
confere('com canal, próximos leads: só o file_id', metodosEnviados() === 'sendVideo,sendMessage' && arquivosEnviados() === 0);

// ---------- mídia sumiu / robustez ----------
zerar();
enviarBoasVindasBasico('TOKEN-A', 30, ['mensagem' => 'Sem arquivo', 'midia_tipo' => 'video', 'midia_path' => 'uploads/sumiu.mp4'], '', '');
confere('mídia que sumiu do servidor: manda só o texto, sem erro', metodosEnviados() === 'sendMessage');
file_put_contents(CACHE_MIDIA_ARQUIVO, '{lixo');
zerar();
enviarBoasVindasBasico('TOKEN-E', 40, $boas, '', '');
confere('arquivo de cache corrompido: não derruba o envio (sobe o arquivo e refaz o cache)', arquivosEnviados() === 1 && count(cacheNoDisco()) === 1);
$mapa_grande = [];
for ($i = 0; $i < 5001; $i++) {
    $mapa_grande['k' . $i] = 'f' . $i;
}
file_put_contents(CACHE_MIDIA_ARQUIVO, json_encode($mapa_grande));
guardarFileIdCacheMidia('T', '', $pasta . '/foto.jpg', 'NOVO');
confere('cache enorme: poda para as mais recentes e mantém a entrada nova', count(cacheNoDisco()) <= 2501 && in_array('NOVO', cacheNoDisco(), true) && in_array('f5000', cacheNoDisco(), true));

foreach (glob($pasta . '/*') ?: [] as $a) {
    @unlink($a);
}
@rmdir($pasta);
echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
