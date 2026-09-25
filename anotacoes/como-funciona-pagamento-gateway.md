# Como funciona pagamento e gateway neste projeto

Nota de referência (não é TODO) pra não precisar reler tudo do zero da próxima vez.
Revisão original 2026-09-16, atualizada em 2026-09-24 (entrada da OmegaPayments) e
**reescrita em 2026-09-25 depois da InfoPago ser removida 100% do código** — ver
[[plano-remocao-infopago]] pro checklist completo do que foi feito e por quê.

## Gateway usado

Só **OmegaPayments** (Pix, split nativo, sem certificado). `funcoes/gateways.php` →
`gatewaysSuportados()` retorna `['omegapayments']`. Linhas antigas de gateways mortos
(ex-EFI, ex-PushinPay, ex-InfoPago) continuam na tabela `gateways` só por causa do
histórico de vendas — não aparecem mais como opção pra ativar.

`OmegaPaymentsBanco` em `funcoes/omegapayments_banco.php`, base
`https://api.omegapayments.com.br` (`[A CONFIRMAR]` — vários pontos ainda não validados
contra sandbox real, ver `anotacoes/pendente/pendencias-sandbox-omegapayments.md`). Auth
só com headers `x-public-key`/`x-secret-key`, **credenciais são do próprio usuário**
(cada dono de bot cadastra a própria conta na tela `gateways.php`, sem credencial
compartilhada pelo admin). Split vai dentro do `splits[]` do payload de criação da
cobrança — sem Cash-Out, sem passo separado de repasse, sem retry.

## ⚠️ Pra onde o dinheiro vai

Diferente do modelo antigo (InfoPago: credencial única do admin, dinheiro caía na conta
do admin e era repassado depois pro usuário via Cash-Out), hoje o pagamento cai **direto
na conta do próprio usuário** (é a credencial dele que gera a cobrança). O split nativo,
configurado no `splits[]` da cobrança, precisa mandar uma fatia **pra dentro, pro
admin** — é assim que a plataforma cobra comissão.

**Importante:** isso hoje é **convenção de operação, não regra travada em código.** O
destino do split (`usuarios_splits.chave_pix_split`) é um campo de texto livre que o
admin digita à mão em `admin/usuarios.php` — nada no código força/valida que a chave
cadastrada seja obrigatoriamente a do admin. Se um dia isso for automatizado, é aqui que
entraria a mudança.

## Fluxo completo

### Geração da cobrança
Acontece dentro de `webhook.php` (endpoint do Telegram), quando o cliente chega num
bloco `pix` do fluxograma visual (`dados_fluxograma`).

1. `getUserGateways()` busca gateways ativos do dono do bot.
2. `criarCobranca()` → `POST /gateway/pix/receive`. Resposta já traz `pixCopiaECola`
   direto (sem passo extra de QR code).
3. `INSERT INTO vendas` com `status='gerado'`, `id_gateway` apontando pro gateway usado.
4. Bot manda QR (gerado via `api.qrserver.com` a partir do copia-e-cola) + botão "Já fiz
   o pagamento" (`callback_data = 'verificar_pagamento_' . $txid`).

**PIX Recorrente/Assinatura não existe mais.** Era um recurso só da InfoPago (PIX
Automático); a OmegaPayments não suporta recorrência na v1. `api.php::gateway_info`
sempre retorna `suporta_recorrente: false` agora, então o editor de fluxo nem mostra a
opção "Assinatura (Recorrente)" pra blocos `pix` novos. Fluxos antigos que já tinham
`tipo_cobranca: 'recorrente'` salvo vão cair no skip "Gateway não suporta PIX
Recorrente" em `webhook.php` se disparados — **conferir se sobrou algum fluxo assim em
produção** e migrar pra `'unica'` manualmente se precisar (não foi feito automático).

### Confirmação (3 caminhos, todos convergem no mesmo `UPDATE vendas SET status='pago'`)

1. **Webhook OmegaPayments** (`webhook_omegapayments.php`) — **nunca confia no payload
   recebido**: sempre reconsulta a cobrança direto na API (`consultarCobranca`) antes de
   marcar como pago. Sem assinatura/HMAC (provedor não oferece), mitigado pela
   reconsulta.
2. **Botão manual "Já fiz o pagamento"** (`webhook.php`) — mesma reconsulta na API, com
   checagem de corrida (`SELECT status` antes do `UPDATE`) pra não processar duas vezes.
   Se a venda for antiga demais pra ter `id_gateway` preenchido, esse caminho
   simplesmente não confirma (não tem mais fallback pra adivinhar o gateway — ver
   [[plano-remocao-infopago]]).
