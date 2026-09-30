# Histórico consolidado do projeto (atualizado em 29/09/2026)

Único arquivo de histórico. Reúne o que antes estava em ~60 notas soltas: varreduras de
segurança, análises de escala, notas de UI, incidentes, capacidade, teste de estresse,
integração OmegaPayments, remoção da InfoPago e todas as rodadas de teste (24/09 a 29/09).

**Como usar:** aqui está o *por que o código é assim*. Não é checklist — o que ainda falta
está em `PENDENCIAS.md`. O pagamento hoje está em `como-funciona-pagamento-gateway.md`.


---


## Segurança — histórico das 15 varreduras (01 a 15)

Feitas entre 16 e 21/09/2026. A maioria dos achados já foi corrigida (confirmado ✅ nos
próprios documentos ou re-verificado agora, ver PENDENCIAS.md). Resumo por varredura:

| # | Foco | Achado principal | Status |
|---|---|---|---|
| 01 | Primeira varredura geral | Arquivos de debug sem `verificarAdmin()`, SQLi leve em `popular_banco.php`, senha admin padrão fraca | ✅ maioria resolvida; senha padrão `123456` continua intencional (ambiente de teste) |
| 02 | Upload/XSS/CSRF | Upload sem whitelist de extensão, `/uploads` sem `.htaccess`, path traversal, CSRF ausente, sem rate limit de login | ✅ tudo resolvido (CSRF e rate limit implementados 2026-09-17) |
| 03 | Webhooks/credenciais/logs | **Achado grave:** webhook InfoPago confiava no payload sem validar — corrigido pra sempre reconsultar a API antes de confirmar pagamento. `/logs` exposto via URL. Credenciais em texto puro no banco | ✅ resolvido (reconsulta obrigatória virou padrão pra todo gateway desde então) |
| 04 | IDOR | Leitura de fluxo de outro usuário via ID sequencial em `api.php` | ✅ resolvido |
| 05 | Pagamento/privilégio/XSS | Rodada de confirmação, sem achado novo | — |
| 06 | Crons sem autenticação | 5 crons acessíveis via URL sem proteção | 🔴 **ainda parcialmente aberto**, ver Seção 1 item 1 |
| 07 | Recuperação de senha/brute-force | **Achado grave:** código de recuperação de senha (6 dígitos) sem limite de tentativas — sequestro de conta por brute-force | ✅ resolvido (reaproveitou o rate-limit do login) |
| 08 | LFI no fluxograma | **Achado crítico:** dono de bot podia setar `image_path` pra `config.php` ou certificado de outro usuário via API direto, e o bot reenviava o arquivo pro Telegram — vazava credencial de banco e certificado mTLS de terceiros | ✅ resolvido em 2 camadas (validação no envio + na gravação) |
| 09 | XSS admin / SSRF UTMify | **Achado grave:** XSS armazenado no modal de detalhes de `admin/usuarios.php` podia escalar pra sessão do admin. SSRF confirmado no campo de postback UTMify (atingia IP interno/metadata de nuvem) | ✅ ambos resolvidos |
| 10 | Cron ranking / segredos em formulário | `cron_ranking.php` sem proteção nem `flock` (risco de corromper `ranking_cache`, que decide prêmio real) | ✅ resolvido |
| 11 | Cobertura mobile | 6 telas bloqueadas sem necessidade no mobile, 5 telas sem link de navegação nenhum | ✅ resolvido (nova aba "Mais") |
| 12 | Documentação exposta | `/CLAUDE.md` (com a senha padrão documentada!), `/anotacoes/*`, `/README.md` servidos publicamente sem senha | ✅ resolvido (`.htaccess` bloqueando `.md`, dotfiles etc.) |
| 13-14 | Estados vazios / N+1 | N+1 no gráfico de `index.php` e `admin/dashboard.php`; `log_errors` Off; `conexao.php` engolindo erro | ✅ tudo confirmado resolvido nesta consolidação (ver Seção 1) |
| 15 | Varredura completa | Vazamento de erro técnico pro **cliente comprador** (não só admin) quando Pix falhava em todos os gateways; upload de vídeo/áudio do fluxo validava só extensão (não conteúdo real) | ✅ ambos resolvidos |

