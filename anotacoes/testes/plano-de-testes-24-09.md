# Plano de testes — mudanças de 24/09/2026

Cobre os 2 commits do dia (`33aaf3f` gateway OmegaPayments, `264c8ff` Stories + Comunidade)
mais o trabalho ainda não commitado no working tree (sininho de notificações, webhooks de
saída, cor primária). Nada disso foi testado ainda.

Legenda do estado (mesmo padrão de `mapa-de-testes-por-topico.md`):
- ✅ **verificado** — testei e vi funcionando
- 🟡 **parcial** — testei por fora (HTTP, consulta, payload), mas não ponta a ponta
- ❓ **não testado** — ninguém nunca rodou de verdade
- 🔴 **problema conhecido/bloqueado** — já sei que tem coisa errada, ou não dá pra testar ainda

**Pré-requisito único pra qualquer item abaixo:** rodar `admin/atualiza_banco.php` (idempotente).
Ele cria a linha `gateways` de `omegapayments` (nasce `ativo=0`), `stories`,
`stories_visualizacoes`, `comunidade_links`, `atividades.lido_em` + índice, `webhooks` e
`webhooks_envios`. `instalacao.php` (instalação nova) só cria `stories`/`stories_visualizacoes`
e `atividades.lido_em` — **não** cria `comunidade_links` nem `webhooks`/`webhooks_envios`.

---

## 1. Gateway OmegaPayments (Pix) — 🔴 bloqueado (sandbox)

Arquivos: `funcoes/omegapayments_banco.php`, `webhook_omegapayments.php`,
`funcoes/gateways.php`, `gateways.php`, `cron/cron_verificar_pix.php`

**Bloqueio:** sem credenciais sandbox ainda. `anotacoes/pendente/pendencias-sandbox-omegapayments.md`
já lista o que está `[A CONFIRMAR]` no código (URL base, endpoint de consulta, schema de
`splits[]`/`products[]`, formato do payload do webhook, enum de status "pago") — nada disso
pode ser validado contra a API real neste ciclo. Quando tiver sandbox, seguir o roteiro que já
está naquele arquivo (gerar cobrança real, inspecionar resposta, testar split 80/20, confirmar
via webhook real e via fallback do cron) em vez de duplicar aqui.

### 1.1 Configuração — testável agora, sem sandbox
- Em `gateways.php`, cadastrar credenciais de um usuário pra `omegapayments`: só devem aparecer
  Client ID, Client Secret (mascarado, em branco = mantém atual) e Chave Pix — **sem** campo
  Tipo de Conta (fixo `pj` oculto) e **sem** upload de certificado/senha de certificado.
- **Gap conhecido pra confirmar:** o formulário exige "Chave Pix" e `webhook.php` também exige
  `chave_pix` não-vazia pra considerar o gateway ativo, mesmo a API da OmegaPayments nunca
  usando esse campo. Preencher qualquer placeholder e confirmar que só isso trava (inconsistência
  cosmética, não bug funcional).
- Ativar o gateway (nasce `ativo=0`) e testar prioridade/toggle igual já funciona pro InfoPago.

### 1.2 Split por usuário — regressão importante, testável agora
Arquivos: `ajax/salvar_splits_usuario.php`, `ajax/listar_splits_usuario.php`,
`admin/usuarios.php`, `admin/transacoes.php`, `admin/atualiza_banco.php`

- Configurar splits pro **mesmo usuário** em InfoPago e em OmegaPayments (abas separadas em
  `admin/usuarios.php`).
- Salvar o split de um gateway e confirmar que o do outro **não** é apagado (bug antigo: DELETE
  não era escopado por gateway).
- Re-rodar `admin/atualiza_banco.php` depois de configurar os dois e confirmar que ambos
  sobrevivem (segunda parte do bug: a limpeza de splits órfãos rodava
  `DELETE ... WHERE gateway_nome <> 'infopago'` a cada migração, apagando OmegaPayments junto).
- Gerar/simular uma venda por cada gateway e conferir em `admin/transacoes.php` que a % de split
  exibida corresponde ao gateway realmente usado naquela venda.

### 1.3 Fluxo de cobrança/webhook — só o que não depende da API real
- `webhook_omegapayments.php` responde 200 pra corpo vazio (ping de validação de URL) e pra JSON
  malformado, sem quebrar.
- POST fake pro webhook referenciando uma venda inexistente não altera nada (a confirmação real
  depende de `consultarCobranca()`, não do payload do webhook).

### 1.4 Cron de verificação
- `cron_verificar_pix.php` já era genérico por gateway antes desse commit — confirmar que
  resolve `omegapayments` via `resolveGatewayProvider()` sem lançar exceção quando não há
  credenciais válidas (deve logar e seguir, sem travar o cron pras vendas pendentes de InfoPago).

---

## 2. Stories — ❓

Arquivos: `funcoes/stories.php`, `assets/stories.js`, `parciais/barra_stories.php`, `api.php`,
`cron/cron_limpar_stories.php`

- **Upload:** foto (jpg/png/webp, até 5MB) e vídeo (mp4/webm, até 20MB) válidos publicam OK;
  arquivo acima do limite e extensão fora da whitelist são rejeitados com erro claro.
- **Limite de 20 ativos:** criar 20 stories, confirmar que a 21ª é rejeitada com mensagem
  específica e que nenhum arquivo órfão fica em `uploads/stories/` (o `api.php` deve deletar o
  arquivo já salvo se o insert falhar por limite).
- **Expiração:** story expira em 24h — com `expira_em` manipulado pro passado no banco, some da
  barra imediatamente (filtro em toda query), mesmo antes do cron rodar.
