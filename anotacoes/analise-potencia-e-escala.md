# Análise de potência e escala — 700 usuários, ~120 vendas/dia cada

Nota de referência (não é TODO), criada em 2026-09-16 a pedido do Caio, olhando pra um cenário de **~700 usuários** na plataforma, cada um vendendo em média **~120 vendas/dia** (~84.000 vendas/dia no total da plataforma, ~30 milhões de linhas/ano só na tabela `vendas`). Objetivo: separar o que é **limite do Telegram** (a plataforma não controla) do que é **limite da nossa própria aplicação** (isso sim dá pra resolver), com números reais tirados do código e de um teste real no banco — não só teoria.

Metodologia: leitura completa de `webhook.php`, `webhook_infopago.php`, todos os `cron/*.php`, `funcoes/gateways.php`, `funcoes/infopago_split.php`, `funcoes/infopago_cashout.php`, índices e volume real do banco de dev (via `SHOW INDEX`/`SELECT COUNT(*)`), um benchmark real e reversível no banco simulando escala, e confirmação dos limites oficiais atuais da API do Telegram.

## Conta de capacidade do cenário-alvo

- 84.000 vendas/dia ÷ 86.400s/dia ≈ **1 venda/s em média**, ~58/min, ~3.500/hora.
- Tráfego não é uniforme — concentração provável em horário de pico (noite/fim de semana). Considerando 70-80% do volume num período de ~10-12h ativas, a média em horário de pico já sobe pra **~2-3 vendas/s**, com picos curtos (ex. campanha de tráfego pago disparando junto) podendo passar disso.
- **Venda ≠ requisição de webhook.** Cada venda completa envolve várias mensagens (`/start`, navegação no funil, geração do PIX, clique em "Já paguei", confirmação) — tipicamente 5-15 hits em `webhook.php` por venda concluída. Some a isso os leads que **não convertem** (todo mundo que manda `/start` mas não compra) — em funis de venda por Telegram uma taxa de conversão de 10-30% é comum, o que significa que o volume de leads/mensagens é várias vezes maior que o volume de vendas. **Não temos dado real de conversão/funil pra calcular isso com precisão** — a estimativa é de algumas requisições/s em média, com picos podendo passar de dezenas/s. Recomendo tratar isso como uma faixa a confirmar com dado real de produção (ou com o teste de carga da seção final), não como número fechado.
- Conclusão prática: **em volume médio, isso é uma carga pequena pra qualquer stack PHP+MySQL bem indexado.** O risco real não é "muita requisição por segundo o tempo todo", é **rajada concentrada** (todo mundo comprando no mesmo horário de pico) batendo em pontos do código que hoje não foram desenhados pra rajada — é exatamente isso que os achados abaixo mostram.

## 1. Onde o Telegram é, de fato, o teto (confirmado)

Limites oficiais do Bot API (Telegram FAQ, não é doc formal da API mas é a fonte oficial — Telegram não publica número exato em lugar nenhum, só essa orientação):

| Limite | Valor | Escopo |
|---|---|---|
| Por chat individual | ~1 mensagem/s (com tolerância a rajada curta) | Por chat |
| Por grupo | ~20 mensagens/minuto | Por grupo |
| Global (broadcast) | ~30 mensagens/s | **Por token de bot** |
| Global com "Paid Broadcasts" | até 1.000 mensagens/s | Por token de bot, custa 0,1 Telegram Stars por mensagem acima de 30/s |

Passar do limite não derruba nada — a API responde `429 Too Many Requests` com um campo `retry_after` (segundos pra esperar).

**O ponto mais importante pra essa plataforma especificamente: esses limites são por token de bot, não por plataforma.** Com 700 bots (700 tokens diferentes), cada um tem seu próprio orçamento de ~30 msg/s — o volume agregado da plataforma inteira **não** compete por um limite global do Telegram. Isso muda a análise:

- **Conversa normal (1 bot ↔ 1 cliente) nunca chega perto do limite.** Ninguém troca mensagem com um bot mais rápido que 1x/segundo na prática — mesmo um funil "afobado" com PIX + QR + texto (3-5 mensagens em sequência pro mesmo chat, ver `webhook.php`) fica bem abaixo de 1 msg/s por chat, porque o gargalo ali é o tempo de I/O da nossa própria aplicação (ver seção 2), não o Telegram.
- **O único lugar que legitimamente manda muitas mensagens pro mesmo bot em pouco tempo é fan-out**: remarketing (campanha em massa pros leads de um bot) e os crons que avisam/expiram acesso de vários membros do mesmo bot de uma vez (`cron_aviso_vencimento.php`, `cron_verificar_acessos.php`, `cron_renovacao.php`). Isso é tratado na seção 3.
- **Conclusão:** pra conversa 1:1 (a maioria do tráfego), o Telegram não é e não vai ser o teto nesse cenário de 700 usuários. O teto real é a aplicação. Onde o Telegram *pode* aparecer como teto real é em fan-out mal pauteado — ver abaixo.

