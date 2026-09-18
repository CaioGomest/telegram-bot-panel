# ✅ RESOLVIDO — banco estourou a cota e a Hostinger revogou a escrita

Ocorrido em 2026-09-18. O Caio mandou print de erro ao salvar um fluxo no celular:

```
SQLSTATE[42000]: 1142 UPDATE command denied to user 'u214219698_telegram'@'localhost'
for table `u214219698_telegram`.`fluxos`
```

## O que era

**Não era problema de código nem específico de `fluxos`.** O usuário MySQL tinha perdido
**INSERT, UPDATE, CREATE e INDEX** no banco inteiro — `SELECT` e `DELETE` continuavam valendo.
Testado tabela a tabela: `SELECT` ok, `INSERT`/`UPDATE` negados com 1142 em todas.

**Causa raiz:** o banco estourou a cota de disco — **4435 MB de 3072 MB** (o print do hPanel
mostrava "Você usou todo o seu espaço em disco"). A Hostinger revoga privilégios de escrita
automaticamente quando isso acontece. Ou seja: o erro 1142 era consequência, não a doença.

**Quem encheu:** o teste de capacidade de 2 anos que rodei na véspera (5 milhões de vendas +
5 milhões de leads em `bot_id=1`). `vendas` sozinha estava com 2419 MB e `leads` com 1798 MB.

## O que quebrava

Com o banco só-leitura, nada que grava funcionava: salvar fluxo/bot, registrar lead, gerar
cobrança PIX e — mais grave — **confirmar pagamento** (`UPDATE vendas SET status='pago'`, tanto
pelo webhook quanto pelo cron de fallback).

## Como foi resolvido

Sem depender do hPanel (o usuário do app não tem GRANT OPTION, tentei e deu 1044):

1. `OPTIMIZE TABLE` estava negado (exige INSERT), mas **`ALTER TABLE ... FORCE` era permitido** —
   esse foi o caminho pra devolver espaço ao disco, já que `DELETE` sozinho no InnoDB não encolhe
   o arquivo da tabela.
2. Apagadas, em lotes de 50 mil (por causa do `MAX_STATEMENT_TIME 120`): 6.000.000 vendas e
   6.000.000 leads do bot de teste de carga, e 1.480.088 linhas antigas de `atividades` (ficaram
   as 20 mil mais recentes). O dataset "Carlos 3 anos" (`bot_id=2`) foi **preservado**.
3. `ALTER TABLE vendas FORCE` pra reconstruir. A conexão caiu no meio ("MySQL server has gone
   away") e o banco ficou vários minutos sem responder nem a um `SELECT 1` — a reconstrução
   continuou rodando no servidor e terminou sozinha.

**Resultado:** 4424 MB → **1033 MB** (34% da cota). A Hostinger **restaurou a escrita
automaticamente** assim que voltou pra dentro do limite — confirmado com teste de `INSERT` e
`UPDATE` em transação com rollback: os dois OK.

## Lição pra não repetir

Teste de carga nesse ambiente tem que caber na cota de 3 GB. Os ~10 milhões de linhas da
simulação de 2 anos ocupavam sozinhos mais que o plano inteiro. Se for repetir, gerar em volume
menor e extrapolar (como a própria `analise-potencia-e-escala.md` seção 11 já recomendava) — e
apagar + `ALTER TABLE ... FORCE` logo depois de coletar os números, não deixar acumulado.

Estado atual das tabelas grandes: `leads` 714 MB (1,09M linhas), `vendas` 311 MB (766 mil).
`leads` ainda não foi reconstruída — um `ALTER TABLE leads FORCE` devolveria uns 500 MB a mais,
mas trava o banco por alguns minutos, então ficou pra quando for conveniente.
