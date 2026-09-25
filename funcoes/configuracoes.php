<?php
declare(strict_types=1);

// Identidade visual do painel (white-label): nome, logo e favicon ficam no banco, não no
// código, pra cada instalação ter a sua sem precisar editar arquivo. Os valores iniciais são
// gravados por instalacao.php e podem ser trocados depois em admin/configuracoes.php.

const CONFIG_SISTEMA_PADRAO = [
    'nome_sistema'  => 'Painel de Bots',
    'logo'          => 'assets/img/coyote-logo.jpg',
    'favicon'       => '',
    'cor_primaria'  => '#ff6a1a',
];

/**
 * Lê uma configuração do banco, com cache por requisição.
 *
 * Tolera a tabela não existir: instalação antiga que ainda não rodou a atualização do banco
 * continua abrindo normalmente, só usando os valores padrão (mesmo padrão defensivo já usado
 * em tentativas_login e em membros_grupos.em_renovacao).
 */
function configSistema(string $chave): string
{
    static $cache = null;
    global $pdo;

    if ($cache === null) {
        $cache = [];
        try {
            if (isset($pdo)) {
                foreach ($pdo->query("SELECT chave, valor FROM configuracoes")->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                    $cache[$linha['chave']] = (string) $linha['valor'];
                }
            }
        } catch (\Throwable $e) {
            // tabela ainda não existe — segue com os padrões
        }
    }

    $valor = $cache[$chave] ?? '';
    return $valor !== '' ? $valor : (CONFIG_SISTEMA_PADRAO[$chave] ?? '');
}

function definirConfigSistema(string $chave, string $valor): bool
{
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([$chave, $valor]);
        return true;
    } catch (\Throwable $e) {
        error_log('[configuracoes] falha ao gravar ' . $chave . ': ' . $e->getMessage());
        return false;
    }
}

function nomeSistema(): string
{
    return configSistema('nome_sistema');
}

/** Caminho da logo pronto pro src/href, já com o prefixo certo pra páginas dentro de admin/. */
function logoSistema(string $caminho_base = ''): string
{
    return $caminho_base . configSistema('logo');
}

/** Favicon é opcional: sem ele configurado, cai na logo. */
function faviconSistema(string $caminho_base = ''): string
{
    $favicon = configSistema('favicon');
    return $caminho_base . ($favicon !== '' ? $favicon : configSistema('logo'));
}

function corPrimariaSistema(): string
{
    return configSistema('cor_primaria');
}

/** Clareia um hex #rrggbb interpolando cada canal em direção ao branco. Usada pra derivar
 *  a variante de hover (--or2) a partir da cor de destaque escolhida no admin. */
function corClareada(string $hex, float $fator = 0.24): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    $r = (int) round($r + (255 - $r) * $fator);
    $g = (int) round($g + (255 - $g) * $fator);
    $b = (int) round($b + (255 - $b) * $fator);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/** Versão translúcida de um hex #rrggbb, pra fundo suave (--orsoft). */
function corSuave(string $hex, float $alpha = 0.13): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return "rgba($r, $g, $b, $alpha)";
}

/**
 * Salva um arquivo de marca (logo/favicon) em uploads/ com nome fixo por tipo, pra não
 * acumular lixo a cada troca. A extensão vem de uma lista fechada e, para formato que o PHP
 * sabe ler, o conteúdo é conferido com getimagesize() -- mesmo padrão dos uploads de mídia
 * em api.php. A pasta uploads/ já tem .htaccess bloqueando execução de PHP.
 *
 * Retorna o caminho relativo à raiz do projeto (ex: uploads/marca_logo.png), que é o formato
 * que logoSistema()/faviconSistema() esperam encontrar no banco.
 */
function salvarArquivoMarca(array $arquivo, string $nome_base): string
{
    $permitidas = ['png', 'jpg', 'jpeg', 'webp', 'ico'];
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $permitidas, true)) {
        throw new RuntimeException('Formato não aceito. Use PNG, JPG, WEBP ou ICO.');
    }
    if ($arquivo['size'] <= 0 || $arquivo['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Arquivo vazio ou maior que 2 MB.');
    }
    // .ico o getimagesize() não lê de forma confiável; os demais são conferidos de verdade.
    if ($ext !== 'ico' && !@getimagesize($arquivo['tmp_name'])) {
        throw new RuntimeException('O arquivo não parece ser uma imagem válida.');
    }

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta uploads/.');
    }
    $destino = $dir . '/' . $nome_base . '.' . $ext;
    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o arquivo enviado.');
    }
    // Só depois de gravar a nova é que as versões em outra extensão são removidas -- se o
    // move falhasse antes, a marca anterior já teria sido apagada e o banco apontaria pro vazio.
    foreach ($permitidas as $antiga) {
        $anterior = $dir . '/' . $nome_base . '.' . $antiga;
        if ($anterior !== $destino && file_exists($anterior)) {
            @unlink($anterior);
        }
    }
    return 'uploads/' . $nome_base . '.' . $ext;
}

/**
 * Converte um caminho de marca relativo à página (ex: "../uploads/marca_logo.png") num
 * caminho absoluto a partir da raiz do site (ex: "/uploads/marca_logo.png").
 *
 * Existe por causa do `url()` dentro de custom property: o navegador resolve esse url()
 * contra a folha de estilo que CONSOME a variável, não contra a página que a declarou.
 * Como --logo-url é declarada num <style> inline mas usada no coyote.css, o Chrome pedia
 * /assets/css/assets/img/coyote-logo.jpg e dava 404. Com caminho absoluto isso não importa.
 *
 * Funciona em subdiretório (o projeto roda em /telegram-bot-panel/ no XAMPP local e na
 * raiz em produção), porque parte do diretório do próprio script.
 */
function urlMarca(string $relativo): string
{
    if ($relativo === '' || preg_match('#^(https?:)?//#', $relativo)) {
        return $relativo; // já é absoluto
    }
    // No Windows, dirname("/bots.php") devolve a barra invertida em vez de "/" -- some em
    // producao (Linux), mas quebraria o caminho no XAMPP local. Normaliza os dois casos.
    $dir = str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $partes = [];
    foreach (explode('/', rtrim($dir, '/') . '/' . ltrim($relativo, '/')) as $parte) {
        if ($parte === '' || $parte === '.') {
            continue;
        }
        if ($parte === '..') {
            array_pop($partes);
            continue;
        }
        $partes[] = $parte;
    }
    return '/' . implode('/', $partes);
}
