# Revisão linha por linha — Facebook e UTMify

> **Atualização 19/09/2026 (commit `d5cfc8e`): corrigidos** — o problema transversal (evento
> sem identificação), U1, U2, U3, U4, U5, U6, U8, U9, F1 e F2.
>
> **Ficaram de fora**, por dependerem de conferir a documentação atual do terceiro ou de
> decisão sua: F3 (`action_source` `system_generated` vs `chat`), F4 (`test_event_code`),
> F5, F6 (nova tentativa em falha de rede), F7, U7, U10 (janela de DNS rebinding) e U11.
>
> Verificado ao vivo depois da correção: o payload da UTMify sai com UTM, comissão certa
> (975 + 8775 = 9750), produto real e data de pagamento convertida pra UTC; o do Facebook sai
> com o token no corpo e o telefone com DDI. Detalhes em `traqueamento-o-que-foi-corrigido.md`.

19/09/2026. Revisão de código, sem teste ao vivo: não tenho conta de anúncio nem token pra
disparar evento de verdade. Onde a conclusão depende do comportamento atual da API do
terceiro, está marcado.

TikTok foi comentado nesta mesma rodada (ver commit `9c718d6`) — `funcoes/tiktok.php` segue
intacto no repositório.

---

## 🔴 O problema que afeta os dois (e é o mais grave)

**Os eventos saem praticamente sem dado de identificação.**

Os dois arquivos leem `email`, `telefone`, `ip`, `user_agent` de `$user_data`. Fui atrás de
quem preenche isso. São três pontos de chamada, e **nenhum** manda esses campos:

| origem | o que vai em `$user_data` |
|---|---|
| `webhook.php:553` (pix_gerado) | `id_telegram` |
| `webhook.php:762` (compra) | `id_telegram`, `first_name` |
| `webhook_infopago.php:430` (compra) | `id_telegram`, `first_name` |

Consequência prática:

- **Facebook** recebe só `external_id` (o ID do Telegram hasheado). A Conversions API aceita
  e devolve 200, mas sem e-mail, telefone, IP ou user agent a correspondência com usuário
  real fica muito baixa. O evento entra, a atribuição não acontece.
- **UTMify** não recebe UTM nenhuma (ver o achado U1 abaixo, que é ainda mais direto).

Ou seja: os dois integram, respondem 200, o log diz sucesso — e o resultado de negócio é
próximo de zero. É o pior tipo de bug, porque parece que está funcionando.

**Além disso:** `nome_produto` só é enviado no `pix_gerado`. Nos dois eventos de compra ele
não vai, então todo produto vira `'Produto'` nos dois relatórios.

**Também:** existe a tela de Links de Rastreamento (`links_rastreamento`), que captura um
identificador por link. Ele nunca é ligado ao traqueamento. É a peça que faltaria pra ter
UTM de verdade.

---

## Facebook — `funcoes/facebook.php`

### F1. Access token na query string (linha 8) — 🔴
```php
$url = "https://graph.facebook.com/v19.0/{$pixel_id}/events?access_token={$access_token}";
```
Token em URL aparece em log de proxy, de CDN e no `access.log` do servidor. A Graph API
aceita `access_token` dentro do corpo do POST; é uma linha de mudança.

Também não há `urlencode()` no `$pixel_id` nem no token — um caractere especial monta uma URL
quebrada em silêncio.

### F2. Telefone sem código do país (linha 25) — 🟠
```php
$user_params['ph'] = hash('sha256', preg_replace('/[^0-9]/', '', $user_data['telefone']));
```
A Meta espera o telefone com código do país (`5511987654321`). Se o número vier como
`11987654321`, o hash não bate com o que a Meta tem e a correspondência falha — sem erro
nenhum, porque o hash é sempre "válido". (Hoje isso é teórico: telefone nunca chega. Mas se
alguém passar a mandar, o bug já está lá.)

O e-mail (linha 23) está certo: `strtolower` + `trim` + sha256 é o que a Meta pede.

### F3. `action_source: 'system_generated'` (linha 45) — 🟡
Valor válido, mas descreve evento gerado pelo sistema sem ação do usuário. Uma compra feita
por alguém conversando com o bot é mais bem descrita por `chat`. Afeta como a Meta modela a
conversão. **Depende de conferir na documentação atual da Meta** — não testei.

### F4. Sem `test_event_code` — 🟡
Não há como mandar evento de teste pro Events Manager sem sujar o dado real. É um campo
opcional no payload; sem ele, validar a integração fica difícil. Dado que você quer validar
o Facebook, isso vale mais que parece.

### F5. `event_id` pode ser `null` (linha 51) — 🟡
```php
'event_id' => $dados['event_id'] ?? ($dados['transacao_id'] ?? null)
```
Hoje os três pontos de chamada mandam `event_id`, então na prática nunca é null. Mas se um
dia um evento novo não mandar, a deduplicação da Meta some silenciosamente.

### F6. Falha de rede perde o evento — 🟠
Se o cURL falhar, o resultado é logado em `logs/traqueamento.log` e acabou. Não há fila nem
nova tentativa. Uma instabilidade de 30 segundos na Meta = vendas sem evento, sem alarme.

### F7. Sem `declare(strict_types=1)` e sem tipos nos parâmetros — 🟢
Destoa do resto do projeto, que usa `strict_types` em quase todo lugar.

---

## UTMify — `funcoes/utmfy.php`