**Nota sobre datas:** as varreduras 15, 16 e 17 originalmente planejadas (segurança,
resiliência, consistência de produto) — só a 15 foi feita, com escopo redefinido; 16 e
17 nunca aconteceram como tal. Não é uma pendência ativa, é só uma nota de que o plano
original mudou de rumo (o trabalho de resiliência acabou espalhado em outras sessões,
como a análise de escala da Seção 4).

---

## Como o sistema funciona — arquitetura (histórico)

**⚠️ Aviso geral:** a InfoPago foi **removida 100% do código em 2026-09-25** — o
gateway hoje é só OmegaPayments. Tudo abaixo que menciona InfoPago é **histórico**, não
reflete mais o código atual. Ver `anotacoes/pendente/plano-remocao-infopago.md` e
`anotacoes/como-funciona-pagamento-gateway.md` (este sim mantido atualizado) pro estado
real de hoje.

### Ciclo de acesso ao grupo (histórico, pré-remoção InfoPago)
4 caminhos confirmavam pagamento (webhook InfoPago Pix comum, webhook InfoPago PIX
Automático, botão manual "Já fiz o pagamento", cron de fallback) — corrida entre eles
resolvida via `UPDATE ... WHERE status != 'pago'` + `rowCount()` (esse padrão de
idempotência continua válido e foi reaproveitado no fluxo OmegaPayments atual).
Tolerância de acesso vencido unificada pra 2 dias em todos os planos.

### Ranking
Nunca calcula ao vivo — `cron/cron_ranking.php` recalcula `ranking_cache` via
`RANK() OVER (...)`, `ranking.php` só lê o cache. Uma campanha em cartaz por vez, com 3
estados (agendada/ativa/encerrada) — resultado de campanha encerrada fica visível até a
próxima começar (decisão de produto, pra ninguém "sumir" sem ver que ganhou).
`apelido_publico` nunca expõe nome real/e-mail no ranking público.

### Criptografia de credenciais de gateway
`funcoes/criptografia.php` cifra `client_secret`/`cert_password`/`chave_pix` com AES via
`CHAVE_CRIPTOGRAFIA_GATEWAYS` (chave única por instalação, gerada automaticamente pelo
instalador — ver `INSTALACAO.md`). Retrocompatível com dado legado sem prefixo `enc:v1:`.

### Traqueamento (Facebook/UTMify/TikTok)
Corrigido em 19/09: os 3 pontos de disparo de evento não mandavam e-mail/telefone/UTM
real (evento "funcionava" mas atribuição era zero). `funcoes/traqueamento.php::
montarUserDataTraqueamento()` centraliza isso hoje. E-mail/telefone ausentes usam
domínio de teste (RFC 2606), nunca inventam dado real de terceiro. TikTok está
comentado de propósito (sem conta pra validar).

### White-label (marca)
Nome/logo/favicon configuráveis via tabela `configuracoes`, com fallback gracioso se a
tabela ainda não existir (instalação antiga sem migração rodada). Cor de destaque (`--or`)
também virou configurável depois (`cor_primaria`, feature mais recente que este doc).

### URLs sem `.php`
`.htaccess` reescreve `/bots` → `bots.php` internamente, com 301 de `.php` pra URL limpa
— exceto em POST (301 descarta corpo), webhooks (URL registrada em serviço externo) e
`api.php`/`ajax/` (chamados pelo JS com `.php` literal).

---

## Escala e capacidade (histórico + números que ainda importam)

- **700 usuários × 120 vendas/dia (~84 mil vendas/dia):** sistema aguenta com folga por
  2 anos projetados (testado com 6,77 milhões de vendas sintéticas), tudo no caminho
  crítico sub-segundo. Teto real era de negócio (conta InfoPago compartilhada, ver nota
  abaixo), não técnico.