- **Cron de limpeza:** rodar manualmente, confirmar que remove linha do banco **e** arquivo em
  disco; rodar duas instâncias em paralelo (ou checar o lock file) pra confirmar que a segunda
  não roda concorrente.
- **Viewer:** navegação ‹/› e teclado, avanço automático (foto 5s, vídeo até 30s ou fim do
  vídeo), fechar no último story do grupo (não deve pular pro próximo usuário), marcar como
  vista ao abrir.
- **Ordenação da barra:** stories do próprio usuário sempre primeiro; depois usuários com
  não-vistas; depois vistas.
- **Caso vazio:** usuário sem stories de ninguém — só o botão "Criar" aparece, sem erro.
- **CSRF:** upload sem `X-CSRF-Token` (ex. sessão expirada) falha de forma limpa.

## 3. Comunidade — ❓

Arquivos: `funcoes/comunidade.php`, `admin/comunidade.php`, `comunidade.php`

- **CRUD admin:** criar link de cada tipo (grupo, canal, instagram, telefone, site, denúncia,
  outro), editar, reordenar (mover cima/baixo), ativar/desativar, excluir.
- **Validação de URL:** `https://` válido passa; `http://` (sem TLS) é **rejeitado** — confirmar
  que é o comportamento esperado antes de divulgar a página; `mailto:`/`tel:` nos formatos
  aceitos; URL vazia, `javascript:`, ou acima de 500 caracteres devem ser rejeitados.
- **Contador de membros:** `membros_max = 0` não mostra contador/badge; `membros_atual >=
  membros_max` (com max > 0) mostra "LOTADO" e vira card não-clicável.
- **Página pública:** só mostra links `ativo=1`, na ordem configurada; admin acessando é
  redirecionado pro dashboard (não pode ver a página pública); usuário comum acessando
  `admin/comunidade.php` é barrado.
- **Instalação do zero:** como `instalacao.php` não cria `comunidade_links`, confirmar que a
  página (admin e pública) mostra o fallback "rode Atualizar banco" em vez de erro fatal, antes
  de rodar a migração.

## 4. Sininho de notificações — ❓ (não commitado)

Arquivos: `funcoes/log.php`, `ajax/notificacoes.php`, `ajax/marcar_notificacao_lida.php`,
`parciais/sino_notificacoes.php`, `assets/js/notificacoes.js`

- Gerar uma atividade elegível (venda, pix gerado, lead) e confirmar que aparece no sino com
  contagem de não-lidas.
- Abrir o painel do sino ou clicar "Marcar todas como lidas" e confirmar que `atividades.lido_em`
  é preenchido e o badge zera.
- Confirmar que o polling de 30s atualiza sem recarregar a página, e que volta a atualizar ao
  trocar de aba e voltar (visibilitychange).
- Confirmar que em instalação nova (antes de rodar a migração), o backfill de
  `lido_em = criado_em` faz o sino nascer zerado (não mostrando todo o histórico como não-lido).
- Testar em página de admin (onde `atividades.id_usuario` normalmente não bate com o admin) —
  deve mostrar "Nenhuma notificação recente" sem quebrar.

## 5. Webhooks de saída — ❓ (não commitado)

Arquivos: `webhooks.php`, `funcoes/webhooks.php`

- **Pré-requisito:** sem rodar `atualiza_banco.php`, a página deve cair no estado "ainda não
  disponível" sem crashar — testar esse caminho primeiro.
- Criar um webhook (URL precisa ser `https://`; `http://` e URL com userinfo embutido devem ser
  rejeitados — reusa a checagem de SSRF do UTMify).
- Selecionar eventos (`user_joined`, `payment_created`, `payment_approved`) e, opcionalmente,
  escopar por bot.
- Dispará-los de verdade: criar um lead (user_joined), gerar um Pix (payment_created) e confirmar
  pagamento (payment_approved, pelo fluxo manual e pelo cron) — usar um endpoint de teste (ex.
  webhook.site) pra ver o payload chegando.
- Conferir a assinatura HMAC-SHA256 quando um secret é configurado.
- Forçar 5 falhas consecutivas (endpoint que sempre retorna erro) e confirmar que o webhook é
  auto-desativado (`ativo=0`), e que reativar manualmente zera o contador.
- Confirmar em `webhooks_envios` que cada tentativa (sucesso ou falha) é logada com status HTTP.
- Confirmar que um endpoint de cliente fora do ar/lento não trava o webhook do Telegram nem o
  cron de Pix (disparo é best-effort, nunca deve lançar exceção pra quem chamou).

## 6. Cor primária — ❓ (não commitado)

Arquivos: `admin/configuracoes.php`, `funcoes/configuracoes.php`, `tema_inline.php`

- Trocar a cor, salvar, confirmar validação do formato `#rrggbb` (rejeitar valor malformado se
  possível forçar via request manual).
- Confirmar que a cor se aplica nos temas claro **e** escuro (`--or`/`--or2`/`--orsoft`
  sobrescritas via `tema_inline.php`).

---

## Por onde eu começaria

1. Rodar `admin/atualiza_banco.php` (pré-requisito de tudo).
2. OmegaPayments: seções 1.1–1.2 — é o pedido explícito e tem a regressão mais arriscada
   (splits sumindo).
3. Stories + Comunidade (seções 2–3) — maior superfície de código novo.
4. Sininho + Webhooks de saída + cor (seções 4–6) — ainda não commitado, testar antes de decidir
   commitar.

Atualizar o estado (✅/🟡/❓/🔴) de cada item direto neste arquivo conforme for testando. Se virar
uma rodada extensa, criar um arquivo de resultado separado nesta mesma pasta
(`anotacoes/testes/`), seguindo o padrão de `anotacoes/rodada-de-testes-19-09.md`, referenciando
este mapa.
