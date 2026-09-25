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

### 1. Erro de token de bot aparece em inglês ("Unauthorized") em vez de português

**Onde:** `api.php`, ações `testar_bot` (linha ~506) e `salvar_bot` (linha ~523).

**Reproduzido:** colei um token de bot inválido e chamei as duas ações — as duas
devolveram `{"sucesso":false,"mensagem":"Unauthorized"}`, cru, em inglês, misturado no
meio de uma interface 100% em português.

**Causa:** as duas ações fazem `$vivo['description'] ?? 'mensagem em português de
fallback'` — mas a API do Telegram **sempre** devolve uma `description` quando o token é
rejeitado (`getMe` retorna `{"ok":false,"error_code":401,"description":"Unauthorized"}`),
então o fallback em português nunca dispara na prática. É o caso mais comum de erro
nessa tela (usuário cola token errado ou incompleto) sempre aparecendo em inglês.

**Sugestão pra quando for corrigir:** mapear os `description` mais comuns do Telegram
(`Unauthorized` → "Token inválido ou revogado.", `Not Found` → "Bot não encontrado.")
antes de usar o texto cru, mantendo o cru só como último recurso.

### 2. "+0,0%" no dashboard aparece destacado como se fosse alta (verde)

**Onde:** `index.php`, linha ~507: `$variacao_percentual >= 0 ? 'selo-variacao-positivo'
: 'selo-variacao-negativo'`.

**Reproduzido:** conta nova, sem venda nenhuma em nenhum período — o selo de variação
mostra **"+0,0% vs período anterior"** com o estilo verde de "coisa boa aconteceu",
quando na real não houve variação nenhuma (não tem dado nos dois períodos pra comparar).

**Impacto:** baixo, cosmético — mas destacar "+0,0%" em verde é visualmente enganoso.
Um estado neutro (cinza, sem sinal de +) faria mais sentido pra exatamente 0%.

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

## Notas relacionadas

- `anotacoes/pendente/plano-expansao-editor-fluxo.md` — blocos novos do editor de nós.
- `anotacoes/pendente/plano-modo-basico-fluxo.md` — modo Básico.
- `anotacoes/pendente/pendencias-sandbox-omegapayments.md` — bloqueio de pagamento real.
