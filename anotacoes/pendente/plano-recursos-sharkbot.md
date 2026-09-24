# Portar recursos do SharkBot para o telegram-bot-panel

> Plano criado em 2026-09-23, a partir de uma análise ao vivo do sharkbot.com.br (logado
> manualmente pelo usuário) comparando com o padrão atual deste projeto. Ainda não implementado —
> ver seção "Ordem de implementação sugerida" no fim.

## Contexto da pesquisa (sharkbot.com.br, logado)

Analisei ao vivo, com o usuário logado manualmente, todas as telas pedidas. Resumo do que cada uma faz e como vou adaptar para os padrões do nosso projeto.

### 1. Navbar de Stories
Barra horizontal no topo do dashboard: círculo "+ Criar" (upload de foto/vídeo, máx. 30s) seguido dos avatares de outros usuários da plataforma. Clicar em um avatar abre um visualizador em tela cheia (mockup de celular) mostrando a mídia. É uma vitrine social tipo Instagram Stories — sem lógica de negócio, só engajamento/prova social entre usuários da plataforma.

**Plano de implementação (v1, enxuto):**
- Tabela nova `stories` (`id`, `id_usuario`, `tipo_midia` enum('foto','video'), `arquivo`, `criado_em`, `expira_em`) — expira em 24h como Instagram, um cron/checagem simples oculta expiradas (sem precisar de job dedicado: filtrar `WHERE expira_em > NOW()` nas queries).
- Upload em `uploads/stories/`, reaproveitando o padrão de validação de `funcoes/configuracoes.php::salvarArquivoMarca()` (extensão permitida, tamanho máx., `getimagesize()` para foto). Para vídeo: limite de tamanho (ex. 20MB) e extensão mp4/webm; duração de 30s é validada no client (JS), documentado como best-effort já que o servidor não tem ffmpeg disponível.
- Novo parcial `parciais/barra_stories.php`, incluído no topo de `index.php` (dashboard do usuário): avatar "+" seguido dos avatares de outros usuários com stories ativas (anel colorido = não visto, cinza = já visto — precisa de tabela leve `stories_visualizacoes(story_id, id_usuario)`).
- Modal de visualização (JS simples, sem dependência nova): mostra a mídia atual, avança para a próxima ao terminar/clicar, fecha com X ou Esc.
- Novo endpoint AJAX `ajax/criar_story.php` (upload) e `ajax/marcar_story_vista.php`.

### 2. Comunidade (bio-tree institucional)
Em sharkbot.com.br/comunidade: página estática estilo Linktree com logo, título, e uma lista de cards (Grupo WhatsApp 1-6 com contador `1024/1024` e badge "LOTADO" quando cheio, Canal de Atualizações, Instagram, Telefone, Site, Canal de Denúncias). É conteúdo institucional da plataforma (não por usuário).

**Plano de implementação:**
- Tabela nova `comunidade_links` (`id`, `tipo`, `titulo`, `subtitulo`, `url`, `icone`, `membros_atual`, `membros_max`, `ordem`, `ativo`) — segue o padrão de tabela simples com `ordem`/`ativo` já usado no projeto.
- Nova página admin `admin/comunidade.php` (CRUD dos links, só admin edita — mesmo padrão de `admin/configuracoes.php`).
- Nova página pública `comunidade.php`, card list com badge "LOTADO" quando `membros_atual >= membros_max`, senão o link fica clicável.
- Novo item de nav em `barra_lateral.php` (`$grupo_operacao`).

### 3. Webhooks
Tela completa de CRUD (peguei o formulário completo e os 3 exemplos de payload reais, veja abaixo). Suporta HMAC-SHA256, HTTPS obrigatório, desativação automática após 5 falhas seguidas, e escopo por "fluxo" (no nosso caso, por bot).

**Payloads capturados (evento → schema):**
- `user_joined` (Novo Lead): `customer{id,telegram_id,first_name,last_name,username,phone,email,is_vip}`, `bot{id,name,username}`, `flow{id,name}`, `tracking{utm_source,utm_campaign,ip}`, `joined_at`.
- `payment_created` (Pagamento Criado): igual + `transaction{id,external_id,status:"pending",amount,currency,gateway,plan_name,type,payment_method,pix_code,sales_code,created_at}`.
- `payment_approved` (Pagamento Aprovado): igual + `transaction.status:"paid"` + `paid_at` + `contact_capture_status`.

