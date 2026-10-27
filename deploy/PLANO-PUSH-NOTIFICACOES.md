# Plano — Avisos push de Pix gerado e Pix pago (Web Push completo, com iPhone)

Documento de execução, escrito em 20/10/2026 a pedido do dono (Caio) para ser entregue a uma sessão do Claude Code (`/goal`). **Nada aqui foi implementado ainda.** Fonte única do plano: `anotacoes/PENDENCIAS.md` só aponta para cá.

---

## 0. Como executar este documento (leia primeiro)

**Prompt sugerido para o `/goal`:**

> Implemente o plano de `deploy/PLANO-PUSH-NOTIFICACOES.md`, fase por fase (F0 → F4), na ordem do documento. Siga as regras da seção 0, use os padrões assumidos da seção 2, teste cada fase antes de seguir e atualize as notas ao final. Não pare para perguntar o que já tem padrão assumido.

**Regras que valem o tempo todo**
1. Ler `CLAUDE.md`, `anotacoes/OBSERVACOES.md` (seções 6, 6b, 8, 9) e `anotacoes/credenciais-vps-coyotebot.md` antes de mexer em servidor. **Nunca imprimir senha** em resposta, log ou commit.
2. Trabalhar na branch `new`. **Não commitar nem dar push sem o dono pedir.**
3. Sem Composer, framework ou dependência pesada (compatível com Hostinger compartilhada). Criptografia e assinatura só com `openssl`/`hash_hkdf` nativos.
4. Mudou schema? Altere **`instalacao.php`, `admin/atualiza_banco.php` e `deploy/schema.sql`** (e crie as tabelas direto na VPS pelo SQL antes de publicar o código).
5. **Servidor da VPS** (`coyotebot.com.br`): mexer só em `/var/www/painel`, `/root/painel-src`, `/var/backups/painel`. Publicar como no arquivo de credenciais: backup em `/var/backups/painel/deploy-antes/<data-nome>/` → `pscp` para `/root/painel-src/<caminho>` → `php -l` → `install -o root -g www-data -m 640` → `cmp -s`. **Antes de enviar um arquivo, baixe o da VPS e confira o diff**: só pode sair linha que seja sua (já houve sobrescrita de trabalho de outra sessão).
6. **Autorização pendente do dono:** criar/ativar o serviço `painel-push.service` (systemd) e a linha de cron do trabalhador na VPS são **mudanças de servidor**. Só fazer se o `/goal` trouxer a frase "autorizo criar o serviço painel-push na VPS". Sem ela: entregar `deploy/vps/painel-push.service` pronto + instruções em `INSTALACAO.md`, e testar o trabalhador rodando à mão (`php cron/push_worker.php --uma-vez`). O caminho de envio imediato sem trabalhador (seção 4, caminho B) não exige nada no servidor.
7. A homologação (`https://telegram.stackcode.com.br`, Hostinger) pode ser usada para testar; deploy por `pscp`, **nunca `git pull`** lá. Nada de `resetar/`.
8. Teste de tela com o Chrome de `tests/homologacao/ui/cdp.js` (agora apaga o perfil temporário). Conta de teste da VPS: `tests/vps/usuario-novo/estado/usuario_novo.json` (gitignored); se não existir, `node tests/vps/usuario-novo/00_nova_conta.js`.
9. Notas: **só** `anotacoes/TESTES.md` (uma linha por rodada), `OBSERVACOES.md` e `PENDENCIAS.md` (não criar nota nova). Scripts de teste documentados em `tests/vps/usuario-novo/README.md` (e `tests/push/README.md`, novo).
10. Depois de mexer em `assets/css/painel.css` rodar `node tests/css_chaves_balanceadas.js` (uma `}` a mais descarta regras em silêncio).

**Definição de pronto (tudo):** F0–F4 concluídas com os critérios da seção 12; testes passando e registrados; interruptor global **desligado por padrão**; notas atualizadas; resumo final ao dono dizendo o que foi feito, o que foi medido (latência, vazão) e o que falta ele validar nos aparelhos.

---

## 1. Objetivo e escopo

- **Avisar o vendedor no celular/navegador, fora do painel, na hora:** (a) **Pix gerado** (cobrança criada) e (b) **Pix pago** (venda aprovada). **Lead (/start) fica de fora.**
- **Prioridade do dono: o aviso sai NA HORA da compra, o mais rápido possível; o que não der para mandar na hora vai para a fila e é reenviado.**
- Alvos: **Android** (Chrome/Edge/Firefox/Samsung), **computador** (Chrome/Edge/Firefox, Safari no Mac) e **iPhone/iPad** (iOS/iPadOS 16.4+, **com o site adicionado à Tela de Início**: é a regra da Apple).
- **Ícone da notificação = logo do sistema** (a configurada em Admin → Configurações; white-label).
- Escala: projeção de ~65 mil vendas/dia; o plano precisa aguentar rajadas sem atrasar webhook nem site.
- **Fora do v1:** aviso de lead, horário de silêncio, push para o cliente final, push do split para o admin (padrão assumido, ver seção 2), Declarative Web Push do iOS 18.4, badge numérico no ícone do app.

## 2. Decisões

**Fechadas pelo dono**
- Eventos: só **Pix gerado** e **Pix pago**.
- Meta: **iPhone incluído** (PWA com manifest + conteúdo criptografado).
- **Entrega imediata** com fila como reserva.
- Ícone = **logo do sistema**.

**Padrões assumidos (usar se o dono não disser outra coisa; registrar no resumo final)**
1. Admin **não** recebe push do split no v1.
2. Ao ativar um aparelho, **os dois avisos nascem ligados** (o vendedor desliga o que não quiser).
3. Texto do aviso traz **valor + plano + bot**, **nunca** nome do cliente, e-mail ou código da transação.
4. Contato VAPID (`sub`): `https://<domínio>` vindo de `configSistema('url_base')`; se vazio, `mailto:` do e-mail do admin (Apple responde 403 se o `sub` for inválido).
5. Trabalhador como serviço systemd na VPS (**depende da autorização da seção 0, regra 6**) e modo cron com `flock` na homologação.
7. Limites: **10 aparelhos por vendedor**; validade do aviso: Pix gerado **10 min**, Pix pago **2 h**.
8. Horário de silêncio: v2.

