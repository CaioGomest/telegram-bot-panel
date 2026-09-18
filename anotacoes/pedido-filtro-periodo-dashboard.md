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