**Plano de implementação:**
- Tabela nova `webhooks` (`id`, `id_usuario`, `nome`, `url`, `secret` nullable, `eventos` JSON/CSV, `bot_id` nullable (NULL = todos os bots do usuário), `ativo`, `falhas_consecutivas`, `criado_em`).
- Tabela nova `webhooks_envios` (log leve, para os cards "Webhooks Ativos / Fluxos Monitorados / Total Enviados").
- `funcoes/webhooks.php`: `dispararWebhooks(int $id_usuario, string $evento, array $dados)` — monta o payload no formato acima usando dados que já existem em `leads`/`vendas`/`bots`, assina com HMAC-SHA256 (header `X-Webhook-Signature`), envia com timeout curto, incrementa/zera `falhas_consecutivas`, desativa com `ativo=0` ao chegar em 5.
- Chamar `dispararWebhooks()` nos mesmos pontos onde `registrarAtividade()` já é chamado hoje para `lead`, `pix_gerado` e `venda` (em `webhook.php`, `webhook_infopago.php`, `webhook_omegapayments.php`) — evita duplicar a lógica de "quando disparar".
- Página `webhooks.php`: cards de estatística, aviso de segurança, modal "Novo Webhook" (nome, URL https-only, secret com botão "Gerar", checkboxes de evento, seleção de bot), accordion "Exemplo de Payload" com os 3 JSONs reais acima.
- Novo item de nav em `barra_lateral.php`.

### 4. Notificações de vendas (sino no header)
**Achado importante:** o projeto já tem uma nota (`anotacoes/urgente/notificacoes-sino-header.md`) descrevendo exatamente esse pedido, criada em 2026-09-17 e adiada. Já existe infraestrutura pronta pra isso:
- Tabela `atividades` + `registrarAtividade()`/`listarAtividades()` (`funcoes/log.php`) — já grava `venda`, `pix_gerado`, `lead`, `sistema`.
- CSS `.ponto-vivo` (bolinha verde pulsante) já existe, usada em `ranking.php`.
- Padrão de cabeçalho `.acoes-cabecalho` + `.alternador-tema` já resolve onde o sino entra (do lado do botão de tema).

**Plano de implementação (decisões da nota, agora resolvidas):**
- Sino mostra: `venda`, `pix_gerado`, `lead` (não mostra `sistema`/login) — mesmo filtro que o dashboard do usuário já usa.
- Lido/não lido: adicionar coluna nullable `lido_em` em `atividades` (mais simples que tabela separada de leitura por usuário).
- "Ao vivo": polling a cada 30s (mesmo padrão do `ranking.php`), sem WebSocket/SSE.
- Fora do painel (push navegador/e-mail/Telegram do dono): fora de escopo deste plano.
- Novo botão `.sino-notificacoes` ao lado de `.alternador-tema` em `.acoes-cabecalho` de cada página (reaproveita a régua CSS mobile que já trata esse container).
- Novo endpoint leve `ajax/notificacoes.php` (lista as N mais recentes não lidas + contador) e `ajax/marcar_notificacao_lida.php`.
- Dropdown reaproveita a mesma estrutura visual de `parciais/lista_atividades.php` (ícone por tipo, tempo relativo).

### 5. Bio Link
Por usuário: nome, slug (`/b/{slug}`), lista de links (título + URL) — construtor tipo Linktree pessoal. **Decisão do usuário: só domínio próprio, sem domínios alternativos/cloaking.**

**Plano de implementação:**
- Tabela `bio_links` (`id`, `id_usuario`, `nome`, `slug` unique, `ativo`, `criado_em`) + `bio_link_itens` (`id`, `bio_link_id`, `titulo`, `url`, `icone`, `ordem`, `ativo`).
- Nova rota pública `b.php?slug=...` (ou regra de rewrite `/b/{slug}` seguindo o padrão de URLs limpas já usado no projeto — ver `anotacoes/url-sem-php.md`).
- Página de gestão `biolink.php` (usuário): criar/editar nome+slug, builder de itens (arrastar pra reordenar é opcional/v2; v1 usa botões subir/descer como já é feito em `admin/bots.php`).
- Novo item de nav em `barra_lateral.php`.

