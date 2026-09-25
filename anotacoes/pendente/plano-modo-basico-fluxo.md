# Plano — modo "Básico" (funil guiado por formulário)

> Frente B do plano geral (`anotacoes/pendente/criacao-fluxos-sharkbot.md`,
> `anotacoes/pendente/plano-expansao-editor-fluxo.md`). Decidido com o usuário: B antes
> de C (Modo IA), e a decisão de cobrança do Modo IA fica pra quando chegar lá.

## Ideia central

Hoje só existe 1 tipo de fluxo (o editor de nós/canvas). Vamos ter 2: o de nós continua
existindo do jeito que está (renomeado internamente pra `modo = 'avancado'`, sem quebrar
nada que já existe), e um novo `modo = 'basico'` — uma tela de formulário por seções em
vez de canvas, pra quem não quer montar fluxograma.

### Decisão de schema: reaproveitar `dados_fluxograma`, não criar tabelas novas

`fluxos.dados_fluxograma` já é um LONGTEXT livre (JSON). Em vez de criar várias tabelas
novas (`fluxos_planos`, `fluxos_upsell`, etc.) — que exigiriam joins extras em toda
leitura de fluxo e mais uma camada de migração — o modo básico guarda sua configuração
inteira nesse mesmo campo, só que com um formato de JSON diferente (objeto de seções em
vez de `{operators, links}`). Mesma lógica que já vale pro modo avançado: 1 fluxo = 1
blob. Mantém `api.php` (`salvar_fluxo`/`obter_fluxo`) igual, sem mudança de contrato.

Única coluna nova: `fluxos.modo ENUM('avancado','basico') NOT NULL DEFAULT 'avancado'`.
Todo fluxo existente já nasce `avancado` (default), zero migração de dado.

## Fatia 1 (esta rodada) — vertical, funcionando ponta a ponta

Prioridade: entregar um caminho **completo e testável**, não as 13 seções da Shark de
uma vez. Fica de fora desta fatia (documentado na Seção "Fora desta fatia" abaixo):
Upsell/Downsell/Order Bump automáticos, Packs, Prévias, Assinatura (renovação fora do
grafo), Top Assinantes, Conversões, estilo de Botões.

**Dentro desta fatia:**

1. **Escolha de modo ao criar fluxo** — `fluxos.php`, botão "Criar Fluxo" abre uma
   escolha simples (2 cards: "Editor Visual" = o que já existe, "Guiado" = básico) antes
   de cair na tela de edição. Resolve o que o usuário via na print da Shark.
2. **Tela do editor básico** (`fluxo_basico.php`, nova) — sidebar de seções, 4 primeiras:
   - **Bots**: vincular bot(s) a este fluxo, canal de cache de mídia (igual o que a Shark
     mostrou, adaptado ao nosso modelo de 1 fluxo → N bots via `id_fluxo_conectado`).
   - **Boas-vindas**: mensagem inicial, mídia opcional, texto do botão CTA.
   - **Planos**: lista de planos (nome, preço, dias de acesso, grupo de entrega) —
     substitui o nó PIX único do modo avançado por uma lista de opções que o cliente
     escolhe.
   - **Pagamentos**: mensagem de "Pix gerado" e "Pagamento aprovado".
3. **Execução em `webhook.php`** — novo branch `if (($fluxo['modo'] ?? 'avancado') ===
   'basico')`, motor próprio e simples (sem grafo): `/start` → manda boas-vindas → manda
   lista de planos como botões → clique gera Pix do plano escolhido → paga → entrega no
   grupo configurado. Reaproveita as funções que já existem (`getUserGateways`,
   `resolveGatewayProvider`, `getGatewaySplit`, criação de invite link) — só a
   orientação do fluxo é mais simples que o grafo.

## Fora desta fatia (documentado, não implementado agora)

- **Upsell/Downsell/Order Bump automáticos com sequência/reenvio** — no modo avançado já
  existem como nó manual (frente A, feito). No básico, a Shark tem isso como sequência
  configurável com reenvio por tempo — isso cai na mesma dependência de "estado por lead"
  já identificada em `plano-expansao-editor-fluxo.md` (Seção 3). Fica pra quando essa
  infra existir.
- **Packs** (produtos avulsos fora do funil principal) — seção adicional, baixo risco,
  mas fora da fatia 1 pra não inflar o escopo do primeiro corte.
- **Prévias** (mídia de amostra que se autodestrói) — depende de um job de expiração de
  mídia; nada parecido existe hoje.
