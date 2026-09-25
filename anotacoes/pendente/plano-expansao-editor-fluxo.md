## ✅ Executado em 2026-09-25 (blocos 2.1–2.3) — ver nota no fim do documento

# Plano — expandir o editor de fluxo (nós que faltam vs. SharkBot)

> Plano de implementação, ainda não codado. Baseado em
> `anotacoes/pendente/criacao-fluxos-sharkbot.md` (pesquisa ao vivo do concorrente,
> 25/09/2026) comparado com o estado atual do nosso editor (levantamento feito na mesma
> data). Escopo decidido com o usuário: só a frente **A** das 3 possíveis (expandir o
> editor de nós existente) — **não** inclui o modo "Básico" guiado por formulário nem o
> "Modo IA" (agente conversacional com créditos), que ficam para decisão futura.

## O que já temos vs. o que falta

Nosso editor (`fluxo.php` + `assets/edicao_fluxo.js`, motor `flowchart.js`, execução em
`webhook.php`) já cobre o equivalente ao "Fluxo n8n" da Shark, só que com menos blocos:

| Bloco | Temos hoje | Shark tem |
|---|---|---|
| Mensagem / Mídia / Botões / Delay ("digitando") | ✅ | ✅ |
| PIX / pagamento | ✅ (`pix`, nome+valor+recorrência) | ✅ (`charge`, similar) |
| Entrega (link / grupo) | ✅ (`link`, `grupo`) | ✅ (`send_delivery`, mais tipos de destino) |
| **Condição** (aguardar resposta, com timeout) | ❌ | ✅ |
| **Randomizer** (caminho aleatório ponderado) | ❌ | ✅ |
| **Input do usuário** (captura resposta numa variável) | ❌ | ✅ |
| **Upsell / Downsell** (nó dedicado, aceito/recusado) | ❌ | ✅ |
| **Order Bump** (oferta extra no checkout) | ❌ | ✅ |

## Achado importante que muda o tamanho do trabalho

O motor de execução hoje (`webhook.php`) **não guarda em lugar nenhum "em que nó da
conversa o lead está".** Quando chega um clique de botão, o callback_data é só o *texto*
do botão — o webhook varre **todos os operadores do grafo** (`foreach
$dados_fluxo['operators']`) procurando um bloco `botoes` que tenha um botão com aquele
texto, e segue o link de saída correspondente. Funciona hoje porque tudo que espera
resposta (`botoes`, `pix`) é sempre resolvido por um **clique** (callback_data
determinístico), nunca por texto livre.

Isso quebra pros dois blocos mais "caros" da lista:

- **Input do usuário** precisa capturar **texto livre** (não um clique) e saber pra qual
  variável salvar — não dá pra "adivinhar" isso varrendo o grafo, porque texto livre não
  carrega identificador nenhum do bloco que pediu ele.
- **Condição** com timeout (`not_responded`) precisa saber que **passou tempo demais**
  sem resposta — isso não existe hoje (nada monitora "lead parado há N minutos nesse
  nó"), e só um cron pode detectar isso, não o webhook (que só roda quando o Telegram
  manda algo).

**Ou seja: esses dois blocos exigem uma peça de infraestrutura nova — estado por lead —
que os outros 3 não exigem.** Detalhado na Seção 3.

## Ordem sugerida (do mais barato pro mais caro)

1. **Randomizer** — sem espera, resolve na hora, reaproveita 100% o padrão de link/saída
   que já existe (só que a saída é sorteada por peso em vez de fixa).
2. **Upsell / Downsell** — reaproveita o padrão de saída por clique de botão que já existe
   no `botoes`/`pix` (aceito/recusado = 2 botões com callback_data fixo).
3. **Order Bump** — mesmo padrão dos dois acima, oferta extra amarrada a um `pix`/plano.
4. **Condição** (variante `clicked_button`/`paid`/`not_paid`) — ainda cabe no modelo
   atual (decide na hora, sem esperar nada novo, só reorganiza uma decisão que hoje é
   implícita no fluxo do `pix`).
5. **Input do usuário** + **Condição** (variante `responded`/`not_responded` com timeout)
   — exige a peça de estado por lead (Seção 3). Maior risco/esforço, deixar por último.

