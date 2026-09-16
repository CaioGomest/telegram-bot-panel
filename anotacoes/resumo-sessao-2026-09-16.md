# Resumo da sessão — 2026-09-16 (escala + 3 varreduras de segurança)

Nota de referência (não é TODO em si) juntando tudo que foi analisado e corrigido numa sessão só, motivada pelo Caio pedindo uma análise de potência/escala do sistema pra ~700 usuários vendendo ~120/dia cada (~84 mil vendas/dia). Serve pra não precisar reler 4 arquivos separados da próxima vez — os detalhes completos de cada achado continuam nos arquivos individuais linkados.

## 1. Análise de escala e segurança nas vendas

Ver [[analise-escala-seguranca-vendas]]. Motivada pela pergunta original: o sistema aguenta 700 usuários × ~120 vendas/dia?

**Corrigido:**
- Condição de corrida entre os 3 (na prática 4, contando a renovação automática) caminhos que confirmam pagamento — `UPDATE ... WHERE status != 'pago'` atômico (compare-and-swap via lock de linha do InnoDB) em vez de `SELECT` + `UPDATE` separados. Sem isso, duas confirmações simultâneas da mesma venda disparavam `dispararSplitInfopago()` duas vezes → repasse Pix duplicado de dinheiro real. Aplicado em `webhook.php`, `webhook_infopago.php` (PIX comum e renovação `cobsr`) e `cron/cron_verificar_pix.php` (inclusive proteção equivalente na expiração, pra não expirar por engano uma venda que acabou de ser paga).
- Índice único em `vendas.transacao_id` + índice em `vendas.id_assinatura` — sem isso, toda notificação de pagamento fazia *full table scan*, que piora com o crescimento de ~84 mil vendas/dia (~30M linhas/ano). Migração já rodada no banco de dev local.
- `flock` + `LIMIT 200` em `cron/cron_verificar_pix.php` — sem isso, o cron podia se sobrepor sozinho em volume alto (processava tudo sequencialmente, sem lote, sem trava).

**Ainda pendente (registrado, não corrigido):**
- Decisão de infraestrutura — avaliar VPS antes de bater volume real de 700 usuários ativos (hospedagem compartilhada Hostinger tem teto de PHP-FPM/MySQL concorrente; todo o fluxo de venda é síncrono, sem fila).
- mTLS sem verificar certificado do servidor (`VERIFICAR_CERTIFICADO_SERVIDOR = false`) — aguardando obter a CA raiz da ONZ Software.
- `LOCK_EX` faltando em alguns `file_put_contents` de log + rotação de log — baixa prioridade.

## 2. Varredura 08 — LFI via bloco de imagem do fluxograma, fixação de sessão

Ver [[varredura-08-lfi-fluxograma-sessao]].

**Corrigido:**
- **LFI crítico** — um dono de bot conseguia gravar um `image_path`/`video_path`/`audio_path` arbitrário (ex. `"config.php"` ou `"certificados/cert_5_1.pem"`, nome previsível) direto via API, pulando a tela do editor, e o próprio bot dele reenviava esse arquivo do servidor de volta pro Telegram dele — vazando credenciais de banco de toda a plataforma e certificado de gateway de **outros** usuários. Corrigido em duas camadas: validação no envio (`webhook.php`, `webhook_infopago.php`, `cron_verificar_pix.php` — só aceita caminho que resolva de fato pra dentro de `uploads/`) e na gravação (`api.php::salvar_fluxo`/`importar_fluxo` — zera qualquer caminho fora do padrão antes de salvar no banco).
- Erro de PDO exposto em `traqueamento.php` (mensagem genérica + `error_log`, mesmo padrão já usado em outros arquivos).

**Ainda pendente (aguardando confirmação do Caio):**
- Fixação de sessão — falta `session_regenerate_id()` após login (`funcoes/usuario.php::fazerLogin()`). Expliquei o impacto (herdar sessão de outra pessoa, exige vetor auxiliar pra "plantar" o ID de sessão antes) mas ainda não recebi o ok pra aplicar.

## 3. Varredura 09 — XSS que vira admin, SSRF via postback UTMfy

Ver [[varredura-09-xss-admin-ssrf-utmfy]].

