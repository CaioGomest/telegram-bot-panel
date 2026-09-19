# Mapa do sistema por tópico — pra testar um de cada vez

19/09/2026. Ordem proposta: do que mexe com dinheiro pro que é cosmético.

Legenda do estado:
- ✅ **verificado** — testei e vi funcionando
- 🟡 **parcial** — testei por fora (HTTP, consulta, payload), mas não ponta a ponta
- ❓ **não testado** — ninguém nunca rodou de verdade
- 🔴 **problema conhecido** — já sei que tem coisa errada

---

## 1. Venda ponta a ponta (o coração) — ❓

**É o único teste que prova que o produto funciona.** Tudo que validei até hoje foi código,
banco e HTTP. Ninguém rodou o caminho completo.

Arquivos: `webhook.php`, `webhook_infopago.php`, `funcoes/infopago_banco.php`,
`cron/cron_verificar_pix.php`

O caminho: `/start` → bot responde o fluxo → escolhe plano → gera PIX → paga de verdade →
webhook confirma → recebe o link → entra no grupo.

**Como testar:** um bot real, um plano de R$ 1, um PIX pago do seu celular.

**O que observar em cada etapa:**
- o PIX gerado tem QR code e copia-e-cola válidos?
- depois de pago, quanto tempo até o bot responder? (webhook ou cron de 1 min)
- o link do grupo chega? funciona? é de uso único?
- a venda aparece em `vendas` com `status='pago'` e `pago_em` preenchido?
- o split disparou? (`vendas_splits`, `split_status`)

---

## 2. Acesso ao grupo e expiração — ❓

Arquivos: `cron/cron_verificar_acessos.php`, `cron_renovacao.php`, `cron_aviso_vencimento.php`
Tabela: `membros_grupos`

O que precisa acontecer sozinho: avisar antes de vencer, remover quem venceu, renovar quem
pagou de novo.

**Como testar sem esperar 30 dias:** criar um plano de acesso curto (minutos), ou mexer na
mão em `membros_grupos.data_expiracao` pra uma data passada e ver o cron agir.

**Ponto de atenção que já apareceu:** os crons estão cadastrados no hPanel, mas o
`cron_retry_split.php` **não está** (ver `urgente/crontab-hostinger-checklist.md`).

---

## 3. Gateway e split — 🟡

Arquivos: `gateways.php`, `funcoes/infopago_*.php`, `funcoes/criptografia.php`,
`cron/cron_retry_split.php`

Só existe um gateway: InfoPago (Pix). Os segredos ficam criptografados com a
`CHAVE_CRIPTOGRAFIA_GATEWAYS` — aquela que quase perdi hoje.

**O que já sei:** a chave está íntegra e o painel lê os gateways sem erro.
**O que não sei:** se um split real cai na conta certa, e o que acontece quando falha.

🔴 **Pendente conhecido:** os segredos aparecem em texto claro no formulário (`gateways.php`
ecoa `client_secret`/`chave_pix` com o valor real). E a conta InfoPago é compartilhada — está
no checklist como decisão sua.

---

## 4. Editor de fluxo — 🟡

Arquivos: `fluxo.php`, `fluxos.php`, `assets/edicao_fluxo.js`, `api.php`

Blocos que existem: `message`, `image`, `video`, `audio`, `botoes`, `delay`, `link`, `grupo`,
`pix`.

**O que já verifiquei:** salvar/abrir/excluir fluxo; no celular, arrastar com 1 dedo, zoom de
pinça e mover bloco.
**O que não verifiquei:** se cada tipo de bloco realmente entrega no Telegram — principalmente
`audio`, `video` e `delay`.

**Como testar:** montar um fluxo com um bloco de cada tipo e rodar `/start` uma vez.

---

## 5. Traqueamento — 🟡 (mexido hoje)

Arquivos: `funcoes/traqueamento.php`, `facebook.php`, `utmfy.php`, `links_rastreamento.php`

**Corrigido hoje e verificado por payload:** UTMs, comissão, produto, data de pagamento,
token do Facebook fora da URL, DDI no telefone, origem do lead gravada.
**Não verificado:** se a Meta e a UTMify aceitam o que mandamos. Só um pixel real mostra.

