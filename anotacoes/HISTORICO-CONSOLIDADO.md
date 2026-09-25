# Histórico consolidado do projeto (até 2026-09-25)

Este arquivo substitui os ~37 arquivos soltos que existiam direto em `anotacoes/`
(varreduras de segurança, análises de escala, notas de UI, sessões de trabalho) —
consolidados aqui num relatório único pra não precisar navegar dezenas de arquivos pra
entender o histórico do projeto. As pastas `anotacoes/criticas/`, `anotacoes/pendente/`,
`anotacoes/urgente/` e `anotacoes/testes/` **não foram tocadas** — continuam como estão.

**Como usar este documento:** a Seção 1 é a única parte que importa pro dia a dia —
pendências reais, confirmadas no código atual hoje. O resto é histórico, pra entender
"por que o código é assim" quando precisar, não é checklist.

---

## 1. ⚠️ Pendências reais, confirmadas no código atual (2026-09-25)

Cada item abaixo foi checado direto no código nesta consolidação — não é só o que a nota
antiga dizia, é o que ainda é verdade hoje.

1. **🔴 5 dos 8 cron jobs sem proteção de acesso.** `cron_verificar_pix.php`,
   `cron_renovacao.php`, `cron_aviso_vencimento.php`, `cron_remarketing.php` e
   `cron_verificar_acessos.php` não checam `CHAVE_SECRETA_CRON` nem `php_sapi_name() ===
   'cli'` — qualquer um que descubra a URL pode disparar manualmente e repetidamente
   (`cron_remarketing.php` é o mais grave: dispara mensagem real pra até 1000
   leads/campanha, risco de ban do bot no Telegram por spam se martelado). Só
   `cron_ranking.php`, `cron_metricas_admin.php` e `cron_limpar_stories.php` têm essa
   proteção. **Todos os 8 já têm `flock`** (proteção contra rodar 2x ao mesmo tempo já
   está OK, só falta a proteção de acesso externo).
2. **🟡 Token de integração (Facebook/TikTok/UTMify) em texto claro no formulário** de
   `traqueamento.php` — visível via "inspecionar elemento", não é XSS, mas nunca foi
   mascarado como já é feito com segredo de gateway. Baixa prioridade, cosmético.
3. **🟡 `log_errors` — status real não verificável por código** (é config de servidor,
   não tem `ini_set` no projeto). Última checagem documentada (varredura 13-14, 19/09)
   encontrou `Off` em produção. **Precisa confirmar no hPanel da Hostinger**, não dá pra
   saber pelo código.
4. **🟡 Sem rotação de log.** Nenhum arquivo de log (`logs/*.log`) tem limite de tamanho
   ou expiração automática — crescem indefinidamente.
5. **🔴 Teto de capacidade é o banco compartilhado da Hostinger, não o código.** Em
   2026-09-18 o banco **realmente estourou a cota (3.072 MB) durante um teste de carga**
   e a Hostinger revogou INSERT/UPDATE automaticamente — sistema parou de funcionar
   (não salvava fluxo, lead, PIX, nem confirmava pagamento) até liberar espaço. Cenário
   de 700 usuários × 1000 vendas/dia estouraria a cota em menos de 3 dias. Ver Seção 4
   pra números completos. **Decisão de negócio pendente:** sair do banco compartilhado
   (VPS/banco dedicado) antes de qualquer crescimento real de base de usuários.
6. **🟡 `admin/transacoes.php` faz `SELECT COUNT(*)` sem filtro** na visão padrão —
   medido em 1,57s a 6,77 milhões de linhas, projeção de ~14s a 61 milhões. Só afeta
   tela interna de admin, não o cliente final.
7. **🟡 Split de pagamento não tem retry/fila assíncrona pro caminho de confirmação.**
   Com volume alto, confirmar pagamento segura o worker PHP-FPM até o split terminar
   (síncrono). Isso era descrito como problema específico da InfoPago (split via
   Cash-Out); a OmegaPayments faz split nativo na criação da cobrança, então **esse
   item pode já não se aplicar mais** — precisa reavaliação à luz do gateway atual (ver
   Seção 3 sobre a remoção da InfoPago).
8. **Decisão de produto em aberto:** bot com token revogado (erro `Not Found` do
   Telegram) faz os crons de acesso/aviso reprocessar o mesmo membro pra sempre — token
   inválido não está na lista de erros "permanentes" tratados. Membro fica "ativo" no
   banco indefinidamente e nada avisa que o bot quebrou. Não é bug simples de corrigir
   com uma linha — é decisão de como sinalizar "bot quebrado" no produto.
