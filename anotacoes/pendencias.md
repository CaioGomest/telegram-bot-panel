# Pendências / lembretes

Notas e coisas pra fazer depois. Não é código, não afeta a aplicação.

## Débitos técnicos identificados na limpeza

- (preencher conforme formos limpando os arquivos)

## Pra etapa de layout (futuro, com Claude Code)

- Refazer layout/UI do painel (fora do escopo da limpeza atual)

## Dúvidas pra confirmar com o Caio

- (preencher)

## Renomeações feitas (nomes de página mais claros)

- `debug_cron.php` → `debug_ultima_venda.php`
- `debug_fix_db.php` → `debug_colunas_vendas.php`
- `temp_check_db.php` → `debug_colunas_grupos.php`
- `teste_infopago_painel.php` → `teste_gateway_infopago.php`
- `teste_infopago_recorrente_painel.php` → `teste_gateway_infopago_recorrente.php`

Não renomeados de propósito: `cron_*.php` e `webhook*.php` (nomes usados fora do repo — crontab da Hostinger e URLs de webhook no Telegram/InfoPago).

## Limpeza de itens pequenos das varreduras (resolvidos numa passada só)

- `popular_banco.php:61`: concatenação direta trocada por prepared statement.
- `declare(strict_types=1)` adicionado nos 5 arquivos que faltavam: `remarketing.php`, `leads.php`, `sidebar.php`, `login.php`, `cadastro.php`.
- Mensagem de erro do PDO exposta na tela trocada por log + mensagem genérica em `debug_colunas_vendas.php` e `atualizacao_seguranca.php`.
- `debug_colunas_grupos.php`: removido o host forçado `127.0.0.1` — agora usa a conexão normal da aplicação (`$pdo` global).
- `instalacao.php`: mantido o erro do PDO visível na tela, de propósito — é o instalador, sem essa mensagem não dá pra debugar falha de conexão no primeiro deploy, e nesse ponto ainda não tem dado sensível de verdade pra vazar.

## Ainda pendente (grandes, precisam de rodada própria)

- CSRF nas rotas administrativas (varredura 02).
- ~~Criptografar `client_secret`/`cert_password`/`chave_pix` no banco~~ **Feito** — ver `anotacoes/criptografia-credenciais-gateway.md`.
- Mascarar token de integração no formulário (varredura 03, cosmético, baixa prioridade).