TikTok está comentado de propósito.

**Como testar:** criar um link de rastreamento, entrar pelo link, comprar, e ver se o evento
chega no Events Manager com a campanha certa.

---

## 6. Remarketing — ❓

Arquivos: `remarketing.php`, `cron/cron_remarketing.php`
Tabelas: `remarketing_campanhas`, `remarketing_envios`

Nunca vi uma campanha rodar. É o recurso que dispara mensagem em massa pelo bot — ou seja, o
que mais pode dar errado de forma visível pro cliente final (mandar duas vezes, mandar pra
quem já comprou, tomar limite do Telegram).

**Como testar:** campanha pra 2–3 leads de teste, audiência "não comprou".

**O que observar:** manda uma vez só? respeita a audiência? o que acontece se o cron rodar
duas vezes junto?

---

## 7. Ranking — 🟡 (refeito hoje)

Arquivos: `ranking.php`, `admin/ranking.php`, `cron/cron_ranking.php`

**Verificado hoje:** os quatro estados da tela (agendada / em andamento / encerrada / sem
campanha), e o cron fechando o placar final e parando de mexer depois.
**Não verificado:** com vários usuários competindo de verdade (hoje só 1 pontua).

---

## 8. Dashboards e métricas — 🔴

Arquivos: `index.php`, `admin/dashboard.php`, `cron/cron_metricas_admin.php`

**Verificado:** os números do dashboard do usuário batem exatamente com o banco.

🔴 **Problema aberto:** selecionar um bot específico custa 5 a 11× mais que "todos os bots"
(4,7s contra 0,42s), porque o cache só agrega por usuário. E o mesmo N+1 que corrigi no
`index.php` continua no `admin/dashboard.php` (5 laços).

---

## 9. Leads e exportação — ❓

Arquivos: `leads.php`

Filtros e paginação respondem sem erro, mas nunca testei o **Exportar CSV** — nem com a base
grande (1 milhão de leads na conta de teste).

---

## 10. Contas, login e permissão — ✅

**Verificado hoje:** login, as 14 telas protegidas redirecionando quando deslogado, as 8 de
admin barrando usuário comum, e ausência de IDOR (bot de outro dono dá 404).

🔴 **Pendente seu:** trocar a senha `123456` e a `CHAVE_SECRETA_CRON`.

---

## 11. Admin — 🟡

`admin/usuarios.php` (criar/editar/excluir, splits), `transacoes.php`, `logs.php`,
`consultar_venda.php`, `configuracoes.php` (identidade visual), `atualiza_banco.php`

Telas abrem sem erro e os modais funcionam. **Não testei** criar/editar/excluir usuário de
verdade, nem salvar splits.

---

## 12. White-label — ✅

Nome, logo e favicon vindos do banco. Testado ponta a ponta hoje (salvar, propagar, reverter).

🔴 **Pendente seu:** definir a marca de verdade — está no padrão genérico "Painel de Bots".

---

## 13. Infra e operação — 🔴

- 🔴 `log_errors` está **Off** em produção. Erro fatal não vai pra log nenhum.
- 🔴 `conexao.php` engole falha de conexão e deixa `$pdo` nulo, virando fatal genérico depois.
- 🔴 Backup do banco: nunca confirmado nem testado restauração.
- ✅ Documentação interna e `config.php` agora fora do alcance da web.
- 🟡 Rotação de log: `cron_verificar_acessos.log` cresce sem teto.

---

## 14. Mobile e layout — ✅

18 telas sem estouro horizontal, gestos do fluxo funcionando, login/cadastro/conta no padrão
do protótipo.

---

## Por onde eu começaria

1. **Tópico 1 (venda ponta a ponta)** — é o que decide se existe produto. Tudo o mais é
   detalhe se isso não fechar.
2. **Tópico 2 (acesso e expiração)** — é o que o cliente percebe depois de pagar.
3. **Tópico 6 (remarketing)** — o de maior chance de estragar algo visível.
4. Depois os demais, na ordem que der.