---

## 3. Estado atual do sistema (verificado em 20/10/2026)

- **Só existe o sino** do painel: `parciais/sino_notificacoes.php`, `ajax/notificacoes.php`, `assets/js/notificacoes.js` (consulta a cada 30 s, só com a aba visível). Lê a tabela `atividades` (tipos `venda`, `pix_gerado`, `lead`). **Não há service worker, manifest, VAPID, `Notification` nem Composer.**
- **Pix pago** = `registrarAtividade(…, 'venda', …)`, gravado em 3 caminhos (`webhook.php`, `webhook_omegapayments.php`, `cron/cron_verificar_pix.php`) — **todos chamam `aposPagamentoConfirmado($venda)` em `funcoes/operacao.php:42`**, e cada um já garante a venda uma vez só (transição atômica `status != 'pago'`). **Esse é o gancho único do Pix pago.**
- **Pix gerado** = `registrarAtividade(…, 'pix_gerado', …)` em `funcoes/fluxo_blocos.php` (~linha 658), logo depois do INSERT da venda. Planos, packs, renovação de assinatura e Visual passam todos por `processarEEnviarBloco()` → esse ponto. **Gancho único do Pix gerado.**
- Os webhooks (`webhook.php`, `webhook_omegapayments.php`) **só respondem ao chamador no fim do script** (`http_response_code(200)` perto do fim); não usam `fastcgi_finish_request()`.
- Já existem e devem ser **reaproveitados**: `criptografarSegredo()/descriptografarSegredo()` (`funcoes/criptografia.php`), `verificarCsrf()` (`funcoes/csrf.php`), `limiteTaxaConsumir($chave,$max,$janela)` (`funcoes/limite_taxa.php`), `exigirChamadaDeCron($nome)` (`funcoes/cron_guarda.php`), `garantirConexaoBanco()` (`conexao.php`, o `wait_timeout` do MySQL é **20 s**), `configSistema()/definirConfigSistema()` (`funcoes/configuracoes.php`), `logoSistema()`, `faviconSistema()`, `nomeSistema()`, `fazerLogout()` (`funcoes/usuario.php`), `colunaExiste()` (`funcoes/operacao.php`). Padrão de cron: `flock` + `logs/<nome>.log` (rotacionado por `cron_rotacionar_logs`). Fila já existente como modelo: fila de e-mails em `cron_aviso_vencimento`.
- **Páginas:** `configuracao_usuario.php` (conta do vendedor; cartões `.a-ch`) e `admin/configuracoes.php` (abas `data-aba="identidade|dominios|google|email"`). O `<head>` comum é `tema_inline.php` (38 páginas o incluem).
- **VPS** (Ubuntu 26.04, 2 vCPU, 8 GB, PHP 8.5, MariaDB 11.8, systemd 259): extensões `openssl`, `curl` **com HTTP/2**, `sodium`, `gd` (PNG/JPEG/WebP/FreeType), `pcntl`, `posix`, `mbstring`; **sem `gmp`, `bcmath`, `apcu`**; sem Composer. Logo configurada hoje: `uploads/marca_logo.jpeg` (JPEG, sem transparência). Formatos aceitos para a logo no admin: png, jpg, jpeg, webp, ico. `uploads/` serve só estático (`.htaccess` bloqueia PHP).
- `.htaccess` da raiz: URL limpa (`/x` → `x.php`), `mod_headers` presente, regra `^(deploy|tests|resetar)/` bloqueada, `.md` bloqueado.

---

## 4. Arquitetura

```
Pix gerado / Pix pago
   └─ enfileirarPush()  → INSERT … SELECT em push_fila (1 linha por aparelho inscrito; 0 linhas se ninguém ativou)
        ├─ A) trabalhador vivo  → ele pega em ≤ 100 ms e envia por HTTP/2 já aberto
        ├─ B) sem trabalhador   → o próprio processo envia DEPOIS de responder ao webhook (fastcgi_finish_request)
        └─ C) falhou/sem vaga   → fica na fila, reenvia com espera curta (cron flock a cada minuto como rede)
Serviço de push (FCM / Mozilla / Apple / WNS) → aparelho → sw.js mostra a notificação
```

**O item sempre nasce na fila** (1 INSERT, ~1 ms): é o que garante que nada se perde. A entrega rápida acontece por cima disso.

**Escolha do caminho (automática):**
- `pushTrabalhadorVivo()`: arquivo `storage/push_batimento` com `mtime` < 10 s (`clearstatcache(true, $arq); time()-@filemtime($arq) < 10`). **Sem consulta ao banco.**
- **A:** vivo → só enfileira. O trabalhador varre a fila a cada **100 ms** ociosos (e repete sem pausa quando há trabalho), com conexões **HTTP/2 persistentes** (sem DNS/TLS por aviso). Meta: p50 < 150 ms, p95 < 500 ms entre o evento e a entrega ao serviço de push; **não ocupa processo do PHP-FPM**.
- **B:** não vivo → `register_shutdown_function('enviarPushesPosResposta')` (uma vez por requisição) + `ignore_user_abort(true)`; na função: `fastcgi_finish_request()` **antes** de qualquer envio (em CLI não existe; seguir direto), reservar **só os ids criados nesta requisição** e enviar. **Nunca enviar antes da resposta** (atrasaria o gateway/Telegram e causaria reenvio). Itens criados com `disponivel_em = NOW() + 2 s` para o trabalhador/cron não pegar antes; a reserva é atômica (`UPDATE … WHERE id IN (…) AND estado='pendente'`; só envia os que `rowCount` confirmou) → **nunca duplica**. Teto: se já houver > 40 itens `enviando` (`SELECT COUNT(*) … estado='enviando'`), **não tenta** e deixa para a fila. Timeouts do envio direto: conexão 3 s, total 6 s.
- **C:** qualquer falha (timeout, 429, 5xx, teto de simultâneos) mantém o item na fila com espera crescente.

