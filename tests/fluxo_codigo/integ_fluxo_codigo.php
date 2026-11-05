<?php
declare(strict_types=1);

/**
 * Exportar/importar fluxo por código (funcoes/fluxo_codigo.php) contra um MariaDB de verdade (a tabela usa LONGTEXT, FK e o UNIQUE do código).
 * Banco de teste descartável, nunca o do painel:
 *   FC_DSN="mysql:host=127.0.0.1;port=3395" FC_USUARIO=root FC_SENHA=teste php tests/fluxo_codigo/integ_fluxo_codigo.php
 */

require_once dirname(__DIR__, 2) . '/funcoes/fluxo_codigo.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

$pdo = new PDO((string) getenv('FC_DSN'), (string) getenv('FC_USUARIO'), (string) getenv('FC_SENHA'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS teste_fluxo_codigo');
$pdo->exec('CREATE DATABASE teste_fluxo_codigo CHARACTER SET utf8mb4');
$pdo->exec('USE teste_fluxo_codigo');
$pdo->exec('CREATE TABLE usuarios (id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec("CREATE TABLE fluxos (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT, nome VARCHAR(120), descricao VARCHAR(500) DEFAULT '', link_suporte VARCHAR(255) DEFAULT '', modo VARCHAR(16), dados_fluxograma LONGTEXT) ENGINE=InnoDB");
$pdo->exec('INSERT INTO usuarios (id) VALUES (1), (2)');
$guiado = ['suporte' => '@dono', 'planos' => [['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'entrega_tipo' => 'grupo', 'id_grupo' => '-100777']], 'boas_vindas' => ['mensagem' => 'Olá, ção 🎉'], 'cache_chat_id' => '-100123'];
$pdo->prepare("INSERT INTO fluxos (id, id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (10, 1, 'Funil VIP', 'desc', 'basico', ?)")->execute([json_encode($guiado, JSON_UNESCAPED_UNICODE)]);
$pdo->exec("INSERT INTO fluxos (id, id_usuario, nome, modo, dados_fluxograma) VALUES (20, 2, 'Do outro', 'avancado', '{\"operators\":{},\"links\":{}}')");

// ---------- formato do código ----------
$c = sortearCodigoFluxo();
confere('código sorteado: CYT- + 8 caracteres sem 0/O/1/I', (bool) preg_match('/^CYT-[A-HJ-NP-Z2-9]{8}$/', $c), $c);
confere('normalizar: aceita minúsculas, espaços, hífens e sem o prefixo', normalizarCodigoFluxo(' cyt-ab2c 3d4e ') === 'CYT-AB2C3D4E' && normalizarCodigoFluxo('ab2c3d4e') === 'CYT-AB2C3D4E');
confere('normalizar: código colado sem prefixo que começa com "CYT" não perde letras', normalizarCodigoFluxo('CYTABC23') === 'CYT-CYTABC23');
confere('normalizar: recusa tamanho errado e letra fora do alfabeto (0, O, 1, I)', normalizarCodigoFluxo('CYT-AB2C3D4') === '' && normalizarCodigoFluxo('CYT-AB2C3D40') === '' && normalizarCodigoFluxo('CYT-AB2C3DIE') === '' && normalizarCodigoFluxo('') === '');

// ---------- gerar ----------
confere('fluxo de outra pessoa não exporta (null)', gerarCodigoFluxo($pdo, 1, 20) === null);
confere('fluxo que não existe não exporta (null)', gerarCodigoFluxo($pdo, 1, 999) === null);
$c1 = gerarCodigoFluxo($pdo, 1, 10);
confere('dono exporta: devolve um código válido', is_string($c1) && normalizarCodigoFluxo($c1) === $c1, (string) $c1);
confere('exportar não mexe no fluxo original (o grupo e o canal de cache continuam nele)', (json_decode((string) $pdo->query('SELECT dados_fluxograma FROM fluxos WHERE id = 10')->fetchColumn(), true)['planos'][0]['id_grupo'] ?? '') === '-100777' && (json_decode((string) $pdo->query('SELECT dados_fluxograma FROM fluxos WHERE id = 10')->fetchColumn(), true)['cache_chat_id'] ?? '') === '-100123');
confere('exportar de novo sem mudar nada devolve o MESMO código', gerarCodigoFluxo($pdo, 1, 10) === $c1);
$guiado['planos'][0]['valor'] = 24.9;
$pdo->prepare('UPDATE fluxos SET dados_fluxograma = ? WHERE id = 10')->execute([json_encode($guiado, JSON_UNESCAPED_UNICODE)]);
$c2 = gerarCodigoFluxo($pdo, 1, 10);
confere('mudou o fluxo: sai um código novo', is_string($c2) && $c2 !== $c1);
confere('só 2 cópias guardadas (a repetida não duplicou)', (int) $pdo->query('SELECT COUNT(*) FROM fluxos_codigos')->fetchColumn() === 2);

// ---------- localizar ----------
$copia1 = localizarCodigoFluxo($pdo, strtolower($c1));
confere('localizar pelo código (mesmo em minúsculas) traz a cópia de quando exportou', $copia1 !== null && ($copia1['dados_fluxograma']['planos'][0]['valor'] ?? 0) == 19.9 && $copia1['nome'] === 'Funil VIP' && $copia1['modo'] === 'basico');
confere('acentos e emoji chegam intactos', ($copia1['dados_fluxograma']['boas_vindas']['mensagem'] ?? '') === 'Olá, ção 🎉');
confere('código atual traz a versão nova', (localizarCodigoFluxo($pdo, $c2)['dados_fluxograma']['planos'][0]['valor'] ?? 0) == 24.9);
confere('código inexistente ou malformado: null', localizarCodigoFluxo($pdo, 'CYT-ZZZZZZZZ') === null && localizarCodigoFluxo($pdo, 'qualquer coisa') === null);
$pdo->exec('DELETE FROM fluxos WHERE id = 10');
confere('fluxo original apagado: o código continua valendo (é uma cópia)', localizarCodigoFluxo($pdo, $c2) !== null);

// ---------- importar ----------
confere('outra conta: o canal de cache de mídias de quem exportou sai', (dadosFluxoParaImportar($copia1['dados_fluxograma'], true)['cache_chat_id'] ?? 'x') === '');
confere('o código guarda a cópia SEM os dados sensíveis (canal de cache vazio, id do grupo do plano vazio)', ($copia1['dados_fluxograma']['cache_chat_id'] ?? 'x') === '' && ($copia1['dados_fluxograma']['planos'][0]['id_grupo'] ?? 'x') === '' && ($copia1['dados_fluxograma']['planos'][0]['entrega_tipo'] ?? 'x') === 'nenhuma', json_encode($copia1['dados_fluxograma']));
registrarUsoCodigoFluxo($pdo, $c1);
registrarUsoCodigoFluxo($pdo, $c1);
confere('cada importação conta um uso', (int) $pdo->query("SELECT usos FROM fluxos_codigos WHERE codigo = " . $pdo->quote($c1))->fetchColumn() === 2);

$pdo->exec('DELETE FROM usuarios WHERE id = 1');
confere('conta apagada: os códigos dela somem junto (FK)', (int) $pdo->query('SELECT COUNT(*) FROM fluxos_codigos')->fetchColumn() === 0);

$pdo->exec('DROP DATABASE teste_fluxo_codigo');
echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