- **700 usuários × 1000 vendas/dia (~700 mil vendas/dia):** cenário **NÃO** aguenta no
  plano atual — **o banco realmente estourou a cota da Hostinger (3.072 MB) durante um
  teste em 2026-09-18**, sistema parou de aceitar INSERT/UPDATE. Ver PENDENCIAS.md —
  esse continua sendo o teto real hoje.
- Correções aplicadas ao longo do caminho: índice em `vendas.transacao_id`/
  `id_assinatura` (consulta de 0,542s→0,00029s), cache pré-calculado de dashboard
  (`metricas_horarias_admin`/`usuario`, evitando `SUM`/`GROUP BY` ao vivo), `flock` +
  `LIMIT` em todos os crons, `ORDER BY` na paginação do remarketing (evitava mandar
  mensagem duplicada).
- **Nota histórica (pré-remoção InfoPago):** o achado mais citado nessa série de
  análises era que **todos os usuários compartilhavam a mesma conta InfoPago** (ponto
  único de falha + risco de a InfoPago suspender por volume). Isso **não se aplica mais**
  à OmegaPayments, que usa credencial própria por usuário desde o início — ver
  `anotacoes/criticas/conta-infopago-unica-compartilhada.md` (já marcado resolvido) e
  `anotacoes/como-funciona-pagamento-gateway.md`.

---

## UI/UX — decisões de design (histórico)

- **Gestos mobile no editor de fluxo** (18/09): 1 dedo move o canvas, pinça faz zoom
  ancorado no ponto médio — precisou de `touch-action: none` + lógica própria, porque o
  pan é feito via `scrollLeft`/`scrollTop` em JS (não dá pra usar `touch-action:
  pan-x/y` nativo).
- **Paginação sem recarregar (AJAX)**: critério do Caio — só vale pra lista que é um
  bloco DENTRO de página com outro conteúdo principal (ex. log no rodapé do dashboard).
  Quando a listagem É o produto da página (usuários, transações, leads), recarregar é
  aceitável. `assets/js/paginacao.js` + `inicioBlocoPaginado()`/`fimBlocoPaginado()`.
- **Login/cadastro/conta mobile** (18/09): bloco de marketing removido no mobile (cabia
  ~1000px antes, 727px depois). Botão do Google reposicionado depois (não antes) do
  formulário. Badge de "plano" do painel deixado de fora de propósito — não existe
  assinatura de painel, só produto vendido pelo bot.
- **Filtro de período do dashboard** (17-19/09): várias rodadas de ajuste mobile (scroll
  horizontal na barra de período, remoção de header fixo duplicado). **Bug de fuso
  horário real encontrado:** `conexao.php` fixava o fuso do MySQL mas não do PHP — via
  CLI (crons, scripts), PHP calculava em UTC, adiantando "hoje" ~3h/dia. Corrigido com
  `date_default_timezone_set('America/Sao_Paulo')`. Efeito colateral real: ~7.000 linhas
  sintéticas de teste tinham `criado_em` um dia no futuro, inflando receita do admin em
  ~R$300 mil até ser corrigido.
- **Cards de "Meus Bots"** redesenhados com avatar, status, métricas de fluxo/leads 7d.

Estado atual de UI já passou por várias camadas de redesign depois destas notas
(inclusive o card de "Vendas Aprovadas"/dashboard, o redesign completo do layout do painel,
e a reorganização do dashboard do cliente feitos em sessões mais recentes) — tratar tudo
acima como "por que uma decisão foi tomada", não como "como a tela é hoje".

---

## Sessões de trabalho específicas (resumo)

- **16/09** — primeira grande rodada: análise de escala + varreduras 08/09/10 no mesmo
  dia (condição de corrida no split, índices faltando, LFI crítico, XSS admin, SSRF).