**Nunca quebrar a venda:** todo o código de push no caminho quente fica em `try { … } catch (Throwable $e) { error_log(…); }`.

---

## 5. Banco de dados

Registrar nos **três** lugares (instalador, atualizador, `deploy/schema.sql`). Na VPS, criar pelo SQL antes do código.

```sql
CREATE TABLE IF NOT EXISTS push_inscricoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,                 -- sha256 do endpoint
  endpoint VARCHAR(600) NOT NULL,
  p256dh VARCHAR(100) NOT NULL,                    -- base64url, 65 bytes (começa em 0x04)
  auth VARCHAR(40) NOT NULL,                       -- base64url, 16 bytes
  plataforma ENUM('ios','android','desktop','outro') NOT NULL DEFAULT 'outro',
  apelido VARCHAR(80) NOT NULL DEFAULT '',         -- ex.: "iPhone · Safari"
  pref_pix_gerado TINYINT(1) NOT NULL DEFAULT 1,
  pref_pix_pago TINYINT(1) NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  falhas_seguidas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  primeira_falha_em DATETIME NULL,
  ultimo_envio_ok DATETIME NULL,
  ultimo_visto DATETIME NULL,
  ultimo_aviso_gerado_em DATETIME NULL,            -- janela de 30 s do Pix gerado
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_usuario (id_usuario, ativo),
  CONSTRAINT fk_push_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_fila (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_inscricao BIGINT UNSIGNED NOT NULL,
  tipo TINYINT UNSIGNED NOT NULL,                  -- 1 = pix_gerado, 2 = pix_pago
  id_venda INT NOT NULL,                           -- vendas.id (conferir o tipo real da coluna)
  estado ENUM('pendente','enviando') NOT NULL DEFAULT 'pendente',
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  disponivel_em DATETIME NOT NULL,
  expira_em DATETIME NOT NULL,
  reservado_ate DATETIME NULL,
  lote CHAR(16) NULL,
  criado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),   -- ms: base da métrica de latência
  UNIQUE KEY uq_push_item (id_inscricao, tipo, id_venda),
  KEY idx_push_pronto (estado, disponivel_em, id),
  KEY idx_push_lote (lote),
  CONSTRAINT fk_pushfila_insc FOREIGN KEY (id_inscricao) REFERENCES push_inscricoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_metricas_hora (
  hora DATETIME NOT NULL PRIMARY KEY,
  enviados INT UNSIGNED NOT NULL DEFAULT 0,
  falhas_temporarias INT UNSIGNED NOT NULL DEFAULT 0,
  inscricoes_removidas INT UNSIGNED NOT NULL DEFAULT 0,
  descartados INT UNSIGNED NOT NULL DEFAULT 0,
  agrupados INT UNSIGNED NOT NULL DEFAULT 0,
  latencia_soma_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latencia_n INT UNSIGNED NOT NULL DEFAULT 0,
  latencia_max_ms INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Chaves em `configuracoes` (via `definirConfigSistema`): `push_ativo` (`'0'|'1'`, **padrão `'0'`**), `push_vapid_publica` (base64url, 65 bytes), `push_vapid_privada` (PEM, **cifrado com `criptografarSegredo`**), `push_contato` (valor do `sub`), `push_icone_v` (hash curto da logo usada nos ícones).

Limpeza: a fila só guarda pendentes (apaga no sucesso); um passo do trabalhador apaga o que passou de `expira_em` e itens `enviando` com `reservado_ate` vencida são devolvidos (`tentativas+1`). `push_metricas_hora`: apagar > 30 dias no cron de limpeza existente ou no próprio trabalhador (1x/dia).

---

## 6. Backend — arquivo por arquivo

| Arquivo | O que fazer |
|---|---|
| `funcoes/push.php` (novo) | Núcleo. Funções (nomes sugeridos): `pushAtivo(): bool` (config + tabela existe; cache por requisição); `enfileirarPush(int $id_usuario, int $tipo, int $id_venda): void` (um `INSERT IGNORE … SELECT` das inscrições `ativo=1` do usuário com a preferência do tipo ligada; `disponivel_em` = agora (caminho A) ou agora+2 s (B); `expira_em` = +10 min (gerado) ou +2 h (pago); chama o disparo imediato quando for o caso); `pushTrabalhadorVivo(): bool`; `enviarPushesPosResposta(): void`; `validarEndpointPush(string $url): bool`; `criptografarPayloadPush(string $json, string $ua_pub_b64u, string $auth_b64u, ?string $efemera_priv_pem=null, ?string $salt=null): string` (parâmetros opcionais **só para o teste do vetor do RFC**); `cabecalhoVapid(string $endpoint): string` (JWT ES256, cache por `aud`); `montarMensagemPush(array $item): array`; `agruparPushes(array $itens): array`; `tratarRespostaPush(...)`; `esperaPush(int $tipo, int $tentativa): int`; `gerarChavesVapid(): array`. |
| `funcoes/push_icones.php` (novo) | Gerador de ícones a partir da logo (seção 10). |
| `cron/push_worker.php` (novo) | Trabalhador (seção 7). Modos: `--loop` (serviço), `--uma-vez` (teste/cron), e sem argumento = cron de 55 s com `flock`. Protegido por `exigirChamadaDeCron('push_worker')`. Log em `logs/push_worker.log`. |
| `funcoes/operacao.php` | Dentro de `aposPagamentoConfirmado()` (já em `try/catch`): chamar `enfileirarPush((int)$venda['id_dono'], 2, (int)$venda['id'])` (conferir os nomes dos campos do array `$venda`). |
| `funcoes/fluxo_blocos.php` | Logo depois do `registrarAtividade(…, 'pix_gerado', …)` (~linha 658): `enfileirarPush($id_usuario_dono_insert, 1, $id_venda_criada)`. |
| `ajax/push_chave.php` | GET → `{sucesso, chave_publica}` (login obrigatório). |
| `ajax/push_inscrever.php` | POST (CSRF) → registra/atualiza a inscrição do aparelho (seção 8). |
| `ajax/push_preferencias.php` | POST (CSRF) → liga/desliga `pref_pix_gerado`/`pref_pix_pago` de **um** aparelho do próprio usuário. |
| `ajax/push_teste.php` | POST (CSRF) → envia um aviso de teste a **este** aparelho (1 a cada 10 s e 5 por hora por usuário, `limiteTaxaConsumir`). |
| `ajax/push_remover.php` | POST (CSRF) → remove um aparelho (checa que é do usuário) ou, com `endpoint`, cancela o aparelho atual (usado no sair). |
| `ajax/push_listar.php` | GET → aparelhos do usuário (apelido, plataforma, preferências, último visto/ok). |
| `manifest.php` | Manifest dinâmico (seção 9); rota `/manifest.webmanifest` (regra no `.htaccess`), `Content-Type: application/manifest+json`. |
| `sw.js` (raiz) | Service worker (seção 9). **Estático**, sem PHP. |
| `assets/js/push.js` (novo) | Máquina de estados da tela e inscrição (seção 9). |
| `tema_inline.php` | Acrescentar no `<head>`: `<link rel="manifest" href="/manifest.webmanifest">`, `<meta name="theme-color">`, `<link rel="apple-touch-icon" href="/uploads/pwa/apple-touch-180.png?v=…">`, metas de app do iOS. **Não** colocar nas páginas públicas que não incluem o arquivo. |
| `configuracao_usuario.php` | Novo cartão "Avisos no celular e no navegador" (seção 9). |
| `admin/configuracoes.php` | Nova aba "Notificações" (seção 9). Ao salvar a logo (aba Identidade), chamar o gerador de ícones. |
| `funcoes/usuario.php` (`fazerLogout()`) / `logout.php` | O JS cancela a inscrição do aparelho antes de sair (a página de sair precisa dar chance ao JS: ver seção 9). |
| `.htaccess` | `Cache-Control: no-cache` para `sw.js`; tipo MIME do manifest; rewrite do manifest. |
| `deploy/vps/painel-push.service` (novo) | Unit systemd (seção 7). |
| `INSTALACAO.md`, `deploy/PLANO-VPS.md` | Instruções do serviço e da linha de cron. |

---

## 7. Trabalhador (`cron/push_worker.php`) — algoritmo

Rodar como `www-data`. Unit: `User=www-data`, `ExecStart=/usr/bin/php /var/www/painel/cron/push_worker.php --loop`, `Restart=always`, `RestartSec=2`, `Nice=10`, `MemoryMax=300M`, `RuntimeMaxSec=3600` (recicla contra vazamento), logs no arquivo do app.

Ciclo (repetir):
1. `touch storage/push_batimento` (batimento) e, a cada 30 s, gravar `push_worker_batimento` no banco só para o admin.
2. `garantirConexaoBanco()`.
3. **Manutenção a cada 30 s:** devolver itens `enviando` com `reservado_ate < NOW()` (`estado='pendente', tentativas=tentativas+1`); apagar `expira_em < NOW()` (contar em `descartados`); verificar **sobrecarga** (> 50 000 pendentes → apagar Pix gerado com mais de 2 min, manter Pix pago, avisar admin).
4. **Reservar lote** (atômico, vários trabalhadores seguros, sem `SKIP LOCKED`):
   ```sql
   UPDATE push_fila SET estado='enviando', reservado_ate = NOW() + INTERVAL 60 SECOND, lote = :lote
   WHERE estado='pendente' AND disponivel_em <= NOW()
   ORDER BY disponivel_em, id LIMIT 300
   ```
   depois `SELECT` por `lote` com `JOIN push_inscricoes`, `vendas` (valor, nome do produto) e `bots` (nome de usuário do bot).
5. **Agrupar** (`agruparPushes`) — regras **por aparelho**:
   - **Pix pago:** sempre individual. Só se houver **> 20 no mesmo aparelho no lote**: 19 individuais + 1 resumo "+N Pix pagos".
   - **Pix gerado:** no máximo **1 aviso a cada 30 s por aparelho**. Se `agora < ultimo_aviso_gerado_em + 30 s`, devolver os itens à fila com `disponivel_em = ultimo_aviso_gerado_em + 30 s` (acumulam). Fora da janela: 1 item → aviso individual; ≥ 2 itens → **um resumo** ("12 Pix gerados · R$ 598,80"); gravar `ultimo_aviso_gerado_em = NOW()`. Os itens resumidos são apagados e contados em `agrupados`.
6. **Montar + criptografar + assinar** cada mensagem (seção 8). Enviar em **`curl_multi` HTTP/2** (`CURLMOPT_PIPELINING = CURLPIPE_MULTIPLEX`), **64 simultâneos** (configurável), handles **reaproveitados** entre ciclos, `CURLOPT_CONNECTTIMEOUT=3`, `CURLOPT_TIMEOUT=10`, `CURLOPT_FOLLOWLOCATION=false`, `CURLOPT_PROTOCOLS=CURLPROTO_HTTPS`.
7. **Tratar cada resposta:**
   - **201/202:** apagar o item; `ultimo_envio_ok=NOW()`, `falhas_seguidas=0`; latência = `agora − criado_em` (ms) → métricas.
   - **404/410:** inscrição morta → apagar a inscrição (CASCADE leva a fila dela).
   - **400/401/403:** erro de assinatura/criptografia → **não repetir**, `error_log` + alerta ao admin (contador), apagar o item.
   - **413:** bug de tamanho → apagar + log.
   - **429:** respeitar `Retry-After`; **disjuntor por host** (pausar esse host pelo tempo indicado) e reagendar.
   - **5xx / timeout / erro de rede:** `tentativas+1`, `disponivel_em = NOW() + espera`; passou do máximo → apagar e contar `descartados`.
   - Inscrição com **20 falhas seguidas e primeira falha há > 24 h** → `ativo=0`.
8. **Espera de nova tentativa** (com ±20% de variação): Pix pago `[2, 10, 60, 300, 1200]` s, máx. 5 tentativas; Pix gerado `[5, 30, 120]` s, máx. 3.
9. Atualizar `push_metricas_hora` (UPSERT por lote). Dormir 100 ms só se a fila estiver vazia.

---

## 8. Criptografia, VAPID, payload e segurança

### 8.1 Conteúdo (RFC 8291, `aes128gcm`) — só `openssl` nativo
Para cada mensagem:
1. `ua_public` (65 bytes) e `auth_secret` (16 bytes) vêm da inscrição.
2. Chave **efêmera** P-256: `openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC])`; `as_public = "\x04" . x . y` (de `openssl_pkey_get_details`, cada coordenada com 32 bytes, preenchendo com zeros à esquerda).
3. Montar a chave pública do aparelho como recurso OpenSSL: DER SPKI = `hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . ua_public` → PEM → `openssl_pkey_get_public`.
4. `ecdh = openssl_pkey_derive($ua_key, $efemera, 32)`.
5. `salt = random_bytes(16)`.
6. `key_info = "WebPush: info\0" . ua_public . as_public`; `ikm = hash_hkdf('sha256', $ecdh, 32, $key_info, $auth_secret)`.
7. `cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt)`; `nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt)`.
8. `dados = payload . "\x02"` (um registro só; o payload cabe com folga: **< 400 bytes**, máx. 3993); `cifra = openssl_encrypt($dados, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16)`.
9. Corpo = `salt(16) . pack('N', 4096) . chr(65) . as_public . cifra . tag`. Cabeçalho `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`.

**Obrigatório testar com o vetor oficial do RFC 8291 (Apêndice A)**: copie as constantes (chaves do servidor/aparelho, `auth_secret`, `salt`, texto e corpo esperado) **do RFC** (rfc-editor.org/rfc/rfc8291) — **não de memória** — e faça `criptografarPayloadPush()` aceitar a chave efêmera e o salt injetados só no teste.

### 8.2 VAPID (RFC 8292)
- Par de chaves P-256 gerado **uma vez** (`gerarChavesVapid()`), privada em PEM **cifrada** com `criptografarSegredo`, pública em base64url de 65 bytes (é o `applicationServerKey` do navegador e o `k=` do cabeçalho).
- JWT ES256: header `{"typ":"JWT","alg":"ES256"}`, claims `{"aud": "<scheme>://<host do endpoint>", "exp": agora+12h (máx. 24h), "sub": push_contato}`; assinatura com `openssl_sign(..., OPENSSL_ALGO_SHA256)` **convertendo DER → R‖S (64 bytes)**. **Cache por `aud` por ~11 h** (memória do trabalhador; no caminho B assinar na hora).
- Cabeçalhos: `Authorization: vapid t=<jwt>, k=<chave pública>`, `TTL` (= validade do tipo em segundos), `Urgency` (`high` no Pix pago, `normal` no Pix gerado), `Topic` (só nos resumos, ≤ 32 caracteres url-safe, para o serviço **substituir** o resumo anterior).
- Verificar o JWT no teste com `openssl_verify` e a chave pública.

### 8.3 Payload (JSON curto, **sem dados pessoais**)
```json
{"k":"pp","t":"Pix pago · R$ 49,90","b":"Plano Mensal · @meubot","g":"pp-1234","u":"/vendas","ts":1792500000000,"n":1,"v":"a1b2c3"}
```
`k`: `pp` Pix pago, `pg` Pix gerado, `rg` resumo de gerados, `rp` resumo de pagos · `t`/`b` título e corpo · `g` tag · `u` caminho a abrir · `ts` milissegundos · `n` quantidade · `v` versão do ícone. Gerado: título "Pix gerado · R$ X", corpo "Aguardando pagamento · <plano>". Resumos: "12 Pix gerados · R$ 598,80" / "nos últimos 30 s". Sem plano/bot disponível: corpo "Venda aprovada" / "Cobrança criada". **Nunca** nome do cliente, e-mail, ID do Telegram ou txid.

### 8.4 Segurança (checklist de aceite)
- **SSRF** — o servidor faz POST para um endereço informado pelo usuário: `validarEndpointPush()` aceita só `https`, ≤ 600 caracteres, sem usuário/senha/porta, host **não-IP**, e host **dentro da lista** de serviços de push (`fcm.googleapis.com`, `*.push.services.mozilla.com`, `web.push.apple.com`, `*.push.apple.com`, `*.notify.windows.com`); curl **sem seguir redirecionamento**, só HTTPS. Em **teste**, um modo que aceita o servidor falso só quando `php_sapi_name()==='cli'` **e** a variável de ambiente `PUSH_MODO_TESTE=1` — **nunca** por configuração, banco ou HTTP.
- Chaves validadas: `p256dh` decodifica para 65 bytes e começa em `0x04`; `auth` decodifica para 16 bytes.
- Todo endpoint `ajax/push_*`: login + `verificarCsrf()` + `limiteTaxaConsumir()` (inscrever: 20/h por usuário; teste: ver acima) e checagem de posse.
- **Máx. 10 aparelhos por usuário** (ao passar, remove o mais antigo). Mesmo `endpoint_hash` registrado por outro usuário → **troca o dono** (mesmo aparelho, outra conta) em vez de duplicar.
- Apagar a conta apaga as inscrições (CASCADE).
- Logs e métricas **não** gravam o endpoint completo (só host + 8 primeiros caracteres do hash).
- A chave privada VAPID nunca é mostrada; o admin vê só a impressão digital (primeiros 12 caracteres do sha256 da pública). **Regenerar as chaves invalida todos os aparelhos** → exige confirmação forte (digitar "REGENERAR").
- Nada de segredo no `sw.js` nem no payload.

---

## 9. Cliente (front) e telas

### 9.1 Manifest (`manifest.php`)
`name`/`short_name` = `nomeSistema()`; `id` e `scope` `/`; `start_url` `/index`; `display` `standalone`; `background_color` `#0c0d11` e `theme_color` = `corPrimariaSistema()`; `lang` `pt-BR`; `icons`: `icone-192.png`, `icone-512.png`, `icone-512-maskable.png` (`purpose: "maskable"`) em `/uploads/pwa/`. (O iPhone usa o `apple-touch-icon` do `<head>`.)

