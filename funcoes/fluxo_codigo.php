<?php
declare(strict_types=1);

/**
 * Exportar e importar fluxo por CÓDIGO (04/11/2026, pedido do dono: "sempre via código dentro da plataforma, assim não leva o fluxo pra outro sistema").
 * Exportar guarda uma cópia do fluxo NESTE momento em `fluxos_codigos` e devolve um código curto ("CYT-7K2M9QXA"); importar procura o código e cria um fluxo
 * novo na conta de quem colou. O fluxo nunca sai do servidor como arquivo: antes era um .json baixável, que qualquer outro sistema lia.
 * Exportar de novo o mesmo fluxo sem ter mudado nada devolve o mesmo código; mudou alguma coisa, sai um código novo (os antigos continuam valendo com a cópia antiga).
 */

const FLUXO_CODIGO_PREFIXO = 'CYT-';
const FLUXO_CODIGO_TAMANHO = 8;
const FLUXO_CODIGO_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sem 0/O e 1/I: o código é digitado e lido por gente

/** A tabela também está em admin/atualiza_banco.php; criar aqui evita quebrar o botão se o código subir antes do "Atualizar Banco". */
function garantirTabelaCodigosFluxo(PDO $pdo): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fluxos_codigos (
        codigo VARCHAR(16) NOT NULL PRIMARY KEY,
        id_usuario INT NOT NULL,
        id_fluxo INT NULL,
        modo VARCHAR(16) NOT NULL DEFAULT 'avancado',
        nome VARCHAR(120) NOT NULL DEFAULT '',
        descricao VARCHAR(500) NOT NULL DEFAULT '',
        link_suporte VARCHAR(255) NOT NULL DEFAULT '',
        dados_fluxograma LONGTEXT NOT NULL,
        hash_dados CHAR(32) NOT NULL,
        usos INT NOT NULL DEFAULT 0,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_codigos_fluxo (id_fluxo, hash_dados),
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $feito = true;
}

/** Como a pessoa colou ("cyt-7k2m 9qxa", com espaço ou sem o prefixo) para a forma guardada ("CYT-7K2M9QXA"). '' se não parece um código. */
function normalizarCodigoFluxo(string $codigo): string
{
    $limpo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
    $prefixo = rtrim(FLUXO_CODIGO_PREFIXO, '-');
    if (strlen($limpo) === strlen($prefixo) + FLUXO_CODIGO_TAMANHO && strpos($limpo, $prefixo) === 0) {   // sem o prefixo, um código que começa com "CYT" segue valendo
        $limpo = substr($limpo, strlen($prefixo));
    }
    if (strlen($limpo) !== FLUXO_CODIGO_TAMANHO || strspn($limpo, FLUXO_CODIGO_ALFABETO) !== FLUXO_CODIGO_TAMANHO) {
        return '';
    }
    return FLUXO_CODIGO_PREFIXO . $limpo;
}

function sortearCodigoFluxo(): string
{
    $codigo = '';
    $max = strlen(FLUXO_CODIGO_ALFABETO) - 1;
    for ($i = 0; $i < FLUXO_CODIGO_TAMANHO; $i++) {
        $codigo .= FLUXO_CODIGO_ALFABETO[random_int(0, $max)];
    }
    return FLUXO_CODIGO_PREFIXO . $codigo;
}

/** Campos que nunca viajam no código: identificam o grupo/canal do dono, dão acesso ao produto dele ou apontam para o contato dele. */
const FLUXO_CODIGO_CAMPOS_SENSIVEIS = ['id_grupo', 'link_entrega', 'mensagem_entrega', 'suporte', 'cache_chat_id', 'chat_id', 'id_chat'];
/** Convite de grupo/canal privado do Telegram escrito em qualquer texto ou botão (t.me/+..., t.me/joinchat/..., t.me/c/...). */
const FLUXO_CODIGO_REGEX_CONVITE = '#(?:https?://)?(?:t|telegram)\.me/(?:\+|joinchat/|c/)[^\s"\'<>)]*[^\s"\'<>).,;:!?]#i';   // o ponto ou a vírgula que fecham a frase ficam

/**
 * Tira do fluxo o que é do dono e não deve ir para quem importa (item 75, 05/11/2026: "compartilhar fluxos, só não manda dados sensíveis"):
 *  - grupo/canal de entrega, link de entrega, mensagem de entrega (pode ter acesso, login ou link do produto), usuário de suporte e canal de cache de mídias;
 *  - convites privados do Telegram escritos em textos e botões.
 * Onde a entrega era grupo, link ou mensagem, vira "nenhuma" (sem isso o fluxo importado seria recusado por "grupo sem id"); "igual ao plano" fica.
 * Fica o resto: mensagens, planos, preços, ofertas, mídias (arquivos do servidor) e a estrutura. Funciona no Guiado e no Editor Visual, em qualquer profundidade.
 */