- **17/09** — correções de segurança em produção (fixação de sessão, escape em
  `admin/transacoes.php`), export CSV de `leads.php` trocado pra streaming.
- **18-19/09** — teste de capacidade a 2 anos, achado do estouro de cota real, rodada de
  testes manual contra produção (segredo de gateway em claro corrigido, N+1 do
  dashboard admin corrigido, bug do `conexao.php` engolindo erro corrigido, remarketing
  duplicando envio corrigido).
- **21/09** — varredura completa: vazamento de erro técnico pro cliente comprador
  corrigido, validação de upload de vídeo/áudio por conteúdo real (não só extensão).
- **24-25/09** — gateway OmegaPayments adicionado, depois InfoPago removida 100% do
  código; Stories e Comunidade adicionados; sino de notificações e webhooks de saída;
  split de pagamento virou regra global por gateway (não mais por usuário); foto de
  perfil; correções de UI (busca de leads que não filtrava no banco, botão de tema
  duplicado no login, texto preto ilegível com cor customizada). Ver
  `anotacoes/pendente/plano-remocao-infopago.md` pro detalhe completo da remoção da
  InfoPago e `anotacoes/como-funciona-pagamento-gateway.md` pro estado atual do sistema
  de pagamento.

---

---

## Escala e capacidade — números atuais (25–26/09)

Substitui `capacidade.md`, `teste-de-estresse-25-09.md` e `capacidade-vendas-dia-26-09.md`.

| | Hoje (hospedagem compartilhada) | Numa VPS |
|---|---|---|
| Número passado ao cliente | ~600 vendas/dia | ~40.000 vendas/dia |
| Teto real medido/calculado | ~1.000 vendas/dia sustentável ~2,3 anos | ~64.800 vendas/dia (teto do cron) |
| O que trava | cota de banco de 3 GB (não muda trocando de plano compartilhado) | crons de acesso/aviso/renovação sequenciais, ~45/min (código, não infra) |

- Números ao cliente são ~40% abaixo do teto real, de propósito.
- Duração hoje (2.382 MB livres): 600/dia ≈ 3,8 anos; 1.000/dia ≈ 2,3 anos (841 dias).
  Numa VPS de 100 GB (~36 mi de vendas de espaço, ~2,9 KB/venda): 40 mil/dia ≈ 2,5 anos;
  64,8 mil/dia ≈ 1,5 ano — e disco é aumentável, o limite real é o cron.
- **Concorrência não é gargalo:** 60 requisições simultâneas em `webhook.php`, ~130–190 ms,
  sem degradar (parou em 60 por precaução, não por ter achado o limite).
- **Recomendação:** Hostinger VPS KVM 2 (2 vCPU, 8 GB, 100 GB, ~US$ 9/mês) ou Cloudways
  (DigitalOcean 2 GB/50 GB, ~US$ 22/mês, gerenciado). Gatilho para agir: banco acima de 2,4 GB.
- **Não medido:** latência real do gateway sob carga (não testado de propósito, evita
  cobrança real e antifraude), carga sustentada por horas, tráfego misto, crons durante a carga.
- Susto do teste de 25/09: o site inteiro devolveu 404 da Hostinger e o SSH parou no meio
  do teste; foi coincidência — o plano de hospedagem tinha expirado naquele momento.
- Resíduo do teste: contas de teste com e-mail `@teste-descartavel.invalid` e leads de
  nomes "G1", "H1", "J1"... podem ser apagados.

---

## Incidentes

**Banco estourou a cota (18/09/2026).** Teste de 2 anos (5 mi de vendas + 5 mi de leads
sintéticos) levou o banco a 4.435 MB de uma cota de 3.072 MB. A Hostinger revoga
INSERT/UPDATE/CREATE/INDEX automaticamente (SELECT/DELETE seguem liberados); sintoma:
erro 1142 ao salvar qualquer coisa, inclusive confirmar pagamento. Resolvido apagando o
dataset em lotes e `ALTER TABLE ... FORCE` (devolve espaço ao InnoDB, `DELETE` sozinho
não). Banco caiu para 1.033 MB e a escrita voltou sozinha. **Lição:** teste de carga
precisa caber na cota de 3 GB; gerar em volume menor, extrapolar e limpar logo depois.