### 6. Reativar Pixel do TikTok (verificado contra a documentação oficial)
**Achado importante:** o projeto já tem uma implementação quase completa e **desativada de propósito** em 19/09/2026 ("ninguém tinha conta de TikTok Ads pra validar"): `funcoes/tiktok.php` intacto, colunas `tiktok_ativo/tiktok_pixel_id/tiktok_access_token` na tabela `usuarios_traqueamento`, UI comentada em `traqueamento.php`, chamada comentada em `funcoes/traqueamento.php`.

Confirmei contra a documentação atual da TikTok Events API v1.3 (`business-api.tiktok.com/portal/docs/report-app-web-offline-or-crm-events/v1.3`): o endpoint, header `Access-Token` e formato `event_source`/`event_source_id`/`data[]` em `funcoes/tiktok.php` **batem** com o oficial. **Sim, dá pra integrar o pixel via Events API** (server-side), do mesmo jeito que Facebook Conversions API já funciona hoje.

**O que precisa de ajuste antes de religar** (mesma classe de bug já corrigida no Facebook/UTMify, ver `anotacoes/revisao-traqueamento-facebook-utmify.md`):
- `properties.content_type` é obrigatório quando `contents[]` é enviado — está faltando em `funcoes/tiktok.php`. Adicionar `'content_type' => 'product'`.
- Ligar ao `montarUserDataTraqueamento()` (já existe, usado por Facebook/UTMify) em vez de receber só `id_telegram`/`first_name` nos pontos de chamada.
- `ip`/`user_agent`/`ttclid`/`ttp` continuam indisponíveis (mesma limitação do Facebook: bot do Telegram não vê isso) — documentar como limitação aceita, não bloqueante.

**Plano de implementação:**
- Corrigir `funcoes/tiktok.php` (content_type + qualquer outro ajuste pontual).
- Descomentar o bloco em `funcoes/traqueamento.php` (chamada `enviarEventoTikTok`) e em `traqueamento.php` (UI + JS `['facebook','utmfy','tiktok']`).
- Atualizar `anotacoes/revisao-traqueamento-facebook-utmify.md` ou criar nota nova documentando a reativação e a verificação contra a doc oficial.

### 7. Geolocalização / "Mapa de Leads por Estado" — PAUSADO

Achado que motivou a pausa: o Bot API do Telegram nunca expõe o IP de quem conversa com o bot (tudo passa pelos servidores do Telegram). Hoje `leads` não tem nenhuma coluna de IP/local, e `links_rastreamento` é só um identificador de deep-link (`t.me/bot?start=identificador`), não uma página HTTP capaz de capturar IP.

Pra esse mapa funcionar de verdade seria preciso um redirecionador HTTP próprio antes do link do bot (captura IP real, geolocaliza, e de brinde melhora o match rate do Facebook/TikTok Pixel) — isso é uma peça de infraestrutura nova, maior que os outros itens.

**A pedido do usuário: pausado.** Registrar esse achado como ponto de atenção pra quando for retomado, sem nenhuma mudança de código enquanto isso.

## Ordem de implementação sugerida

1. TikTok Pixel (menor risco, já quase pronto)
2. Notificações de vendas / sino (infraestrutura já existe)
3. Comunidade (CRUD simples, institucional)
4. Bio Link
5. Webhooks (maior integração com os 3 arquivos de webhook existentes)
6. Stories (o mais novo, upload de mídia + visualizador)

## Arquivos-chave a criar/editar
- Novo: `funcoes/webhooks.php`, `funcoes/stories.php`, `funcoes/comunidade.php`, `funcoes/biolink.php`
- Novo: `webhooks.php`, `comunidade.php`, `biolink.php`, `b.php`, `admin/comunidade.php`, `ajax/notificacoes.php`, `ajax/marcar_notificacao_lida.php`, `ajax/criar_story.php`, `ajax/marcar_story_vista.php`
- Editar: `barra_lateral.php` (novos itens de nav), `index.php` (barra de stories + sino), `funcoes/log.php` (coluna `lido_em`), `funcoes/tiktok.php`, `funcoes/traqueamento.php`, `traqueamento.php`, `admin/atualiza_banco.php` (migrações idempotentes de todas as tabelas novas), `assets/css/coyote.css` (estilos novos: stories, sino, badges)

## Decisões já tomadas com o usuário
- Geolocalização (item 7): pausada, só documentar.
- Bio Link: só domínio próprio (`/b/{slug}`), sem domínios alternativos/cloaking como a Shark.
