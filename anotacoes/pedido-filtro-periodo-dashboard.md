# Pedido: filtro de período na dashboard (Hoje / Ontem / 8d / 30d / Total)

Print de referência mandado pelo Caio (print de conversa no Telegram) mostrando um exemplo de
dashboard com abas/filtro de período: **Hoje, Ontem, 8d, 30d, Total**.

## Pedido

Colocar esse mesmo tipo de filtro na dashboard do painel (`index.php` / `admin/dashboard.php`) —
deixar a pessoa alternar entre esses períodos e as métricas da tela se atualizarem de acordo.

## ~~Ainda não implementado~~ Implementado

O filtro existe e funciona em `index.php` (usuário) e `admin/dashboard.php` (admin) — pílulas
**Hoje / Ontem / 7 dias / 30 dias / Total / Personalizado** (`.seletor-periodo`), cada uma um link
que dispara a troca de período via JS (`filter_form.submit()`, ver script no fim de cada arquivo),
recarregando os cards e o gráfico com `WHERE criado_em >= ...` nas queries (ou lendo do cache
`metricas_horarias_usuario`/`metricas_horarias_admin` quando dá, ver `analise-potencia-e-escala.md`
seção 9). Não achei o commit exato de quando isso foi implementado (aconteceu numa sessão anterior
a esta), só confirmei que está no ar e funcionando.

## Correção de exibição no mobile — 2026-09-17

O Caio mandou print comparando o protótipo (Figma/Claude Design) com o site real e apontou duas
diferenças: (1) no mobile, a fileira de período quebrava em várias linhas — "Personalizado"
sozinho numa linha, e o botão "Aplicar" + o alternador de tema em outra, bem diferente da fileira
única do protótipo; (2) faltava a logo Coyote no topo das telas mobile (só existia na barra lateral,
escondida no mobile).

**Corrigido** (`barra_lateral.php` + `assets/css/coyote.css`):
- Fileira de período agora tem scroll horizontal no mobile (`flex-wrap: nowrap` + `overflow-x: auto`)
  em vez de quebrar linha — todas as pílulas (inclusive "Personalizado") ficam numa fileira só,
  acessível arrastando pro lado.
- Botão "Aplicar" escondido via CSS (`:has()`) quando não há nada pra aplicar (fora do período
  "personalizado" e sem filtro de bot ativo) — continua no DOM porque o JS despacha clique nos
  períodos fixos chamando `filter_form.submit()` programaticamente, não por navegação direta do
  link; removê-lo do HTML quebraria a troca de período inteira no mobile (quase cometi esse erro,
  percebi a tempo lendo o script antes de aplicar a correção).
- Cabeçalho novo (`<header class="barra-mobile-topo">`) fixo no topo de toda página mobile
  autenticada, com a logo + "COYOTEBOT" (BOT em laranja), igual ao protótipo.

Testado ao vivo em produção: CSS confirmado no servidor, HTML da barra renderizando com a logo,
imagem carregando (200).

## "7 dias" na verdade era "8 dias" — corrigido, e achou um bug de fuso horário de verdade

Reparando no print original (linha 4 desta nota: pedido era **"8d"**), a pílula "7 dias" só
rotulava errado — o número grande do card já somava 8 dias corridos (`INTERVAL 7 DAY` = hoje + 7
pra trás), mas o gráfico logo abaixo só desenhava 7 pontos (`INTERVAL 6 DAY`), então o total nunca
batia com a soma visual do gráfico. Corrigido em `index.php` e `admin/dashboard.php`: gráfico agora
cobre os mesmos 8 dias do card, rótulos "8 dias"/"8 DIAS", e a janela "vs período anterior" ajustada
pra também ser 8 dias (comparação justa, mesmo nº de dias dos dois lados).

**Ao testar essa correção ao vivo, achei um bug real de fuso horário**: `conexao.php` fixava o
fuso do MySQL em `-03:00`, mas nunca chamava `date_default_timezone_set()` do lado do PHP — que
ficava no default do servidor. Via HTTP isso não tinha efeito prático (o SAPI web já vinha com
fuso correto configurado no php.ini da Hostinger), mas via **CLI** (cron jobs, e os scripts que
rodei este mês inteiro por SSH pra testar/gerar dados) o PHP calculava "hoje" em UTC — cerca de 3h
por dia (21h–23:59 em Brasília, já madrugada seguinte em UTC) isso empurra "hoje" um dia pra
frente. Corrigido adicionando `date_default_timezone_set('America/Sao_Paulo')` em `conexao.php`
(carregado por quase toda rota, junto do fuso do MySQL na linha seguinte) — protege qualquer cron
que não seta o próprio fuso (a maioria não seta, só `webhook.php`/`webhook_infopago.php` já
setavam).

**Efeito colateral que isso expôs**: ~7.000 linhas de `vendas`/`leads` do bot de teste de carga
(gerados por `bench_2anos.php` nesta mesma sessão, antes da correção acima existir) tinham
`criado_em` datado um dia no futuro (ex. "2026-09-18" quando o dia real ainda era 17) — exatamente
esse bug, rodando via CLI. Isso inflava o card "Receita líquida" do admin em ~R$300 mil sem
aparecer no gráfico (nenhum dia do gráfico é "amanhã"). Confirmado que é 100% dado sintético dos
bots de teste (`pago_em` nulo em todas as linhas afetadas, nunca passou pelo fluxo real de
pagamento) — corrigido com `UPDATE ... SET criado_em = criado_em - INTERVAL 1 DAY WHERE criado_em
> NOW()` nas duas tabelas, e o cache (`metricas_horarias_admin`/`_usuario`) recalculado pras datas
afetadas. Verificado depois: soma do gráfico bate exatamente com o total do card (R$ 2.057.890,52
nos dois).