### U1. `trackingParameters` vai com os 5 campos `null` (linhas ~140-146) — 🔴
```php
'trackingParameters' => [
    'utm_campaign' => null,
    'utm_content'  => null,
    'utm_medium'   => null,
    'utm_source'   => null,
    'utm_term'     => null
]
```
Está escrito `null` no código, fixo. **A integração com a UTMify não envia nenhuma UTM.**

E o mais revelador: o outro ramo do mesmo arquivo (o de URL de postback, linhas 74-78) lê
`$user_data['utm_source']`, `utm_campaign`, etc. corretamente. Alguém sabia que os campos
existiam e o ramo da API oficial ficou com os valores fixos em nulo.

A UTMify existe pra dizer qual campanha gerou qual venda. Sem UTM, ela recebe uma lista de
pedidos sem origem — que é justamente o que ela não precisa.

### U2. E-mail inventado (linhas 106-110) — 🟠
```php
$email = 'cliente' . ($user_data['id_telegram'] ?? time()) . '@telegram.com';
```
`telegram.com` é um domínio real, de terceiro. Se a UTMify (ou qualquer ferramenta ligada a
ela) disparar e-mail pra essa base, sai mensagem pra um domínio que não é nosso. E a base do
cliente fica cheia de contato falso.

Se for mesmo preciso preencher, o correto é um domínio reservado pra isso: `.invalid`
(RFC 2606), que nunca vai existir de verdade.

### U3. Telefone inventado `00000000000` (linhas 101-104) — 🟠
Mesma história. O comentário no código assume que a API exige o campo, mas isso não foi
verificado; pode ser que aceite vazio ou ausente.

### U4. Comissão declarada como 100% do valor (linhas ~133-137) — 🟠
```php
'gatewayFeeInCents'     => 0,
'totalPriceInCents'     => $valor_centavos,
'userCommissionInCents' => $valor_centavos
```
Diz que o vendedor recebeu o valor inteiro e que o gateway não cobrou nada. Nenhuma das duas
coisas é verdade: existe taxa do gateway e existe split. O banco tem `comissao_admin` na
tabela `vendas` — o dado está lá, só não é usado.

Quem olhar lucro na UTMify vai ver um número inflado.

### U5. Todo produto é o mesmo produto (linhas ~124-131) — 🟠
```php
'id' => '1', ... 'planId' => '1', 'planName' => 'Unico'
```
Fixos. No relatório da UTMify, um bot com 5 planos vira um produto só. Perde-se exatamente a
análise de qual oferta converte melhor.

### U6. Evento desconhecido vira venda paga (linha ~95) — 🔴
```php
$status = $status_map[$evento] ?? 'paid';
```
O padrão de um `switch` que não reconheceu o valor é **"pago"**. Se alguém adicionar um
evento novo amanhã e esquecer de mapear, ele entra na UTMify como venda aprovada. Erro que
inventa faturamento é o pior tipo de erro pra ter num relatório.

O padrão seguro seria recusar o envio e logar.

### U7. `cadastro` entra como `waiting_payment` (linha ~92) — 🟡
Um lead que só deu `/start` vira "pedido aguardando pagamento". Infla o funil com pedidos que
nunca existiram. (Hoje nenhum ponto de chamada dispara `cadastro`, então está dormindo.)

### U8. `approvedDate` é sempre "agora" (linha ~119) — 🟡
```php
'approvedDate' => $status === 'paid' ? $data_atual : null
```
Se o webhook atrasar ou o cron de verificação de PIX pegar um pagamento antigo, a data de
aprovação registrada é a do processamento, não a do pagamento. Distorce relatório por dia.

### U9. Sem `CURLOPT_CONNECTTIMEOUT` (linha ~155) — 🟡
Só tem `CURLOPT_TIMEOUT => 15`. O `facebook.php` tem os dois (`CONNECTTIMEOUT 5`,
`TIMEOUT 10`). Sem o de conexão, um DNS lento segura a requisição mais do que o esperado — e
isso acontece **dentro do webhook**, que precisa responder rápido pro Telegram/InfoPago.

### U10. A proteção de SSRF tem uma janela (TOCTOU) — 🟡
`urlPostbackEhSegura()` está bem escrita e cobre o essencial. Mas resolve o DNS, valida os
IPs, e depois o cURL **resolve de novo** — entre as duas resoluções o registro pode mudar
(DNS rebinding) e apontar pra rede interna.

Fechar isso é usar `CURLOPT_RESOLVE` fixando o IP que já foi validado. Risco baixo (exige um
atacante com DNS próprio), mas é exatamente o furo que a varredura 09 tentou tapar.

### U11. O ramo é escolhido por "parece uma URL?" (linha ~57) — 🟢
```php
$is_url = filter_var($token, FILTER_VALIDATE_URL);
```
Um token que por acaso passe na validação de URL cairia no ramo errado. Improvável, mas um
campo separado ("token" vs "URL de postback") seria mais honesto que adivinhar.

---

## Se fosse pra atacar em ordem

1. **Fazer `$user_data` carregar algo** (e-mail/telefone quando existirem, IP, user agent) e
   ligar os Links de Rastreamento às UTMs. Sem isso, o resto é polimento — os dois destinos
   continuam recebendo evento sem identificação.
2. **U1** (UTMs fixas em `null`) e **U6** (desconhecido vira "pago"). São duas linhas cada.
3. **F1** (token na URL) — vazamento real, correção pequena.
4. **U4/U5** (comissão e produto) — o dado já existe no banco, só não é passado.
5. O resto.
