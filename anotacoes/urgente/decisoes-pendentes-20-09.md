# Decisões pendentes — 20/09/2026

Junta o que ficou em aberto depois da rodada de testes de 19/09
(`anotacoes/rodada-de-testes-19-09.md`). Nada aqui foi mexido — é só pra ler
quando der.

---

## 1. Bot com token revogado — a mais importante

### O que acontece hoje

O acesso de alguém vence. O cron tenta remover a pessoa do grupo no Telegram.
A chamada pode falhar por três motivos:

| | o que houve | a pessoa ainda está no grupo? |
|---|---|---|
| 1 | Telegram lento/fora por um minuto | sim — tentar de novo está certo |
| 2 | bot foi expulso, grupo apagado, pessoa já saiu | não — **já tratado**, marca expirado |
| 3 | **token do bot foi revogado** | **sim, e vai ficar assim pra sempre** |

O caso 3 não é sobre aquele membro: se o token morreu, **o bot inteiro morreu**.
Não vende, não responde `/start`, não manda nada. E hoje **ninguém fica
sabendo** — nem você, nem o dono do bot. A tabela `bots` não tem nenhuma
coluna de saúde, e não existe em lugar nenhum do sistema a noção de "esse bot
está quebrado".

### Decisão A — o que fazer com o registro do membro nesse caso

- **Opção 1 — deixar como está (tentar pra sempre).** O banco continua
  dizendo "ativo", que é verdade: a pessoa está com acesso. Mas o cron refaz o
  mesmo trabalho todo minuto, pra sempre, e o painel mostra plano ativo pra
  quem já deveria ter saído.
- **Opção 2 — depois de N tentativas, marcar como expirado.** Para o
  retrabalho, mas o banco passa a mentir na direção contrária: diz "sem
  acesso" enquanto a pessoa continua dentro do grupo consumindo o conteúdo.
- **Opção 3 — um estado novo: "deveria sair, não consegui".** Honesto, mas
  exige coluna nova e um lugar na tela mostrando isso, senão vira dado que
  ninguém olha.

**Recomendação:** Opção 1, e resolver pela decisão B abaixo.

### Decisão B — dar ao sistema a noção de "bot quebrado"

Com isso no lugar, o caso 3 se resolve sozinho: o dono é avisado, renova o
token, e as remoções pendentes passam na próxima rodada. De quebra, você para
de perder venda sem saber — hoje um token revogado é bot mudo e zero alarme.

Seria: uma coluna de saúde em `bots`, o cron marcando quando o Telegram
responde erro de autenticação, e um aviso visível no painel do dono do bot.

**Recomendação:** fazer a B. Com ela, a decisão A vira sem importância —
tentar pra sempre deixa de ser problema, porque alguém vai consertar o token.

---

## 2. `log_errors` está Off em produção

Sem isso, quando algo quebra o sistema não deixa rastro nenhum — foi o que
atrasou achar a causa do incidente do `config.php` em 19/09. É mudança no
hPanel, não no código.

## 3. Senha `123456` e `CHAVE_SECRETA_CRON` nunca trocadas

Intencional no ambiente de teste, mas precisa mudar antes de cliente real.

## 4. `cron_retry_split.php` não está cadastrado no crontab

Ver `anotacoes/crontab-hostinger-checklist.md`.

## 5. Backup do banco nunca confirmado nem testada a restauração

Depois do episódio da cota estourada, não dá pra assumir que existe.

## 6. Definir a marca do painel

Nome, logo e favicon estão no genérico "Painel de Bots" desde o white-label.
Configurável em Administração → Identidade Visual.

## 7. Os 10 fluxos de teste na conta do admin

Criados sem querer durante os testes de mobile (ids 17–26, "Novo fluxo"
vazios). Nunca recebi confirmação pra apagar.

## 8. Conta InfoPago compartilhada + estorno/chargeback (PIX MED)

Hoje não há tratamento nenhum: se o cliente pedir devolução, ele continua no
grupo e o split já saiu. Decisão de negócio antes de código.

---

## Achados menores da rodada de 19/09 (sem decisão pendente, só registro)

- **Exportar CSV perto do timeout:** `status=nao_pago` levou 36,6s, o proxy
  corta em 60s. Com mais leads, trunca sem erro.
- **Remarketing pagina por `OFFSET`:** com 1M de leads, pula 900 mil linhas a
  cada rodada. Resolver direito pede paginar por `id > último_id`.
- **`cron_aviso_vencimento` carrega tudo em memória:** sem limite superior na
  consulta. Não pesa hoje (tabela vazia), pesa com volume.
- **MySQL do servidor está em UTC; só a aplicação corrige o fuso.** Qualquer
  escrita de data feita por fora (phpMyAdmin, console do hPanel) grava 3h
  adiantada.