**`config.php` sobrescrito em produção (19/09/2026, causado pelo Claude).** Um
`git stash -q` cego dentro de um comando de deploy apagou as credenciais reais (o
arquivo ainda era versionado). Login caiu (500 em qualquer POST) por ~6h30; restaurado
com `git checkout stash@{0} -- config.php` (não copiado à mão, para não errar a chave de
criptografia). Corrigido: `config.php` saiu do versionamento (`.gitignore` + `git rm
--cached`). **Lição permanente:** nunca rodar `git stash` em produção; modificação local
não commitada num servidor costuma ser a configuração real.

**Cron de remarketing travado ~3 h (28/09).** Campanha real com foto no "Bot Carlos"
(token de demonstração inválido, `getMe` = 404). Como toda tentativa falhava, o `file_id`
nunca era salvo e cada uma das 536 tentativas refez o upload completo; alguma chamada do
`curl_multi` nunca devolveu `$active = 0` e o loop interno não tinha teto de tempo. Fix em
`cron/cron_remarketing.php`: `MAX_TICK_SEC = 40` (teto duro dentro do loop) e circuit
breaker `MAX_FALHAS_SEGUIDAS_BOT = 10` (descarta a fila e marca a campanha `falha`). O
processo foi morto com `kill -9`; nada foi entregue a ninguém real. Ver também o bug de
CSRF do upload da mesma rodada (corrigido).

---

## OmegaPayments — o que foi confirmado ao vivo (28/09)

Primeira vez que a integração recebeu chamadas reais. A doc pública tem bot-detection (só
as páginas de endpoint individuais respondem com user-agent de navegador).

- Base: `https://app.omegapayments.com.br/api/v1` (a antiga `api.omegapayments.com.br`
  nem resolvia). Auth: headers `x-public-key`/`x-secret-key`, sem OAuth2/mTLS.
- Criar cobrança: `POST /gateway/pix/receive`; `amount` em reais. Consulta:
  `GET /gateway/transactions?id={id}` (não é `/gateway/pix/{id}`). Recorrente:
  `POST /gateway/pix/subscription`, devolve o `pixCopiaECola` da 1ª cobrança direto;
  periodicidade `WEEKS` existe (semanal liberado).
- `products[]` exige `id` (usa id sintético); `dueDate` exige ISO 8601 UTC completo
  (`gmdate('Y-m-d\TH:i:s.000\Z', ...)`); `client.document` é obrigatório e validado (sem
  CPF real coletado, usa o CNPJ fixo `CNPJ_PIX_RECORRENTE_FIXO` de `webhook.php`);
  e-mail/telefone sintéticos nunca deram erro.
- Enum de status: `PENDING`, `COMPLETED`, `FAILED`, `REFUNDED`, `CHARGED_BACK`, `EXPIRED`.
- Payload real do webhook: `{event, token, client, transaction{id, identifier, status,
  pixInformation{qrCode, expiresAt}}, subscription{id, cycle, startAt, intervalType,
  intervalCount, status}|null}`.
- **Bugs achados e corrigidos na auditoria de PIX avulso + recorrente:** o webhook nunca
  casava venda (extraía `identifier`, a venda é gravada com `transaction.id` — corrigido em
  `extrairIdentificadorOmegapayments()`); a API bloqueia polling com HTTP 429 (`retryAfterSeconds:
  300`) — backoff persistente em `storage/omegapayments_429_*.txt`, cron consulta a cada 5 min
  e o webhook só reconsulta em evento de pagamento; ciclo 2+ da assinatura nunca era
  creditado — `criarVendaCicloOmegapayments()` acha a venda-mãe por `vendas.id_assinatura`,
  cria venda filha, manda o PIX do novo ciclo pelo Telegram; mensal de 30 dias atrasava
  ~0,4 dia por ciclo — a expiração nunca fica abaixo de `startAt + cycle × intervalo`
  (`fimCicloAssinaturaOmegapayments`). O bloqueio hard-coded que recusava recorrente foi
  removido; `vendas.tipo_cobranca`/`id_assinatura` agora são preenchidos de verdade.