**Corrigido:**
- **XSS armazenado → sessão do admin** — modal "Detalhes" em `admin/usuarios.php` montava HTML via `innerHTML` sem escapar `bot.nome`/`log.titulo`/`log.descricao` (dados que um usuário comum controla: nome de fluxo, título de grupo do Telegram). Um payload nesses campos rodava na sessão do admin quando ele abrisse os detalhes daquela conta, podendo escalar pra admin/excluir usuário via `fetch()`. Corrigido com a mesma função `escaparHtml()` já usada em outras telas do projeto.
- **SSRF confirmado** — campo "Token ou URL de Postback" da UTMfy (`funcoes/utmfy.php`) aceitava qualquer URL e o servidor fazia POST pra lá a cada venda/PIX gerado, sem checar se o host era interno/privado. Corrigido com `urlPostbackEhSegura()` — resolve o host e recusa se cair em faixa privada/reservada (loopback, RFC1918, link-local — cobre o clássico endpoint de metadata de nuvem `169.254.169.254`).

**Ainda pendente:**
- `teste_gateway_infopago.php`/`teste_gateway_infopago_recorrente.php` exigem só `verificarLogin()`, não `verificarAdmin()` — qualquer usuário comum dispara cobrança real na conta compartilhada da InfoPago.
- `enviarEventoFacebook()`/`enviarEventoTikTok()` sem `CURLOPT_TIMEOUT` — mesma categoria de risco de exaustão de worker em pico de vendas já mapeada na análise de escala.

## 4. Varredura 10 — cron_ranking.php sem proteção, segredo de gateway em claro

Ver [[varredura-10-cron-ranking-segredos-formulario]].

**Corrigido:**
- `cron/cron_ranking.php` (recálculo do ranking com prêmio real) e `cron/cron_metricas_admin.php` — nenhum dos dois tinha sido coberto pela varredura 06 original (que só mapeou 5 dos 7 arquivos de `cron/`). `cron_ranking.php` fazia `DELETE`+`INSERT` numa transação sem `flock` — execução concorrente podia deadlockar e descartar a atualização do ranking de uma campanha. Corrigido com proteção CLI-ou-chave (nova constante `CHAVE_SECRETA_CRON` em `config.php`) + `flock` nos dois arquivos.

**Ainda pendente:**
- Segredo de gateway em texto puro no formulário de `gateways.php` (`client_secret`, `chave_pix`, `cashout_client_secret` ecoados com valor real) — o próprio arquivo já usa o padrão certo (`placeholder="••••••••"`) em `cert_password`, só falta replicar nos outros 3 campos.
- Token do bot também em claro, mas via resposta AJAX (risco bem menor).
- Concatenação de SQL em `admin/dashboard.php` (segura hoje por validação estrita, mas frágil a longo prazo — trocar por bind numa limpeza futura).

## ⚠️ Ação manual necessária no deploy (não é código, é operação no servidor)

1. **Rodar `admin/atualiza_banco.php`** (menu Debug) — cria os índices novos em `vendas` (item 1) e qualquer outra migração pendente. Só feito no banco de **dev local** até agora.
2. **Trocar `CHAVE_SECRETA_CRON`** no `config.php` do servidor (está com valor de exemplo, igual `CHAVE_CRIPTOGRAFIA_GATEWAYS`).
3. **Se o crontab da Hostinger chamar os crons via HTTP/wget** (não `php arquivo.php` direto): as URLs de `cron_ranking.php` e `cron_metricas_admin.php` cadastradas lá precisam ganhar `?chave=<valor de CHAVE_SECRETA_CRON>`, senão passam a responder 403 e param de rodar silenciosamente.

## Pendências consolidadas (o que ainda falta, por prioridade)

1. 🟡 CSRF em rotas administrativas — pendência antiga (varredura 02/05), continua em aberto, nenhuma mudança nessa sessão.
2. 🟡 Fixação de sessão (`session_regenerate_id()`) — aguardando confirmação do Caio (varredura 08).
3. 🟡 `teste_gateway_infopago*.php` sem `verificarAdmin()` (varredura 09).
4. 🟡 Segredo de gateway em claro em `gateways.php` (varredura 10).
5. 🟢 `enviarEventoFacebook`/`enviarEventoTikTok` sem timeout de cURL (varredura 09).
6. 🟢 Concatenação de SQL em `admin/dashboard.php` — sem risco real hoje, só robustez futura (varredura 10).
7. 🟢 Decisão de infra (VPS) + mTLS sem verificar certificado — itens de negócio/operação, não de código (análise de escala).

## Notas relacionadas

- [[analise-escala-seguranca-vendas]]
- [[varredura-08-lfi-fluxograma-sessao]]
- [[varredura-09-xss-admin-ssrf-utmfy]]
- [[varredura-10-cron-ranking-segredos-formulario]]
- [[pendencias]] — lista viva, sempre a mais atualizada linha a linha; este resumo é o retrato consolidado do dia.