### 9.2 `sw.js` (raiz; referência de comportamento)
```js
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) {}
  const v = d.v ? '?v=' + encodeURIComponent(d.v) : '';
  event.waitUntil(self.registration.showNotification(d.t || 'Nova atividade no painel', {   // SEMPRE mostra: o iOS revoga a inscrição se chegar push sem notificação
    body: d.b || 'Abra o painel para ver.',
    icon: '/uploads/pwa/icone-192.png' + v,
    badge: '/uploads/pwa/badge-96.png' + v,
    tag: d.g || 'painel', renotify: d.k === 'rg' || d.k === 'rp',
    timestamp: d.ts || Date.now(), data: { u: d.u || '/vendas' }
  }));
});
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const alvo = new URL((event.notification.data && event.notification.data.u) || '/vendas', self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((abas) => {
    for (const a of abas) { if ('focus' in a) { a.navigate && a.navigate(alvo); return a.focus(); } }
    return self.clients.openWindow(alvo);
  }));
});
self.addEventListener('pushsubscriptionchange', (event) => {   // chave/endpoint renovados pelo navegador
  event.waitUntil((async () => {
    const r = await fetch('/ajax/push_chave.php', { credentials: 'same-origin' }); const j = await r.json();
    const sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: j.chave_publica });
    await fetch('/ajax/push_inscrever.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subscription: sub.toJSON(), renovacao: true }) });
  })());
});
```
Sem `fetch` handler e sem cache offline (zero risco de página velha). Registrar com `navigator.serviceWorker.register('/sw.js', { scope: '/' })`. Servir com `Cache-Control: no-cache`. (A renovação pelo `sw.js` pode não conseguir o CSRF: se o endpoint exigir token, a renovação completa-se na próxima abertura do painel — `push.js` confere ao carregar se a inscrição local bate com a do servidor e reenvia.)

