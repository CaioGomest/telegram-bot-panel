# Análise de escala e segurança nas vendas (700 usuários / ~120 vendas-dia cada)

Data: 2026-09-16. Investigação inicial (análise, sem alterar código) + rodada de correção logo em seguida, no mesmo dia, pros 3 itens de maior prioridade (confirmado com o Caio antes de mexer). Seção "O que foi corrigido" no fim do documento detalha o quê e como.

## Premissa de carga

700 usuários × ~120 vendas/dia = **~84.000 vendas/dia**, média de ~58/min (~1/seg). Isso é a média — o problema real de escala nunca aparece na média, aparece no **pico**: horário de maior conversão (ex. noite), promoção, ou um monte de PIX gerado às 15 minutos de expirar tudo ao mesmo tempo. É realista pensar em picos de 5 a 15 vendas/seg em alguns minutos do dia, cada uma disparando várias chamadas HTTP síncronas (Telegram + InfoPago mTLS + possivelmente Cash-Out).

Isso importa porque cada venda não é 1 operação — é uma cadeia:
`webhook Telegram → gera cobrança (mTLS InfoPago) → QR → cliente paga → webhook InfoPago (ou botão manual, ou cron) → reconsulta na API → grava pago → dispara split (Cash-Out, outra chamada mTLS) → cria invite link (Telegram) → revoga link antigo (Telegram) → envia mensagem (Telegram) → segue fluxo`.

Cada seta acima é uma chamada de rede síncrona, dentro do ciclo de vida de uma única requisição PHP. Isso é o ponto central de toda a análise abaixo: **o sistema não tem fila, não tem worker assíncrono, não tem cache intermediário — é 100% request-response síncrono em PHP procedural, rodando (segundo o `CLAUDE.md`) em hospedagem compartilhada Hostinger.**

## 🔴 Crítico — condição de corrida pode duplicar repasse de dinheiro real ✅ Corrigido

Existem **3 caminhos independentes** que podem marcar a mesma venda como paga e disparar o split:

1. `webhook_infopago.php` (notificação automática do gateway)
2. Botão "Já fiz o pagamento" em `webhook.php` (ação manual do cliente)
3. `cron/cron_verificar_pix.php` (varredura de fallback)

Os três seguem o mesmo padrão pra evitar duplicidade — "check-then-act" **não atômico**:

```php
$stmt_check = $pdo->prepare("SELECT status FROM vendas WHERE id = ?");
$stmt_check->execute([$venda['id']]);
if ($stmt_check->fetchColumn() === 'pago') { exit; } // já processado, para aqui

$pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ?")->execute([$venda['id']]);
// ... segue e chama dispararSplitInfopago() ...
```

O `SELECT` e o `UPDATE` são dois comandos separados, sem transação/lock entre eles. Em baixo volume isso "funciona na prática" porque a janela de corrida é de milissegundos e as duas confirmações raramente colidem. **Mas com 700 usuários gerando picos de vendas simultâneas, a chance de duas dessas três rotas confirmarem o mesmo pagamento no mesmo instante deixa de ser teórica** — cenário plausível: o webhook do InfoPago chega no exato momento em que o cliente clica "Já fiz o pagamento" (ele tende a clicar segundos depois de pagar, que é também quando o gateway manda o webhook). As duas requisições fazem o `SELECT` quase ao mesmo tempo, ambas veem `status='gerado'`, ambas passam, ambas fazem `UPDATE` (idempotente em si, sem problema), **mas ambas seguem o código adiante e chamam `dispararSplitInfopago()` duas vezes.**

E `dispararSplitInfopago()` (`funcoes/infopago_split.php`) **não tem nenhuma proteção de idempotência própria** — não verifica se aquela venda já teve split disparado antes de chamar `transferirPorChavePix()`. A chave `x-idempotency-key` que existe na chamada Cash-Out (`funcoes/infopago_cashout.php:196`, `bin2hex(random_bytes(16))`) é gerada **nova a cada chamada** — ela só protege contra a InfoPago duplicar uma retry de rede da *mesma* chamada HTTP, não protege contra o seu próprio código chamar a função duas vezes de propósito. Resultado prático: **repasse Pix duplicado de dinheiro real** para a chave configurada em `usuarios_splits`, mais mensagem de "acesso liberado" duplicada pro cliente, mais link de convite gerado duas vezes.