3. **Cron de fallback** (`cron_verificar_pix.php`) — resolve o provider por venda via
   `resolveGatewayProvider()`, varre vendas `status='gerado'`, reconsulta cada uma,
   marca pago ou expira (`tempo_expiracao_minutos` vencido → `status='expirado'`, roda
   conector `output_nao_pago` do fluxo).

Depois de marcar pago: mensagem ao cliente → `liberarAcessoGrupoOmegapayments()`
(`webhook_omegapayments.php` — invite link de uso único, revoga anterior, estende a
partir da expiração atual se ainda ativo) → segue fluxo pelo conector `output_pago`. Sem
passo de split separado — já aconteceu dentro da própria cobrança.

O webhook e o cron também disparam a feature de **webhooks de saída**
(`dispararWebhooks()`, `funcoes/webhooks.php`) com o evento `payment_approved`.

### Renovação manual
`cron_renovacao.php` — gateway-genérico. Pra assinaturas sem `id_assinatura` nativo do
gateway (ou seja, todas hoje, já que OmegaPayments não tem recorrência), gera novo PIX
avulso 3 dias antes do vencimento (`venda_pai_id` aponta pra venda original) e reenvia
com o mesmo botão de confirmação.

## Webhooks

| Webhook | Validação |
|---|---|
| Telegram (`webhook.php`) | `hash_equals()` contra `bots.webhook_secret` vs header `X-Telegram-Bot-Api-Secret-Token`. Segredo gerado em `api.php::gerarOuObterSegredoWebhook()`. |
| OmegaPayments (`webhook_omegapayments.php`) | Sem assinatura/HMAC (provedor não oferece). Mitigado por reconsulta obrigatória à API antes de liberar qualquer coisa. |

Ambos sempre respondem 200 (mesmo em erro/ignorado) pra não gerar retentativa agressiva
do provedor.

## Estrutura de dados (principais tabelas)

- **`vendas`** — pedido/transação. `status ENUM('gerado','pago','cancelado','expirado')`,
  `transacao_id` (txid), `id_gateway` (aponta pra qual gateway gerou a venda),
  `tipo_cobranca ENUM('unica','assinatura','recorrente')` (os dois últimos valores
  ficaram sem uso — nenhum gateway suportado hoje gera recorrência), `venda_pai_id`
  (renovação), `id_assinatura`/`ultimo_txid_renovacao` (idem, sem uso hoje, reservados
  caso algum gateway futuro suporte recorrência), `split_status`, `split_valor`,
  `split_em`, `comissao_admin`.
- **`gateways`** — catálogo (hoje só `'omegapayments'` ativa de fato; linhas antigas
  como `'infopago'` ficam de histórico, sem aparecer em UI nenhuma).
- **`usuarios_gateways`** — credenciais por usuário: `client_id`, `client_secret`
  (cifrado), `certificado` (caminho, sem uso pela OmegaPayments), `cert_password`
  (cifrado, idem), `chave_pix` (cifrado, sem uso real — a API não pede, mas o campo
  continua obrigatório no formulário por enquanto), `tipo_conta`. As colunas
  `cashout_*` (só da InfoPago) foram dropadas.
- **`usuarios_splits`** — regra de split por usuário dono de bot, configurada pelo
  admin (`taxa_split`, `chave_pix_split` destino), escopada por `gateway_nome`.
- **`vendas_splits`** — histórico de repasses via Cash-Out (só existiam pra InfoPago).
  Fica como registro histórico; não recebe linha nova (OmegaPayments não passa por
  aqui, o split dela é resolvido na hora da cobrança).
- **`membros_grupos`** — controla acesso liberado (`invite_link`, `data_expiracao`,
  `status ENUM('ativo','expirado','banido')`).

## Configuração / segredos

- Sem `.env` — tudo em `config.php` (só placeholders no repo, nunca credencial real).
- `CHAVE_CRIPTOGRAFIA_GATEWAYS` (config.php) cifra `client_secret`/`cert_password`/
  `chave_pix` — ver [[criptografia-credenciais-gateway]]. **Se essa constante não
  estiver setada no servidor, o sistema salva tudo em texto puro silenciosamente** —
  checar isso na Hostinger antes de considerar resolvido.
- `certificados/` não é mais usado (era só pra mTLS da InfoPago) — continua existindo
  no repo (gitignored, só `.gitkeep`/`.htaccess`) mas não deve receber upload novo.
- Credenciais reais nunca em arquivo — sempre no banco (`usuarios_gateways`), cadastradas
  via UI (`gateways.php`).

## Lógica de negócio

- Não existe tabela de "planos". Valor, tipo de cobrança e grupo vinculado são
  propriedades do **bloco pix no editor de fluxo visual** — o valor cobrado nunca vem
  de input do cliente final (checado, é seguro).
