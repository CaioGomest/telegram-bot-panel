# Rodada de testes — 25/09/2026 (servidor de teste, produção)

Teste feito direto contra `https://telegram.stackcode.com.br`, depois de subir os 5
commits pendentes do dia (confirmação em Configurações, correção do excluir bot,
redesign de criar/editar bot, ícones em listagens). Sessão de admin (`admin@admin.com`)
+ uma conta de usuário descartável criada só pra este teste (`qa.claude.*@teste-
descartavel.invalid`, apagada no fim — nenhum dado de usuário real foi tocado, lido ou
exportado).

**Método:** login real via HTTP (curl, sessão com cookie + CSRF, igual o navegador faz),
varredura de todas as páginas (HTTP + procura por erro de PHP vazando na tela), chamadas
diretas nas ações de `api.php` que dá pra testar sem mexer em dado de outro usuário ou
gerar cobrança real. Não usei navegador (sem essa ferramenta aqui), então nada de
clique/JS/CSS visual foi verificado ao vivo — só o que HTML/API realmente devolvem.

Legenda (mesmo padrão de `mapa-de-testes-por-topico.md`):
- ✅ **verificado** — testei e vi funcionando
- 🟡 **parcial** — testei por fora (HTTP, payload), não ponta a ponta
- ❓ **não testado** — não dava pra testar sem navegador/token real
- 🔴 **achado** — comportamento errado ou inconsistente, confirmado

---

## 🔴 Achados (por prioridade)

### 0. ✅ CORRIGIDO — XSS armazenado em `admin/usuarios.php` via nome de exibição (qualquer usuário compromete a sessão do admin)

**Status: corrigido e no ar** (commit `73c6cb5`, mesmo dia). Reconfirmado ao vivo com o
mesmo payload depois do deploy — não quebra mais (`&quot;X&#039;);alert(...)...&quot;`
vem como uma única string JS segura). Detalhe do achado original mantido abaixo pra
histórico.

**Onde:** `admin/usuarios.php`, linhas 84-85.

**Reproduzido de verdade:** criei uma conta de teste via autocadastro (o próprio
`cadastro.php`, aberto pra qualquer visitante) com o nome
`X');alert(document.cookie);//`. Sem fazer mais nada, só abri `admin/usuarios.php`
logado como admin — o payload executa. Confirmado no HTML devolvido pelo servidor:

```html
<button ... onclick="abrirModalEditar(16, 'X&#039;);alert(document.cookie);//', '...', 'usuario')" ...>
```

**Causa raiz:** o código é `addslashes(htmlspecialchars($u['nome']))` — nessa ordem.
`htmlspecialchars()` roda primeiro e já transforma o `'` em `&#039;`; quando
`addslashes()` roda depois, não sobra nenhuma aspas de verdade pra ele escapar (é um
no-op ali). O navegador, ao interpretar o atributo `onclick="..."`, decodifica
`&#039;` de volta pra `'` **antes** de entregar o conteúdo pro motor de JavaScript —
essa aspas decodificada fecha a string do JS mais cedo do que devia, e o que vem depois
vira código JavaScript de verdade (o `//` no fim vira comentário, escondendo o resto do
`onclick` original). `addslashes(htmlspecialchars(x))` protege HTML; não protege JS
dentro de atributo HTML — a ordem certa seria escapar pra JS primeiro (ou nem usar
`onclick` inline com dado de usuário, e sim `data-*` + `addEventListener`).

**Impacto:** **qualquer pessoa** consegue criar uma conta (autocadastro está aberto,
sem aprovação) com um nome desse tipo — sem precisar de nenhum privilégio — e o
JavaScript dela roda **na sessão do admin** assim que ele abrir a tela de usuários pra
gerenciar qualquer conta (rotina normal do admin). O cookie de sessão tem `HttpOnly`
(confirmado na Rodada 2), então `document.cookie` sozinho não vaza o PHPSESSID — mas
isso não limita o estrago: o script malicioso roda dentro da página já autenticada como
admin, então ele pode fazer qualquer chamada que o admin conseguiria fazer (`fetch()`
com `window.CSRF_TOKEN`, que também está acessível via JS) — criar outro admin, mudar
qualquer configuração, apagar dado, etc., tudo silenciosamente, sem precisar roubar
cookie nenhum.

**Mesmo padrão, mesma linha, no campo e-mail** (linha 84) — na prática menos explorável
porque `criarUsuario()` valida formato de e-mail antes de salvar, então dificilmente dá
pra colocar aspas ali. O nome não tem essa restrição.

