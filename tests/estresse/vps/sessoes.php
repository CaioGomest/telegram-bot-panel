<?php
// Cria sessões PHP prontas (sem passar pelo login, que é caro: bcrypt) para os usuários de teste e para o administrador,
// no pool de teste (/var/lib/php/estresse-sessoes), e grava a lista de cookies para o wrk.
//   php sessoes.php --tag=e1 --n=2000          → /root/res/cookies_usuarios.txt (1 id de sessão por linha)  + /root/res/cookie_admin.txt
// Roda NA VPS como root. As sessões valem para http://127.0.0.1:8080 (a cópia de teste do painel).
chdir('/var/www/painel');
require_once 'conexao.php';
$op = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2]; }
$tag = preg_replace('/[^a-z0-9]/i', '', $op['tag'] ?? 'e1');
$n = (int) ($op['n'] ?? 1000);
$dir = '/var/lib/php/estresse-sessoes';
$st = $pdo->prepare("SELECT id, nome, email, perfil FROM usuarios WHERE email LIKE ? ORDER BY id LIMIT $n");
$st->execute(["$tag-%@estresse.invalid"]);
$usuarios = $st->fetchAll(PDO::FETCH_ASSOC);
$adm = $pdo->query("SELECT id, nome, email, perfil FROM usuarios WHERE perfil = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
@mkdir('/root/res', 0755, true);
function sessao(array $u, string $dir): string
{
    $sid = bin2hex(random_bytes(16));
    $dados = 'usuario_id|i:' . (int) $u['id'] . ';usuario_nome|s:' . strlen($u['nome']) . ':"' . $u['nome'] . '";usuario_email|s:' . strlen($u['email']) . ':"' . $u['email'] . '";usuario_perfil|s:' . strlen($u['perfil']) . ':"' . $u['perfil'] . '";';
    file_put_contents("$dir/sess_$sid", $dados);
    chmod("$dir/sess_$sid", 0600);
    chown("$dir/sess_$sid", 'www-data');
    return $sid;
}
$lista = [];
foreach ($usuarios as $u) $lista[] = sessao($u, $dir);
file_put_contents($op['saida'] ?? '/root/res/cookies_usuarios.txt', implode("\n", $lista) . "\n");
file_put_contents('/root/res/cookie_admin.txt', sessao($adm, $dir) . "\n");
echo count($lista), " sessões de usuário + 1 de administrador criadas\n";
