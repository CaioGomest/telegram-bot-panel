# Como funciona pagamento e gateway neste projeto

Nota de referência (não é TODO) pra não precisar reler tudo do zero da próxima vez. Revisão feita em 2026-09-16.

## Gateway usado

Só **InfoPago** (Pix, mTLS, empresa ONZ Software). Nada de Mercado Pago/Stripe/PagSeguro.

`funcoes/gateways.php` → `gatewaysSuportados()` retorna só `['infopago']`. Linhas antigas de gateways mortos (ex-EFI, ex-PushinPay) continuam na tabela `gateways` só por causa do histórico de vendas — não aparecem mais como opção pra ativar.

Duas integrações InfoPago separadas (credenciais e certificados diferentes):
- **Cash-In** (cobrança Pix) — `InfopagoBanco` em `funcoes/infopago_banco.php`, base `https://api.pix.infopago.com.br`.
- **Cash-Out** (transferência, usado só pra simular split) — `InfopagoCashout` em `funcoes/infopago_cashout.php`, base `https://cashout.infopago.com.br/api/v2`. A API de cobrança da InfoPago não tem split nativo, por isso o split é feito "na mão" via Pix depois que o pagamento cai.

## Fluxo completo

### Geração da cobrança
Acontece dentro de `webhook.php` (endpoint do Telegram), quando o cliente chega num bloco `pix` do fluxograma visual (`dados_fluxograma`).

1. `getUserGateways()` busca gateways ativos do dono do bot.
2. Credenciais reais (client_id/secret/certificado/chave Pix) são **únicas da plataforma**, cadastradas só pelo admin (`getInfopagoCredenciaisAdmin()`) — usuário comum só liga/desliga o gateway em `gateways.php`.
3. Cobrança avulsa: `criarCobranca()` → `PUT /cob/{txid}` (txid = `bin2hex(random_bytes(17))`, padrão Bacen). Resposta já traz `pixCopiaECola` direto.
4. Cobrança recorrente (PIX Automático): `criarCobranca()` → `criarRecorrencia()` (`POST /rec`) → `consultarRecorrencia()` (QR composto vem em `dadosQR.pixCopiaECola`).
5. `INSERT INTO vendas` com `status='gerado'`.
6. Bot manda QR (gerado via `api.qrserver.com` a partir do copia-e-cola) + botão "Já fiz o pagamento" (`callback_data = 'verificar_pagamento_' . $txid`).

### Confirmação (3 caminhos, todos convergem no mesmo `UPDATE vendas SET status='pago'`)

1. **Webhook InfoPago** (`webhook_infopago.php`) — trata `pix` (Pix comum) e `cobsr` (recorrência). **Nunca confia no payload recebido**: sempre reconsulta a cobrança direto na API (`consultarCobranca`) antes de marcar como pago. Idempotência da recorrência via `vendas.ultimo_txid_renovacao`.
2. **Botão manual "Já fiz o pagamento"** (`webhook.php`) — mesma reconsulta na API, com checagem de corrida (`SELECT status` antes do `UPDATE`) pra não processar duas vezes.
3. **Cron de fallback** (`cron_verificar_pix.php`) — varre vendas `status='gerado'`, reconsulta cada uma, marca pago ou expira (`tempo_expiracao_minutos` vencido → `status='expirado'`, roda conector `output_nao_pago` do fluxo).

Depois de marcar pago: `dispararSplitInfopago()` → `liberarAcessoGrupoInfopago()` (invite link de uso único, revoga anterior) → mensagem ao cliente → segue fluxo pelo conector `output_pago`.

### Renovação manual (sem PIX Automático)
`cron_renovacao.php` — pra assinaturas sem `id_assinatura` nativo do gateway, gera novo PIX avulso 3 dias antes do vencimento (`venda_pai_id` aponta pra venda original) e reenvia com o mesmo botão de confirmação.

## Webhooks