**Escopo do problema:** só encontrei esse padrão exato (`addslashes(htmlspecialchars(`)
nessas 2 linhas — não é um problema espalhado pelo projeto, é pontual.

**Conta de teste já apagada.** Nenhum dano real foi causado (o `alert()` só mostra uma
caixinha; não fiz nada além de confirmar que o JS roda).

### 1. ✅ CORRIGIDO — Erro de token de bot aparece em inglês ("Unauthorized") em vez de português

**Onde:** `api.php`, ações `testar_bot` (linha ~506) e `salvar_bot` (linha ~523).

**Reproduzido:** colei um token de bot inválido e chamei as duas ações — as duas
devolveram `{"sucesso":false,"mensagem":"Unauthorized"}`, cru, em inglês, misturado no
meio de uma interface 100% em português.

**Causa:** as duas ações fazem `$vivo['description'] ?? 'mensagem em português de
fallback'` — mas a API do Telegram **sempre** devolve uma `description` quando o token é
rejeitado (`getMe` retorna `{"ok":false,"error_code":401,"description":"Unauthorized"}`),
então o fallback em português nunca dispara na prática. É o caso mais comum de erro
nessa tela (usuário cola token errado ou incompleto) sempre aparecendo em inglês.

**Status: corrigido e no ar.** Adicionada `traduzirErroTelegramBot()` em `api.php`,
mapeando `Unauthorized`/`Not Found` pra mensagem em português; qualquer descrição não
mapeada cai num fallback em português (nunca mais no texto cru). Reconfirmado ao vivo
com token inválido de verdade: `"Token inválido ou revogado. Confira se copiou certo no
@BotFather."`.

### 2. ✅ CORRIGIDO — "+0,0%" no dashboard aparece destacado como se fosse alta (verde)

**Onde:** `index.php`, linha ~507: `$variacao_percentual >= 0 ? 'selo-variacao-positivo'
: 'selo-variacao-negativo'`.

**Reproduzido:** conta nova, sem venda nenhuma em nenhum período — o selo de variação
mostra **"+0,0% vs período anterior"** com o estilo verde de "coisa boa aconteceu",
quando na real não houve variação nenhuma (não tem dado nos dois períodos pra comparar).

**Impacto:** baixo, cosmético — mas destacar "+0,0%" em verde é visualmente enganoso.
Um estado neutro (cinza, sem sinal de +) faria mais sentido pra exatamente 0%.

**Status: corrigido e no ar.** Adicionada `.selo-variacao-neutro` (cinza) no CSS;
condição virou 3 vias (`> 0` verde, `< 0` vermelho, `= 0` neutro). Reconfirmado ao vivo
com conta nova: o selo agora sai com a classe `selo-variacao-neutro`.

---

## 🟡 Observação de design (não é bug, so nota)

Badges do menu lateral (`barra_lateral.php`, `nav-badge`) mostram **"0"** pra contagens
zeradas (Meus Bots, Leads) em vez de esconder o badge quando não há nada — é só um jeito
diferente de mostrar a mesma informação, questão de gosto, não afeta nada funcionalmente.

---

## ✅ Verificado — infraestrutura e páginas carregam sem erro

Varredura HTTP completa (login real + sessão), checando status 200 e ausência de
`Fatal error`/`Parse error`/`Warning:`/`Notice:`/`Deprecated:` vazando na tela:

**Lado usuário:** `index`, `bots`, `bot` (criar e editar), `fluxos`, `fluxo`,
`fluxo_basico`, `leads`, `gateways`, `webhooks`, `links_rastreamento`, `remarketing`,
`traqueamento`, `comunidade`, `ranking`, `configuracao_usuario` — todas 200, zero erro
de PHP visível.

**Lado admin:** `admin/dashboard`, `admin/usuarios`, `admin/transacoes`, `admin/logs`,
`admin/ranking`, `admin/comunidade`, `admin/configuracoes`, `admin/consultar_venda` —
todas 200, zero erro de PHP visível.

## ✅ Verificado — trabalho de hoje, especificamente

- **`excluir_bot` (correção de hoje):** confirmado que a ação existe de verdade agora —
  chamando com um ID que não existe, a resposta é `"Bot não encontrado."` (específica),
  não mais o genérico `"Ação inválida."` de antes (que é o que acontecia quando a ação
  simplesmente não existia no backend).
- **Modo Básico do editor de fluxo:** criei um fluxo `modo:"basico"` via API com
  boas-vindas + 1 plano + textos de pagamento — salvou certo, carreguei de volta
  (`obter_fluxo` e `listar_fluxos`) e o JSON voltou idêntico, com `"modo":"basico"`
  presente nos dois.
