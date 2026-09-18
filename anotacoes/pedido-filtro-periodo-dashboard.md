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
