# Pendências / lembretes

Notas e coisas pra fazer depois. Não é código, não afeta a aplicação.

> Ver `anotacoes/resumo-sessao-2026-09-16.md` pro retrato consolidado da sessão de análise de escala + varreduras 08/09/10 (o que foi corrigido, o que falta, e a ação manual necessária no deploy).

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
- Proteção dos crons sem autenticação (varredura 06) — urgência maior agora que existe o achado de sobreposição em `cron_verificar_pix.php`, ver `anotacoes/analise-escala-seguranca-vendas.md`.
- ~~🔴 LFI via bloco "imagem" do fluxograma (varredura 08)~~ **Feito** — ver `anotacoes/varredura-08-lfi-fluxograma-sessao.md` (correção em 2 camadas: validação no envio + na gravação do fluxo).
- **🔴 Sem `session_regenerate_id()` após login (varredura 08)** — fixação de sessão, correção trivial, aguardando confirmação do Caio.
- ~~🔴 XSS armazenado → sessão do admin (varredura 09)~~ **Feito** — `escaparHtml()` aplicado no modal "Detalhes" de `admin/usuarios.php`.
- ~~🔴 SSRF via campo "Token ou URL de Postback" da UTMfy (varredura 09)~~ **Feito** — `funcoes/utmfy.php` agora recusa URL que resolva pra host privado/interno antes de disparar a requisição.
- **🟡 `teste_gateway_infopago*.php` sem `verificarAdmin()` (varredura 09, não corrigido)** — qualquer usuário logado dispara cobrança real na conta compartilhada da InfoPago.
- **🟡 `enviarEventoFacebook`/`enviarEventoTikTok` sem timeout de cURL (varredura 09, não corrigido)**.
- ~~🔴 `cron/cron_ranking.php` sem proteção de acesso e sem `flock` (varredura 10)~~ **Feito** — `CHAVE_SECRETA_CRON` (CLI-ou-chave) + `flock` em `cron_ranking.php`, e mesma chave em `cron_metricas_admin.php`. **⚠️ trocar `CHAVE_SECRETA_CRON` no `config.php` do servidor antes de produção, e ajustar a URL do crontab se ele chamar via HTTP.** Ver `anotacoes/varredura-10-cron-ranking-segredos-formulario.md`.
- **🟡 Segredo de gateway em claro no formulário (varredura 10, não corrigido)** — `client_secret`/`chave_pix`/`cashout_client_secret` ecoados com valor real em `gateways.php`.

## Análise de escala/segurança nas vendas (700 usuários, ~120 vendas/dia cada)

Ver `anotacoes/analise-escala-seguranca-vendas.md`. Achado mais grave (condição de corrida entre os caminhos de confirmação de pagamento podendo disparar `dispararSplitInfopago()` duas vezes pra mesma venda), índice faltando em `vendas.transacao_id`/`id_assinatura`, e `cron_verificar_pix.php` sem `flock`/`LIMIT` — **os 3 já foram corrigidos** (2026-09-16, ver seção "Correções aplicadas" no documento). **Rodar `atualiza_banco.php` no servidor** pra criar os índices novos. Ainda pendente dessa rodada: proteção dos crons (varredura 06), `LOCK_EX`+rotação nos logs, decisão de infra (VPS) e mTLS com verificação de certificado.
