# Varredura 11 — cobertura mobile (quais telas existem de verdade no celular)

Feita em 2026-09-17 a pedido do Caio ("tem telas q n existem para mobile, valide isso"). Não é
leitura de código: abri **as 18 telas autenticadas** em viewport de celular (Playwright + Chromium,
Pixel 5 / 393px), logado como usuário comum e como admin, medindo bloqueio, estouro horizontal
(`scrollWidth` vs `clientWidth`) e erro de console em cada uma.

## Resultado em uma linha

Estouro horizontal: **0 telas**. Erro de console: **0 telas**. O problema não é layout quebrado —
é **acesso**: 6 telas se recusam a abrir no celular e **5 telas que funcionam bem não têm link
nenhum** pra chegar nelas.

## 1. Telas bloqueadas no mobile (mostram "Melhor no desktop" no lugar do conteúdo)

| Tela | Quem usa | Mensagem |
|---|---|---|
| `bot.php` | usuário | criação/edição de bot |
| `fluxo.php` | usuário | editor de fluxo (canvas) |
| `gateways.php` | usuário | credenciais de gateway |
| `admin/dashboard.php` | admin | "os gráficos e indicadores administrativos funcionam melhor em uma tela maior" |
| `admin/transacoes.php` | admin | "a tabela de transações e os filtros funcionam melhor em uma tela maior" |
| `admin/logs.php` | admin | idem |

É bloqueio deliberado (`.somente-desktop-aviso.ativo-mobile`), não bug. Pro editor de fluxo
(canvas de arrastar-e-soltar) faz todo sentido; pras outras é uma decisão a revisitar — `gateways.php`
e `admin/transacoes.php`, por exemplo, são telas que alguém pode precisar abrir no celular.

## 2. 🔴 O achado principal: telas que funcionam no mobile mas não têm como chegar

A barra lateral do desktop tem **17 links**; no mobile ela some (`display: none`) e a tab bar de
baixo tem **6** (Início, Bots, Fluxos, Leads, Ranking, Conta). Quem ficou de fora **e funciona
perfeitamente no celular** (testado: sem estouro, sem erro):

- `remarketing.php`
- `traqueamento.php`
- `links_rastreamento.php`
- `admin/usuarios.php` (tabela rola na horizontal direitinho, `overflow-x: auto`)
- `admin/ranking.php`

Hoje só dá pra abrir essas cinco **digitando a URL na mão**. Não há link pra elas em nenhum lugar
do celular.

## 3. 🔴 Admin no celular é um beco sem saída

A aba "Início" da tab bar aponta pra `admin/dashboard.php` quando o usuário é admin — que é uma das
telas bloqueadas. As outras 5 abas apontam pras telas de usuário comum. Resultado: **um admin que
abre o painel no celular cai numa tela bloqueada e não tem link pra nenhuma área administrativa.**

## 4. O que está ok (não precisa mexer)

Abrem e funcionam no mobile, com link na tab bar: `index.php`, `bots.php`, `fluxos.php`,
`leads.php`, `ranking.php`, `configuracao_usuario.php`. Nenhuma delas estoura a largura nem
registra erro de console. `admin/configuracoes.php` também abre (mas é uma tela placeholder,
"funcionalidade em desenvolvimento").

## Decisões pro Caio (não mexi em nada, é escolha de produto)

1. **Navegação:** as 5 telas órfãs precisam de link no celular. Opções: crescer a tab bar (fica
   apertada com 6+), trocar a última aba por um "Mais" que abre o resto num menu, ou um botão de
   menu no cabeçalho que abre a barra lateral como gaveta.
2. **Admin no celular:** no mínimo a tab bar do admin devia apontar pras telas de admin que
   funcionam (`usuarios`, `ranking`) em vez de cair numa tela bloqueada.
3. **Rever os bloqueios:** quais dos 6 realmente precisam continuar bloqueados? O editor de fluxo
   sim; `gateways.php` e `admin/transacoes.php` provavelmente dariam pra adaptar (a tabela de
   transações já teria o mesmo tratamento de scroll que `admin/usuarios.php` usa hoje).

## Notas relacionadas

- `pedido-filtro-periodo-dashboard.md` — rodadas de alinhamento do mobile com o protótipo.
- `anotacoes/urgente/notificacoes-sino-header.md` — o sino do protótipo, ainda a fazer.

---

## ✅ Resolvido — 2026-09-17 (mesma data da varredura)

O Caio pediu pra adaptar tudo. Estado final: **as 18 telas abrem no celular**, nenhuma bloqueada,
estouro horizontal 0 e erro de console 0 (revalidado com a mesma varredura).

**Navegação.** Nova 7ª aba "Mais" na tab bar abre uma folha inferior com todos os grupos da barra
lateral (Operação / Administração / Debug) + sair, reaproveitando `renderizarItemNav()`. Resolve as
5 telas órfãs e o beco sem saída do admin de uma vez. As 6 abas do protótipo continuam como estavam.

**Bloqueios removidos.** `bot.php`, `gateways.php`, `admin/dashboard.php`, `admin/transacoes.php` e
`admin/logs.php` perderam o "Melhor no desktop" — as tabelas já rolavam na horizontal sozinhas
(`.tabela-dados { overflow-x: auto }`).

**Editor de fluxo (`fluxo.php`)** — era o caso difícil, três coisas faltavam no toque:
- *Adicionar bloco* só existia por drag-and-drop HTML5 (não dispara em toque) e o clique tinha sido
  desativado de propósito → no touch, toque simples adiciona o bloco no canto visível, em cascata.
- *Mover bloco* usa jQuery UI draggable, que só escuta mouse → ponte toque→mouse. **Primeira
  tentativa não funcionou**: usei delegação do jQuery no document e ela nunca disparava — medindo,
  handler direto no elemento dispara e delegado não, ou seja a lib do canvas interrompe a
  propagação do `touchstart` no `.flowchart-operator`. Refeito com listener **nativo em fase de
  captura**, que roda antes disso. Também precisou de `touch-action: none` na alça, senão o Chrome
  assume que é rolagem e manda `touchcancel` no meio do arraste.
- *Conectar blocos* já funcionava (a lib escuta `touchstart`).
- O bloqueio virou um aviso que não esconde a tela, explicando como usar no celular.

**Dois defeitos que apareceram ao validar:**
1. `admin/dashboard.php` estourava 107px na horizontal — item de grid tem `min-width: auto`, então o
   canvas do Chart.js (que ganha largura fixa em px) empurrava a coluna: painel de 484px num viewport
   de 393. Só apareceu agora porque a tela era bloqueada. Corrigido com `min-width: 0` nos itens.
2. `.oculto-desktop` não tinha regra base — só funcionava em elemento que já nascia `display: none`
   (o card de destaque). O aviso novo do editor vazava pro desktop. Corrigido.

**Como foi verificado:** Playwright + Chromium em viewport de celular (Pixel 5, `hasTouch`), as 18
telas com as duas contas; arraste de bloco testado com toque real via CDP (`Input.dispatchTouchEvent`
— o bloco saiu de 400/400 pra 460/440, batendo com o gesto); e regressão no desktop (1440px): arraste
com mouse continua funcionando (400/400 → 464/440) e nenhum elemento mobile aparece.