---

## Seção 2 — Blocos 1 a 4 (sem infraestrutura nova)

### 2.1 Randomizer

- **Editor** (`assets/edicao_fluxo.js`): novo `type: 'randomizer'`, ícone em
  `block_icons`, formulário do bloco com lista de "caminhos" (`path_1`, `path_2`, ...),
  cada um com um peso (%). Saída (`outputs`) dinâmica: uma por caminho, no padrão
  `output_path_N` (mesmo esquema de `botoes` já usa `output_N` por botão).
- **Execução** (`webhook.php`): ao alcançar um nó `randomizer`, sorteia um caminho
  ponderado (`mt_rand`/soma acumulada dos pesos) e segue o link cujo `fromConnector`
  seja o `output_path_N` sorteado — mesma mecânica do salto de `output_pago`/
  `output_nao_pago` que o `pix` já faz hoje (linha ~840-848), só trocando "decisão por
  status" por "decisão por sorteio".
- **CSS**: classe `.no-randomizer` em `fluxograma_tema.css`, seguindo o padrão visual
  dos blocos existentes.

### 2.2 Upsell / Downsell

- **Editor**: `type: 'upsell'` / `type: 'downsell'`, campos: mensagem, desconto (%),
  qual plano oferecer (reaproveita a mesma referência de plano que o `pix` usa — como
  não existe um cadastro de "planos" separado, o campo aponta pro texto/valor livre,
  igual o `pix` já faz). Dois botões fixos no bloco (texto editável, callback_data fixo
  tipo `upsell_aceitar_<id_bloco>`/`upsell_recusar_<id_bloco>`), saídas
  `output_aceito`/`output_recusado`.
- **Execução**: reaproveita o mesmo mecanismo de busca-por-texto-de-botão que `botoes`
  já usa hoje — nenhuma peça nova de estado, só um novo `type` reconhecido no `switch`
  de `processarEEnviarBloco()` e no loop de resolução de callback.
- **Diferença real pro Shark**: lá, upsell/downsell tem uma "sequência" de até 20
  tentativas com espera entre elas (reenvio automático se não responder). **Isso
  também precisaria da peça de estado da Seção 3** (saber quando reenviar) — decisão:
  entrar já na v1 como um nó único disparado manualmente no grafo (sem sequência
  automática), e a sequência com reenvio fica de fora até (ou se) o trabalho da Seção 3
  for feito.

### 2.3 Order Bump