- **Assinatura com renovação fora do grafo** — o sistema já tem renovação (crons
  `cron_renovacao.php`/`cron_aviso_vencimento.php`), mas a ideia da Shark de "grupo de
  destino diferente pra renovação" não existe — fica pra depois.
- **Top Assinantes** (ranking e prêmios por posição) — o projeto já tem um sistema de
  Ranking próprio (`ranking.php`, `cron_ranking.php`); antes de portar o da Shark vale
  entender se não é redundante com o que já existe.
- **Conversões** (funil e origens dentro do editor) — o projeto já tem
  `links_rastreamento`/Traqueamento como telas separadas; replicar dentro do editor de
  fluxo é decisão de produto (duplicar informação em 2 lugares?), não só código.
- **Estilo de Botões** (cor customizada por botão) — cosmético, baixa prioridade.

## Arquivos a criar/editar

| Arquivo | O que muda |
|---|---|
| `admin/atualiza_banco.php`, `instalacao.php` | Coluna `fluxos.modo ENUM('avancado','basico') DEFAULT 'avancado'` |
| `fluxos.php` / `assets/lista_fluxos.js` | Modal de escolha de modo ao criar; badge do modo no card da lista |
| `fluxo_basico.php` (novo) | Tela do editor guiado, 4 seções da fatia 1 |
| `assets/edicao_fluxo_basico.js` (novo) | Lógica de formulário — sem canvas, sem flowchart.js |
| `api.php` | `salvar_fluxo`/`obter_fluxo` passam a aceitar/devolver `modo`; `criar_fluxo` (ou equivalente) grava o modo escolhido |
| `webhook.php` | Novo branch de execução pro modo básico (função própria, ex. `executarFluxoBasico()`), sem tocar no motor de nós existente |

## ✅ Executado em 2026-09-25 — Fatia 1

Implementado o caminho ponta a ponta: escolha de modo ao criar → editor guiado (Bots
info / Boas-vindas / Planos / Pagamentos) → execução real no webhook (`/start` → boas-
vindas com botão → lista de planos → Pix do plano escolhido → pagamento confirmado →
acesso entregue). Não commitado/deployado ainda no momento de escrever esta nota.

**Decisão técnica que vale registrar:** a geração de Pix do modo básico **não duplica**
a lógica de gateway/split/fallback do bloco `pix` do editor de nós — monta um array de
propriedades sintético (`['type' => 'pix', 'nome' => ..., 'valor' => ..., ...]`) e chama
`processarEEnviarBloco()` direto, a mesma função que o editor de nós usa. Isso significa
que qualquer correção futura nessa lógica (troca de gateway, formato de payload, etc.)
vale automaticamente pros dois modos, sem precisar lembrar de mexer em 2 lugares.

**Confirmação de pagamento não precisou de nenhum código novo**: a entrega de acesso
(link de convite, `membros_grupos`, mensagem de confirmação) já roda pra **qualquer**
venda com `id_grupo_telegram` preenchido, independente de ter vindo de um nó de grafo —
o campo `id_operador_fluxo` (que só existe pro modo avançado) fica `NULL` numa venda do
modo básico, e o código que continua a caminhada do grafo já checava
`!empty($venda['id_operador_fluxo'])` antes de tentar continuar — condição que já é
falsa aqui, então nada extra precisou ser escrito.

**Achado incidental (não é bug meu, pré-existente):** os campos `msg_instrucoes` e
`msg_confirmado` do bloco PIX (editor de nós) nunca foram lidos na montagem da mensagem
em `webhook.php` — só existem no formulário do editor, sem efeito real. Os mesmos campos
na seção "Pagamentos" do modo básico herdam essa mesma limitação (ficam salvos, mas sem
efeito na mensagem de verdade, que é fixa). Não corrigido agora (fora do escopo desta
fatia); registrado aqui pra não parecer bug novo se alguém notar depois.

**Testado:** lint (`php -l`, `node --check`) em todos os arquivos, e teste isolado via
CLI da lógica de parse de `callback_data` (`basico::plano::<id>`) e busca do plano por
id. **Não testado ao vivo contra bot real ainda** — falta criar um fluxo básico de
verdade, vincular a um bot de teste, e validar a conversa ponta a ponta.

## Notas relacionadas

- `anotacoes/pendente/criacao-fluxos-sharkbot.md` — pesquisa completa da Shark (fonte).
- `anotacoes/pendente/plano-expansao-editor-fluxo.md` — frente A (blocos do editor de
  nós), já implementada.
