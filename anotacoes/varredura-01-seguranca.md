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

1. Confirmar com o Caio quais dos arquivos de debug/teste podem ser deletados.
2. Proteger ou remover `instalacao.php`/`atualiza_banco.php`/`setup_menus.php`/`popular_banco.php` do ambiente de produção.
3. Trocar a concatenação em `popular_banco.php:61` por prepared statement.
4. Configurar cookie de sessão com `Secure`/`HttpOnly`/`SameSite`.
5. Adicionar `declare(strict_types=1)` nos arquivos que faltam (lista no `CLAUDE.md`).