Esse é o achado mais grave desta análise porque é o único que perde dinheiro de verdade, e piora exatamente com o que o Caio está pedindo (mais volume = mais chance de colisão).

**Correção recomendada (concentra em um único ponto, sem tocar em 3 arquivos separados):** trocar o `UPDATE` solto por um `UPDATE ... WHERE id = ? AND status != 'pago'` e checar `rowCount()`. Só segue com split/notificação/liberação de grupo se `rowCount() === 1` — isso transforma a transição de status num compare-and-swap atômico garantido pelo próprio lock de linha do InnoDB, sem precisar de `SELECT FOR UPDATE` nem transação explícita. As 3 rotas (`webhook_infopago.php`, `webhook.php`, `cron_verificar_pix.php`) precisam da mesma mudança.

## 🔴 Alto — `vendas.transacao_id` sem índice (degrada exatamente com o crescimento que está vindo) ✅ Corrigido

Conferido em `admin/atualiza_banco.php`: a tabela `vendas` tem índices em `(id_telegram, bot_id, status)` e `(status, criado_em, bot_id)`, mas **nenhum índice em `transacao_id`** (nem em `id_assinatura`, usado no fluxo de PIX Automático/`cobsr`).

Toda notificação do `webhook_infopago.php` faz `SELECT * FROM vendas WHERE transacao_id = ?` — hoje, com a tabela pequena, é rápido. Mas com 84.000 vendas/dia isso significa **~30 milhões de linhas por ano** na tabela `vendas`. Sem índice, essa query é um *full table scan* que cresce linearmente com o histórico — ela vai ficando mais lenta mês a mês, exatamente na rota que precisa responder rápido (webhook de pagamento, onde o gateway tem timeout e pode reenviar se demorar). Pior: enquanto o scan roda, ele segura leitura consistente numa tabela que está recebendo `INSERT`/`UPDATE` o tempo todo, aumentando contenção de I/O.

**Correção recomendada:** `ALTER TABLE vendas ADD UNIQUE INDEX idx_transacao_id (transacao_id)` (único faz sentido — `txid` é gerado com `random_bytes(17)`, nunca deveria repetir; o índice único também vira uma segunda camada de proteção contra o cron e o webhook inserirem duas vendas com o mesmo txid por engano) e `ADD INDEX idx_id_assinatura (id_assinatura)`. Baixo risco, alto ganho, é só rodar no `atualiza_banco.php`.

Nota lateral, prioridade bem menor: `bots.token` também não tem índice — é a primeira query de **todo** update recebido de **todo** bot (`SELECT id_usuario FROM bots WHERE token = ?` em `webhook.php`). Com só ~700 linhas na tabela `bots` isso nunca vai doer de verdade (mesmo um full scan de 700 linhas é instantâneo), mas é grátis de corrigir junto.

## 🔴 Alto — `cron_verificar_pix.php` sem trava e sem lote, processa tudo sequencialmente ✅ Corrigido

Esse cron busca **todas** as vendas com `status='gerado'` (sem `LIMIT`, sem paginação) e, pra cada uma, faz **sequencialmente**: 1 chamada mTLS pra InfoPago (`consultarCobranca`, timeout de 30s configurado) + potencialmente várias chamadas ao Telegram (revogar link, criar link, mandar mensagem, seguir fluxo). Nada disso é paralelizado (`curl_multi` não é usado) nem tem `LIMIT`/paginação por execução.

Isso tem dois problemas que só aparecem em escala:

1. **Duração do cron cresce com o volume de vendas pendentes.** Se em algum momento houver, digamos, 500 vendas com `status='gerado'` esperando confirmação (perfeitamente possível com 84k vendas/dia e uma janela de expiração de 15 min), e cada consulta ao InfoPago levar even 300-800ms, o cron sozinho leva de 2,5 a 7 minutos pra terminar uma volta. Se ele estiver agendado a cada 1-5 min (comum pra esse tipo de verificação), **as execuções passam a se sobrepor**.
2. **Diferente de `cron_remarketing.php`, este arquivo não tem `flock`** (confirmado lendo o arquivo — sem nenhuma trava de exclusão mútua). Duas execuções sobrepostas processando a mesma lista de vendas pendentes ao mesmo tempo é exatamente o cenário que **alimenta a condição de corrida do item anterior** — e nesse caso não depende nem de coincidência com o webhook, o próprio cron pode duplicar contra ele mesmo.

