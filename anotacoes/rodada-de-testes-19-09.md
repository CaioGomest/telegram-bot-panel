# Rodada de testes — 19/09/2026

Testes feitos sozinho, contra produção. O mapa de tópicos está em
`mapa-de-testes-por-topico.md`.

---

## ✅ Corrigidos nesta rodada

### 1. Segredo do gateway aparecia no HTML — `gateways.php`

`client_secret` e `chave_pix` iam com o valor real no `value=` do formulário. O
`type="password"` mascara na tela, mas o segredo aparecia inteiro em "ver código-fonte". A
chave Pix era pior: `type="text"`, visível direto.

O padrão certo **já existia no próprio arquivo** — `cert_password` usa placeholder e o
salvamento mantém o atual quando o campo vem vazio. Só não tinha sido aplicado aos outros.

> **Efeito colateral bom:** antes, campo vazio significava "apaga". Como o formulário do
> usuário comum manda `client_secret=""` num campo hidden (InfoPago é gerenciado pelo admin),
> qualquer liga/desliga do gateway gravava credencial vazia. Agora vazio significa "mantém".

**Testado:** gravar → salvar de novo com campos vazios → segredo preservado; salvar com valor
novo → trocado. E o segredo não aparece mais no HTML (0 ocorrências). Linha de teste apagada.

### 2. N+1 no dashboard do admin — `admin/dashboard.php`

Cinco laços (hoje, ontem, 8 dias, 30 dias, período personalizado) fazendo uma consulta por
ponto do gráfico, com `DATE()`/`HOUR()` no `WHERE` anulando o índice.

| com um bot selecionado | antes | depois |
|---|---|---|
| hoje | 21,4s | 3,0s |
| ontem | **31,9s** | 3,0s |

**Valores conferidos contra o banco:** 8 dias R$ 104.563,16 e 30 dias R$ 482.225,27 — batem
exatamente.

### 3. `conexao.php` engolia a falha de conexão

Só logava e seguia com `$pdo` indefinido; o erro estourava adiante como
`Call to a member function prepare() on null`, num arquivo qualquer. Foi o que mascarou o
incidente da manhã.

Agora para na hora: CLI sai com código 1 (cron não finge que rodou), web devolve 503 com
página honesta e **zero dado de conexão**. Testado nos dois modos, e confirmado que nenhum
usuário/host/senha vaza pra tela.

### 4. Remarketing mandava repetido e pulava lead — `cron/cron_remarketing.php`

O envio pagina com `LIMIT/OFFSET` entre execuções do cron, mas a consulta **não tinha
`ORDER BY`**. Sem ordem definida o MySQL não garante a mesma sequência de uma rodada pra
outra — ainda mais com escrita concorrente na tabela (bot criando lead a cada `/start`).

Num disparo em massa isso manda mensagem duplicada pra uns e nenhuma pra outros, sem nada no
log denunciando. Corrigido com `ORDER BY l.id`.

---

## ✅ Testado e funcionando

### Exportar CSV (tópico 9)
1.092.365 linhas, 110 MB, 27s, sem HTML no meio — o streaming está certo. Filtros funcionam
(872 pagos + 1.091.493 não pagos = 1.092.365 ✓) e bot de outro dono devolve vazio.

### CRUD de admin (tópico 11)
Criar, ler, editar e excluir — todos passaram. Além disso:
- senha preservada ao editar com o campo em branco ✓
- e-mail duplicado recusado ✓
- POST sem CSRF → 403 ✓
- usuário comum → barrado ✓
- **admin não consegue excluir a própria conta** ✓

Usuário de teste apagado; 3 usuários de volta.

### Audiência do remarketing (tópico 6)
O filtro `comprou` / `nao_comprou` está correto, conferido contra o banco.

### Lógica de expiração e aviso (tópico 2)
Com dados de teste, o cron:
- encontrou o acesso vencido e tentou remover ✓
- deixou em paz quem vence só daqui a 30 dias ✓
- disparou o aviso na janela certa (`deveAvisar=SIM`, "Restam 4 min") ✓

Dados de teste apagados.

---

## 🔴 Achados que **não** corrigi

### A. Bot com token inválido = tentativa infinita, e ninguém é avisado

Os dois crons (`cron_verificar_acessos` e `cron_aviso_vencimento`) só gravam o resultado no
banco **quando a chamada ao Telegram dá certo**. Existe uma lista de erros considerados
permanentes que são marcados mesmo assim (`chat not found`, `bot was kicked`, etc.) — bom
design —, mas `Not Found`, que é o que o Telegram devolve pra **token inválido**, não está
nela.

Consequência: um bot com token revogado faz o cron reprocessar os mesmos membros a cada
minuto, para sempre. O membro continua `ativo` no banco (aparece como plano válido nas telas)
e **nada no sistema avisa que aquele bot parou de funcionar**.

Não corrigi porque a saída óbvia (marcar como expirado assim mesmo) é pior: o banco diria
"sem acesso" enquanto a pessoa continua dentro do grupo. O certo é contar tentativas e
sinalizar o bot como quebrado — é decisão de produto.

### B. Exportação de CSV perto do timeout

`status=nao_pago` levou **36,6s**; o proxy da Hostinger corta em 60s. Com 2–3× mais leads o
download estoura no meio e gera um **CSV truncado sem erro nenhum** — parece completo.

### C. `OFFSET` grande no remarketing

Corrigi a ordem, mas a paginação continua por `OFFSET`. Com 1 milhão de leads, `OFFSET 900000`
faz o banco pular 900 mil linhas a cada rodada. Resolver de verdade é paginar por
`WHERE l.id > ultimo_id`, o que pede uma coluna nova na campanha.

### D. `cron_aviso_vencimento` carrega todos os ativos na memória

A consulta filtra só `data_expiracao > NOW()`, sem limite superior, e faz `fetchAll()`. O
"falta pouco pra vencer?" é decidido em PHP, depois. Hoje não pesa (tabela vazia), mas com
milhares de membros ativos isso traz todos pra memória a cada minuto.

### E. Fuso do MySQL é UTC; só a aplicação corrige

`@@global.time_zone` é `SYSTEM` (UTC). O `conexao.php` faz `SET time_zone = '-03:00'` em toda
conexão, então **a aplicação está consistente consigo mesma**.

O risco é externo: qualquer escrita de data feita por fora (phpMyAdmin, console SQL do
hPanel, `mysql` na mão) grava **3 horas adiantado**. Caí nisso testando — marquei um acesso
pra vencer "em 4 minutos" e o sistema leu 3h04.

Se um dia for preciso corrigir data na mão, rodar `SET time_zone = '-03:00';` antes.

### F. `log_errors` está Off em produção

Erro fatal não vai pra log nenhum. É o que fez o incidente da manhã levar tanto tempo pra ser
diagnosticado. Mudança é no hPanel, não no código.

---

## O que continua sem teste de verdade

Tudo que depende de bot e pagamento reais. O ambiente de teste tem **token de bot inválido**
(`TESTE_..._TOKEN_INVALIDO`) e **zero credenciais de gateway** — não dá pra gerar PIX nem
receber resposta do Telegram.

Tópicos 1 (venda ponta a ponta), 3 (gateway/split) e 4 (entrega de cada tipo de bloco no
fluxo) seguem em ❓ e só fecham com bot e PIX de verdade.