### 9.3 Tela do vendedor (`configuracao_usuario.php`, cartão "Avisos no celular e no navegador")
Estados (decididos no `push.js`; textos em pt-BR):
1. **Sem suporte** (sem `serviceWorker`/`PushManager`/`Notification`): "Este navegador não recebe avisos. Use o Chrome, o Edge, o Firefox ou o Safari atualizado."
2. **iPhone/iPad fora da Tela de Início** (`/iPhone|iPad/` e não `standalone`): guia ilustrado: "1) Abra esta página no **Safari**. 2) Toque em **Compartilhar** (quadrado com seta para cima). 3) Toque em **Adicionar à Tela de Início**. 4) Abra o app pelo ícone novo e volte aqui para **Ativar avisos**." Detecção: `navigator.standalone === true || matchMedia('(display-mode: standalone)').matches`. Exige iOS/iPadOS 16.4+ (avisar se `PushManager` não existir mesmo instalado).
3. **Permissão ainda não pedida:** botão **"Ativar avisos neste aparelho"**. A permissão (`Notification.requestPermission()`) e o `pushManager.subscribe({userVisibleOnly:true, applicationServerKey})` **só dentro do clique** (nunca ao carregar a página).
4. **Permissão negada:** "Os avisos estão bloqueados neste aparelho. Libere nas configurações do navegador/do app e volte aqui." (Brave: "ative 'Usar serviços do Google para mensagens push'".)
5. **Ativo:** dois interruptores ("Pix gerado", "Pix pago"), botão **"Enviar aviso de teste"**, lista de aparelhos (apelido, plataforma, "visto há…", remover).
- Apelido: derivado do user-agent ("iPhone · Safari", "Android · Chrome", "Windows · Edge").
- **Convite discreto no sino** (uma linha "Ative os avisos neste aparelho") quando o usuário nunca ativou; some com "agora não" (lembrar por 30 dias em `localStorage`).
- **Sair:** o botão/link "Sair" chama primeiro `POST ajax/push_remover.php {endpoint}` (cancela o aparelho **atual** para esse usuário e `subscription.unsubscribe()` se ele não quiser manter) e só então segue para `logout`. (Computador compartilhado não pode continuar recebendo os avisos do vendedor anterior.)