| Webhook | Validação |
|---|---|
| Telegram (`webhook.php`) | `hash_equals()` contra `bots.webhook_secret` vs header `X-Telegram-Bot-Api-Secret-Token`. Segredo gerado em `api.php::gerarOuObterSegredoWebhook()`. |
| InfoPago (`webhook_infopago.php`) | **Sem assinatura/HMAC** (provedor não oferece). Mitigado por reconsulta obrigatória à API antes de liberar qualquer coisa. |

Ambos sempre respondem 200 (mesmo em erro/ignorado) pra não gerar retentativa agressiva do provedor.

## Estrutura de dados (principais tabelas)

- **`vendas`** — pedido/transação. `status ENUM('gerado','pago','cancelado','expirado')`, `transacao_id` (txid), `tipo_cobranca ENUM('unica','assinatura','recorrente')`, `venda_pai_id` (renovação), `id_assinatura` (idRec PIX Automático), `ultimo_txid_renovacao` (idempotência), `split_status`, `split_valor`, `split_em`, `comissao_admin`.
- **`gateways`** — catálogo (hoje só `'infopago'` ativo de fato).
- **`usuarios_gateways`** — credenciais por usuário: `client_id`, `client_secret` (cifrado), `certificado` (caminho), `cert_password` (cifrado), `chave_pix` (cifrado) + colunas espelhadas pra Cash-Out.
- **`usuarios_splits`** — regra de split por usuário dono de bot, configurada pelo admin (`taxa_split`, `chave_pix_split` destino).
- **`vendas_splits`** — histórico de cada repasse executado (`status ENUM('pago','falhou')`).
- **`membros_grupos`** — controla acesso liberado (`invite_link`, `data_expiracao`, `status ENUM('ativo','expirado','banido')`).

## Configuração / segredos

- Sem `.env` — tudo em `config.php` (só placeholders no repo, nunca credencial real).
- `CHAVE_CRIPTOGRAFIA_GATEWAYS` (config.php) cifra `client_secret`/`cert_password`/`chave_pix`/campos de Cash-Out — ver [[criptografia-credenciais-gateway]]. **Se essa constante não estiver setada no servidor, o sistema salva tudo em texto puro silenciosamente** — checar isso na Hostinger antes de considerar resolvido.
- Certificados mTLS (`.pem/.p12/.pfx`) ficam em `certificados/`, protegidos por `.htaccess` (bloqueia execução de PHP, não bloqueia download do arquivo em si).
- Credenciais reais nunca em arquivo — sempre no banco (`usuarios_gateways`), cadastradas via UI (`gateways.php`).

## Lógica de negócio

- Não existe tabela de "planos". Valor, tipo de cobrança, periodicidade e grupo vinculado são propriedades do **bloco pix no editor de fluxo visual** — o valor cobrado nunca vem de input do cliente final (checado, é seguro).
- Não há cupons/desconto implementado.
- Split simulado via Cash-Out (`infopago_split.php`) depois que o Pix cai — **falha no split nunca bloqueia a liberação do produto**, só registra em `vendas.split_status`/`vendas_splits`.
- Idempotência do split via header `x-idempotency-key` (UUID por chamada).
- Sem reembolso/estorno implementado.
- Expiração de cobrança: `vendas.tempo_expiracao_minutos` (padrão 15min), checada no cron de fallback.

## Segurança — estado atual

- mTLS obrigatório em toda chamada InfoPago, mas com `VERIFICAR_CERTIFICADO_SERVIDOR = false` (`CURLOPT_SSL_VERIFYPEER/VERIFYHOST` desligados) — risco de MITM enquanto a CA raiz da ONZ Software não for obtida. Reconhecido no próprio código.
- Prepared statements PDO consistentes em todo o código de pagamento.
- CSRF ausente em rotas admin (inclusive config de gateway) — pendência antiga, ver `varredura-02-upload-xss-csrf.md`.

## ⚠️ Pontos de atenção (por prioridade)