- **4 blocos novos do editor de nós (Randomizer/Upsell/Downsell/Order Bump):** salvei um
  fluxo avançado com os 4 tipos de bloco novos (mais um link ligando Início →
  Randomizer) — voltou tudo certo no `salvar_fluxo`, nenhum tipo foi rejeitado ou
  corrompido.
- **Tela de criar/editar bot (redesign de hoje):** confirmado no HTML que o card "Criar
  automaticamente", o botão real "Abrir BotFather", o tutorial de 5 passos e o aviso
  "Mantenha seu token seguro" aparecem certos no modo de criação.
- **Ícones nas listagens (troca de hoje):** confirmado no HTML de `admin/comunidade.php`,
  `admin/ranking.php` e `webhooks.php` que os botões saíram como `btn-icon
  editar`/`excluir`/`ativar`/`desativar`, sem texto solto "Editar"/"Excluir" na tabela.
- **Modal de confirmação em Configurações (de hoje):** confirmado no HTML que o modal
  existe com o texto certo pros dois formulários (identidade visual / Google).
- **CSRF:** confirmado que `window.CSRF_TOKEN` está presente e que as ações de POST
  recusam requisição sem o token certo.
- **API pra conta nova (zero dados):** `listar_bots`, `listar_fluxos`,
  `listar_grupos_usuario` devolvem array vazio limpo, sem erro, pra usuário recém-criado.

## ❓ Não testado (precisa de navegador real ou token real do Telegram)

- **Fluxo de criar bot de verdade** — `salvar_bot`/`testar_bot` exigem um token válido
  de verdade (o backend valida contra a API do Telegram antes de aceitar); não dá pra
  simular isso sem um bot real do BotFather.
- **Conversa real passando pelos 4 blocos novos** (Randomizer/Upsell/Downsell/Order
  Bump) — só validei que salvam/carregam certo (dado), não que o webhook.php executa
  certo num update de verdade do Telegram. A lógica pura (sorteio ponderado, resolução
  por conector) já tinha sido testada isolada via script CLI numa rodada anterior.
- **Cliques de verdade nos botões de ícone novos** (Ativar/Desativar/Excluir em
  admin/comunidade, admin/ranking, webhooks, gateways) — só confirmei que renderizam
  certo no HTML; não cliquei de verdade pra não mexer em conteúdo global/real do site
  (links da Comunidade e campanhas de ranking são vistos por todo mundo).
- **Tudo que depende de tela/JS/CSS visual** (drag-and-drop no editor de fluxo, zoom,
  modais abrindo/fechando, responsividade mobile) — não tenho navegador aqui, só HTTP
  puro. Precisa de um passe visual manual.
- **Pagamento real via OmegaPayments** — já documentado como bloqueado em
  `anotacoes/pendente/pendencias-sandbox-omegapayments.md`, continua bloqueado.

---

## Rodada 2 — segurança (mesmo dia, sessão separada)

Foco em segurança de verdade: 2 contas de teste descartáveis (A e B) pra testar acesso
cruzado entre contas, tentativas de XSS/SQLi/upload malicioso, força bruta de login e
controle de acesso. As duas contas foram apagadas no fim (cascata apaga os dados de
teste junto). Nenhum dado de usuário real foi tocado.

### ✅ Verificado seguro — nenhum problema novo encontrado

- **IDOR em fluxos:** criei um fluxo com a conta A, tentei ler/editar/excluir/exportar
  com a conta B (`obter_fluxo`, `salvar_fluxo`, `excluir_fluxo`, `exportar_fluxo`) — as
  4 tentativas voltaram `"Fluxo não encontrado."` (404). Toda query já é escopada por
  `id_usuario`, como devia ser.
- **IDOR em bots:** `obter_bot` com ID de bot que não é da conta logada também devolve
  "não encontrado", mesmo padrão.
- **Upload de imagem disfarçada de outra coisa:** subi um `.php` com payload
  (`system($_GET['c'])`) fingindo ser `.jpg` no upload de imagem do fluxo — rejeitado
  (`"Arquivo de imagem inválido."`, 422). Conferido no código
  (`api.php:738-757`): usa `getimagesize()` de verdade (não confia no Content-Type
  enviado) **e** força a extensão salva pra `jpg/jpeg/png` mesmo que o nome original
  seja outro — dois níveis de proteção, não só um.
