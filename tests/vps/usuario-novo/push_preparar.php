<?php
// Auxiliar do teste 36_push_tela.js. Roda NA VPS como www-data, lido da entrada padrão:
//   plink … "cd /var/www/painel && sudo -u www-data php - <comando> [argumento]" < push_preparar.php
// Comandos:
//   ligar                liga os avisos (guarda o valor original em /tmp/push_prep_estado.json), garante as chaves e gera os ícones
//   desligar             devolve push_ativo ao valor original e apaga as sessões de teste criadas por "sessao_admin"
//   sessao_admin         cria uma sessão de ADMIN só para o navegador de teste (a senha do admin não é necessária) e devolve o id; "desligar" apaga
//   estado <email>       aparelhos da conta (sem endereço nem chaves) + itens na fila + configuração, em JSON
//   limpar <email>       apaga os aparelhos (e a fila deles) da conta de teste
//   config               push_ativo / push_contato / existência das chaves e dos ícones
chdir('/var/www/painel');
require_once 'conexao.php';
require_once 'funcoes/configuracoes.php';
require_once 'funcoes/push.php';
require_once 'funcoes/push_icones.php';
$pdo = $GLOBALS['pdo'];
$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';
const ESTADO_ARQ = '/tmp/push_prep_estado.json';
const SESSOES_ARQ = '/tmp/push_prep_sessoes.json';

function sair(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function idPorEmail(PDO $pdo, string $email): int
{
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    return (int) $st->fetchColumn();
}

switch ($cmd) {
    case 'ligar':
        if (!is_file(ESTADO_ARQ)) {
            file_put_contents(ESTADO_ARQ, json_encode(['push_ativo' => pushLerConfig($pdo, 'push_ativo'), 'push_contato' => pushLerConfig($pdo, 'push_contato'),
                'tinha_chaves' => pushVapidPublica($pdo) !== '', 'push_icone_v' => pushIconesConfig('push_icone_v')]));
        }
        $chaves = pushGarantirChaves($pdo);
        definirConfigSistema('push_ativo', '1');
        $ic = pushIconesGerar();
        sair(['chaves' => $chaves, 'icones' => $ic['sucesso'], 'origem' => $ic['origem'], 'versao' => $ic['v'], 'avisos' => $ic['avisos']]);

    case 'desligar':
        $orig = is_file(ESTADO_ARQ) ? (json_decode((string) file_get_contents(ESTADO_ARQ), true) ?: []) : [];
        // valor original vazio = a linha não existia: apaga em vez de gravar "0" (o banco volta exatamente como estava)
        foreach (['push_ativo', 'push_contato'] as $chave) {
            if (!array_key_exists($chave, $orig)) {
                continue;
            }
            if ((string) $orig[$chave] === '') {
                $pdo->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([$chave]);
            } else {
                definirConfigSistema($chave, (string) $orig[$chave]);
            }
        }
        // chaves e versão do ícone criadas pelo teste saem junto (só se não existiam antes e ninguém tem aparelho registrado)
        $chaves_apagadas = false;
        if (array_key_exists('tinha_chaves', $orig) && !$orig['tinha_chaves'] && (int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes')->fetchColumn() === 0) {
            $pdo->exec("DELETE FROM configuracoes WHERE chave IN ('push_vapid_publica', 'push_vapid_privada')");
            $chaves_apagadas = true;
        }
        if (array_key_exists('push_icone_v', $orig) && (string) $orig['push_icone_v'] === '') {
            $pdo->exec("DELETE FROM configuracoes WHERE chave = 'push_icone_v'");
        }
        @unlink(ESTADO_ARQ);
        $apagadas = 0;
        foreach ((array) (is_file(SESSOES_ARQ) ? json_decode((string) file_get_contents(SESSOES_ARQ), true) : []) as $arq) {
            if (is_string($arq) && str_contains($arq, 'sess_') && @unlink($arq)) {
                $apagadas++;
            }
        }
        @unlink(SESSOES_ARQ);
        sair(['push_ativo' => pushLerConfig($pdo, 'push_ativo'), 'sessoes_apagadas' => $apagadas, 'chaves_apagadas' => $chaves_apagadas]);

    case 'sessao_admin':
        $admin = $pdo->query("SELECT id, nome, email FROM usuarios WHERE perfil = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$admin) {
            sair(['erro' => 'sem admin']);
        }
        $sid = bin2hex(random_bytes(16));
        session_save_path('/var/lib/php/painel-sessoes');   // a mesma pasta de sessões do PHP-FPM do painel (a do CLI é outra)
        session_id($sid);
        session_start();
        $_SESSION['usuario_id'] = (int) $admin['id'];
        $_SESSION['usuario_nome'] = $admin['nome'];
        $_SESSION['usuario_email'] = $admin['email'];
        $_SESSION['usuario_perfil'] = 'admin';
        session_write_close();
        $arquivo = '/var/lib/php/painel-sessoes/sess_' . $sid;
        $lista = is_file(SESSOES_ARQ) ? (json_decode((string) file_get_contents(SESSOES_ARQ), true) ?: []) : [];
        $lista[] = $arquivo;
        file_put_contents(SESSOES_ARQ, json_encode($lista));
        sair(['sid' => $sid, 'arquivo_existe' => is_file($arquivo)]);

    case 'estado':
        $uid = idPorEmail($pdo, $arg);
        $st = $pdo->prepare('SELECT id, plataforma, apelido, pref_pix_gerado, pref_pix_pago, ativo, falhas_seguidas, endpoint_hash, ultimo_envio_ok FROM push_inscricoes WHERE id_usuario = ? ORDER BY id');
        $st->execute([$uid]);
        $aparelhos = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT f.tipo, f.estado, COUNT(*) AS n FROM push_fila f JOIN push_inscricoes i ON i.id = f.id_inscricao WHERE i.id_usuario = ? GROUP BY f.tipo, f.estado');
        $st->execute([$uid]);
        sair(['uid' => $uid, 'aparelhos' => $aparelhos, 'fila' => $st->fetchAll(PDO::FETCH_ASSOC),
            'metricas_hora' => $pdo->query('SELECT enviados, falhas_temporarias, inscricoes_removidas, descartados, erros_fatais FROM push_metricas_hora ORDER BY hora DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null]);

    case 'limpar':
        $uid = idPorEmail($pdo, $arg);
        $st = $pdo->prepare('DELETE FROM push_inscricoes WHERE id_usuario = ?');
        $st->execute([$uid]);
        $apagados = $st->rowCount();
        // os limites de inscrição/teste da conta de teste também zeram (20 inscrições/h e 5 testes/h travariam uma 2ª rodada)
        $pdo->prepare('DELETE FROM limites_taxa WHERE chave IN (?, ?, ?)')->execute(['push_inscrever:' . $uid, 'push_teste_10s:' . $uid, 'push_teste_1h:' . $uid]);
        sair(['apagados' => $apagados]);

    case 'config':
        sair(['push_ativo' => pushLerConfig($pdo, 'push_ativo'), 'push_contato' => pushLerConfig($pdo, 'push_contato'), 'tem_chaves' => pushVapidPublica($pdo) !== '',
            'icones_completos' => pushIconesCompletos(), 'push_icone_v' => pushIconesConfig('push_icone_v')]);

    default:
        sair(['erro' => 'comando desconhecido']);
}