- A venda só é marcada paga depois de reconsultar a API (nunca só pelo payload do
  webhook). Credenciais entram no mesmo mecanismo de cifra de `client_secret`/`chave_pix`.
- Em aberto (ver `PENDENCIAS.md`): pagamento real de PIX recorrente, eventos a assinar no
  painel, schema de `splits[]` com vários destinos.

---

## Remoção da InfoPago (executada em 25/09/2026)

Feita sem esperar a OmegaPayments passar em sandbox (risco aceito pelo Caio). Removidos:
`cron/cron_retry_split.php` e mais 3 arquivos; todos os 22 pontos `=== 'infopago'` em
`funcoes/gateways.php`, `webhook.php`, `cron/cron_verificar_pix.php`, `gateways.php`,
`admin/usuarios.php`, `admin/atualiza_banco.php`, `instalacao.php`, `.htaccess`,
`assets/css/painel.css`, `funcoes/criptografia.php`. Colunas `cashout_*` de
`usuarios_gateways` dropadas pela migração; a linha `'infopago'` em `gateways` não é
re-semeada nem apagada (preserva o histórico de vendas antigas).

Achados extras: `api.php::gateway_info` passou a devolver `suporta_recorrente=false`
(depois revertido pela implementação do PIX recorrente na OmegaPayments); o
`.htaccess` não tinha a exceção de redirect para `webhook_omegapayments` (bug antigo,
corrigido). O modelo antigo (uma conta InfoPago compartilhada por todos, ponto único de
falha e risco de chargeback/PIX MED) deixou de existir: a OmegaPayments usa credencial
própria por usuário e split nativo dentro da cobrança. Fluxos antigos com
`tipo_cobranca='recorrente'` que ainda existam caem em skip explícito.

---

## Rodadas de teste (25/09 a 29/09)

Todos os achados abaixo foram corrigidos e estão no ar, salvo o que está em `PENDENCIAS.md`.

- **Rodada 25/09 (servidor de teste, produção).** XSS armazenado em `admin/usuarios.php`
  via nome de exibição (qualquer usuário comprometia a sessão do admin) — corrigido; erro de
  token de bot aparecia em inglês ("Unauthorized"); "+0,0%" no dashboard destacado como alta;
  webhook de saída mandava `customer.username` sempre vazio (agora manda `username` e `phone`
  do lead). Segurança (rodadas 2–3): nenhum problema novo.
- **Homologação 25–26/09 (28 itens, navegador real).** Vários achados vinham do dataset sintético
  de 766 mil vendas (2023 em diante, anterior às colunas de gateway/split): conversão 100%,
  gateway "-" e split "Pendente", aba "Já pagou" com só ~865 leads pagos, "Novo fluxo" repetido.
  Os de código real foram corrigidos: toast invisível bloqueando o botão "Mais" no mobile, link
  "Editar fluxo" sem id, login com o e-mail do admin preenchido para qualquer visitante,
  `[hidden]` perdendo para `display:flex/grid`, "esqueci a senha" inacessível por teclado,
  23 páginas ignorando o "Nome do sistema" no título da aba, plural "1 participantes" e
  "R$ 0,00 do Top 5" do líder do ranking, auto-save ao abrir fluxo (`suprimir_auto_salvar`),
  Visão Geral não somando o que a lista de usuários mostra (cron de métricas, abaixo),
  bot novo mostrando prévia escondida, `/instalacao` bloqueado após instalar, "Usuário"
  sem acento, `aria-label` nos interruptores de Traqueamento.