- **SQL Injection:** payload clássico (`' OR '1'='1`, `' UNION SELECT 1-- -`) na busca
  de leads e no filtro de TXID do admin — sem erro de SQL vazando, sem comportamento
  anômalo. Bate com o padrão do projeto de sempre usar PDO com prepared statements.
- **Autenticação:** `api.php` sem nenhuma sessão devolve 401 limpo. Usuário comum
  tentando abrir página de admin é redirecionado (`index?erro=sem_permissao`), não vê
  nada da tela.
- **Cookie de sessão:** `Set-Cookie` já vem com `secure; HttpOnly; SameSite=Lax`.
- **Força bruta de login:** 6 tentativas erradas seguidas contra `admin@admin.com` —
  bloqueou na 6ª ("Muitas tentativas"), confirmando o limite de 5 já documentado. Nota:
  isso deixou `admin@admin.com` temporariamente bloqueado por até 15 min (efeito
  colateral do teste, some sozinho — sessão de admin já aberta não foi afetada,
  confirmei que continuou funcionando normalmente).
- **Senha fraca no cadastro:** senha de 1 caractere foi rejeitada com mensagem clara.
- **`webhook.php` com entrada hostil:** corpo vazio, JSON malformado e requisição sem
  token na URL — nenhum dos três derruba o script; todos respondem 200 com "token
  ausente" (correto pro Telegram não ficar reenviando pra sempre).
- **Página "Comunidade":** ao testar achei que fosse pública (like um Linktree
  compartilhável) e não é — sem login, `comunidade.php` redireciona pra `/login`.
  Testando o código, isso bate com a descrição original da feature ("conteúdo
  institucional da plataforma", pros usuários da plataforma, não pros clientes finais
  deles) — **não é bug, era suposição errada minha**, registrando só pra não repetir a
  dúvida depois. Admin acessando a mesma URL é corretamente redirecionado pro próprio
  dashboard (não vê a versão pública).

### ❓ Não testado nesta rodada (fica pra próxima, se quiser continuar)

- XSS em outros pontos que ainda não conferi diretamente (ex.: `admin/logs.php`,
  descrição de campanha de ranking, bio/descrição de bot no perfil do Telegram).
- Rate limit especificamente no fluxo de recuperação de senha (só testei o de login).
- Abuso de regra de negócio (ex.: valor de plano negativo, split acima de 100%, datas
  de campanha invertidas) — não tentei quebrar validação numérica/lógica ainda.
- `ajax/editar_usuario.php` (admin editando outro usuário) — não tentei escalonar
  privilégio nem trocar `perfil` de forma indevida.
- Reuso/expiração de token CSRF entre sessões.

---

## Rodada 3 — continuando (mesmo dia)

Foco: XSS em mais pontos onde nome/texto de usuário é ecoado, principalmente em telas
que o **admin** vê (maior impacto), e o começo de testar abuso de regra de negócio.

### 🔴 Achado grave — ver seção "0" no topo do documento

Testando exatamente esse ponto (nome de usuário ecoado em `admin/usuarios.php`) achei o
XSS armazenado documentado na seção 0 — subi pro topo do arquivo por ser o achado mais
sério desta rodada toda (compromete sessão de admin, sem precisar de privilégio nenhum).

### ❓ Ainda não testado (continua pra próxima rodada, se pedir)

- Abuso de regra de negócio (valor de plano negativo, split acima de 100%, datas de
  campanha invertidas).
- Rate limit da recuperação de senha.
- Escalonamento de privilégio via `ajax/editar_usuario.php`.
- Outros pontos de eco de nome/texto de usuário fora de `admin/usuarios.php` (ex.: se
  algum outro lugar do admin também usa nome do usuário dentro de `onclick=`).

---

## Rodada 4 — jornada real de usuário (dado de verdade, não só "não quebrou")

As rodadas anteriores testaram muita coisa pontual (segurança, casos-limite). O pedido
aqui foi diferente: **coisas grandes que um usuário de verdade encontra** — filtro que
não filtra, página quebrada, integração que não manda o dado certo. Pra isso, criei uma
conta de teste com **dado real de verdade** (não só conta vazia): um bot de teste, 30
leads espalhados em datas diferentes, 8 vendas pagas e 3 pendentes — e simulei uma
mensagem `/start` de verdade chegando pelo Telegram (webhook.php de verdade, não só a
API interna). Tudo apagado no fim.

### ✅ Verificado funcionando de verdade (não só "não deu erro")

- **Busca de leads:** procurei por um nome específico entre os 30 — achou exatamente os
  3 leads certos, nenhum a mais.
