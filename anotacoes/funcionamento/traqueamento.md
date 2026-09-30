# Traqueamento

Tela: `traqueamento.php` (sidebar: Traqueamento). Lógica: `funcoes/traqueamento.php`.

Configuração dos pixels/APIs de conversão pra onde o sistema manda os eventos de venda. Não
cria link nem sabe de onde veio o lead — só dispara o sinal pra plataforma de anúncio quando
um evento acontece no bot.

## Plataformas

- **Facebook Conversions API** — ativa, precisa de Pixel ID + Access Token (`funcoes/facebook.php`).
- **UTMify** — ativa, precisa só do token (`funcoes/utmfy.php`).
- **TikTok Ads** — código existe (`funcoes/tiktok.php`) mas está **desligado** desde 19/09/2026
  a pedido do Caio (ninguém tem conta de TikTok Ads pra validar se o evento chega certo do
  outro lado). Pra religar: descomentar o bloco em `enviarEventosTraqueamento()` e a seção
  `tiktok_fields` em `traqueamento.php`.

Configuração de cada usuário fica na tabela `usuarios_traqueamento`.

## Como dispara

`enviarEventosTraqueamento($id_usuario, $evento, $dados, $user_data)`:
1. Busca a config do usuário em `usuarios_traqueamento`.
2. Pra cada plataforma ativada e com credencial preenchida, chama a função de envio
   correspondente e loga request/resposta em `logs/traqueamento.log` (`logTraqueamento()`).

Chamado hoje em `pix_gerado` (PIX criado) e em `payment_created`/confirmação de venda, a
partir de `processarEEnviarBloco()` (`funcoes/fluxo_blocos.php` — ver `anotacoes/HISTORICO-CONSOLIDADO.md`
sobre a unificação desse executor entre webhook.php/cron/webhook_omegapayments).

## user_data (dados de correspondência)

`montarUserDataTraqueamento($pdo, $id_telegram, $bot_id)` monta o que o Telegram realmente
fornece: nome e telefone do lead (se capturado) e a origem do link de rastreamento (vira
`utm_source=telegram`, `utm_medium=bot`, `utm_campaign=<origem_rastreio>`). Não inventa e-mail
nem IP — o Telegram não dá acesso a isso.

Existe como função separada porque antes cada ponto de chamada (webhook.php x2,
webhook_omegapayments.php) montava esse array na mão só com `id_telegram`, então Facebook e
UTMify recebiam evento sem dado de correspondência nenhum e a atribuição não acontecia (mesmo
com a API devolvendo HTTP 200).

## Relação com as outras duas telas

Traqueamento não sabe nada sobre de onde o lead veio — só usa o `origem_rastreio` que **Links
de Rastreamento** ou **Redirecionamento** já gravaram no lead, pra preencher o `utm_campaign`
do evento.