### 9.4 Admin (`admin/configuracoes.php`, aba "Notificações")
Interruptor geral (`push_ativo`, **padrão desligado**); contato VAPID; botão "Gerar chaves" (só se não houver) e "Regenerar" (confirmação forte); **saúde:** batimento do trabalhador (verde < 10 s, vermelho ≥ 3 min), fila pendente, enviados e falhas em 24 h, latência média/máxima, inscrições por plataforma. **Alerta no sino do admin** se o batimento passar de 3 min ou as falhas passarem de 20% em 10 min (no máximo 1 alerta por hora, padrão do "Bot desconectado").

---

## 10. Ícone = logo do sistema (`funcoes/push_icones.php`)

Origem: `configSistema('logo')` (hoje `uploads/marca_logo.jpeg`). **Ordem de reserva:** logo (png/jpg/webp) → favicon (png/jpg/webp) → mascote `assets/img/coyote-animado.webp` (o padrão `logo-padrao.svg` e `.ico` o GD não lê). Saída em `uploads/pwa/` (estático):

| Arquivo | Uso | Regra |
|---|---|---|
| `icone-192.png` | `icon` da notificação (Android/computador) | logo inteira dentro do quadrado, centralizada, sem cortar |
| `icone-512.png` | manifest | idem |
| `icone-512-maskable.png` | manifest (maskable) | logo a **80%** no centro (zona segura), fundo preenchido |
| `apple-touch-180.png` | `apple-touch-icon` (**o iPhone ignora o `icon` do payload e usa este**) | idem; sem transparência (fundo sólido) |
| `badge-96.png` | `badge` (ícone pequeno da barra de status do Android) | **branco com transparência** — com logo colorida o Android desenha um quadrado branco. Usar o **canal alfa** da logo quando for PNG/WebP com transparência; se for JPEG/ICO (sem alfa), usar um **glifo branco neutro** em vez de adivinhar a silhueta |