1. **🔴 Cron jobs de pagamento sem proteção de acesso** — `cron_verificar_pix.php`, `cron_renovacao.php`, `cron_aviso_vencimento.php`, `cron_verificar_acessos.php` ficam na raiz pública, sem checar `php_sapi_name()==='cli'` nem chave secreta, sem `flock` (exceto `cron_remarketing.php`, que tem). Qualquer um que descubra a URL pode disparar manualmente e repetidamente. Ver `varredura-06-cron-sem-autenticacao.md` (ainda pendente).
2. ~~`comissao_admin` nunca é populada no fluxo real~~ **→ resolvido em 2026-09-16.** `funcoes/infopago_split.php::dispararSplitInfopago()` agora grava `split_valor` (total repassado com sucesso pro(s) destino(s) configurado(s) em `usuarios_splits`) e `comissao_admin = valor_venda - split_valor` em toda chamada de `marcar_resumo()` — cobre os 5 status (`sem_split`, `sem_credenciais`, `pago`, `falhou`, `parcial`). Como todo o dinheiro cai primeiro na conta InfoPago do admin (credenciais únicas da plataforma) e o Cash-Out só manda uma parte pra fora, a comissão real é sempre "o que não saiu" — não é percentual fixo. Confirmado com o Caio antes de implementar (não é 5% fixo do seed em `seeds/popular_banco.php`, que ficou desatualizado/só serve pra dado de teste).
3. **🟡 mTLS sem verificar certificado do servidor** — ver seção Segurança acima.
4. **🟡 Webhook InfoPago sem assinatura** — mitigado pela reconsulta, mas ainda superfície aberta a POST malformado (só não tem efeito prático).
5. **🟢 Confusão de propriedades camelCase vs snake_case** em `InfopagoBanco`/`InfopagoCashout` — construtor usa propriedades dinâmicas camelCase (`$this->clientId`), propriedades declaradas na classe são snake_case e nunca usadas. Funciona hoje (todo o resto da classe usa a versão dinâmica consistentemente), mas é frágil — PHP 8.2+ deprecia dynamic properties, e pode confundir refatoração futura.
6. **Logs de debug com dado sensível em disco** (`logs/vendas_debug.log`, `logs/webhook_infopago.log`, etc.) — protegidos por `.htaccess` na pasta, mas sem rotação/expiração automática, guardam TXID/valor/payload completo.

## Mapa de arquivos-chave

| Responsabilidade | Arquivo |
|---|---|
| Cash-In (cobrança Pix) | `funcoes/infopago_banco.php` |
| Cash-Out (split/transferência) | `funcoes/infopago_cashout.php` |
| Disparo do split pós-pagamento | `funcoes/infopago_split.php` |
| CRUD credenciais / resolve provider | `funcoes/gateways.php` |
| Criptografia de segredos | `funcoes/criptografia.php` |
| UI configuração de gateway | `gateways.php` |
| Webhook Telegram + gera cobrança + verificação manual | `webhook.php` |
| Webhook InfoPago (Pix comum e PIX Automático) | `webhook_infopago.php` |
| Fallback de confirmação/expiração | `cron_verificar_pix.php` |
| Geração de renovação manual | `cron_renovacao.php` |
| Admin — transações/vendas | `admin/transacoes.php` |
| Admin — consulta pontual de venda no gateway | `admin/consultar_venda.php` |
| Admin — dashboard/receita | `admin/dashboard.php` |
| Schema/migração | `admin/atualiza_banco.php` |

## Notas relacionadas

- [[criptografia-credenciais-gateway]] — detalhe de como as credenciais são cifradas.
- `varredura-05-pagamento-privilegio-xss.md` — confirma que valor cobrado não vem de input do cliente.
- `varredura-06-cron-sem-autenticacao.md` — pendência dos crons sem proteção (item 1 acima).
- `varredura-03-webhooks-credenciais-logs.md` — webhooks e logs.