function removerDadosSensiveisFluxo(array $dados): array
{
    foreach ($dados as $chave => $valor) {
        if (is_array($valor)) {
            $dados[$chave] = removerDadosSensiveisFluxo($valor);
        } elseif (is_string($chave) && in_array($chave, FLUXO_CODIGO_CAMPOS_SENSIVEIS, true)) {
            $dados[$chave] = is_string($valor) ? '' : null;
        } elseif ($chave === 'entrega_tipo' && in_array($valor, ['grupo', 'link', 'mensagem'], true)) {
            $dados[$chave] = 'nenhuma';
        } elseif (is_string($valor) && stripos($valor, '.me/') !== false) {
            $dados[$chave] = preg_replace(FLUXO_CODIGO_REGEX_CONVITE, '', $valor) ?? $valor;
        }
    }
    return $dados;
}

/** Código do fluxo do usuário (só o dono exporta). null = fluxo não é dele. */
function gerarCodigoFluxo(PDO $pdo, int $id_usuario, int $id_fluxo): ?string
{
    $stmt = $pdo->prepare("SELECT id, nome, descricao, link_suporte, modo, dados_fluxograma FROM fluxos WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$id_fluxo, $id_usuario]);
    $fluxo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fluxo) {
        return null;
    }
    garantirTabelaCodigosFluxo($pdo);
    // A cópia guardada já sai sem os dados sensíveis (o link de suporte do fluxo também): o que o código guarda é o que qualquer pessoa com ele consegue importar.
    $decodificado = json_decode((string) ($fluxo['dados_fluxograma'] ?? '{}'), true);
    $dados = json_encode(removerDadosSensiveisFluxo(is_array($decodificado) ? $decodificado : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fluxo['link_suporte'] = '';
    $modo = ($fluxo['modo'] ?? 'avancado') === 'basico' ? 'basico' : 'avancado';
    $hash = md5($modo . "\n" . $fluxo['nome'] . "\n" . $fluxo['descricao'] . "\n" . $dados);
    $existente = $pdo->prepare("SELECT codigo FROM fluxos_codigos WHERE id_fluxo = ? AND id_usuario = ? AND hash_dados = ? LIMIT 1");
    $existente->execute([$id_fluxo, $id_usuario, $hash]);
    $codigo = $existente->fetchColumn();
    if ($codigo) {
        return (string) $codigo;
    }
    $inserir = $pdo->prepare("INSERT INTO fluxos_codigos (codigo, id_usuario, id_fluxo, modo, nome, descricao, link_suporte, dados_fluxograma, hash_dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    for ($tentativa = 0; $tentativa < 5; $tentativa++) {
        $codigo = sortearCodigoFluxo();
        try {
            $inserir->execute([$codigo, $id_usuario, $id_fluxo, $modo, (string) $fluxo['nome'], (string) $fluxo['descricao'], (string) $fluxo['link_suporte'], $dados, $hash]);
            return $codigo;
        } catch (PDOException $e) {
            if ($tentativa === 4) {
                throw $e;   // 5 sorteios repetidos seguidos (1 em 10^12 cada): é outro erro
            }
        }
    }
    return null;
}

/** A cópia guardada pelo código, já com os dados decodificados; null se o código não existe. */
function localizarCodigoFluxo(PDO $pdo, string $codigo): ?array
{
    $codigo = normalizarCodigoFluxo($codigo);
    if ($codigo === '') {
        return null;
    }
    garantirTabelaCodigosFluxo($pdo);
    $stmt = $pdo->prepare("SELECT * FROM fluxos_codigos WHERE codigo = ?");
    $stmt->execute([$codigo]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return null;
    }
    $linha['dados_fluxograma'] = json_decode((string) $linha['dados_fluxograma'], true);
    return is_array($linha['dados_fluxograma']) ? $linha : null;
}

function registrarUsoCodigoFluxo(PDO $pdo, string $codigo): void
{
    $pdo->prepare("UPDATE fluxos_codigos SET usos = usos + 1 WHERE codigo = ?")->execute([$codigo]);
}

/**
 * Ajusta a cópia para a conta de quem importa. Código de outra conta passa de novo pelo filtro dos dados sensíveis: os códigos gerados antes dele existir
 * (item 72) guardaram o fluxo inteiro, e o canal de cache de mídias é do Telegram de quem exportou (o bot de outra pessoa não está nele).
 * Código da própria conta (ex.: duplicar um fluxo) já sai filtrado na exportação.
 */
function dadosFluxoParaImportar(array $dados, bool $outra_conta): array
{
    return $outra_conta ? removerDadosSensiveisFluxo($dados) : $dados;
}
