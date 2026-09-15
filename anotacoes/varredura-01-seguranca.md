# Varredura 01 — Segurança e limpeza (achados)

Data: análise inicial pós-clone, branch `new`.

## 🔴 Alta prioridade — exposição/vazamento

- **`debug_cron.php`, `debug_fix_db.php`, `temp_check_db.php`, `atualizacao_seguranca.php`** — sem `verificarLogin`/`verificarAdmin`. Se ficarem no servidor, qualquer pessoa que acesse a URL direto vê dados do banco (`print_r` de vendas, estrutura de tabelas, etc). **Remover do repo antes do deploy na Hostinger** (já sinalizados no README para exclusão).
- **`debug_fix_db.php`** força `host = '127.0.0.1'` e expõe mensagem de erro (`$e->getMessage()`) direto na tela — pode vazar detalhes internos do banco.
- **`instalacao.php`, `atualiza_banco.php`, `setup_menus.php`, `popular_banco.php`** — sem checagem de login/admin. Se acessíveis via URL em produção, permitem recriar/alterar schema ou popular dados fake sem autenticação. Precisam de proteção (`verificarAdmin`) ou ficar fora do deploy de produção (rodar só localmente/CLI).
- **`atualiza_banco.php` e `instalacao.php`** criam usuário admin com senha fraca hardcoded (`admin123`, `123456`) — ok pra instalação inicial, mas tem que forçar troca de senha no primeiro login (verificar se isso já existe).

## 🟡 Média prioridade — SQLi potencial

- **`popular_banco.php:61`** — `$pdo->exec("UPDATE bots SET id_fluxo_conectado = $id_fluxo WHERE id = $id_bot")` concatena direto. Nesse caso os valores vêm de `lastInsertId()` (não é input do usuário), então risco real é baixo, mas quebra o padrão de sempre usar prepared statement — trocar por bind mesmo assim.
- **`instalacao.php:16-17`** — `CREATE DATABASE`/`USE` com `$nome_banco` interpolado direto. Verificar de onde vem esse valor (se é input do formulário de instalação, precisa validar/whitelist antes).
- **`atualiza_banco.php`** — vários `ALTER TABLE ... $alter` com variável interpolada, mas `$alter` parece vir de array fixo no código (não de input externo) — baixo risco, mas vale confirmar.
- Vários `$pdo->query(...)` com SQL fixo (sem variável) — ok, não é SQLi, só listado porque usa `query()` ao invés de `prepare()` (não é problema de segurança aqui, só padrão).

## 🟡 Média prioridade — sessão/cookies

- `session_start()` sem `session_set_cookie_params` — cookies de sessão sem flags `HttpOnly`/`Secure`/`SameSite` explícitas. Configurar antes do deploy (principalmente `Secure` já que vai rodar em HTTPS na Hostinger).

## 🟢 Positivo (já está certo)

- Senhas usam `password_hash`/`password_verify` (bcrypt) — correto, não é md5/sha1.
- Maioria das queries com dados de usuário já usa prepared statements (`?` + `execute([...])`).
- Endpoints em `ajax/` todos com `verificarLogin`/`verificarAdmin`.
- `webhook.php` já tem log de erro em arquivo ao invés de expor na tela (bom), só cuidar pra não logar dado sensível (token, chave de gateway) nesse log.

## Código desnecessário / limpeza

- `debug_cron.php`, `debug_fix_db.php`, `temp_check_db.php`, `teste_infopago_painel.php`, `teste_infopago_recorrente_painel.php` — arquivos de teste/debug, candidatos a remoção (ou mover pra fora do deploy).
- `atualizacao_seguranca.php` — nome sugere script de migração pontual já aplicada; confirmar se ainda é necessário ou se já rodou e pode sair.

## Próximos passos sugeridos

1. ~~Confirmar com o Caio quais dos arquivos de debug/teste podem ser deletados.~~ **Feito diferente:** em vez de deletar, protegidos com `verificarAdmin()`/`verificarAdminOuInstalacao()` e adicionados ao menu admin em "Debug".
2. ~~Proteger ou remover `instalacao.php`/`atualiza_banco.php`/`setup_menus.php`/`popular_banco.php` do ambiente de produção.~~ **Feito:** protegidos (ver seção abaixo).
3. ~~Trocar a concatenação em `popular_banco.php:61` por prepared statement.~~ **Feito.**
4. ~~Configurar cookie de sessão com `Secure`/`HttpOnly`/`SameSite`.~~ **Feito** (na verdade já tinha sido feito no fix da varredura 02, essa lista aqui só não tinha sido atualizada até agora).
5. ~~Adicionar `declare(strict_types=1)` nos arquivos que faltam.~~ **Feito** (últimos 5: `remarketing.php`, `leads.php`, `sidebar.php`, `login.php`, `cadastro.php`).

## Correções aplicadas (varredura 01 → fix)

- `debug_cron.php`, `debug_fix_db.php`, `temp_check_db.php`, `atualizacao_seguranca.php` — agora exigem `verificarAdmin()`. Adicionado `declare(strict_types=1)`.
- `atualiza_banco.php`, `setup_menus.php`, `popular_banco.php` — agora exigem admin **depois** que já existe um admin cadastrado no banco (função nova `verificarAdminOuInstalacao()` em `funcoes/usuario.php`, baseada em `sistemaJaInstalado()`). No primeiro deploy, antes de existir qualquer admin, ficam abertos (senão travaria o setup inicial).
- `instalacao.php` — mesma lógica, com checagem própria (`instaladorJaTemAdmin()`) que testa a conexão com o banco configurado em `config.php` sem depender do resto da app (pra não quebrar antes do banco existir).
- Adicionada seção **"Debug"** no menu lateral (admin), com links pros 6 arquivos acima (exceto `instalacao.php`, que não entrou no menu por ser o instalador inicial).
- **Resolvido:** exposição de `$e->getMessage()` na tela trocada por log + mensagem genérica em `debug_colunas_vendas.php` (antigo `debug_fix_db.php`) e `atualizacao_seguranca.php`. Host forçado `127.0.0.1` em `debug_colunas_grupos.php` (antigo `temp_check_db.php`) removido — agora usa a conexão normal da aplicação.
- **Decisão consciente, não mudado:** `instalacao.php` continua mostrando a mensagem de erro do PDO na tela. É o instalador — quem está rodando precisa ver *por que* a conexão falhou (host/usuário/senha errado) pra conseguir corrigir e tentar de novo. Nesse ponto específico (instalação ainda não concluída) não tem dado sensível de verdade pra vazar, e sem essa mensagem o instalador fica inutilizável pra debugar problema de conexão.
