# Redirecionamento

Tela: `redirecionamento.php`. Lógica: `funcoes/redirecionadores.php`. Endpoint público: `l.php`.
Modelado na tela "Links e campanhas" da SharkBot.

Versão robusta de link de rastreamento, pensada pra **tráfego pago** (Meta/TikTok/Google Ads):
domínio próprio, múltiplos bots de destino, filtro de robô e métricas de clique/campanha —
coisas que Links de Rastreamento não tem.

## Fluxo

1. Você cria um redirecionador: título, **slug** (endereço curto — aleatório ou personalizado,
   definido só na criação porque trocar depois quebraria anúncio já publicado e o vínculo dos
   leads antigos), plataforma de origem (Meta/TikTok/Google/Outra), proteção (`nenhuma` ou
   `filtrar_robos`), modo de distribuição (`aleatorio` ou `sequencial`) e um ou mais **bots de
   destino** (até 20).
2. A URL pública fica `https://<seu_dominio_ou_host>/l/<slug>` (`urlPublicaRedirecionador()`).
   Domínio próprio é opcional — precisa estar cadastrado em Administração > Configurações pelo
   admin, com o DNS apontando pro servidor (`dominiosRedirecionamento()`).
3. Ao clicar, `l.php`:
   - Conta o clique do dia, agrupado por `utm_campaign` da query string, em
     `redirecionador_cliques_dia`.
   - Se `protecao = filtrar_robos`, usa `ehRoboRedirecionamento()` (checa user-agent contra
     lista de bots/crawlers conhecidos e requisições `HEAD`/sem user-agent) — só deixa de
     contar o clique, o robô continua indo pro bot normalmente (não é cloaking).
   - Escolhe um bot entre os destinos (aleatório ou sequencial) e redireciona pro
     `t.me/<bot>?start=rd_<slug>`.
4. O prefixo `rd_` no `/start` é o que liga de volta ao redirecionador: `webhook.php` reconhece
   esse prefixo e, além de gravar em `links_rastreamento` como qualquer origem, também
   incrementa `starts`/`leads` na tabela `redirecionadores` pelo `slug`
   (`webhook.php`, bloco que checa `strncmp($start_param, 'rd_', 3)`).
5. A tela lista, por redirecionador: cliques dos últimos 7 dias, quantidade de vendas e receita
   (`listarRedirecionadores()`, junta `leads.origem_rastreio LIKE 'rd\_%'` com `vendas.status =
   'pago'`) e o breakdown de cliques por campanha dos últimos 30 dias
   (`campanhasRedirecionador()`).

## Plataformas suportadas

`REDIRECIONADOR_PLATAFORMAS` (meta, tiktok, google, outra) — cada uma só guarda um texto de
UTM sugerido e uma instrução de onde colar (campo "Parâmetros de URL" do anúncio, etc.). Não
faz chamada de API pra nenhuma plataforma, é só orientação de uso.

## Limites

- Até 50 redirecionadores por usuário (`REDIRECIONADOR_LIMITE_POR_USUARIO`).
- Até 20 bots de destino por redirecionador (`REDIRECIONADOR_MAX_BOTS`).
- Slug: 3 a 40 caracteres, minúsculas/números/hífen, sem hífen no início/fim.

## Fora do escopo hoje (ver PENDENCIAS.md)

Códigos de venda, Página intermediária e aba Domínios (da referência SharkBot) ficaram de
fora desta primeira versão.

## Relação com as outras duas telas

`origem_rastreio = rd_<slug>` no lead é o mesmo mecanismo usado por Links de Rastreamento —
só que aqui alimenta duas tabelas (`links_rastreamento` e `redirecionadores`) em vez de uma.
O `utm_campaign` que chega no clique (via query string do anúncio) é o que aparece agrupado em
`campanhasRedirecionador()`; já o que o Traqueamento manda pro Facebook/UTMify usa apenas o
`slug` como `utm_campaign` (ver `anotacoes/funcionamento/traqueamento.md`), não o UTM original
do clique.