## Alinhamento fino com o protótipo (mobile) — 2026-09-17, 2ª rodada

O Caio mandou dois prints do protótipo (Dashboard e Meus Bots) pedindo pra deixar o layout fiel.
Duas diferenças apontadas por ele + o que caiu junto:

1. **Barra de filtro mais estreita que os cards.** Causa: `.cabecalho-pagina` tinha `padding: 16px`
   somando por cima dos 16px de `.conteudo-principal`, então tudo dentro do cabeçalho ficava 16px
   mais estreito de cada lado. Corrigido pra `padding: 16px 0 12px` no mobile + `width: 100%` no
   seletor. Medido depois com Playwright: filtro e card agora em `x=16, w=361` — idênticos.
2. **Header com logo.** Na 1ª rodada eu tinha entendido errado e criado uma barra fixa separada no
   topo com "COYOTEBOT" — **removida**. O protótipo põe a logo dentro do cabeçalho da própria
   página, à esquerda do título/subtítulo, com o botão de tema virando ícone circular à direita.
   A logo entra por `.cabecalho-pagina::before` com `url('../img/coyote-logo.jpg')` — caminho
   relativo ao próprio CSS, então funciona igual em qualquer página (inclusive `admin/`) sem
   precisar tocar nos ~15 arquivos que têm cabeçalho. `.acoes-cabecalho` vira `display: contents`
   no mobile pra dar pra separar o botão de tema (linha do título) do resto (linha de baixo).
3. **"Personalizado" fora da barra** (pedido dele, pra ficar nos 5 períodos do protótipo) —
   desativado com `if (false)` em `index.php`/`admin/dashboard.php`, reversível numa linha; o
   período continua acessível por URL.
4. **Faixa vazia entre filtro e gráfico** (~65px): era o form de datas, que mesmo invisível
   (`max-width:0`/`opacity:0`) mantinha os inputs ocupando uma linha inteira. Some no mobile,
   ficando só quando a conta tem mais de um bot (aí o seletor de bot é útil). Medido: 65px → 12px.
5. Dois defeitos que apareceram ao conferir o resultado: números dos KPIs saíam sem separador de
   milhar ("1092365" em vez de "1.092.365") e o rodapé do card era cortado no meio da palavra sem
   reticências (o `text-overflow` do pai não pega no `<span>`, que é item flex). Corrigidos.

**Verificação:** screenshots reais em viewport de celular (Playwright + Chromium) logado como
usuário comum e como admin, mais medição por `getBoundingClientRect()` — não foi só leitura de
código.

**Ainda diferente do protótipo, de propósito (não mexi sem pedir):** o sino de notificação (o Caio
pediu pra deixar de lado por ora) e o texto de alguns rodapés de card ("Leads iniciaram conversa"
vs "novas conversas", "X PIX gerados" vs "por venda") — é conteúdo, não layout.

## Tela "Meus Bots" + cache de JS — 2026-09-17, 3ª rodada

Pílulas do filtro ganharam `flex: 1 0 auto` (dividem a linha inteira, sem o espaço morto que
sobrava depois de "Total" com só 5 períodos; se voltar a ter pílula demais, transborda e rola em
vez de espremer).

**Card de bot refeito no formato do protótipo** (`assets/lista_bots.js` + CSS): avatar com 2
iniciais, nome + @usuário empilhados, bolinha de status (verde = fluxo conectado, âmbar = sem
fluxo), dois quadros de métrica (FLUXO / LEADS 7D) e botão "Configurar" largo + excluir. O card
antigo mostrava **o ID cru do fluxo** ("Fluxo: 2") — `listar_bots` agora traz `nome_fluxo`
(LEFT JOIN em `fluxos`) e `leads_7d` (COUNT coberto por `idx_leads_bot_criado_em`).

Dois achados no caminho, os dois só apareceram porque conferi com screenshot real em vez de só
ler o código:

1. **JS sem cache-busting.** O CDN da Hostinger serve estático com `Cache-Control: max-age=604800`
   (7 dias). O CSS já era versionado com `?v=filemtime` e o `edicao_fluxo.js` também, mas os outros
   29 includes de JS não — depois de um deploy, navegador/CDN continuavam rodando o JS antigo por
   até uma semana. Peguei na prática: o card recém-refeito voltou pro layout velho num teste porque
   o edge serviu a versão em cache. Versionados todos (23 arquivos).
2. **Duas regras de CSS que nunca valeram por especificidade.** `.cartao-cabecalho h3` já pedia
   Manrope + `text-transform: none` e `.botao-configurar` já pedia `color: var(--or)`, mas perdiam
   pra `html[data-theme] h3` e `html[data-theme] a` (especificidade maior) — o nome do bot saía em
   CAIXA ALTA e o botão perdia o laranja. Prefixadas pra valer.