Esse item também já estava listado na `varredura-06-cron-sem-autenticacao.md` pelo ângulo de "qualquer um pode chamar a URL" — aqui é o mesmo arquivo, mas o ângulo de escala é diferente e mais urgente: mesmo **sem** ataque nenhum, o próprio crescimento orgânico do negócio faz esse cron se sobrepor sozinho.

**Correção recomendada:** `flock` igual ao `cron_remarketing.php` (bloqueia sobreposição), `LIMIT` por execução (ex. processar no máximo 200 vendas pendentes por rodada, priorizando as mais antigas) pra manter a duração previsível, e aplicar a mesma correção atômica do item crítico acima (`UPDATE ... WHERE status != 'pago'` + `rowCount()`) como segunda camada de proteção mesmo que a trava falhe por algum motivo.

## 🟡 Médio — hospedagem compartilhada (Hostinger) é o teto real do sistema

O `CLAUDE.md` já registra que o destino é Hostinger compartilhada, sem framework/build step. Isso é uma decisão de custo/simplicidade totalmente razoável pro estágio atual, mas é preciso ser direto sobre a implicação: **hospedagem compartilhada tem limites duros de processos PHP-FPM simultâneos e conexões MySQL simultâneas** (tipicamente dezenas, não milhares), e **não costuma incluir fila de mensageria nem Redis/Memcached** nos planos mais comuns.

Como todo o fluxo de venda é síncrono (webhook → várias chamadas de rede → resposta), cada venda em andamento **segura um worker PHP-FPM e uma conexão MySQL abertos** pelo tempo total da cadeia de chamadas (potencialmente 1-3s por causa do mTLS + Telegram). Num pico de 10-15 vendas/seg simultâneas, isso pode facilmente esgotar o pool de workers do plano contratado — o sintoma prático seria requisições do painel (usuários logados) ficando lentas ou caindo com erro 503, bem na hora de pico de venda, e/ou o próprio Telegram/InfoPago recebendo timeout do webhook e reenviando a notificação (o que realimenta a condição de corrida do primeiro item).

Isso não é um bug pra "corrigir" no código — é uma decisão de infraestrutura que vale revisar **antes** de bater 700 usuários ativos de verdade, não depois que já estiver doendo. Duas frentes independentes, que podem ser adotadas uma de cada vez:

- **Curto prazo, sem sair da Hostinger:** plano com mais recursos (VPS Hostinger em vez de compartilhado) resolve o teto de PHP-FPM/MySQL sem reescrever nada.
- **Médio prazo, arquitetural:** tirar a geração de mensagens/split do caminho síncrono do webhook — ex. o webhook só grava a intenção (linha numa tabela de "jobs pendentes") e responde rápido pro gateway/Telegram, e um processo separado (outro cron rodando a cada poucos segundos, ou um worker de fila simples baseado em tabela) processa o envio de mensagens/split. Isso é uma mudança de arquitetura real, não uma tarde de trabalho — vale tratar como projeto à parte se/quando o volume justificar.

## 🟡 Médio — logs sem `LOCK_EX` e sem rotação, no caminho mais quente do sistema

Praticamente todo log de venda (`logWebhookInfopago()` em `webhook_infopago.php`, `logCron()` em `cron_verificar_pix.php`, o log de debug solto em `webhook.php:725`) usa `file_put_contents($arquivo, $msg, FILE_APPEND)` **sem `LOCK_EX`** — só `funcoes/infopago_split.php` usa `FILE_APPEND | LOCK_EX` corretamente. Sem o lock, duas requisições escrevendo no mesmo arquivo de log ao mesmo tempo (que vai ser rotina, não exceção, com 84k vendas/dia) podem intercalar linhas no meio da escrita, corrompendo o log bem no momento em que ele mais seria necessário pra investigar uma reclamação de cliente ou uma duplicidade de split.

Além disso, nenhum desses arquivos tem rotação (nem por tamanho, nem por data) — eles só crescem. `logs/webhook_infopago.log` grava o payload completo de cada notificação; em volume alto isso é um arquivo de várias dezenas ou centenas de MB por mês, sem nunca ser limpo, num plano de hospedagem que tem cota de disco.

**Correção recomendada:** adicionar `LOCK_EX` nos `file_put_contents` que faltam (mudança pequena, mecânica). Rotação pode ser tão simples quanto um cron semanal que renomeia/compacta o log atual e começa um novo, ou truncar se passar de um tamanho X.