Regerar **ao salvar a logo no admin** e, por segurança, quando o `mtime` da logo diferir do gravado; atualizar `push_icone_v` (hash curto da logo). O payload leva só `v`, e o `sw.js` monta `…?v=<v>` (trocar a logo atualiza o ícone sem mudar o `sw.js`). O admin avisa se a logo for < 512 px ("envie ao menos 512×512; PNG com fundo transparente fica melhor"). `title`/nome do app = `nomeSistema()`.

---

## 11. Escala e eficiência (por que este desenho aguenta)

Premissa (a **medir**, não a prometer): ~65 mil vendas/dia; Pix gerado 3–10× isso; ~1,5 aparelho por vendedor que ativou → pior caso ≈ 1 milhão de envios/dia ≈ 12/s em média e ~120/s em rajada.
- **Caminho quente:** 1 `INSERT IGNORE … SELECT` (0 linhas para quem não ativou; alvo ~1 ms; zero consulta extra por evento — o batimento é lido por `filemtime`).
- **Trabalhador (A):** ~64 simultâneos ÷ ~0,2 s ≈ **300/s por trabalhador** (HTTP/2 multiplexado, conexão aquecida) → folga ≈ 2,5× na rajada; **segundo trabalhador** funciona sem mudar nada (reserva atômica). O agrupamento corta o Pix gerado em até ~90% nas rajadas.
- **CPU:** cripto ≈ 1 ms/mensagem → ~17 min de CPU por milhão/dia (~1% de 1 dos 2 núcleos). **Memória** do trabalhador < 100 MB. `Nice=10`: o site vence o trabalhador.
- **Caminho B** (reserva): ocupa 1 processo do FPM por ~0,2–0,4 s por aviso, **por isso tem teto (40 em voo) e só serve de reserva**.
- **Banco:** a fila só tem pendentes (apaga no sucesso) → pequena; índices `(estado, disponivel_em, id)` e `(id_usuario, ativo)`.
- Isolamento de falha: exceção engolida no caminho quente; push fora do ar ≠ venda afetada.

---

## 12. Fases, entregáveis e critérios (estimativa **4,5 a 5,5 dias úteis**)

**F0 — Decisões e chaves (0,25 d).** Confirmar a seção 2 (usar os padrões se o dono não responder). Criar `funcoes/push.php` só com `gerarChavesVapid()`; chaves **não** são geradas em produção até a aba do admin existir.

**F1 — Núcleo (1,25 d).** Tabelas (3 lugares + VPS); `criptografarPayloadPush`, `cabecalhoVapid`, DER→R‖S, `validarEndpointPush`, endpoints `ajax/push_*`.
*Pronto quando:* `tests/push/unitario_cripto.php` passa com o **vetor do RFC 8291**; JWT VAPID confere com `openssl_verify`; `unitario_ssrf.php` (≥ 30 casos: `http`, IP, `localhost`, porta, `user@`, redirecionamento, host fora da lista, `https://fcm.googleapis.com.evil.com`, `@`, unicode/IDN, comprimento, etc.) passa.

**F2 — Fila, trabalhador, envio imediato, ganchos, métricas, admin (1,75 d).** `enfileirarPush`, `push_worker.php`, caminho B (`enviarPushesPosResposta`), ganchos em `aposPagamentoConfirmado()` e `fluxo_blocos.php`, métricas, aba do admin, unit systemd (**instalar só com autorização**).
*Pronto quando:* `tests/push/integracao_navegador_simulado.php` passa (o teste gera as chaves do aparelho, inscreve num servidor falso, o trabalhador envia e o teste **decifra e confere título/corpo/tag**); reserva atômica sem duplicar com 2 trabalhadores e com o caminho B concorrendo; backoff e descarte conforme a seção 7.

**F3 — Front (1 d).** `manifest.php`, `push_icones.php`, `sw.js`, `push.js`, tags no `tema_inline.php`, cartão da conta, convite no sino, sair cancela o aparelho, `.htaccess`.
*Pronto quando:* teste de tela (`tests/vps/usuario-novo/36_push_tela.js`) passa (ver seção 13).

**F4 — Carga, resiliência e aparelhos reais (1 d).** Seção 13.
*Pronto quando:* metas de latência e vazão atingidas **e registradas em `TESTES.md`**; roteiro de aparelhos entregue ao dono.

**Liberação:** interruptor **desligado** → ligar só para o admin/dono → 2–3 vendedores → todos. **Volta atrás:** `push_ativo = 0` e parar o trabalhador (as tabelas ficam inofensivas; o caminho quente volta a ser no-op).

---

## 13. Testes

Criar `tests/push/` (+ `README.md`) e `tests/vps/usuario-novo/3x_push_*.js`. Registrar cada rodada em `anotacoes/TESTES.md` (1 linha) e os scripts novos nos README.

**Unitários (`tests/push/`, rodam sem banco nem rede):**
- `unitario_cripto.php` — vetor **RFC 8291**; round-trip (a própria rotina de teste decifra o que gerou, com chaves aleatórias); payload no limite; JWT VAPID + DER→R‖S (64 bytes sempre, inclusive quando `r`/`s` têm 31 bytes).
- `unitario_ssrf.php` — tabela do validador (seção F1).
- `unitario_agrupamento.php` — regras do Pix gerado (janela de 30 s, 1 vs ≥ 2 itens, devolver à fila) e do Pix pago (individual; > 20 → resumo), esperas (`esperaPush`), máximos de tentativas.
- `unitario_icones.php` — logo PNG transparente, JPEG, WebP, retangular, minúscula (< 192 px) e ausente → gera os 5 arquivos com as dimensões certas; `badge-96.png` sem quadrado branco (a maioria dos pixels transparente) para PNG e glifo neutro para JPEG; mudar a logo muda `push_icone_v`.