- **Filtro de status pago/não pago:** `status=pago` trouxe exatamente as 8 vendas que
  marquei como pagas; `status=nao_pago` trouxe exatamente as outras 22 leads, sem
  misturar.
- **Paginação de leads:** 30 leads, 25 por página — página 1 trouxe 25, página 2 trouxe
  os 5 restantes, certinho.
- **Exportação CSV:** as 30 linhas exportadas batem exatamente com os dados inseridos.
- **Filtros do admin/transações:** status, busca por TXID e intervalo de data —
  testei os três contra os dados reais, todos trouxeram exatamente o que deviam.
  (Nota: o filtro de data pareceu errado numa primeira tentativa — na real era o
  relógio da minha própria máquina de teste, 1 dia atrasado do servidor. Conferi o
  `NOW()` real do servidor antes de concluir qualquer coisa, e o filtro estava certo o
  tempo todo.)
- **Webhook de saída, de ponta a ponta, de verdade:** cadastrei um webhook apontando pra
  um endpoint de captura real (webhook.site), simulei um `/start` de um "cliente" novo
  batendo direto no `webhook.php` (o mesmo arquivo que recebe update de verdade do
  Telegram) e conferi a entrega real no endpoint de captura. **Chegou**, em tempo real,
  com o evento certo (`user_joined`) e os dados do bot/lead certos.

### ✅ CORRIGIDO — webhook de saída manda `customer.username` sempre vazio

**Onde:** `funcoes/webhooks.php` (linhas 325-326, monta o payload) e `webhook.php`
(linha 952, cria o lead).

**Reproduzido:** no teste acima, mandei um `/start` simulado com
`"username": "cliente_webhook_teste"` (campo que o Telegram manda de verdade em todo
`message.from`). O payload que chegou no endpoint de captura veio assim:

```json
"customer": {"id":7092396,"telegram_id":"8888888888","first_name":"Cliente Webhook Teste","last_name":null,"username":null,"phone":null,"email":null,"is_vip":false}
```

`username` (e `last_name`) sempre `null` — não é bug de "esqueceu de colocar no
payload", é mais fundo: a tabela `leads` **nunca teve coluna pra isso**. O
`INSERT INTO leads` em `webhook.php:952` só grava `id_telegram, nome,
origem_rastreio, bot_id, criado_em` — o `username` que o Telegram manda em todo
`/start` é descartado ali mesmo, na entrada, nunca chega a ser salvo em lugar nenhum.
`last_name` é um pouco diferente: ele é somado dentro de `nome` (nome completo junto),
não é bug, é simplificação de propósito.

**Por que importa:** quem integra via webhook (CRM externo, automação de marketing)
recebe o registro do lead sem o `@username` do Telegram — que na prática é o dado mais
útil pra alguém conseguir abrir uma conversa direta com aquele lead fora do bot (o
`telegram_id` numérico sozinho não abre chat em lugar nenhum). Pra corrigir de verdade
precisaria de uma coluna nova em `leads` (ex. `nome_usuario_telegram`) + salvar ela no
INSERT do `/start` + usar ela no payload — não é 1 linha, mas também não é grande.

**Status: corrigido, migração rodada e no ar.** Coluna `leads.nome_usuario_telegram`
criada, `webhook.php` passou a capturar no `/start` (lead novo grava direto; lead que já
existia atualiza se o username mudou ou nunca tinha sido capturado). Reconfirmado ao
vivo com um bot/webhook de teste novo: `/start` simulado com `username: "novo_cliente_ok"`
→ payload chegou no endpoint de captura com `"username":"novo_cliente_ok"` (antes vinha
sempre `null`). Dados de teste já apagados.

### 🟡 Nota — falta a "faixa de totais" do admin/transações

O design de referência (`design_handoff_coyote_bot_panel/README.md`, seção "Admin ·
Transações") descreve uma faixa de totais (Volume filtrado, Pagos, Expirados, Split
pendente) acima da tabela — não encontrei isso implementado na tela hoje. Não testei
mais fundo se é falta de implementação ou eu não encontrei; registrando pra conferir
depois. Não é urgente (informação de resumo, não afeta os filtros em si, que funcionam).

## Notas relacionadas

- `anotacoes/pendente/plano-expansao-editor-fluxo.md` — blocos novos do editor de nós.
- `anotacoes/pendente/plano-modo-basico-fluxo.md` — modo Básico.
- `anotacoes/pendente/pendencias-sandbox-omegapayments.md` — bloqueio de pagamento real.
