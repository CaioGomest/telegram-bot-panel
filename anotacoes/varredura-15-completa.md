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

## 🔴 Bloqueio da sessão: SSH parou de aceitar a senha

Durante essa varredura, o acesso SSH ao servidor (`185.213.81.10:65002`, usado o tempo todo
nas sessões anteriores) começou a rejeitar a senha documentada em
`credenciais-ssh-hostinger.md`, com erro explícito de credencial (`FATAL ERROR: Configured
password was not accepted`), não timeout de rede. **O site em si está 100% normal** — testei
`/login` e `/index` direto por HTTPS e respondem certo. O problema é isolado ao acesso remoto.

Testado 3 vezes ao longo da sessão (incluindo uma última tentativa antes de fechar este
relatório), sempre a mesma rejeição. Parei de tentar pra não arriscar um bloqueio de IP por
tentativa repetida. Isso já aconteceu uma vez antes (a própria nota de credenciais registra
que a Hostinger trocou a senha em 16/09 sem aviso).

**Efeito prático:** os dois achados corrigidos acima (itens 1 e 2) estão **commitados e
enviados pro GitHub, mas não aplicados em produção** — preciso da senha nova (ou confirmação
de que ela mudou) pra rodar o `git pull` no servidor.

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
| Acesso SSH | 🔴 fora do ar, bloqueando deploy |