**Integração com navegador simulado (`integracao_navegador_simulado.php`):** servidor falso (`tests/push/servidor_falso.php`, `php -S 127.0.0.1:<porta>`, com latência e falhas injetáveis: 201, 410, 429+Retry-After, 500, timeout) + "aparelho" que gera P-256/auth, inscreve e **decifra** o que chega; roda com `PUSH_MODO_TESTE=1`. Casos: Pix pago e Pix gerado chegam com o texto certo e **sem nome de cliente/txid**; 410 apaga a inscrição; 429 pausa o host e reagenda; 500 reagenda com a espera certa e descarta no máximo; 2 trabalhadores + caminho B não duplicam; `kill -9` no meio do lote → nada se perde (item volta e é enviado de novo; duplicata só colapsa pela `tag`).

**Gancho ponta a ponta:** com um vendedor de teste, uma venda pendente e `aposPagamentoConfirmado($venda)` chamado por CLI → aparece 1 item por aparelho inscrito e **0** para quem não ativou; na homologação (que tem o simulador de Telegram/Omega em `tests/homologacao/`), simular o webhook "pago" da Omega e ver o aviso sair. Provar que a venda **continua** sendo confirmada mesmo com a tabela `push_*` ausente/quebrada (o `try/catch` funciona).

**Latência (alvo):** evento → entrega ao servidor falso: **caminho A p50 < 150 ms, p95 < 500 ms; caminho B p95 < 800 ms**; e o webhook responde **no mesmo tempo** com o push ligado e desligado (comparar medianas).

**Carga:** semear 200 mil itens (aparelhos de teste apontando para o servidor falso com 150 ms de latência) e medir vazão, p95 fila→entrega, CPU, memória e carga no MariaDB; **rodar junto** com a bateria `tests/estresse` (webhooks) para provar que o webhook não piora. Reportar os números reais em `TESTES.md` (e corrigir as estimativas da seção 11 se forem diferentes).

**Tela (`36_push_tela.js` etc., Chrome de teste):** estados do cartão (com `PushManager`/`Notification` simulados por `Page.addScriptToEvaluateOnNewDocument`, e `Browser.grantPermissions` quando fizer sentido; o Chrome de teste **não** recebe push de verdade); UA de iPhone sem `standalone` mostra o guia; manifest válido (JSON, `icons` existem e têm o tamanho declarado, tipo MIME), `sw.js` com MIME `text/javascript`, `Cache-Control: no-cache` e escopo `/`; tags no `<head>` das páginas do painel; **sair cancela a inscrição do aparelho**; permissão pedida só no clique; admin: aba "Notificações", interruptor, saúde.

**Conferência real (com o dono; entregar o roteiro no resumo final):** (1) **Android** com o navegador fechado e a tela apagada: gerar um Pix e pagar → 2 avisos chegam, com a logo e abrindo `/vendas`; (2) **iPhone** com o site na Tela de Início, tela bloqueada: idem; (3) Chrome, Edge e Firefox no computador; Safari no Mac; (4) trocar a logo no admin e ver o ícone novo; (5) negar a permissão e ver a tela explicar como reativar; (6) sair da conta e confirmar que o aparelho **para** de receber.

---

## 14. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| Cripto própria com erro silencioso | Vetor oficial do RFC 8291 + round-trip + decifragem no teste de integração |
| iOS revoga inscrição se o push não mostra notificação | `sw.js` **sempre** chama `showNotification`; payload ilegível vira texto genérico |
| Rajada de campanha (milhares de Pix em minutos) | Agrupamento de Pix gerado, disjuntor por host, modo sobrecarga, 2º trabalhador possível |
| Caminho B ocupando o FPM | Teto de 40 em voo, timeouts curtos, só roda sem trabalhador |
| SSRF por endpoint malicioso | Lista de hosts permitidos, sem redirecionamento, só HTTPS, validação de chaves |
| Aparelho compartilhado recebendo aviso de outro | Sair cancela o aparelho; reinscrever troca o dono do endpoint |
| Logo JPEG/ICO/SVG sem transparência ou ilegível | Ordem de reserva da logo, badge neutro, aviso no admin |
| Trabalhador morto sem ninguém ver | Batimento por arquivo + alerta no sino do admin + caminho B assume |
| Mudança no servidor sem autorização | Seção 0, regra 6 |
| Regenerar chaves derruba todos | Confirmação forte; interface mostra o aviso |

---

## 15. Ao terminar — notas a atualizar
- `anotacoes/TESTES.md`: uma linha por rodada (o que foi testado, números de latência/vazão medidos, o que foi corrigido).
- `anotacoes/OBSERVACOES.md`: "Avisos push" em §8 (decisão de produto: Pix gerado e Pix pago, logo do sistema, entrega imediata com fila) e em §9 (armadilhas: iOS exige Tela de Início e notificação sempre visível; `sw.js` sem cache; badge precisa ser monocromático; regenerar chaves invalida aparelhos; hosts permitidos).
- `anotacoes/PENDENCIAS.md`: apagar o ponteiro deste plano e as decisões já resolvidas; manter só o que falta (ex.: validação nos aparelhos, autorização do serviço, horário de silêncio na v2).
- `CLAUDE.md`: uma linha na seção da pasta `/deploy` apontando este plano e o serviço `painel-push`.
- `INSTALACAO.md` / `deploy/PLANO-VPS.md`: serviço systemd (ou linha de cron) do trabalhador e a nova tabela no schema.
- `tests/vps/usuario-novo/README.md` e `tests/push/README.md`: scripts novos.
