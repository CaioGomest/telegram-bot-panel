# 🔴 URGENTE — usuário do banco perdeu INSERT/UPDATE (app está só-leitura)

Detectado em 2026-09-18, quando o Caio mandou print de erro ao salvar um fluxo no celular:

```
SQLSTATE[42000]: 1142 UPDATE command denied to user 'u214219698_telegram'@'localhost'
for table `u214219698_telegram`.`fluxos`
```

**Não é problema do código nem específico de `fluxos`.** Conferido no servidor com `SHOW GRANTS`:

```
GRANT SELECT, DELETE, DROP, REFERENCES, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES,
      EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER,
      DELETE HISTORY, SHOW CREATE ROUTINE ON `u214219698_telegram`.*
```

Faltam **INSERT, UPDATE, CREATE e INDEX** — no banco inteiro. Testado tabela a tabela
(`fluxos`, `bots`, `vendas`, `leads`, `usuarios`): `SELECT` funciona, `UPDATE` e `INSERT` são
negados com erro 1142 em todas.

## O que isso quebra na prática

Com o banco só-leitura, **nada que grava funciona**:

- salvar/criar fluxo e bot;
- registrar lead novo (todo `/start` no bot);
- gerar cobrança PIX (`INSERT INTO vendas`);
- **confirmar pagamento** (`UPDATE vendas SET status='pago'`) — o webhook da InfoPago e o cron
  de fallback falham igual;
- liberar/cortar acesso a grupo (`membros_grupos`);
- qualquer cron que grava (métricas, ranking, remarketing, retry de split).

Ou seja: o painel abre e mostra os dados antigos, mas o produto não funciona.

## Quando mudou

Hoje mais cedo (2026-09-17, mesma conta) rodaram sem problema: `UPDATE` em massa corrigindo
datas, `INSERT` de 5 milhões de linhas do teste de carga, `CREATE TABLE` das tabelas de
verificação e recálculo de cache. Então o privilégio foi removido **entre ontem à noite e hoje de
manhã**, não é um estado antigo.

Hipótese mais provável: alguma ação automática da Hostinger (o teste de carga daquela sessão criou
~10 milhões de linhas e deixou a tabela `vendas` com ~6,7M) ou alguma mudança feita no hPanel.
Não dá pra confirmar do lado do servidor — só o painel/suporte da Hostinger mostra isso.

## Como resolver (precisa ser no hPanel — eu não consigo)

Tentei restaurar por SQL e foi negado, como esperado:
`GRANT INSERT, UPDATE ... -> 1044 Access denied` (o usuário do app não tem GRANT OPTION).

Caminho: **hPanel → Bancos de Dados → Gerenciamento de bancos MySQL** → no usuário
`u214219698_telegram`, restaurar todos os privilégios (ou remover e readicionar o usuário ao
banco, que costuma reconceder o conjunto completo). Se não aparecer opção de privilégios, abrir
chamado no suporte da Hostinger citando o erro 1142 e a lista de grants acima.

Depois de restaurar, dá pra conferir em 1 minuto rodando um teste de `INSERT`/`UPDATE` numa
transação com rollback — foi assim que diagnostiquei.