- Igual ao upsell/downsell em mecânica (oferta + aceitar/recusar por botão), mas
  pensado pra encaixar antes da confirmação de um `pix` (ex.: "quer adicionar X por mais
  R$Y?" antes de gerar a cobrança) em vez de depois da compra.
- **Editor/execução**: mesmo padrão da 2.2, só muda o texto/posição sugerida no fluxo
  (é o próprio usuário que decide onde encaixar o bloco no grafo, arrastando).

### 2.4 Condição (variantes sem timeout: `clicked_button`, `paid`, `not_paid`)

- **Editor**: `type: 'condicao'`, campo select com o tipo (`clicked_button`, `paid`,
  `not_paid` nesta primeira leva), duas saídas fixas `output_sim`/`output_nao`.
- **Execução**: `paid`/`not_paid` já é literalmente o que o `pix` resolve hoje (saída
  `output_pago`/`output_nao_pago`) — aqui vira um nó reutilizável e explícito em vez de
  ficar embutido só no `pix`. `clicked_button` verifica se o parâmetro que chegou bate
  com algum botão específico anterior (checagem local, sem esperar nada novo).
- **Não entra nesta leva**: `responded`/`not_responded` (dependem de timeout — Seção 3).

---

## Seção 3 — Infraestrutura nova: estado do lead no fluxo

Necessária só para: **Input do usuário**, e **Condição** nas variantes `responded`/
`not_responded`. Proposta:

### Schema

Nova tabela `leads_estado_fluxo` (1 linha por lead ativo esperando algo):

```sql
CREATE TABLE leads_estado_fluxo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_id INT NOT NULL,
    id_telegram VARCHAR(50) NOT NULL,
    id_operador_aguardando VARCHAR(100) NOT NULL,   -- nó que está esperando
    tipo_espera ENUM('input_usuario','condicao_timeout') NOT NULL,
    nome_variavel VARCHAR(80) DEFAULT NULL,          -- só pra input_usuario
    expira_em DATETIME DEFAULT NULL,                 -- só quando há timeout
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (bot_id, id_telegram),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
);
```

`UNIQUE KEY (bot_id, id_telegram)` — um lead só pode estar esperando **uma** coisa por
vez (se um novo nó de espera for alcançado, substitui o anterior).

Variáveis capturadas por `Input do usuário` (`user_input`) precisam de onde morar —
proposta: coluna `leads.variaveis_fluxo JSON DEFAULT NULL` (mapa `nome_variavel =>
valor`), lido/escrito pelo webhook e disponível como `{variavel}` nos textos dos blocos
seguintes (mesmo padrão de variável tipo `{nome}` que já existe hoje).

### Execução

- Ao alcançar `user_input` ou `condicao` (variante com timeout): grava linha em
  `leads_estado_fluxo` com o `id_operador_aguardando` = o nó atual, `expira_em` = agora +
  timeout configurado, e **para** de processar (não envia mais nada, só a pergunta).
- Nova mensagem de texto chega no webhook: antes do fluxo normal, checa se existe linha
  em `leads_estado_fluxo` pro `(bot_id, id_telegram)`. Se existir:
  - `input_usuario`: salva o texto em `leads.variaveis_fluxo[nome_variavel]`, apaga a
    linha de espera, segue pelo `output_sucesso` do nó.
  - `condicao_timeout`: texto chegou = "respondeu", segue `output_sim`, apaga a linha.
  - Se não existir linha de espera, segue o comportamento atual (busca no grafo, /start,
    etc.) sem mudança.
- **Novo cron** `cron_verificar_timeouts_fluxo.php` (padrão dos outros 8 crons já
  existentes: `flock`, proteção por `CHAVE_SECRETA_CRON`/CLI, `LIMIT` na varredura):
  roda a cada 1 minuto, busca `leads_estado_fluxo` com `expira_em < NOW()` e
  `tipo_espera = 'condicao_timeout'`, segue o `output_nao` do nó (timeout = "não
  respondeu"), apaga a linha. Linhas de `input_usuario` sem timeout configurado nunca
  expiram sozinhas (ficam esperando pra sempre, comportamento aceito — igual o `botoes`
  já faz hoje).

### Por que isolar isso numa seção própria

Esse pedaço é o único que precisa de: tabela nova, coluna nova em `leads`, um cron a
mais, e uma mudança na ordem de leitura do `webhook.php` (checar espera pendente antes
do resto). Os blocos 2.1-2.4 não tocam em nada disso — só adicionam `type` novo no
`switch` já existente. Por isso a sugestão de ordem (Seção 1) deixa isso por último: dá
pra entregar valor real (randomizer, upsell/downsell, order bump, condição básica) sem
esperar essa parte mais arriscada ficar pronta.

---

## Arquivos a editar (resumo)

| Arquivo | O que muda |
|---|---|
| `assets/edicao_fluxo.js` | Novos `type` nos templates de nó, ícones, formulário embutido por tipo, cálculo de outputs dinâmicos (randomizer) |
| `assets/fluxograma_tema.css` | Classes visuais dos 5 blocos novos |
| `webhook.php` | Novo `case` em `processarEEnviarBloco()` por tipo; checagem de `leads_estado_fluxo` no início do tratamento de mensagem de texto; leitura/escrita de `leads.variaveis_fluxo` |
| `admin/atualiza_banco.php` | Migração idempotente: tabela `leads_estado_fluxo`, coluna `leads.variaveis_fluxo` |
| `instalacao.php` | Mesmo schema, pra instalação nova já nascer com isso |
| `cron/cron_verificar_timeouts_fluxo.php` | Novo arquivo — cron de timeout (só necessário se a Seção 3 for implementada) |
| `INSTALACAO.md` | Adicionar o novo cron na tabela da Seção 7, se a Seção 3 entrar |

## Decisões que ainda precisam de confirmação antes de codar

1. **Confirma a ordem sugerida** (2.1→2.4 primeiro, Seção 3 por último), ou prefere
   tudo de uma vez / outra ordem?
2. **Upsell/Downsell/Order Bump na v1 sem sequência automática de reenvio** (só o nó
   único no grafo) — aceitável, ou a sequência automática (que exige a Seção 3) é
   importante já de início?
3. Nome exato da tabela/coluna novas (`leads_estado_fluxo`, `leads.variaveis_fluxo`) —
   só pra confirmar que não colide com nada que já exista com nome parecido.

## Notas relacionadas

- `anotacoes/pendente/criacao-fluxos-sharkbot.md` — pesquisa completa da Shark (fonte).
- `anotacoes/pendente/plano-recursos-sharkbot.md` — plano de outras features da Shark
  (Stories, Comunidade, Webhooks, etc.), a maioria já implementada; este documento é
  específico do editor de fluxo, que ficou de fora daquele.

## ✅ Executado em 2026-09-25 — blocos 2.1 a 2.3

Implementados **Randomizer**, **Upsell**, **Downsell** e **Order Bump** (2.1–2.3 da
Seção 2), com a ordem sugerida respeitada. Não commitado/deployado ainda.

**Arquivos alterados:**
- `webhook.php` — novo `case` por tipo em `processarEEnviarBloco()`; nova função
  `proximoNoConsiderandoTipo()` (sorteio ponderado do randomizer); nova função
  `obterProximoNoPorConector()` (resolução por saída específica, reaproveitando o que já
  existia inline pro `pix`); nova função `caminharFluxoAPartirDe()` que **substitui os 3
  loops de caminhada que estavam duplicados** (início, resposta de botão, pagamento
  confirmado) por um só — reduz código e evita repetir a lista de tipos que "param a
  execução" em 3 lugares diferentes; novo bloco de interceptação de callback `saida::`
  pro clique de aceitar/recusar do upsell/downsell/order_bump.
- `assets/edicao_fluxo.js` — ícones, `nodeTemplate()`, `renderCorpoDoBloco()`, mapa de
  classe CSS, e handlers de formulário pros 4 blocos novos (add/remover caminho do
  randomizer, campos de mensagem/valor/textos de botão da oferta).
- `fluxo.php` — 4 novos botões na paleta.
- `assets/fluxograma_tema.css` — classes visuais dos 4 blocos novos (randomizer reusa a
  cor neutra do delay/link; upsell verde; downsell vermelho; order bump laranja, mesma
  cor do "Botões" mas ícone diferente).

**Decisão de performance/escalabilidade tomada durante a implementação:** o bloco
"Botões" resolve o clique varrendo **todos os operadores do grafo** procurando o texto
do botão (O(nós) a cada clique, e frágil se dois botões em nós diferentes tiverem o
mesmo texto). Os 3 blocos novos **não repetem esse padrão** — o `callback_data` já
carrega o id do próprio bloco (`saida::<id_operador>::aceito`), então resolver o clique
vira uma busca direta pela chave, sem varrer nada. Não mudei o bloco "Botões" existente
(evitar quebrar fluxos antigos já salvos com aquele formato), mas os blocos novos já
nascem no padrão melhor.

**Escopo intencionalmente deixado de fora (não é bug, é decisão):** o bloco **Condição**
(2.4) não foi implementado. Motivo: nas variantes `paid`/`not_paid` ele duplicaria a
saída dupla que o próprio `pix` já tem; na variante `clicked_button` duplicaria a saída
por botão que o próprio `botoes` já tem. Como nó *independente* solto no grafo, sem a
infraestrutura de estado por lead da Seção 3, ele não agrega nada que os blocos que já
existem não resolvam — implementar mesmo assim seria adicionar um bloco confuso/redundante
na paleta só pra "bater a lista". Fica pra quando (ou se) a Seção 3 for implementada, onde
aí sim ele ganha sentido próprio (`responded`/`not_responded` com timeout de verdade).

**Não testado ao vivo ainda** — só lint (`php -l`, `node --check`), sem teste funcional
num bot real. Próximo passo antes de considerar pronto: criar um fluxo de teste com os 4
blocos novos, vincular a um bot de teste, e validar cada saída manualmente.
