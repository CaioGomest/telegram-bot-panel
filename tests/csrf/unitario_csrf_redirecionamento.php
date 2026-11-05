<?php
declare(strict_types=1);

/** Código de segurança vencido: para onde o painel redireciona (funcoes/csrf.php). php tests/csrf/unitario_csrf_redirecionamento.php */
require_once dirname(__DIR__, 2) . '/funcoes/csrf.php';

$ok = 0; $falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
$h = 'coyotebot.com.br';
$d = fn(string $ref, bool $logado = false) => destinoAposCsrfInvalido($ref, $h, $logado);

confere('volta ao login com o aviso', $d('https://coyotebot.com.br/login') === '/login?erro=sessao', $d('https://coyotebot.com.br/login'));
confere('login.php também', $d('https://coyotebot.com.br/login.php') === '/login.php?erro=sessao');
confere('outra página volta para ela mesma, com a consulta', $d('https://coyotebot.com.br/vendas?aba=pagas&periodo=7') === '/vendas?aba=pagas&periodo=7');
confere('página dentro de admin/', $d('https://coyotebot.com.br/admin/configuracoes') === '/admin/configuracoes');
confere('Referer de OUTRO site não vale (sem redirecionamento aberto)', $d('https://site-malicioso.com/login') === '/login?erro=sessao' && $d('https://site-malicioso.com/x', true) === '/index');
confere('host parecido não engana', $d('https://coyotebot.com.br.evil.com/login') === '/login?erro=sessao' && $d('https://evilcoyotebot.com.br/a') === '/login?erro=sessao');
confere('"//" no caminho não vira outro site', strpos($d('https://coyotebot.com.br//evil.com/x'), '//') !== 0);
confere('sem Referer: deslogado vai ao login, logado ao início', $d('') === '/login?erro=sessao' && $d('', true) === '/index');
confere('Referer quebrado não dá erro', $d('::::') === '/login?erro=sessao');
echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