- Não há cupons/desconto implementado.
- Split nativo, vai no `splits[]` do payload de `criarCobranca()`. Sem passo de repasse
  separado, sem retry automático — se o split falhar dentro da própria cobrança, isso é
  parte da resposta da API na hora, não uma pendência que fica pra trás.
- Sem reembolso/estorno implementado.
- Expiração de cobrança: `vendas.tempo_expiracao_minutos` (padrão 15min), checada no cron
  de fallback.

## Segurança — estado atual

- Prepared statements PDO consistentes em todo o código de pagamento.
- CSRF ausente em algumas rotas admin (config de gateway inclusa) — pendência antiga, ver
  `varredura-02-upload-xss-csrf.md`.
- Sem mTLS (OmegaPayments não usa certificado) — o risco de MITM que existia com a
  InfoPago (`VERIFICAR_CERTIFICADO_SERVIDOR = false`) não se aplica mais.

## ⚠️ Pontos de atenção (por prioridade)

1. **🔴 Cron jobs de pagamento sem proteção de acesso** — `cron_verificar_pix.php`,
   `cron_renovacao.php`, `cron_aviso_vencimento.php`, `cron_verificar_acessos.php` ficam
   na raiz pública, sem checar `php_sapi_name()==='cli'` nem chave secreta, sem `flock`
   (exceto `cron_remarketing.php`, que tem). Ver `varredura-06-cron-sem-autenticacao.md`
   (ainda pendente).
2. **🔴 OmegaPayments sem sandbox testado** — vários pontos da integração
   (`[A CONFIRMAR]` no código: URL base, endpoint de consulta, schema de `splits[]`,
   formato do webhook, enum de status) nunca foram validados contra a API real. Isso
   agora é o **único** gateway do sistema — qualquer coisa errada nesses pontos
   significa nenhuma venda funcionando. Ver
   `anotacoes/pendente/pendencias-sandbox-omegapayments.md`.
3. **🟡 Webhook sem assinatura** — mitigado pela reconsulta, mas ainda superfície aberta
   a POST malformado (só não tem efeito prático).
4. **🟢 "Split sempre pro admin" é convenção manual, não travada em código** — ver seção
   "Pra onde o dinheiro vai" acima. Se o admin esquecer de configurar a própria chave
   Pix como destino do split de algum usuário, nada no sistema avisa — a comissão da
   plataforma simplesmente não sai daquela venda.
5. **Logs de debug com dado sensível em disco** (`logs/vendas_debug.log`, etc.) —
   protegidos por `.htaccess` na pasta, mas sem rotação/expiração automática.
6. **Crontab da Hostinger** — se `cron_retry_split.php` (removido, era só da InfoPago)
   estava cadastrado no painel de cron jobs, precisa tirar de lá manualmente (isso não
   é código, é configuração externa que este repo não controla).

## Mapa de arquivos-chave

| Responsabilidade | Arquivo |
|---|---|
| Cash-In (cobrança Pix + split nativo) | `funcoes/omegapayments_banco.php` |
| CRUD credenciais / resolve provider | `funcoes/gateways.php` |
| Criptografia de segredos | `funcoes/criptografia.php` |
| UI configuração de gateway | `gateways.php` |
| Webhook Telegram + gera cobrança + verificação manual | `webhook.php` |
| Webhook OmegaPayments | `webhook_omegapayments.php` |
| Fallback de confirmação/expiração | `cron_verificar_pix.php` |
| Geração de renovação manual | `cron_renovacao.php` |
| Admin — transações/vendas | `admin/transacoes.php` |
| Admin — usuários/splits por gateway | `admin/usuarios.php` |
| Admin — consulta pontual de venda no gateway | `admin/consultar_venda.php` |
| Admin — dashboard/receita | `admin/dashboard.php` |
| Schema/migração | `admin/atualiza_banco.php` |

## Notas relacionadas

- [[plano-remocao-infopago]] — o que foi feito (executado em 2026-09-25) pra tirar a
  InfoPago do código, e o que ficou de nota/histórico.
- [[criptografia-credenciais-gateway]] — detalhe de como as credenciais são cifradas.
- [[pendencias-sandbox-omegapayments]] — o que ainda não foi validado da integração,
  agora crítico por ser o único gateway.
- `varredura-05-pagamento-privilegio-xss.md` — confirma que valor cobrado não vem de
  input do cliente.
- `varredura-06-cron-sem-autenticacao.md` — pendência dos crons sem proteção.
- `varredura-03-webhooks-credenciais-logs.md` — webhooks e logs.
- `criticas/conta-infopago-unica-compartilhada.md` — risco do modelo antigo de
  credencial única/compartilhada, marcado resolvido pela remoção.
