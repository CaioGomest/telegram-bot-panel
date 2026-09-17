# 🔴 URGENTE — Checklist de crons no crontab da Hostinger

Nota criada em 2026-09-17, pasta `anotacoes/urgente/` — ação prática que precisa ser feita no painel da Hostinger antes de considerar o sistema pronto pra produção. Eu não tenho acesso ao hPanel (só SSH), então não consigo cadastrar isso sozinho nem reconferir o crontab de forma independente — essa lista é baseada no que fizemos juntos nessa sessão.

Todos seguem o mesmo padrão em **Sites → stackcode.com.br → Avançado → Cron Jobs → Criar nova tarefa cron**, aba **PHP**, caminho relativo à pasta home (sem barra no início):

```
domains/stackcode.com.br/public_html/telegram/cron/<arquivo>.php
```

## Status atual

| # | Arquivo | Frequência recomendada | Status |
|---|---|---|---|
| 1 | `cron_verificar_pix.php` | a cada minuto | ✅ Cadastrado (feito juntos nessa sessão) |
| 2 | `cron_ranking.php` | a cada minuto | ✅ Cadastrado |
| 3 | `cron_metricas_admin.php` | a cada 5 minutos | ✅ Cadastrado |
| 4 | `cron_remarketing.php` | a cada minuto | ✅ Cadastrado |
| 5 | `cron_aviso_vencimento.php` | a cada minuto | ✅ Cadastrado |
| 6 | `cron_verificar_acessos.php` | a cada minuto | ✅ Cadastrado |
| 7 | `cron_renovacao.php` | a cada hora | ✅ Cadastrado |
| 8 | `cron_retry_split.php` | **a cada 15-30 minutos** | 🔴 **NOVO, ainda não cadastrado** — criado depois do checklist original, precisa ser adicionado |

## ⚠️ Antes de marcar como "tudo certo"

- Os itens 1-7 foram cadastrados juntos durante essa sessão (vi a lista completa no seu print em algum momento) — mas eu **não tenho como reconferir isso agora por conta própria** (SSH não expõe `crontab -l` nessa conta, testei e confirmei isso já). Se algo foi removido/editado depois, essa lista fica desatualizada — vale um clique rápido em "Cron Jobs" pra bater o olho antes de publicar.
- O item 8 (`cron_retry_split.php`) é **novo de hoje** — sem ele cadastrado, split que falha não tem retentativa automática nenhuma (fica só o que já existia antes: nada, precisa mexer no banco na mão).
- Todos usam a chave secreta (`CHAVE_SECRETA_CRON` em `config.php`) quando chamados por HTTP, mas via CLI (que é como o crontab da Hostinger normalmente chama) não precisam da chave — só confirmar que o comando cadastrado usa o caminho certo do arquivo, não a URL.

## Notas relacionadas

- `analise-potencia-e-escala.md` — onde os itens 1-7 foram documentados originalmente.
- `como-funciona-pagamento-gateway.md` — onde o item 8 (retry de split) foi documentado.
- `como-funciona-ranking.md` — item 2.