## 🟡 Médio — mTLS sem verificar certificado do servidor (já mapeado, ganha peso com o volume)

Já registrado em [[como-funciona-pagamento-gateway]]: `VERIFICAR_CERTIFICADO_SERVIDOR = false` desliga `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` em toda chamada à InfoPago (cobrança e cash-out). Isso não é um problema *novo* de escala, mas o risco de exposição é proporcional ao volume que passa por esse canal — hoje 84.000 tentativas de conexão mTLS por dia estariam vulneráveis a MITM (rede comprometida entre o servidor Hostinger e a InfoPago) em vez de umas poucas por dia. Prioridade de correção não muda pela escala, mas o *custo de não corrigir* antes de escalar, sim.

## 🟢 O que já está bem pensado pra esse volume (positivo, vale registrar)

- **`ranking_cache`** (ver `CLAUDE.md`, seção Ranking) já foi desenhado explicitamente pensando em 700+ usuários — pré-cálculo em vez de `SUM(vendas)` ao vivo a cada carregamento de página. É o padrão certo, e o único lugar do sistema onde "escala" já foi tratado de propósito antes de virar problema.
- **Reconsulta obrigatória na fonte** antes de marcar como pago (não confia cegamente em nenhum dos 3 gatilhos) é a decisão de design certa contra fraude — o problema encontrado aqui não é *que* existem 3 caminhos de confirmação, é que eles não são mutuamente exclusivos a nível de banco.
- **Prepared statements** consistentes em todo o caminho de pagamento — nenhuma SQL injection encontrada na cadeia de vendas.
- **Split não bloqueia liberação de produto** em caso de falha — bom pra experiência do cliente final, mesmo que crie a lacuna de idempotência discutida acima.
- **Credenciais de gateway cifradas no banco** (ver [[criptografia-credenciais-gateway]]) — reduz o impacto de um vazamento de dump de banco justamente na tabela que teria as chaves de todos os 700 usuários.

## Como isso conversa com as varreduras já existentes

Nenhum achado novo aqui invalida ou repete as varreduras anteriores — são o mesmo tipo de atenção (segurança/correção), só que filtrados pela pergunta "o que quebra ou piora especificamente com 700 usuários / ~84 mil vendas por dia". Dois itens pendentes de varreduras antigas se tornam **mais urgentes** com esse contexto de escala, não é só "ainda não fiz":

- [[varredura-06-cron-sem-autenticacao]] — os crons abertos na internet, combinado com o fato de que `cron_verificar_pix.php` faz trabalho caro (uma chamada de API por venda pendente) sem `LIMIT`, significa que alguém martelando a URL não só desperdiça chamada de API à toa (como já registrado), mas pode **forçar sobreposição de execução de propósito**, provocando a condição de corrida do primeiro item deste documento sob demanda — deixa de ser só desperdício de recursos e passa a ser um vetor pra tentar duplicar split.
- CSRF em rotas administrativas (`varredura-02-upload-xss-csrf.md`) — não é um problema de volume de vendas em si, mas com 700 contas de usuário ativas, a superfície de quem pode ser vítima de um CSRF (ex. mudar configuração de split/gateway) cresce na mesma proporção.

## Prioridade sugerida de correção (visão consolidada)

1. ~~Transição de status atômica (`UPDATE ... WHERE status != 'pago'` + `rowCount()`) nas 3 rotas de confirmação~~ **Feito.**
2. ~~Índice único em `vendas.transacao_id` + índice em `id_assinatura`~~ **Feito.**
3. ~~`flock` + `LIMIT` em `cron_verificar_pix.php`~~ **Feito.**
4. Proteção dos crons (CLI-ou-chave, já mapeado na varredura 06) — ainda pendente.
5. `LOCK_EX` nos logs que faltam + rotação básica — ainda pendente.
6. Avaliar plano de hospedagem (VPS) antes de bater volume real de 700 usuários ativos — decisão de negócio/infra, não de código, ainda em aberto.
7. mTLS com verificação de certificado ligada, assim que a CA raiz da ONZ Software for obtida (item já conhecido) — ainda pendente.

## Correções aplicadas (2026-09-16)

### 1. Transição de status atômica (fecha a duplicidade de split)

