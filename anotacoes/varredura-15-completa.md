# Varredura 15 — auditoria completa

21/09/2026. Pedido do Caio: "Faça uma varredura completa". As varreduras 1-14 já cobriram
segurança (auth, XSS, CSRF, IDOR, upload, LFI, SSRF), mobile e documentação exposta — essa
sweep focou no que ainda não tinha sido auditado sistematicamente, mais uma revisão dura das
minhas próprias mudanças mais recentes (busca de leads, mini-ranking, N+1, alinhamento de
botões), que ainda não tinham passado por um olhar de segurança independente.

Metodologia: grep sistemático em cada categoria + leitura manual de todo achado antes de
confiar nele (vários "achados" do grep bruto eram falso-positivo — documentado abaixo onde
aconteceu).

---

## 🔴 Achados corrigidos

### 1. Falha ao gerar PIX vazava diagnóstico interno pro cliente — `webhook.php`

Quando o PIX falhava em **todos** os gateways tentados, o bot mandava pro **cliente que está
comprando** o rastro técnico inteiro: nome de gateway, "não configurado completamente", erro
cru da API do provedor, e até a mensagem de exceção do PHP.

Pior que o vazamento em si: o **vendedor nunca ficava sabendo** que o PIX dele estava
quebrado — esse diagnóstico só existia dentro da mensagem que o cliente recebia, e mais
ninguém via.

Corrigido: o rastro completo vai pro `logs/vendas_debug.log`, o dono do bot recebe uma
entrada em Atividade ("Falha ao gerar Pix"), e o cliente recebe uma mensagem genérica
pedindo pra tentar de novo.

**Bug que eu mesmo introduzi ao corrigir isso, e pego antes de commitar:** usei `$bot['id']`
no log, mas essa função nunca tem um array `$bot` em escopo (só `$id_usuario_dono`, uma
coluna isolada). E o `require_once` de `funcoes/log.php` só existia mais abaixo, no ramo de
sucesso — que roda *depois* deste ramo de falha na ordem de execução real. Sem o require
aqui, `registrarAtividade()` seria função indefinida e o webhook cairia com fatal error,
trocando um vazamento por uma quebra total do pagamento. Os dois corrigidos antes do commit.

### 2. Upload de vídeo/áudio do fluxo validava só a extensão — `api.php`

Todo upload de **imagem** do projeto confirma o conteúdo com `getimagesize()`. Só
`upload_video_fluxo`/`upload_audio_fluxo` validavam a extensão do *nome* do arquivo — um
`.txt` ou `.html` renomeado pra `.mp4` passava direto. O `.htaccess` de `uploads/` já bloqueia
execução de PHP ali (não é RCE), mas o arquivo seguia intacto pro fluxo e podia ser enviado
pra um lead de verdade via Telegram como se fosse o vídeo/áudio do produto.

Corrigido com `mime_content_type()` checando o prefixo `video/`/`audio/` do conteúdo real.
Testado localmente: um arquivo PHP renomeado pra `.mp4` é detectado como `text/x-php` e
bloqueado.

---

## ✅ Categorias auditadas, sem achado

- **Cobertura de auth/CSRF** — todo arquivo que processa POST (`$_POST`, `filter_input`,
  `$_REQUEST`) tem guarda de login e de CSRF. Nenhum buraco.
- **Injeção SQL** — nenhuma variável de entrada colada direto numa query fora de prepared
  statement. As únicas interpolações literais são os IDs de bot já documentados como
  seguros (vêm de consulta própria do servidor, nunca de input externo).
- **XSS** — nenhuma saída de dado de usuário sem `htmlspecialchars`, nem em contexto de
  atributo/JS (`onclick`, etc).
- **Segredos no git** — os valores reais de `config.php` nunca foram commitados em nenhum
  ponto do histórico (sempre placeholders `TROQUE_ESTA_CHAVE...`); a senha do banco/SSH
  também nunca apareceu em nenhum commit.
- **Reprocessamento de pagamento** — os dois caminhos de confirmação (`webhook.php` e
  `webhook_infopago.php`) usam `UPDATE ... WHERE status != 'pago'` + checagem de
  `rowCount()` antes de disparar split/tracking/liberação — proteção correta contra corrida
  entre webhook e cron de fallback.
- **Conexão com o banco** — só `conexao.php` (sempre com fuso -03:00 aplicado) cria PDO em
  runtime; nenhum arquivo abre conexão paralela que pudesse ficar com data errada.
- **Vazamento de erro de banco/exceção pro usuário** — revisão completa de todo
  `getMessage()` do projeto. Os que aparecem em tela são: scripts de admin/instalador (o
  próprio operador vendo o próprio erro, contexto onde isso é esperado) ou saída de CLI de
  cron (vai pro output do cron, não pra nenhum visitante). O único caso real de vazamento pra
  um usuário não-autenticado era o item 1 acima, já corrigido.

## Revisão das minhas próprias mudanças recentes

- **Busca de leads** (`leads.php`) — `$where`/`$params` reaproveitados sem duplicação entre
  COUNT, listagem paginada e exportação CSV; ordem dos `?` confere com a ordem dos valores
  empurrados pro array. Sem desalinhamento.
- **Mini-ranking na dashboard**, **N+1 do admin dashboard**, **alinhamento de botões** — sem
  novo achado; já tinham sido testados ao vivo quando implementados.

---

## ✅ Bloqueio da sessão: resolvido — SSH trocou de senha, deploy concluído

Durante essa varredura, o acesso SSH ao servidor começou a rejeitar a senha documentada
(`FATAL ERROR: Configured password was not accepted`), não timeout de rede — o site em si
seguiu 100% normal o tempo todo. Já tinha acontecido uma vez antes (a Hostinger trocou a
senha em 16/09 sem aviso).

O Caio confirmou a senha nova (`Jshhhah626@`) e o acesso voltou. `anotacoes/credenciais-ssh-hostinger.md`
atualizado (arquivo gitignored, não versionado). Deploy concluído:

- `git pull` no servidor trouxe os dois commits pendentes.
- `php -l` sem erro nos dois arquivos tocados.
- **`registrarAtividade()` testado rodando de verdade em produção** (não só `php -l`), com a
  mesma cadeia de `require` usada no fix do webhook — linha de teste apagada depois.
- **A checagem de MIME testada em produção**: um arquivo PHP disfarçado de `.mp4` é detectado
  como `text/x-php` e seria bloqueado.
- Regressão: `login`, `index`, `bots`, `fluxo`, `leads` respondendo 200 sem erro; os dois
  webhooks (`webhook.php`, `webhook_infopago.php`) respondendo 200 — Telegram e InfoPago
  continuam recebidos normalmente.

Os dois achados estão **no ar**, não só no GitHub.

---

## Resumo

| categoria | resultado |
|---|---|
| Auth/CSRF | limpo |
| Injeção SQL | limpo |
| XSS | limpo |
| Upload — imagem | já era seguro |
| Upload — vídeo/áudio | 🔴 corrigido |
| Segredos versionados | limpo |
| Corrida em pagamento | limpo |
| Vazamento de erro pro usuário | 🔴 1 achado, corrigido |
| Fuso do banco | limpo |
| Acesso SSH | ✅ senha trocada pelo Caio, deploy concluído e verificado em produção |