Sources:
- [Bots FAQ](https://core.telegram.org/bots/faq)
- [Telegram Bot API Rate Limits Explained — Calculator & Best Practices (2026)](https://botnamefinder.com/blog/telegram-bot-rate-limits-explained)

## 2. `cron_verificar_pix.php` — achado corrigido durante esta mesma sessão 🟢 (com um resíduo 🟡)

**Nota de transparência:** a primeira versão desta análise apontava esse cron como o achado de maior confiança (sem `LIMIT`, sem lock, loop síncrono ilimitado). Isso era verdade quando a pesquisa rodou — mas o arquivo foi **editado durante esta mesma conversa** (timestamp `16:17`, bem depois dos outros crons e da pesquisa que embasou a primeira versão desta nota) já corrigindo exatamente os três problemas apontados. Deixo registrado aqui porque é um bom lembrete de que análise de código sob uma sessão longa pode ficar desatualizada no meio do caminho — sempre vale reconferir antes de agir sobre um achado antigo.

**Estado atual (conferido de novo, linha por linha):**

```php
// cron/cron_verificar_pix.php:22-27 — trava contra execução concorrente
$lock_file = sys_get_temp_dir() . '/cron_verificar_pix.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logCron("Execução anterior ainda em andamento. Encerrando essa chamada.");
    exit;
}
```
```php
// cron/cron_verificar_pix.php:132-140 — LIMIT + ordem justa (mais antigo primeiro)
$sql_pendentes = "
    SELECT v.*, b.token, b.id_usuario as id_dono
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'gerado'
    AND v.transacao_id IS NOT NULL
    ORDER BY v.criado_em ASC
    LIMIT 200
";
```
```php
// cron/cron_verificar_pix.php:187-192 — transição atômica, protege contra corrida
// com o webhook InfoPago ou o botão manual confirmando a mesma venda ao mesmo tempo
$stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = ? WHERE id = ? AND status != 'pago'");
$stmt_marca->execute([$pago_em, $venda['id']]);
if ($stmt_marca->rowCount() === 0) {
    logCron("Venda #{$venda['id']} já foi marcada como paga por outra requisição simultânea (webhook/botão). Ignorando duplicata.");
    continue;
}
```

Isso resolve, ao mesmo tempo: (a) rodada nunca mais processa o backlog inteiro de uma vez, (b) rodadas nunca mais se sobrepõem (mesmo padrão de `flock` de `cron_remarketing.php`), (c) mesmo se duas fontes tentarem confirmar a mesma venda ao mesmo tempo (esse cron + webhook InfoPago + botão manual), só uma vence — a query já filtra por `WHERE ... AND status != 'pago'` e confere `rowCount()`.

**O que isso muda na prática:** o risco deixa de ser "corrupção/duplicação sob carga" e passa a ser só **atraso de fila sob pico sustentado** — se uma rodada de até 200 chamadas externas sequenciais (a ~300-800ms cada) levar mais que os 60s do intervalo do cron, a próxima chamada só espera o lock liberar e tenta de novo no minuto seguinte, sem stackar. Isso é testável de verdade (não só teoria) — ver o teste de throughput na seção 8.

**Resíduo que continua sem `LIMIT`:** a segunda query do mesmo arquivo, a que varre vendas **expiradas** (`cron/cron_verificar_pix.php:279-289`), não tem `LIMIT`. É uma operação bem mais leve por linha (só um `UPDATE` local +, condicionalmente, uma chamada pra continuar o fluxo — nunca uma chamada síncrona ao gateway de pagamento), então o risco aqui é bem menor que o da query de pendentes — mas vale aplicar o mesmo padrão por consistência se for mexer no arquivo de novo.

## 3. Conta InfoPago única compartilhada por todos os 700 usuários 🔴

Confirmado no código, não é suposição: **todo o dinheiro dos 700 usuários passa pela mesma conta InfoPago** — a do admin.

- `funcoes/gateways.php:41-55` (`getInfopagoCredenciaisAdmin()`) busca as credenciais de **um único usuário** (`perfil='admin'`).
- `funcoes/gateways.php:76-81` **sobrescreve** as credenciais que o tenant teria com as do admin sempre que o gateway é `'infopago'` — a UI inclusive já sinaliza isso (`'gerenciado_pelo_admin' => true`, linha 278).
- O próprio comentário no código já documenta a decisão: *"A InfoPago usa credenciais únicas do admin (compartilhadas por toda a plataforma)"* (`gateways.php:37-40`).

**Implicação pra escala:** isso não é um bug de código que dá pra corrigir com um índice — é uma decisão de arquitetura de negócio que faz da plataforma inteira depender de **uma única conta mercante processando ~84.000 transações/dia (~30 milhões/ano)**. Dois riscos concretos:

1. **Ponto único de falha.** Se o certificado mTLS do admin expira, a senha é revogada, ou a InfoPago suspende a conta por qualquer motivo (inclusive por volume/risco — ver item 2), **os 700 usuários param de vender ao mesmo tempo**, não só um.
2. **Limite de volume/risco não está sob nosso controle.** Provedores de pagamento tipicamente aplicam limites de KYC, antifraude e volume por conta mercante — uma conta pessoa física/pequena empresa processando dezenas de milhões de transações/ano tende a acionar revisão manual, congelamento temporário ou exigência de upgrade de conta (PJ maior, compliance adicional) bem antes de qualquer limite técnico de API ser o problema. **Isso precisa ser validado diretamente com a InfoPago antes de escalar** — não é algo que dá pra simular localmente.

## 4. Split (Cash-Out) rodando de forma síncrona no meio da resposta do webhook 🔴

`dispararSplitInfopago()` (`funcoes/infopago_split.php:13-105`) é chamada **antes** do `http_response_code(200)` em três lugares:

- `webhook_infopago.php:242` (PIX Automático/recorrência)
- `webhook_infopago.php:384` (PIX comum)
- `webhook.php:743` (confirmação manual — trava a própria conversa do cliente no bot até o split terminar)

Cada chamada faz **1 autenticação OAuth + 1 chamada por destinatário de split configurado**, cada uma com até 30s de timeout (`infopago_cashout.php:70,122`) e **sem retry** — se falha, só loga e marca `split_status='falhou'`, sem fila de reprocessamento automático (só é pego de novo se `cron_verificar_pix.php` passar por aquela venda de novo, o que não é garantido pra vendas já confirmadas fora daquele fluxo).

**Impacto em escala:** se um usuário configura 2-3 destinos de split, isso é 2-3 chamadas mTLS sequenciais (mTLS é mais lento que HTTPS comum) **segurando a resposta pro webhook da InfoPago**. A maioria dos provedores de pagamento reenvia o webhook se não recebe 200 rápido — sob carga (múltiplas notificações de pagamento chegando ao mesmo tempo, cada uma travando por até `(1+N)×30s`), isso é a receita pra uma tempestade de reentregas do lado da InfoPago, competindo ainda mais por workers do PHP.

## 5. Índice em `vendas.transacao_id` — já corrigido nesta sessão, com o número real que provou por quê 🟢

**Nota de transparência (segunda vez na mesma análise):** essa consulta é a mais frequente de todo o fluxo de pagamento (`WHERE transacao_id = ?`, disparada em **toda** confirmação — `webhook_infopago.php:299`, `webhook.php:702`) e, quando a pesquisa rodou, **não tinha nenhum índice** (confirmado por grep no schema e por `SHOW INDEX` ao vivo na hora). Rodei o benchmark abaixo pra medir o impacto real — e, entre esse momento e a checagem final desta nota, o **Caio já aplicou a correção**: `SHOW INDEX FROM vendas` confirma agora `idx_transacao_id` (UNIQUE) presente de verdade no banco, e `admin/atualiza_banco.php` foi atualizado no mesmo horário (`16:17`) que a correção do `cron_verificar_pix.php` da seção 2 — os dois achados de maior prioridade desta análise foram resolvidos durante a própria sessão em que ela foi escrita. Deixo o benchmark abaixo porque ele é exatamente o motivo pelo qual essa correção importava tanto.

**Testei isso de verdade** (não só teoria): inseri 300.000 linhas sintéticas na cópia local do banco (dentro de uma operação revertível — os detalhes de limpeza estão na seção 8) e comparei a mesma consulta com e sem índice:

| Cenário | `EXPLAIN` | Tempo médio (5 execuções) |
|---|---|---|
| Sem índice (schema atual) | `type=ALL`, `rows=292.936` (varre a tabela inteira) | **0,542s** |
| Com índice único em `transacao_id` | `type=const`, `rows=1` | **0,00029s** |

**~1.893x mais lento sem índice, já em 300 mil linhas** — e isso é só ~1% do 1 ano de volume projetado (~30 milhões de linhas/ano). Full table scan escala linearmente com o tamanho da tabela: a ~30 milhões de linhas (100x o teste), a mesma consulta que hoje levaria 0,5s passaria pra dezenas de segundos **por chamada**, numa query que roda em todo pagamento confirmado — inviabilizaria o fluxo de pagamento por completo, muito antes de qualquer outro gargalo dessa lista aparecer.

**Estado atual, conferido de novo:**
```
idx_transacao_id | transacao_id | seq=1 | unique=SIM
```
Já em produção no schema (`admin/atualiza_banco.php`) e no banco de dev. Nada a fazer aqui — item fechado.

## 6. Outros achados (por prioridade)

| # | Achado | Onde | Por quê importa em escala |
|---|---|---|---|
| 🔴 | `atividades.tipo` sem índice | `atividades` só tem índice em `id_usuario` | Toda venda/split/login grava em `atividades` (cresce mais rápido que `vendas`); filtro por tipo no admin (`funcoes/log.php:34-37,80-83`) vira full scan numa tabela que cresce mais rápido que a de vendas |
| 🟡 | 3 de 5 crons ainda sem `flock()` | `cron_renovacao.php`, `cron_aviso_vencimento.php`, `cron_verificar_acessos.php` | Mesmo risco que `cron_verificar_pix.php` tinha antes da correção desta sessão (ver seção 2) — rodadas sobrepostas sob backlog crescente. `cron_remarketing.php` e `cron_verificar_pix.php` já têm o padrão certo (`flock` +, no caso do remarketing, `FOR UPDATE SKIP LOCKED`); os outros 3 ainda não |
| 🟡 | Sem `CURLOPT_TIMEOUT` nas chamadas ao Telegram em 5 de 6 lugares | `webhook.php`, `webhook_infopago.php`, e 4 dos 6 helpers de Telegram duplicados pelo código | Uma resposta lenta/travada do Telegram segura o worker do PHP-FPM/Apache indefinidamente — em hospedagem compartilhada com pool de workers limitado, isso pode esgotar workers disponíveis sob carga concorrente e derrubar o site inteiro, não só aquele request |
| 🟡 | Arquivo JSON compartilhado sem lock de leitura (`storage/pix_recorrente_estado.json`) | `webhook.php:54-96` | **Todos os 700 tenants** compartilham o mesmo arquivo pro estado de PIX recorrente em andamento — leitura sem lock + escrita com lock cria janela de corrida onde escritas concorrentes de tenants diferentes podem se sobrescrever. É um ponto de serialização de plataforma inteira num único arquivo |
| 🟡 | Logs sem rotação/limite de tamanho | `vendas_debug.log`, `verificar_pag_debug.log`, `webhook_infopago.log`, `split_debug.log`, todos os logs de cron | Escrita incondicional em toda venda/webhook, sem `unlink`/rotação em lugar nenhum do código — cresce sem limite; a 84 mil vendas/dia isso é gigabytes/mês em hospedagem compartilhada com disco limitado |
| 🟡 | Sem conexão persistente/pool ao MySQL | `conexao.php:11` — `new PDO(...)` simples, sem `PDO::ATTR_PERSISTENT` | Toda requisição (webhook, cron, página) abre uma conexão nova; sob rajada concorrente, isso soma handshakes de conexão ao `max_connections` do MySQL — vale medir se o plano da Hostinger aguenta o pico real antes de escalar |
| 🟢 | 3 consultas duplicadas no mesmo bloco `pix` de `webhook.php` | `webhook.php:271-273`, `279-281`, `512-515` — mesma query `SELECT ... FROM bots WHERE token=?` repetida | N+1 pequeno por request, mas soma sob QPS alto — fácil de consolidar numa variável só |
| 🟢 | Cron de remarketing é a referência certa no próprio código | `cron/cron_remarketing.php` | `flock`, `FOR UPDATE SKIP LOCKED`, `curl_multi` com no máx. 1 envio em voo por bot, pacing de 40ms (~25 msg/s/bot, dentro do limite do Telegram) e tratamento de `429`/`retry_after` — esse padrão só não foi replicado nos outros 4 crons de pagamento/acesso, que têm exatamente a mesma forma (fan-out sobre muitas linhas) |
| 🟡 | Paginação de `admin/transacoes.php` com `OFFSET` degrada em tabela grande | `admin/transacoes.php` — testado na seção 8.4 | Medido: `OFFSET 900000` numa tabela de 1M linhas já força `type=ALL` + `Using filesort` (full scan + ordenação antes de aplicar o offset) — piora conforme a tabela cresce; paginação por cursor (`WHERE criado_em < ?` em vez de `OFFSET`) resolveria |

## 7. Resumo — quem é dono de cada limite

| Camada | É o teto nesse cenário? | Detalhe |
|---|---|---|
| **Telegram (conversa 1:1)** | Não | ~1 msg/s/chat é folgado pra qualquer funil real; nunca é o gargalo em conversa normal |
| **Telegram (fan-out por bot)** | Só se mal pauteado | ~30 msg/s por token — `cron_remarketing.php` já respeita isso; `cron_aviso_vencimento.php`/`cron_verificar_acessos.php`/`cron_renovacao.php` **não têm pacing** — risco real só se muitos acessos do mesmo bot vencerem no mesmo minuto (plausível em promoções com prazo igual pra muita gente) |
| **InfoPago (gateway)** | Provavelmente sim, e é o mais crítico de resolver **fora do código** | Conta única compartilhada pelos 700 usuários — precisa validação direta com o provedor, não dá pra simular localmente |
| **Nossa aplicação (PHP/lógica)** | Sim, é onde está o maior risco imediato | Split síncrono no webhook (sem lock/limite, seção 4) é o item #1 em aberto hoje — `cron_verificar_pix.php` e o crash de rodada por credencial faltando (seção 8.3) já foram corrigidos nesta sessão; os outros 3 crons de acesso/renovação ainda repetem o padrão antigo (fan-out sem `flock`) |
| **Nosso banco (MySQL)** | Já corrigido nesta sessão | Índice em `transacao_id` — o achado de maior impacto medido (~1.900x) — já está aplicado (`idx_transacao_id`, confirmado via `SHOW INDEX` ao vivo); resta só `atividades.tipo` como índice pendente |
| **Hospedagem (Hostinger, compartilhada)** | Desconhecido — não dá pra testar localmente | Limites de `max_connections` do MySQL, número de workers PHP-FPM/Apache simultâneos e cota de CPU dependem do plano contratado — precisa confirmar com o painel/suporte da Hostinger antes de escalar |

## 8. Testes de estresse — o que rodei e o que ainda falta

### 8.1 Benchmark de índice (`transacao_id`) — seção 5

Inseri 300.000 vendas sintéticas na cópia local do banco de dev, dentro de uma transação que **deveria** ser revertida no final. Um detalhe técnico interessante (registrando pra não repetir o erro): o script criava uma tabela temporária pra testar o índice via `ALTER TABLE ... ADD INDEX`, e **DDL causa commit implícito no MySQL mesmo dentro de uma transação em andamento** — isso comitou as 300 mil linhas sintéticas de verdade na tabela `vendas` real antes que o `rollBack()` pudesse rodar (que falhou com "no active transaction", exatamente por isso). Percebi imediatamente, apaguei as 300.000 linhas (`DELETE FROM vendas WHERE id > 741`) e resetei o `AUTO_INCREMENT` de volta pra 742 — conferido depois: banco de volta aos 741 registros originais, idêntico ao estado antes do teste. Lição prática: **`ALTER TABLE`/`CREATE TEMPORARY TABLE ... ADD INDEX` nunca deve rodar dentro de uma transação que depende de rollback pra limpar dado de teste** — usar banco/tabela totalmente separada da próxima vez, não uma transação revertível.

### 8.2 Concorrência HTTP em `webhook.php` — executado

Testei com `curl --parallel` (curl 8.14.1, que já suporta rajada de verdade dentro de um processo só — evita o overhead de abrir centenas de processos separados no Windows, que distorceu minha primeira tentativa com loop de shell). Alvo: `webhook.php?token=<inválido>` — sem token válido, a aplicação para logo depois do primeiro `SELECT ... FROM bots WHERE token=?` e responde "bot não encontrado", então isso mede o **piso** de custo (abrir conexão PHP→MySQL + 1 query + resposta), sem gerar nenhuma chamada real ao Telegram. Tráfego real que bate num bot válido faz vários round-trips a mais (seção 1 do achado original), então o número real de produção tende a ser pior que esse piso, não melhor.

| Concorrência real (`--parallel-max`) | Latência média | Latência min–max | Tempo total da rajada | Throughput sustentado |
|---|---|---|---|---|
| 50 | 0,168s | 0,124s – 0,238s | 503ms | ~99 req/s |
| 200 | 0,635s | 0,406s – 0,992s | 1.377ms | ~145 req/s |
| 500 | 1,101s | 0,422s – 1,676s | 2.953ms | ~169 req/s |

**Leitura:** a latência média sobe ~6,5x (0,17s → 1,1s) só por aumentar a concorrência de 50 pra 500 — mesmo nesse caminho mais barato possível (sem lógica de fluxo, sem chamada ao Telegram). Isso é evidência direta, não só teoria, do achado "sem conexão persistente ao MySQL" (seção 6): cada requisição abre conexão nova, e sob rajada concorrente esse custo de abrir conexão vira o gargalo antes de qualquer lógica de negócio rodar. **Ressalva importante:** essa máquina de dev roda o gerador de carga (`curl`) e o servidor (Apache+PHP+MySQL) no mesmo hardware, competindo pelo mesmo CPU — os números absolutos (throughput ~150-170 req/s) não representam a capacidade real da Hostinger em produção, só mostram a **forma da degradação** (cresce rápido com concorrência, não é uma curva plana). Ainda assim, mesmo esse piso comporta a carga média projetada (~1-3 vendas/s, seção "Conta de capacidade") com folga — o risco é rajada muito concentrada de tráfego não-conversor (muita gente mandando `/start` no mesmo segundo), não o volume médio.

### 8.3 Simulação de backlog do `cron_verificar_pix.php` — achou um bug real, corrigido e reverificado 🟢

Criei um usuário e bot descartáveis (sem nenhuma credencial de gateway configurada) e 200 vendas `status='gerado'` de teste, pra medir quanto tempo uma rodada de até 200 pendências levaria isolando o custo do laço/banco do custo da chamada externa (evitando bater na API real da InfoPago). **O teste não completou como esperado na primeira tentativa — encontrou um bug real:**

```
Fatal error: Uncaught TypeError: InfopagoBanco::__construct():
Argument #1 ($client_id) must be of type string, null given,
called in funcoes/gateways.php on line 139
```

`getUserGateways($id_dono, true)` (`funcoes/gateways.php`) devolveu a InfoPago como "disponível" mesmo pro usuário de teste sem nenhuma linha em `usuarios_gateways` — porque a entrada da InfoPago no catálogo (`gateways`) está `ativa` globalmente, e o código substitui as credenciais pelas do admin sem checar se essas credenciais do admin realmente existem (nesse banco de dev, o admin não tem credencial InfoPago real cadastrada — só placeholder, como o próprio `config.php` documenta). `resolveGatewayProvider()` então tentava construir `InfopagoBanco` com `client_id = NULL`, e como é um `TypeError` (não um `Exception`), **não era pego por nenhum `catch` no arquivo** — o `try/catch` do `cron_verificar_pix.php:178` só envolve a chamada `consultarCobranca()`, não a resolução do provedor logo acima (linha 172).

**Por que importava em escala, além do bug em si:** o efeito não era "pula essa venda com problema", era **crash da rodada inteira** — nenhuma das outras vendas do lote (até 200, seção 2) chegava a ser processada, e a segunda metade do script (expiração de vendas vencidas) também não rodava. Com 700 tenants, bastaria **um único bot** com configuração de gateway incompleta/corrompida pra travar o processamento de pagamento de todos os outros 699 naquela rodada.

**Correção aplicada** — `funcoes/gateways.php:135-145` (`resolveGatewayProvider()`), o único ponto que constrói o provedor e é chamado por 6 lugares diferentes (`webhook.php` x2, `webhook_infopago.php`, `cron_verificar_pix.php`, `cron_renovacao.php`, `admin/consultar_venda.php`) — **todos os 6 já faziam `if (!$provedor) { ... continue/erro ... }`** antes de usar o resultado, então bastou corrigir na origem pra proteger todos de uma vez:

```php
function resolveGatewayProvider(string $gateway_nome, array $config): ?object {
    switch (strtolower($gateway_nome)) {
        case 'infopago':
            // Sem client_id/client_secret não dá pra autenticar — retorna null em vez de deixar
            // o construtor (tipagem estrita) estourar TypeError, que os chamadores não capturam.
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                return null;
            }
            require_once __DIR__ . '/infopago_banco.php';
            return new InfopagoBanco($config['client_id'], $config['client_secret'], $config['certificado'] ?? '', true, $config['cert_password'] ?? '');
        default:
            return null;
    }
}
```

**Reverifiquei reproduzindo o mesmo cenário exato** (mesmo usuário/bot descartáveis, mesmas 200 vendas de teste): a rodada agora **completa sem erro** — `Cron Pix executado. Pagos encontrados: 0. Expirados processados: 0`, `exit_code=0`, em **810ms para as 200 linhas**, com o log confirmando o skip gracioso linha a linha (`"Venda #N gateway infopago não suportado."`). `php -l` limpo.

Esse número (810ms / 200 linhas ≈ 4ms/linha) é útil por si só: confirma que o **custo puro de laço+banco é desprezível** — o gargalo real de uma rodada de verdade é inteiramente a latência da chamada externa ao gateway (~300-800ms típico de REST por `consultarCobranca()`, não testável localmente sem bater na conta de produção — ver seção 3). A conta de projeção continua: 200 linhas × 300-800ms de chamada externa ≈ 60-160s por rodada no cenário de pico, + ~1s de overhead de banco, desprezível no total.

**Limpeza:** apaguei as 200 vendas de teste, o bot e o usuário descartáveis depois de confirmar a correção — conferido depois, banco de volta a 741 vendas / 4 bots / 4 usuários, idêntico ao estado original.

### 8.4 Volume real em `vendas` (1 milhão de linhas) — executado, achou o próximo gargalo real 🔴

Inseri **1.000.000 de vendas sintéticas** (`status='pago'`, valores e datas aleatórios distribuídos num ano, vinculadas a um bot/usuário descartável) via `INSERT` em lote (5.000 linhas por `INSERT`, ~4.140 linhas/s — bem mais rápido que o teste da seção 8.1, que fazia 1 `INSERT` por linha). **1 milhão de linhas é só ~4 dias do volume-alvo** (84 mil vendas/dia) — ainda assim, deu pra medir degradação real nas queries que `admin/dashboard.php` e `admin/transacoes.php` já rodam hoje:

| Query (a mesma do código real) | Tempo a 1M linhas | `EXPLAIN` |
|---|---|---|
| `SUM(valor) WHERE status='pago'` (card "faturamento total", `dashboard.php`) | **4,61s** | `type=ref`, `rows=497.486`, usa `idx_vendas_ranking` mas ainda lê ~497 mil linhas pra somar |
| `SUM(comissao_admin) WHERE status='pago' AND DATE(criado_em)=CURDATE()` (comparativo por hora, `dashboard.php:130,140`) | 0,38s | `type=ref`, `idx_vendas_ranking`, `Using index condition` — o `DATE()` na coluna não impediu o uso do índice aqui, resultado melhor que o esperado |
| `GROUP BY DATE_FORMAT(criado_em, '%Y-%m')` (gráfico mensal, `dashboard.php:164`) | **4,84s** | `type=ref`, `idx_vendas_ranking`, mas **`Using temporary`** — `DATE_FORMAT()` no agrupamento impede usar o índice pra ordenar/agrupar, MySQL monta tabela temporária em memória/disco |
| Paginação com `OFFSET` grande (`transacoes.php`, testado com `OFFSET 900000`) | 1,18s | **`type=ALL`, `rows=994.973`, `key=NULL`, `Using filesort`** — sem filtro de `status` nessa query, não há índice que cubra `ORDER BY criado_em DESC` sozinho; MySQL varre a tabela inteira e ordena antes de aplicar o `OFFSET` |

**Leitura:** o dashboard de admin roda **várias** dessas consultas na mesma página (o grep em `dashboard.php` mostra ~8 variações de `SUM`, uma por hora/dia comparado, mais o gráfico mensal) — a soma de alguns segundos por card, numa página carregada toda vez que um admin abre o painel, já é ruim a 1 milhão de linhas (4 dias de volume). Escalando de forma aproximadamente linear pra 30 milhões de linhas/ano (30x mais), o card de "faturamento total" sozinho passaria de ~4,6s pra **potencialmente minutos**, e o `GROUP BY DATE_FORMAT` (que já precisa de tabela temporária) tende a piorar mais que proporcionalmente. A paginação de `transacoes.php` com `OFFSET` grande também piora, porque quanto mais linhas, mais itens o `filesort` precisa ordenar antes de descartar via `OFFSET`.

**Isso é exatamente o mesmo problema que o projeto já resolveu uma vez** — o ranking (`ranking.php`) também precisava agregar `vendas` e foi desenhado desde o início com cache pré-calculado (`ranking_cache`, recalculado por `cron/cron_ranking.php`) pra nunca fazer `SUM`/`GROUP BY` ao vivo numa página (ver `como-funciona-ranking.md`). O dashboard de admin hoje faz exatamente o que o ranking evitou fazer. Recomendação: aplicar o mesmo padrão — uma tabela pequena tipo `metricas_diarias`/`metricas_admin_cache` (data, faturamento, comissão, contagem) recalculada por cron a cada alguns minutos, com o dashboard só lendo dessa tabela em vez de agregar `vendas` inteira a cada carregamento.

**Limpeza:** apaguei o 1 milhão de linhas sintéticas, o bot e o usuário descartáveis logo depois de capturar os tempos. O próprio `DELETE` das 1.000.000 de linhas levou **274,5s (~4,6 minutos)** — dado extra que vale registrar: operações de manutenção/arquivamento em massa (ex. uma futura limpeza de vendas antigas) também ficam caras nesse volume, não só as leituras do dashboard. Conferido depois que terminou: banco de volta a 741 vendas / 4 bots / 4 usuários, idêntico ao estado original.

### 8.5 Ainda proposto, não executado

1. **Teste de concorrência com bot/fluxo válidos** — repetir o teste 8.2 mas com um bot de teste que tem um fluxo conectado de verdade (incluindo um bloco `pix`), pra medir o custo real do caminho mais caro (múltiplas queries + geração de cobrança), não só o piso "bot não encontrado". Precisa de mais setup (fluxo, credencial de gateway de teste) — não fiz ainda.
2. **Mesmo teste de volume em `leads`/`atividades`** — só testei `vendas` até agora; `atividades` cresce mais rápido (múltiplas linhas por venda) e já tem um índice faltando conhecido (`tipo`, seção 6) — vale confirmar o impacto real do jeito que fiz aqui.

## 9. Correção do dashboard de admin — cache pré-calculado (mesmo padrão do ranking)

Índice sozinho não resolve o achado da seção 8.4: `SUM`/`COUNT`/`GROUP BY` continuam precisando ler toda linha que casa com o filtro, então o custo cresce junto com `vendas` não importa quão bom seja o índice (um índice "coberto" ajudaria uns 5-10x, mas não corta a raiz do problema). Cogitei três alternativas antes de escolher:

- **Contador incremental** (atualizar a métrica direto nos 4 pontos que confirmam pagamento) — dado sempre em tempo real, mas aumenta a superfície de risco bem no caminho crítico que a correção da seção 4 está tentando deixar mais enxuto.
- **Trigger no MySQL** — funciona, mas é lógica "invisível" fora do PHP, contra o padrão do projeto de manter tudo explícito e fácil de debugar.
- **Cache de página (arquivo/APCu)** — só empurra o problema pra um TTL, e não serve os vários recortes de data (hoje/ontem/7 dias/mês) com uma fonte só.

**Escolhido: cache pré-calculado + cron**, o mesmo desenho já validado no projeto pro ranking (`ranking_cache` + `cron/cron_ranking.php`, ver `como-funciona-ranking.md`).

### Schema — `metricas_horarias_admin` (`admin/atualiza_banco.php`)

```sql
CREATE TABLE IF NOT EXISTS metricas_horarias_admin (
    data DATE NOT NULL,
    hora TINYINT UNSIGNED NOT NULL,
    faturamento DECIMAL(14,2) NOT NULL DEFAULT 0,
    comissao DECIMAL(14,2) NOT NULL DEFAULT 0,
    quantidade INT NOT NULL DEFAULT 0,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (data, hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Uma linha por hora (no máx. 24/dia, ~8.760/ano) — fica pequena pra sempre, **independente de `vendas` crescer pra 30 milhões de linhas**. Cobre todos os recortes que o dashboard usa: hoje/ontem (leitura direta por hora), 7/30 dias/personalizado (`SUM` agrupado por `data`, tabela minúscula) e histórico de 12 meses (`GROUP BY DATE_FORMAT(data,...)`, também minúsculo). `admin/atualiza_banco.php` também faz o **preenchimento único do histórico** existente (`INSERT ... SELECT ... GROUP BY` a partir de `vendas`, com `ON DUPLICATE KEY UPDATE` — idempotente, seguro rodar de novo).

**Decisão de escopo:** a tabela só guarda o agregado **de toda a plataforma** ("todos os bots"), sem granularidade por bot — do contrário, com 700+ bots, seriam até 700x mais linhas (ainda pequeno em termos absolutos, mas desnecessário). Quando o admin filtra por um bot específico (`admin/dashboard.php` já tem esse seletor), o código cai de volta pra query ao vivo em `vendas` — o que continua rápido porque fica naturalmente restrito ao volume de **um** tenant (~120 vendas/dia), não da plataforma inteira.

### Cron — `cron/cron_metricas_admin.php`

Só recalcula as **últimas 48h** a cada execução (`WHERE v.criado_em >= CURDATE() - INTERVAL 1 DAY`, `INSERT ... ON DUPLICATE KEY UPDATE`) — como venda paga nunca muda de valor depois de confirmada (sem fluxo de estorno nesse projeto), o histórico mais antigo já preenchido pelo backfill nunca precisa ser revisitado. Isso mantém o cron rápido pra sempre, não importa quantos milhões de linhas `vendas` acumule — o filtro de 48h é bem coberto por `idx_vendas_ranking(status, criado_em, bot_id)`.

Mesmo padrão de lock (`flock(LOCK_EX|LOCK_NB)`) de `cron_verificar_pix.php`/`cron_remarketing.php`. **Nota de transparência de novo:** enquanto eu limpava os dados de teste desta sessão, o Caio já editou esse arquivo direto e adicionou proteção de acesso (`CHAVE_SECRETA_CRON` via `?chave=` pra chamada HTTP, liberado sem chave só via CLI) — o mesmo tipo de correção que `varredura-06-cron-sem-autenticacao.md` já apontava como pendência nos outros crons. Não revertido, é uma melhoria real (aparentemente já aplicada em `cron_ranking.php` também).

### `admin/dashboard.php` — reescrito pra usar o cache

Toda a lógica de cards (`total_transacionado`, `receita_admin`, `total_vendas`) e do gráfico (hoje/ontem/7 dias/30 dias/total/personalizado) agora tem dois caminhos: quando `bot_id=todos` (o caso comum), lê de `metricas_horarias_admin`; quando um bot específico é filtrado, mantém exatamente a query antiga em `vendas` (esse caminho não foi tocado — já era seguro por ser naturalmente pequeno). Variável `$where_data_metricas` construída em paralelo a `$where_data_vendas` (mesmo `switch` de período, sem manipulação de string frágil).

### Verificação — testado de ponta a ponta, não só `php -l`

1. Rodei a migração (`admin/atualiza_banco.php`) via sessão de admin simulada (`_sim_login_temp.php`, apagado depois) — tabela criada, histórico preenchido sem erro.
2. Conferi que o backfill bate exatamente com o live: `SUM(faturamento)` cache = `SUM(valor)` vendas = 967,00; `SUM(quantidade)` cache = `COUNT(*)` vendas = 49 — igual nos dois lados.
3. Rodei o cron uma vez via CLI — sem erro, log confirma.
4. Carreguei `admin/dashboard.php` de verdade (HTTP, sessão de admin) pra `hoje`/`ontem`/`7dias`/`30dias`/`total`/`personalizado`/com filtro de bot específico — todos `200`, nenhum warning/erro/exception no HTML.
5. **Prova definitiva de escala:** inseri 1 milhão de linhas sintéticas em `vendas` de novo (mesmo processo descartável de sempre) e recarreguei o dashboard:

| | Antes da correção (seção 8.4) | Depois da correção |
|---|---|---|
| Card "faturamento total" (`SUM(valor)`) | 4,61s | — |
| Página completa (`periodo=hoje`) | (não medido a esse nível, mas envolve ~8 queries pesadas) | **0,036s** |
| Página completa (`periodo=7dias`) | — | **0,025s** |
| Página completa (`periodo=total`, com o `GROUP BY` que levava 4,84s sozinho) | — | **0,030s** |

A página inteira agora carrega mais rápido do que uma **única** query pesada levava antes — e o tempo não depende mais do tamanho de `vendas` (testado a 1.000.741 linhas, mesma ordem de grandeza continuaria a 30 milhões).

**Limpeza:** apaguei o 1 milhão de linhas de teste, bot e usuário descartáveis depois de medir — o `DELETE` de 1M linhas levou ~294s dessa vez (consistente com a primeira medição da seção 8.4). Conferido depois: banco de volta a 741 vendas / 4 bots / 4 usuários, idêntico ao original.

## Mapa de arquivos-chave

| Responsabilidade | Arquivo |
|---|---|
| Webhook Telegram (maior ponto de N+1 e falta de timeout) | `webhook.php` |
| Webhook InfoPago (split síncrono) | `webhook_infopago.php` |
| Fallback de confirmação de PIX (corrigido nesta sessão; ver seções 2 e 8.3 pro bug novo) | `cron/cron_verificar_pix.php` |
| Referência de como fazer fan-out direito (lock + pacing) | `cron/cron_remarketing.php` |
| Credenciais únicas do admin pra InfoPago | `funcoes/gateways.php` |
| Split síncrono | `funcoes/infopago_split.php`, `funcoes/infopago_cashout.php` |
| Conexão com banco (sem pool/persistente) | `conexao.php` |
| Schema/índices | `admin/atualiza_banco.php` |
| Cache pré-calculado do dashboard de admin (seção 9) | `metricas_horarias_admin` (schema em `admin/atualiza_banco.php`) |
| Recalcula o cache do dashboard (cron) | `cron/cron_metricas_admin.php` |
| Dashboard de admin (lê do cache pra "todos os bots") | `admin/dashboard.php` |
| Validação de credencial antes de instanciar provedor de gateway (seção 8.3) | `funcoes/gateways.php::resolveGatewayProvider()` |

## ⚠️ Pontos de atenção (por prioridade)

1. **🟢 `cron_verificar_pix.php`** — já corrigido nesta sessão (`flock`, `LIMIT 200`, `UPDATE` idempotente contra corrida). Ver seção 2 pra nota de transparência sobre esse achado ter mudado no meio da análise. Resíduo menor (🟡): a query de vendas expiradas no mesmo arquivo ainda não tem `LIMIT`.
2. **🔴 Conta InfoPago compartilhada entre os 700 usuários** — não é algo que resolvemos só no código; precisa de conversa direta com a InfoPago sobre limite de volume/risco pra uma conta processando ~30 milhões de transações/ano, e um plano de contingência caso essa conta seja suspensa/travada (ver seção 3).
3. **🔴 Split síncrono no webhook** — trava resposta ao gateway e a conversa do cliente; considerar mover pra fila/processamento assíncrono com retry, em vez de síncrono sem retry (ver seção 4).
4. **🟢 Dashboard de admin agregava `vendas` ao vivo a cada carregamento** — achado novo (seção 8.4), **já corrigido nesta sessão** com o mesmo padrão cache+cron do ranking. Ver seção 9 pro desenho completo e os números de antes/depois (4,6s → ~0,03s, independente do tamanho de `vendas`).
5. **🟢 Um bot com credencial de gateway incompleta crashava a rodada inteira do `cron_verificar_pix.php`** — achado novo, descoberto rodando o teste de estresse da seção 8.3 (não fazia parte da pesquisa original), **já corrigido e reverificado** nesta sessão. `resolveGatewayProvider()` (`funcoes/gateways.php`) agora retorna `null` em vez de deixar o construtor do `InfopagoBanco` estourar `TypeError` com `client_id=NULL` — como os 6 pontos de chamada já checavam `if (!$provedor)`, a correção na origem protegeu todos de uma vez. Reproduzi o mesmo cenário de teste depois da correção: rodada completa sem erro em 810ms/200 linhas.
6. **🟢 Índice em `vendas.transacao_id`** — já corrigido nesta sessão (`idx_transacao_id`, UNIQUE, confirmado ao vivo no banco). O benchmark que rodei (~1.900x mais lento sem índice a 300 mil linhas, seção 5) documenta por que isso importava, não um problema em aberto.
7. **🟡 Índice faltando em `atividades.tipo`**, **3 crons sem `flock`**, **sem timeout no cURL pro Telegram**, **JSON compartilhado sem lock de leitura**, **logs sem rotação**, **sem pool de conexão ao MySQL**, **paginação de `transacoes.php` com `OFFSET` degrada em tabela grande (seção 8.4)** — ver tabela da seção 6, nenhum sozinho é fatal, mas todos se agravam juntos sob a mesma rajada de tráfego.
8. **Limites reais da hospedagem Hostinger (workers PHP-FPM/Apache simultâneos, `max_connections` do MySQL, cota de CPU) não são testáveis localmente** — precisa confirmar com o plano contratado antes de considerar a análise "fechada" pro ambiente de produção real.
9. **Testes de carga já rodados** (concorrência HTTP em `webhook.php`, simulação de backlog do cron, 1 milhão de linhas sintéticas em `vendas` — ver seção 8) **confirmaram a degradação por falta de pool de conexão, acharam e já corrigiram o bug do item 5, e acharam e já corrigiram o achado do item 4** (ver seção 9); ainda falta o teste de concorrência com um bot/fluxo válido de verdade e o mesmo teste de volume em `leads`/`atividades` (seção 8.5).
10. **🟡 `cron/cron_metricas_admin.php` (novo, seção 9) precisa entrar no crontab da Hostinger** — mesma pendência que `cron_ranking.php` já tinha: sem ele rodando periodicamente (recomendo a cada poucos minutos), o dashboard fica com o cache congelado no último cálculo em vez de quebrar — mas precisa ser cadastrado pra funcionar de verdade em produção.

## Notas relacionadas

- `varredura-06-cron-sem-autenticacao.md` — os crons citados aqui continuam sem proteção de acesso (URL pública, sem `php_sapi_name()==='cli'` nem chave secreta). Pra `cron_verificar_pix.php` isso não gera mais duplicação (o `flock` novo já barra reentrada enquanto uma rodada legítima está rodando — quem disparar via URL só recebe "execução anterior em andamento"), mas ainda permite alguém forçar rodadas fora do horário programado nos outros crons sem lock, e continua sendo uma superfície pública desnecessária em todos os 5.
- `como-funciona-pagamento-gateway.md` — contexto completo do fluxo de pagamento/split que essa análise usa como base.
- `como-funciona-ranking.md` — exemplo do padrão cache+cron já adotado no projeto pra evitar agregação ao vivo em escala; o mesmo raciocínio poderia se aplicar a outras telas se aparecer agregação pesada em `vendas`/`atividades` no futuro.