9. **`termos.php` é rascunho, nunca revisado por advogado.** Escrito pelo autor com base
   no funcionamento real do sistema (bots Telegram, PIX, dados de lead, LGPD) — precisa
   revisão jurídica antes de cliente real usar pra valer.
10. **LGPD/retenção de dados** — segue sem análise jurídica formal.

### Já verificado como RESOLVIDO hoje (não são mais pendência, apesar de notas antigas)
Estes apareciam como "pendente" em algum documento antigo, mas checagem direta no
código confirma que já estão corrigidos:
- ✅ CSRF — `verificarCsrf()` implementado e usado em 17+ arquivos.
- ✅ Fixação de sessão — `session_regenerate_id(true)` presente em `funcoes/usuario.php`
  (linha ~96, dentro do login).
- ✅ `conexao.php` não engole mais falha de conexão — CLI sai com código 1, web devolve
  503 sem vazar dado de conexão.
- ✅ N+1 do dashboard admin — usa `metricas_horarias_admin` (cache), não mais 5 laços de
  query por ponto do gráfico.
- ✅ Segredo de gateway em texto claro no formulário (`client_secret`, `chave_pix`) —
  ambos mascarados (`•••• (salvo)`), valor real nunca ecoado no HTML.
- ✅ `CURLOPT_TIMEOUT` em `funcoes/facebook.php` e `funcoes/tiktok.php` — presente nos
  dois.
- ✅ Paginação presa após filtro reduzir resultado — `admin/transacoes.php` e
  `remarketing.php` já têm o clamp (`min($pagina, $total_paginas)`).
- ✅ Documentação interna (`/CLAUDE.md`, `/README.md`, `/anotacoes/*`) bloqueada de
  acesso via URL (`.htaccess`).
- ✅ Páginas de teste de gateway inseguras (`teste_gateway_infopago*.php`) — apagadas.

---

## 2. Segurança — histórico das 15 varreduras (01 a 15)

Feitas entre 16 e 21/09/2026. A maioria dos achados já foi corrigida (confirmado ✅ nos
próprios documentos ou re-verificado agora, ver Seção 1). Resumo por varredura:

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

## 3. Como o sistema funciona — arquitetura (histórico)

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

## 4. Escala e capacidade (histórico + números que ainda importam)

- **700 usuários × 120 vendas/dia (~84 mil vendas/dia):** sistema aguenta com folga por
  2 anos projetados (testado com 6,77 milhões de vendas sintéticas), tudo no caminho
  crítico sub-segundo. Teto real era de negócio (conta InfoPago compartilhada, ver nota
  abaixo), não técnico.
- **700 usuários × 1000 vendas/dia (~700 mil vendas/dia):** cenário **NÃO** aguenta no
  plano atual — **o banco realmente estourou a cota da Hostinger (3.072 MB) durante um
  teste em 2026-09-18**, sistema parou de aceitar INSERT/UPDATE. Ver Seção 1 item 5 —
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

## 5. UI/UX — decisões de design (histórico)

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
(inclusive o card de "Vendas Aprovadas"/dashboard, o redesign completo do layout Coyote,
e a reorganização do dashboard do cliente feitos em sessões mais recentes) — tratar tudo
acima como "por que uma decisão foi tomada", não como "como a tela é hoje".

---

## 6. Sessões de trabalho específicas (resumo)

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

## Notas relacionadas (ainda vivas, não tocadas nesta consolidação)

- `anotacoes/como-funciona-pagamento-gateway.md` — como o pagamento funciona **hoje**
  (só OmegaPayments), mantido atualizado.
- `anotacoes/pendente/plano-remocao-infopago.md` — checklist completo da remoção da
  InfoPago, já executado.
- `anotacoes/pendente/pendencias-sandbox-omegapayments.md` — o que ainda não foi
  validado da integração OmegaPayments contra sandbox real.
- `anotacoes/criticas/conta-infopago-unica-compartilhada.md` — risco do modelo antigo,
  marcado resolvido pela migração de gateway.
- `anotacoes/testes/plano-de-testes-24-09.md` — mapa de testes atual (substitui o
  `mapa-de-testes-por-topico.md`/`rodada-de-testes-19-09.md` antigos, cujo conteúdo está
  resumido na Seção 5/6 acima).
- `anotacoes/urgente/` — pendências urgentes específicas, não tocadas aqui.
- `anotacoes/credenciais-ssh-hostinger.md` — credenciais, gitignored, fica onde está.