- **Rodadas 05–07 (26–27/09).** Além do acima: criar campanha nova no Ranking sempre falhava (INSERT
  do ranking) e ganhou o banner de campanha (recurso novo, também ajustado para mobile).
- **Rodada 08 (28/09, foto/vídeo no remarketing).** Upload (`upload_midia_remarketing` em
  `api.php`, foto até 5 MB, vídeo até 20 MB, valida conteúdo real), colunas `midia_caminho`/
  `midia_tipo`/`midia_file_id` em `remarketing_campanhas`, cache do `file_id` do Telegram
  depois do 1º envio para não reenviar o arquivo por destinatário. Entrega real confirmada com
  bot descartável do Caio: foto chegou com legenda completa. Bug de CSRF no upload corrigido.
- **Rodada 09 (28/09).** Cron de remarketing travado — ver Incidentes.
- **Rodada 10 (28/09, fluxo completo).** Conta do Caio (`id_usuario=36`) recebeu cópia das
  credenciais OmegaPayments do Carlos, bot real `@Claude_Gomes_bot` (bot_id=15, `setWebhook`
  confirmado) e o fluxo id=41 cobrindo os 12 tipos de bloco: Início → Mensagem → Imagem → Vídeo →
  Áudio → Delay → Link → Grupo → Sorteio (60/40) → Oferta extra/menor → Extra no pagamento →
  Botões (Mensal/Anual/Suporte) → PIX R$ 1 (pago/não pago). Mídia sintética (GD/ffmpeg) em
  `uploads/`. O bloco Grupo usa id falso (fallback esperado) e o PIX de R$ 1 é real. Resultado
  do teste ao vivo nunca foi registrado.
- **Rodada 11 (29/09).** Lixeira no visualizador de Stories; padrão de botões (Cancelar à esquerda,
  Salvar à direita, `.linha-acoes`) em `bot.php`, `remarketing.php`, `configuracao_usuario.php`,
  `gateways.php` (fora do padrão de propósito: login/cadastro/instalação, `fluxo.php`,
  `fluxo_basico.php`); `aria-label` no TikTok; texto do webhook "Novo lead" corrigido
  (telefone e @usuário entram quando o lead tem).
- **Métricas do dashboard (29/09).** O cron `cron_metricas_admin.php` recalculava só 48 h por
  `vendas.criado_em`, mas a venda muda de status depois (PIX de assinatura fica pagável ~6
  dias): o total do dashboard divergia da lista de usuários. Janela passou para 7 dias
  (dias inteiros) e ganhou `--completo` (CLI) / `?completo=1` (HTTP com chave) para refazer o
  histórico. Cache conferido igual a `vendas` (767.130 / R$ 63.188.655,93), rodou em 0,5 s.
  Índice `idx_vendas_ranking (status, criado_em, bot_id)`; não há índice em `pago_em`. Venda é
  marcada paga em `cron_verificar_pix.php`, `webhook.php` e `webhook_omegapayments.php`.

---

## Correções já verificadas no código (não são mais pendência)

CSRF (`verificarCsrf()` em 17+ arquivos); fixação de sessão (`session_regenerate_id(true)`
no login); `conexao.php` não engole mais falha de conexão (CLI sai com 1, web devolve 503);
N+1 do dashboard admin (usa `metricas_horarias_*`); segredo de gateway mascarado no
formulário; `CURLOPT_TIMEOUT` em `funcoes/facebook.php` e `funcoes/tiktok.php`; clamp de
paginação em `admin/transacoes.php` e `remarketing.php`; documentação interna
(`CLAUDE.md`, `README.md`, `anotacoes/*`) bloqueada por URL via `.htaccess`; páginas
`teste_gateway_infopago*.php` apagadas; sino de notificações no ar; `flock` em todos os
crons; INSERT do ranking; bloqueio de `/instalacao`; cabeçalho de fuso (`date_default_timezone_set`).
