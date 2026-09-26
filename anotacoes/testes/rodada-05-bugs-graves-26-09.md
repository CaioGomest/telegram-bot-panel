# Rodada de testes — bugs graves (26/09/2026)

Continuação da varredura por bugs grandes/graves (não casos-limite). Metodologia: leitura
de código + reprodução ao vivo com conta descartável (`@teste-descartavel.invalid`),
sempre limpando os dados criados no fim.

Havia também um relatório de homologação não commitado (`anotacoes/tests/relatorio-homologacao-2026-09-25.md`)
com uma lista grande de achados. Vários deles (conversão sempre 100%, gateway "-" e split
"Pendente" nas transações antigas) investiguei a fundo e concluí que **não são bugs de
código** — são reflexo de um dataset de demonstração muito antigo (~766 mil vendas,
volta a 2023) que existia antes das colunas/tabelas de gateway, split e status
intermediário de PIX existirem. `id_gateway`/`split_status` NULL nessas linhas antigas é
o comportamento esperado, não uma falha. Não mexi nisso. Os três abaixo são bugs de
código reais, confirmados e corrigidos.

## 1. ✅ CORRIGIDO — Toast invisível bloqueava o botão "Mais" no menu mobile

**Onde:** `assets/css/coyote.css` (`#toast`), afeta toda página com a barra de navegação
mobile (`.barra-mobile`, visível até 860px de largura).

**O bug:** `#toast` é `position:fixed; right:24px; bottom:24px; z-index:9999`, sempre
presente no DOM, com `opacity:0` quando não está mostrando nenhuma mensagem — mas sem
`pointer-events:none`. Elemento com `opacity:0` continua capturando toque/clique
normalmente; só fica invisível, não inerte. Como o z-index (9999) é muito maior que o da
barra de navegação mobile (200), esse retângulo invisível ficava por cima do botão
**"Mais"** (o último item da barra, mais próximo do canto onde o toast fica ancorado) e
roubava o toque, mesmo o toast nunca tendo aparecido na tela.

**Impacto:** no celular, o usuário não conseguia abrir o menu "Mais" — que é o único
caminho pra Remarketing, Traqueamento, Links de Rastreamento, Webhooks, Comunidade e
Gateways nessa largura de tela. Bug grande: trava navegação real, achável por qualquer
usuário mobile, sem precisar de nenhuma condição especial.

**Fix:** adicionado `pointer-events: none;` na regra base de `#toast`. Conferido no JS
(`assets/lista_bots.js` e outros) que o toast nunca é clicado (é só timeout de 3-6s), então
não tem nenhuma interação perdida com essa mudança.

## 2. ✅ CORRIGIDO — Link "Editar fluxo" na tela do bot nunca abria o fluxo certo

**Onde:** `bot.php` (`#link-fluxo-secundario`) + `assets/edicao_bot.js`.

**O bug:** o link ao lado do seletor de fluxo tinha `href="fluxo"` fixo no HTML. O
JavaScript (`atualizarVisibilidadeModo()`) só trocava o **texto** do link ("Editar
fluxo" / "Novo Fluxo") conforme o modo da tela (editando bot existente vs. criando um
novo) — nunca tocava no `href`. Resultado: clicar em "Editar fluxo" sempre abria
`fluxo.php` sem nenhum `?id=`, ou seja, **sempre criava um fluxo novo em branco**, nunca
abria o fluxo de verdade que estava selecionado no `<select>` ao lado. Existe ainda um
segundo problema dentro desse: fluxos podem ser do modo `avancado` (abre em `fluxo.php`)
ou `basico` (abre em `fluxo_basico.php`) — o link nem sabia diferenciar isso.

**Impacto:** qualquer usuário que tentasse editar o fluxo já conectado ao bot pelo botão
"Editar fluxo" (o caminho óbvio, ao lado do seletor) acabava criando fluxos duplicados
sem querer, achando que estava editando o que já tinha. Bate com o item 4 do relatório
de homologação (20 fluxos duplicados chamados "Novo fluxo" na conta de teste, nenhum
conectado a nenhum bot) — esse é provavelmente o mecanismo que gerou aquele lixo.

**Fix:** `carregarFluxos()` agora guarda o `modo` de cada fluxo como `data-modo` na
`<option>` (o campo já vinha da API, só não estava sendo usado — `listar_fluxos` faz
`SELECT *` em `fluxos`, que tem coluna `modo`). Nova função `atualizarLinkFluxo()` lê o
fluxo selecionado e monta `href="fluxo?id=X"` ou `href="fluxo_basico?id=X"` (mesmo padrão
já usado em `assets/lista_fluxos.js`); sem fluxo selecionado, cai em `href="fluxo"`
(criar novo, comportamento correto nesse caso). Chamada em todo lugar que pode mudar o
fluxo selecionado: ao carregar a lista, ao preencher o formulário de um bot existente, ao
limpar o formulário, e num listener de `change` no seletor.

**Verificado ao vivo:** criei um fluxo `modo=basico` e um `modo=avancado` via API com
conta descartável, chamei `listar_fluxos` e confirmei que o campo `modo` chega certinho
no JSON pra cada um — a lógica do fix bate com o dado real que a API devolve. Fluxos de
teste apagados depois.

## 3. ✅ CORRIGIDO — "Esqueci a senha" não era alcançável por teclado

**Onde:** `login.php`.

**O bug:** `<span class="login-link-esqueci" onclick="abrirEsqueciSenha()">Esqueci a
senha</span>` — um `<span>` não entra na ordem de tabulação por padrão e não responde a
Enter/Espaço. Quem navega o formulário de login só pelo teclado (ou leitor de tela)
nunca alcança essa opção — só existe pra quem usa mouse/touch. A própria folha de
estilo já antecipava isso: existe uma regra `html[data-theme] a.login-link-esqueci`
comentada como correção de cor especificamente para a versão em `<a>`, e o link irmão
"Cadastre-se" (mesma classe CSS) já é um `<a>` de verdade.

**Fix:** trocado pra `<a href="#" class="login-link-esqueci" onclick="abrirEsqueciSenha();
return false;">`, no mesmo padrão do link "Cadastre-se" já existente — agora reachable via
Tab e ativável com Enter, sem precisar de nenhuma CSS nova (a regra pra `<a>` já existia).

## Dado de teste desta rodada

Conta `bugtestflow...@teste-descartavel.invalid` (id_usuario=24) criada, usada só pra
confirmar o campo `modo` na API `listar_fluxos`, sem bot associado. Os 2 fluxos de teste
criados nela já foram apagados via API. A conta em si ficou pra trás (vazia, sem custo
relevante) — apagar quando o SSH voltar, junto com o resto da limpeza pendente do teste
de estresse (ver `teste-de-estresse-25-09.md`).

## Pendente de deploy

Corrigido e commitado no repositório local/GitHub. Ainda não sincronizado no servidor —
SSH continua bloqueado (`/sbin/nologin: No such file or directory`, ver
`teste-de-estresse-25-09.md`).