Trocado o padrão "`SELECT status` → checa em PHP → `UPDATE`" (com janela de corrida entre os dois comandos) por um único `UPDATE ... WHERE id = ? AND status != 'pago'`, checando `rowCount()` antes de seguir pro split/notificação/liberação de grupo. `rowCount() === 0` significa que outra requisição já confirmou essa venda primeiro — a atual simplesmente para (`continue`/`exit`), sem duplicar nada. A garantia de atomicidade vem do próprio lock de linha do InnoDB durante o `UPDATE`, sem precisar de transação explícita nem `SELECT FOR UPDATE`.

Aplicado nos **4 pontos** que faziam essa transição (achado 1 a mais do que o mapeado inicialmente — o mesmo padrão apareceu também na renovação automática):

- `webhook.php` — botão manual "Já fiz o pagamento".
- `webhook_infopago.php` — confirmação de PIX comum (campo `pix`).
- `webhook_infopago.php` — confirmação de PIX Automático/renovação (campo `cobsr`), usando `ultimo_txid_renovacao` em vez de `status` como trava (`UPDATE ... WHERE id = ? AND (ultimo_txid_renovacao IS NULL OR ultimo_txid_renovacao != ?)`) — não estava na lista original das "3 rotas" porque usa mecanismo de idempotência diferente, mas é a mesma classe de corrida.
- `cron/cron_verificar_pix.php` — confirmação via varredura de fallback.

Bônus encontrado durante a correção (mesmo arquivo, mesma ideia): o bloco de **expiração** do `cron_verificar_pix.php` fazia `UPDATE vendas SET status = 'expirado' WHERE id = ?` sem reconferir que a venda continuava `'gerado'` — uma venda paga por outro caminho no instante exato da expiração podia ser sobrescrita como `'expirado'` por engano (e o cliente receberia a mensagem de "pagamento não confirmado" mesmo tendo pago). Corrigido com `WHERE id = ? AND status = 'gerado'` + checagem de `rowCount()`.

### 2. Índices em `vendas.transacao_id` e `vendas.id_assinatura`

Adicionado em `admin/atualiza_banco.php` (idempotente, como todo o resto desse arquivo):
```sql
ALTER TABLE vendas ADD UNIQUE INDEX idx_transacao_id (transacao_id);
ALTER TABLE vendas ADD INDEX idx_id_assinatura (id_assinatura);
```
`UNIQUE` em vez de índice comum porque `transacao_id` é gerado via `random_bytes(17)` e nunca deveria repetir — o índice único também funciona como segunda camada de proteção contra duas vendas acidentalmente compartilharem o mesmo txid. `NULL` continua permitido em múltiplas linhas num índice `UNIQUE` do MySQL, então não afeta vendas que ainda não têm txid gerado.

**Importante:** precisa rodar `atualiza_banco.php` (menu Debug) no servidor pra essas colunas/índices serem criados — igual qualquer outra migração desse arquivo. Se a instalação já tiver, por algum motivo, linhas com `transacao_id` duplicado (não deveria, mas nunca rodou essa checagem antes), o `ALTER` do índice único falha silenciosamente (mesmo padrão de `try/catch` de todo o arquivo) — vale conferir manualmente se isso acontecer.

### 3. `flock` + `LIMIT` em `cron_verificar_pix.php`

- `flock(LOCK_EX | LOCK_NB)` sobre um arquivo de lock em `sys_get_temp_dir()`, mesmo padrão já usado em `cron_remarketing.php`: se uma execução anterior ainda estiver rodando, a nova desiste na hora (loga e sai) em vez de processar a mesma lista de vendas pendentes em paralelo.
- `ORDER BY v.criado_em ASC LIMIT 200` na busca de vendas pendentes — cada rodada processa no máximo 200 vendas mais antigas; o que sobrar fica pra próxima execução do cron.
- Lock liberado (`flock(LOCK_UN)` + `fclose`) no fim do script.

## Notas relacionadas

- [[como-funciona-pagamento-gateway]] — fluxo completo de pagamento/gateway (base pra esta análise).
- [[varredura-03-webhooks-credenciais-logs]] — decisão original de reconsultar na fonte em vez de confiar no payload do webhook.
- [[varredura-06-cron-sem-autenticacao]] — pendência dos crons sem proteção, ganha urgência extra com o achado de sobreposição.
- [[pendencias]] — lista consolidada de pendências do projeto.
