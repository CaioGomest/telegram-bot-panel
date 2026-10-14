<?php
declare(strict_types=1);

/**
 * Roda o "Atualizar Banco" (admin/atualiza_banco.php) pela LINHA DE COMANDO, sem navegador nem login.
 * É a mesma migração idempotente da tela (cria o que falta, nunca apaga dado). Quem tem acesso ao shell já é dono do servidor.
 *
 *   sudo -u www-data php deploy/vps/atualizar-banco.php /var/www/painel [--so-verificar]
 *
 * --so-verificar: só confere que a autenticação de linha de comando funciona e sai (não altera o banco).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = rtrim((string) ($argv[1] ?? '/var/www/painel'), '/');
if (!is_file("$raiz/conexao.php") || !is_file("$raiz/admin/atualiza_banco.php")) {
    fwrite(STDERR, "Pasta do painel inválida: $raiz (não achei conexao.php / admin/atualiza_banco.php)\n");
    exit(2);
}

chdir("$raiz/admin");
$_SERVER['SCRIPT_NAME'] = '/admin/atualiza_banco.php';
$_SERVER['REQUEST_URI'] = '/admin/atualiza_banco';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

require "$raiz/funcoes/usuario.php"; // abre a sessão e carrega conexão e funções

$admin_id = (int) $pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($admin_id < 1) {
    fwrite(STDERR, "Não há administrador cadastrado (o banco já foi instalado?).\n");
    exit(2);
}
$_SESSION['usuario_id'] = $admin_id;
$_SESSION['usuario_perfil'] = 'admin';

if (in_array('--so-verificar', $argv, true)) {
    verificarAdminOuInstalacao();
    echo "ok: a autenticação de linha de comando funciona (admin id $admin_id); nada foi alterado\n";
    exit(0);
}

ob_start();
require "$raiz/admin/atualiza_banco.php"; // imprime a página inteira em HTML
$html = (string) ob_get_clean();

$texto = preg_match('#<div class="saida-debug">(.*?)</div>\s*</div>\s*</main>#s', $html, $m) ? $m[1] : $html;
$texto = preg_replace('#<br\s*/?>|</h2>|</p>|</strong>#i', "\n", $texto);
$texto = trim(html_entity_decode(strip_tags((string) $texto), ENT_QUOTES, 'UTF-8'));
echo $texto, "\n";
exit(stripos($texto, 'Erro fatal') !== false ? 1 : 0);
