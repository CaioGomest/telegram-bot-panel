# Links de Rastreamento

Tela: `links_rastreamento.php`. Lógica: `funcoes/links_rastreamento.php`.

Etiqueta simples pra saber de qual link um lead veio, sem UTM, sem contador de clique, sem
múltiplos destinos. Pensado pra colocar num link na bio, numa mensagem, num grupo — qualquer
lugar onde você quer diferenciar a origem sem a complexidade de um redirecionador.

## Como funciona

1. Você cria um link escolhendo um **título**, um **identificador** (texto livre, único por
   usuário) e **um bot de destino** (`criarLinkRastreamento()`).
2. `gerarUrlLink()` monta a URL: `https://t.me/<bot_username>?start=<identificador>`.
3. Quando alguém abre esse link e o Telegram manda `/start <identificador>` pro bot,
   `webhook.php` extrai esse parâmetro (`$start_param`) e:
   - Grava `origem_rastreio = <identificador>` no lead, só na primeira vez (primeiro toque —
     se sobrescrevesse a cada `/start`, a última campanha levaria o crédito de um lead que
     outra trouxe).
   - Incrementa `starts` sempre, e `leads` só se for lead novo, na linha correspondente da
     tabela `links_rastreamento` (`webhook.php`, bloco "Se veio de um link de rastreamento").

Não tem tabela de cliques por dia nem filtro de robô — o contador só existe a partir do
`/start` que efetivamente chega no bot.

## Diferença pro Redirecionamento

Aqui o link **é** o `t.me/...` direto — não existe uma página intermediária nem domínio
próprio. Serve bem pra link colado à mão (bio, grupo, indicação). Pra tráfego pago com métrica
de clique/campanha e distribuição entre vários bots, é o **Redirecionamento** que resolve
(ver `anotacoes/funcionamento/redirecionamento.md`).

## Relação com Traqueamento

O `origem_rastreio` gravado aqui é o que `montarUserDataTraqueamento()` usa pra preencher
`utm_campaign` nos eventos mandados pro Facebook/UTMify (ver
`anotacoes/funcionamento/traqueamento.md`).
