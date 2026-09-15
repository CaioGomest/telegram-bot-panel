# Varredura 03 — Webhooks, credenciais e logs

Data: pós-correções das varreduras 01 e 02 (branch `new`).

## 🔴 Alta prioridade

### 1. Webhook do InfoPago aceita notificação sem validar autenticidade — risco de fraude financeira
`webhook_infopago.php` lê o corpo bruto da requisição (`php://input`), decodifica o JSON e, se o `status` vier como pago pra um `idRec`/`txid`, marca a venda como `'pago'` e libera acesso — **sem checar se a requisição realmente veio do InfoPago** (sem validar assinatura, HMAC, header secreto ou IP de origem).

Na prática: se alguém souber ou conseguir adivinhar um `idRec`/`txid` de uma cobrança pendente, pode mandar um POST fake pro seu webhook simulando "pagamento confirmado" e ganhar acesso ao produto/grupo **sem pagar nada**. Esse é o achado mais sério das 3 varreduras até agora — afeta dinheiro diretamente.

**Correção recomendada:** verificar na documentação do InfoPago se eles mandam algum jeito de validar a notificação (HMAC de um secret compartilhado, certificado mTLS, IP fixo de origem). Se não tiver nada disponível, pelo menos: antes de marcar como pago, fazer uma segunda checagem consultando a API do InfoPago pra confirmar o status da cobrança direto na fonte (em vez de confiar cegamente no payload recebido).

### 2. Pasta `/logs` sem proteção — acessível direto pela URL
Não tem `.htaccess` em `/logs` (só em `/uploads` e `/certificados`, que corrigi na varredura 02). Isso significa que qualquer um que souber o nome de um arquivo de log (ex. `logs/webhook_infopago.log`, `logs/vendas_debug.log`) consegue baixar ele direto pela URL.

E o conteúdo desses logs é sensível: `webhook_infopago.php` loga **o payload completo** recebido (`logWebhookInfopago("Payload recebido: " . $entrada)`), que pode conter dados do cliente que pagou (nome, CPF, etc, dependendo do que o InfoPago manda). `vendas_debug.log` (em `webhook.php`) loga txid, IDs internos e URLs de QR code de pagamento.

**Correção recomendada:** `.htaccess` em `/logs` bloqueando qualquer acesso direto (não só `.php` como fiz em uploads — aqui pode bloquear tudo, já que ninguém deveria baixar log pela URL mesmo).

## 🟡 Média prioridade

### 3. Webhook do Telegram também não valida origem
`webhook.php` também aceita qualquer POST sem checar se veio realmente do Telegram. O Telegram suporta um `secret_token` configurável no `setWebhook` que vem de volta no header `X-Telegram-Bot-Api-Secret-Token` — dá pra validar isso. Risco menor que o do InfoPago (não mexe com dinheiro diretamente), mas alguém podia mandar update falso simulando mensagem de um "cliente" no bot.

### 4. Credenciais de gateway em texto puro no banco
`client_secret`, `cert_password` e `chave_pix` (tabela `usuarios_gateways`) são salvos sem nenhuma criptografia. Se o banco vazar (dump de SQL, backup mal protegido, etc.), essas credenciais de pagamento de todos os usuários ficam expostas direto, sem nem precisar quebrar hash nenhum.

**Correção recomendada:** criptografar esses campos antes de salvar (`openssl_encrypt` com uma chave de aplicação fora do banco, ex. variável de ambiente ou constante em `config.php` que não vai pro banco) e descriptografar só na hora de usar.

### 5. Tokens de integração (Facebook, TikTok, UTMify) aparecem em texto puro no formulário de edição
Em `traqueamento.php`, os campos de token já vêm preenchidos com o valor salvo, visível no HTML (`value="TOKEN_REAL_AQUI"`). Tá escapado com `htmlspecialchars` (não é XSS), mas o token fica exposto no código-fonte da página pra qualquer um que abrir "inspecionar elemento" enquanto a pessoa dona da conta usa o painel. Comum em painéis assim, mas dá pra melhorar mascarando (ex. mostrar só os últimos 4 caracteres, com botão "mostrar/trocar").

## 🟢 Positivo

- Nenhuma função perigosa encontrada (`eval`, `exec` de shell, `system`, `unserialize`) — os `exec()`/`curl_exec()` encontrados são todos PDO ou cURL, uso normal e seguro.
- Prepared statements continuam consistentes em praticamente todo o projeto.
- `funcoes/log.php` (log de atividade do usuário, exibido no painel) não grava nada sensível — só ações genéricas.

## Próximos passos sugeridos (por prioridade)

1. ~~Validar autenticidade do webhook do InfoPago~~ **Feito.**
2. ~~`.htaccess` em `/logs` bloqueando acesso direto~~ **Feito.**
3. ~~Validar `secret_token` no webhook do Telegram~~ **Feito.**
4. **Criptografar `client_secret`/`cert_password`/`chave_pix` no banco — ainda NÃO feito.** Envolve gerenciar uma chave de criptografia da aplicação e migrar dados que já existem em texto puro — risco de "trancar" credencial de pagamento se algo sair errado na chave. Fica pra uma rodada própria, com mais cuidado.
5. Mascarar tokens de integração no formulário de edição (opcional, cosmético) — ainda não feito, baixa prioridade.

## Correções aplicadas (varredura 03 → fix)

- **`webhook_infopago.php`:** antes de marcar uma venda como paga, agora consulta a cobrança direto na API da InfoPago (`consultarCobranca`) usando as credenciais do dono do bot, e só libera se a InfoPago confirmar o status como pago de verdade. Se a consulta falhar ou não confirmar, a notificação é ignorada (fica só com o log) — o `cron_verificar_pix.php` (que já fazia essa mesma verificação) pega a venda no próximo ciclo. Ninguém mais consegue "forjar" um pagamento só mandando POST pra URL do webhook.
- **`logs/.htaccess`:** bloqueia qualquer acesso direto à pasta de logs (igual ao que já tinha em `/uploads` e `/certificados`, mas aqui bloqueando tudo, não só `.php`).
- **`webhook.php`:** agora valida o header `X-Telegram-Bot-Api-Secret-Token` contra um segredo salvo por bot (`bots.webhook_secret`, coluna nova). Retrocompatível: bots que ainda não têm segredo salvo continuam funcionando normalmente — o segredo é gerado sozinho na próxima vez que o webhook for (re)configurado (`api.php`, ações `salvar_bot` e `reiniciar_webhook`).
- **`atualiza_banco.php`:** adiciona a coluna `bots.webhook_secret`.

**Importante:** depois do deploy, rodar `atualiza_banco.php` (menu Debug) pra criar a coluna nova. Bots já existentes só passam a exigir o secret_token depois que alguém salvar o bot de novo ou clicar em "reiniciar conexão" — não precisa fazer nada manual, mas vale saber que a proteção vai "ligando" aos poucos, bot por bot.
