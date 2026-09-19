# Traqueamento — o que foi corrigido

19/09/2026, commits `9c718d6` (TikTok) e `d5cfc8e` (Facebook/UTMify).
A lista completa dos achados está em `revisao-traqueamento-facebook-utmify.md`.

## 1. TikTok Ads — comentado

Sem conta pra validar, o código ficava disparando pra uma API de terceiro sem ninguém nunca
ter conferido o outro lado. Comentados o disparo e a seção da tela; `funcoes/tiktok.php`
segue intacto e as colunas `tiktok_*` do banco também — religar é descomentar dois blocos.

## 2. A origem do lead, que era jogada fora

Esse era o buraco que impedia tudo o resto.

Quando alguém chega por um link de rastreamento, o `/start` vem com um identificador. Ele
era usado **só pra incrementar um contador** em `links_rastreamento` e descartado em seguida.
Não sobrava nada ligando aquele lead à campanha que o trouxe.

Agora `leads.origem_rastreio` (coluna nova) guarda esse identificador. Por **primeiro
toque**: se o lead já tem origem, não sobrescreve — senão a última campanha levaria o crédito
de um lead que outra trouxe.

## 3. Os eventos passaram a levar dado

Antes, os três pontos de chamada montavam o `$user_data` na mão e mandavam só o
`id_telegram`. Os dois arquivos de integração liam `email`, `telefone`, `ip`, `user_agent` —
campos que nunca chegavam.

`montarUserDataTraqueamento()` centraliza isso e monta a partir do lead: nome, telefone e a
origem (que vira `utm_source`/`utm_medium`/`utm_campaign`).

E-mail e IP continuam de fora **de propósito**: o Telegram não fornece. Inventar valor pra
preencher campo é o que criava o problema do `@telegram.com`.

Os eventos de compra também passaram a levar comissão, plano, nome do produto e a data real
do pagamento. Tudo isso já existia em `vendas` — só não era passado adiante.

## 4. UTMify

| o que era | o que é |
|---|---|
| `trackingParameters` com as 5 UTMs fixas em `null` | vêm do `$user_data` |
| evento fora do mapa virava `'paid'` | recusa e loga |
| telefone ausente virava `'00000000000'` | vai `null` |
| e-mail ausente virava `cliente123@telegram.com` (domínio real de terceiro) | `@nao-informado.invalid` (RFC 2606) |
| `gatewayFeeInCents: 0` e 100% pro vendedor | usa `comissao_admin` |
| `products[0].id` e `planId` fixos em `'1'` | id do plano real |
| `approvedDate` sempre "agora" | usa `pago_em` quando existe |
| sem `CURLOPT_CONNECTTIMEOUT` | 5s, igual ao Facebook |

## 5. Facebook

- `access_token` saiu da query string pro corpo do POST. Em URL, ele aparecia no log de
  proxy, de CDN e no `access_log` do servidor.
- `pixel_id` passa por `rawurlencode`.
- Telefone ganha DDI `55` quando vem sem. Sem código do país o hash é "válido" mas nunca casa
  com o que a Meta tem — falha sem erro nenhum.

## Verificado ao vivo

Rodado no servidor, contra o banco real:

```
helper com lead real      → {"id_telegram":"100000439","first_name":"Lead 560905","telefone":"5511963362465"}
helper com lead que não existe → {"id_telegram":"999999999"}   (não explode)
UTMify com evento não mapeado  → recusado, sem enviar

payload UTMify:
  approvedDate  2026-09-18 17:30:00   (14:30 BRT convertido pra UTC, como a API pede)
  produto       id 42, "Plano VIP 30 dias", planId 42
  commission    975 + 8775 = 9750 ✓
  UTMs          campaign "promo-black", medium "bot", source "telegram"

payload Facebook:
  token no corpo ✓
  ph = sha256("5511999998888")  ✓ (DDI aplicado sobre "11999998888")
```

Webhooks respondendo 200, e `traqueamento`, `leads`, `index`, `links_rastreamento` sem erro.

## O que ficou de fora

Depende de conferir a documentação atual do terceiro, ou de decisão de produto:

### F3 — `action_source` (pendente, decidir depois)

`action_source` é campo obrigatório da Conversions API e responde "onde essa conversão
aconteceu?". A Meta usa isso pra modelar a conversão, e alguns valores exigem outros campos
junto (`website`, por exemplo, exige a URL de origem).

Hoje **os dois caminhos mandam `system_generated`**, que significa "meu sistema gerou este
evento, sem ação direta de ninguém naquele momento".

O problema é que o sistema tem dois casos bem diferentes, e só um deles é esse:

| caminho | o que realmente acontece | valor provável |
|---|---|---|
| `webhook.php` / `webhook_infopago.php` | a pessoa está conversando com o bot, escolhe o plano e paga | `chat` |
| `cron/cron_verificar_pix.php`, renovação automática | confirmação posterior, sem ninguém na frente | `system_generated` (certo hoje) |

Ou seja: a compra feita dentro da conversa provavelmente está classificada errada, e a
renovação automática está certa por acidente.

**Não mudei porque** a Meta altera essa lista de valores e as regras de cada um com alguma
frequência, e não dá pra testar sem um pixel real. Afirmar de memória qual é o conjunto
válido hoje seria chute. Antes de mexer: conferir a documentação atual da Conversions API
(valores aceitos para `action_source` e o que cada um exige) e validar com um evento de
teste no Events Manager.
- **F4** — `test_event_code`, pra testar sem sujar o dado real. Vale se você for validar o
  Facebook de verdade.
- **F6** — falha de rede perde o evento. Não há fila nem nova tentativa. Resolver isso direito
  é uma fila de reenvio, não uma linha.
- **U10** — a proteção de SSRF resolve o DNS, valida, e o cURL resolve de novo. Entre as duas
  resoluções o registro pode mudar (DNS rebinding). Fecha-se com `CURLOPT_RESOLVE`.
- **U7**, **U11**, **F5**, **F7** — menores, listados na revisão.

## Ainda não dá pra dizer que funciona

Nada disso foi validado ponta a ponta com pixel real e venda real. O que foi verificado é que
o payload sai correto e coerente com o banco. Se a Meta ou a UTMify rejeitarem por algum
detalhe de formato, só um teste com conta de verdade mostra — e isso continua no checklist
pré-lançamento.
