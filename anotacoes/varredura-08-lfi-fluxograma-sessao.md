# Varredura 08 — LFI via bloco de imagem do fluxograma, fixação de sessão

Data: 2026-09-16 (pós-correções de escala/condição de corrida, ver `anotacoes/analise-escala-seguranca-vendas.md`). Rodada focada em ângulos ainda não olhados nas 7 varreduras anteriores: LFI via editor de fluxo, validação de upload, rate limiting no login normal, status do CSRF, IDOR em endpoints recentes, fixação de sessão. Só investigação — nenhuma correção aplicada ainda.

## 🔴 Crítico — LFI/exfiltração de arquivo via bloco "imagem" do fluxograma (permite roubar certificado de gateway de outro usuário) ✅ Corrigido

Este é o achado mais grave de todas as 8 rodadas até agora — diferente dos anteriores, não depende de coincidência de timing nem de adivinhar ID: é determinístico e repetível.

**Causa raiz:** `dados_fluxograma` (JSON que descreve o fluxo visual de um bot, incluindo blocos de "imagem"/"documento") é gravado sem nenhuma validação de conteúdo. `api.php`, ação `salvar_fluxo`, só checa `is_array($dados_grafico)` antes de gravar — nenhuma whitelist de `image_path`. O editor visual (`assets/edicao_fluxo.js`) só deixa escolher imagem via upload (que sempre gera `uploads/flow_image_xxx.jpg`), mas isso é só trava de UI: **o dono do bot pode chamar `api.php?action=salvar_fluxo` direto** (mesma sessão, via curl/devtools) e gravar qualquer `image_path` que quiser.

**Onde isso é usado sem sanitização** — o mesmo padrão se repete em 3 lugares:
- `webhook.php` (linhas ~108-136) — o mais grave, porque também suporta `mode: 'documento'` (`sendDocument`, sem checagem de tipo de arquivo pelo Telegram).
- `webhook_infopago.php` (linhas ~53-63) — só `sendPhoto`.
- `cron/cron_verificar_pix.php` (linhas ~62-72) — só `sendPhoto`.

Em todos: `if (strpos($caminho, 'uploads/') === 0) { ...monta caminho dentro de uploads... } else { $caminho_absoluto = $caminho; }` — ou seja, se o `image_path` **não** começar com `uploads/`, ele é usado exatamente como veio, sem nenhum filtro, e passa por `realpath()` antes de ser anexado ao envio (`CURLFile`).

**Por que isso é explorável de verdade:** `webhook.php`, `config.php`, `certificados/` e `uploads/` são todos irmãos na raiz do projeto. Um dono de bot mal-intencionado configura, no próprio fluxo, um bloco imagem/documento com `image_path` igual a:
- `"config.php"` → `realpath()` resolve pro `config.php` real do servidor, que tem `BANCO_USUARIO`/`BANCO_SENHA`/`CHAVE_CRIPTOGRAFIA_GATEWAYS` de **toda a plataforma**.
- `"certificados/cert_{id}_{gateway}.pem"` → nome de certificado de gateway **previsível** (`gateways.php`, padrão `cert_{$user_id}_{$gateway_id}.{$ext}`), então dá pra enumerar IDs de outros usuários e baixar o certificado mTLS/PIX deles.

Manda `/start` (ou o gatilho que ativa aquele bloco) no próprio bot, e o conteúdo do arquivo é enviado de volta pro próprio Telegram do atacante — sem deixar rastro fora do log da própria conta dele.

**Impacto:** confidencialidade de credenciais de banco da plataforma inteira + roubo de certificado de gateway de pagamento de outros usuários (fraude financeira potencial). Não depende de bug do Telegram nem de sorte de timing.

**Correção recomendada (não aplicada ainda):**
1. No momento de **usar** o caminho (`webhook.php`, `webhook_infopago.php`, `cron_verificar_pix.php`): exigir que `realpath($caminho_absoluto)` comece exatamente com `realpath(__DIR__ . '/uploads') . DIRECTORY_SEPARATOR` — se não bater, não envia nada (loga e ignora), independente do texto original ter ou não o prefixo `uploads/`.
2. Complementar, na **gravação** (`api.php::salvar_fluxo` ou um helper compartilhado): validar que todo `image_path`/`video_path`/`audio_path` dentro de `dados_fluxograma` é relativo a `uploads/`, sem `..`, antes de aceitar o JSON.

## 🔴 Alto — Sem `session_regenerate_id()` após login (fixação de sessão)

`fazerLogin()` (`funcoes/usuario.php`) grava `$_SESSION['usuario_id']` etc. direto após `password_verify()` bater, sem nunca regenerar o ID de sessão. `login.php` também não chama isso depois. Zero ocorrências de `session_regenerate_id` em todo o projeto.

**Impacto:** se um atacante conseguir fixar/plantar um `PHPSESSID` conhecido na vítima antes dela logar (precisa de outro vetor pra isso — não é explorável sozinho), e a vítima loga usando esse mesmo ID de sessão, o atacante herda a sessão autenticada sem saber a senha. Risco moderado (depende de vetor auxiliar), mas é prática básica de sessão que falta, e a correção é uma linha.

**Correção recomendada:** `session_regenerate_id(true)` logo após `$_SESSION['usuario_id'] = ...` em `fazerLogin()`.

## 🟡 Médio — Mensagem de erro do PDO exposta em página de produção ✅ Corrigido

`traqueamento.php`: `'Erro ao salvar: ' . $e->getMessage()` exibido direto pro dono do bot (não é arquivo de debug, é página normal de uso). Mesma categoria já sinalizada nas varreduras 01/02, mas essa instância específica não estava listada. Baixo risco (não vaza dado de outro usuário), mas mesma recomendação de sempre: logar em vez de ecoar `getMessage()`.

## 🟡 CSRF — confirmado, ainda pendente

`grep -i csrf` no projeto só acha menções nos `.md` de `anotacoes/`. Sem mudança desde a varredura 02/05.

## 🟢 Conferido e sem problema

- **Upload de imagem/vídeo/áudio de fluxo e foto de bot** — nomes de arquivo sempre gerados por `uniqid()` (nunca reaproveitam nome enviado pelo cliente), imagem validada com `getimagesize()` de verdade (não só extensão), `.htaccess` em `uploads/` já bloqueia execução de PHP (varredura 02). Vídeo/áudio só checam extensão, mas risco prático baixo dado o resto das proteções.
- **Rate limiting no login normal** — já implementado corretamente (`loginEstaBloqueado`/`registrarTentativaLoginFalha`/`resetarTentativasLogin`, tabela `tentativas_login`, 5 tentativas bloqueiam por 15 min). Não é uma lacuna, confirma que a proteção da varredura 02 continua funcionando.
- **IDOR em `admin/consultar_venda.php`, `admin/ranking.php`, `ajax/listar_splits_usuario.php`, `ajax/salvar_splits_usuario.php`, `links_rastreamento.php`, `traqueamento.php`** — todos corretos: rotas admin usam `verificarAdmin()` (o admin *deve* poder acessar qualquer registro, é o design esperado), e as rotas de usuário comum filtram por `$_SESSION['usuario_id']` em toda query. Nenhum achado novo.
- Nenhuma ocorrência nova de `eval`, `unserialize`, `system`, `exec` de shell, `passthru`, `proc_open` ou `assert` dinâmico em todo o projeto.
- Migrações (`atualiza_banco.php`/`instalacao.php`) usam `$pdo->exec()` só com SQL fixo de DDL — sem SQL injection.

## Resumo por prioridade

1. ~~🔴 LFI/exfiltração de arquivo via bloco "imagem" do fluxograma~~ **Feito.**
2. 🔴 Fixação de sessão — falta `session_regenerate_id()` após login. Aguardando confirmação do Caio antes de aplicar (explicado o impacto, ainda não corrigido).
3. **🟡 CSRF** — ainda pendente (varredura 02/05, sem mudança).
4. ~~🟡 Erro de PDO exposto em `traqueamento.php`~~ **Feito.**

## Correções aplicadas (2026-09-16)

### LFI/exfiltração de arquivo via bloco de mídia — duas camadas

**Camada 1 (uso) — validação no momento de enviar o arquivo:**
- `webhook.php`: nova função `resolverCaminhoUploadSeguro()` (logo após `requisicaoTelegram()`) — só aceita caminho que comece literalmente com `uploads/`, resolve via `realpath()` e confirma que o resultado fica dentro de `realpath(DIRETORIO_UPLOADS)`; qualquer coisa fora disso retorna `null` e o bloco não envia nada. Os 3 blocos que usavam o padrão antigo (imagem, vídeo, áudio) foram trocados pra usar essa função.
- `webhook_infopago.php` e `cron/cron_verificar_pix.php`: mesma lógica, funções equivalentes (`resolverCaminhoUploadSeguroInfopago()`/`resolverCaminhoUploadSeguroLocal()`, ajustando só a base do caminho já que ficam em diretórios diferentes). Esses dois arquivos usam uma função de requisição ao Telegram que nem faz upload de arquivo de verdade (`http_build_query`, sem `CURLFile`) — ou seja, provavelmente nunca vazariam o conteúdo do arquivo de fato — mas a checagem foi aplicada mesmo assim, por consistência e porque não custa nada.

**Camada 2 (gravação) — `api.php`, ações `salvar_fluxo` e `importar_fluxo`:**
- Nova função `sanitizarCaminhosMidiaFluxo()` (perto de `sanitizarTexto()`), chamada antes de gravar `dados_fluxograma` no banco: percorre todos os operadores do fluxo e zera qualquer `image_path`/`video_path`/`audio_path` que não siga exatamente o padrão `uploads/nome_do_arquivo.ext` (sem `..`, sem subpasta). Isso impede que um caminho malicioso seja sequer salvo, além da proteção em tempo de envio.

Testado manualmente (fora do banco, só a lógica das funções): `config.php`, `certificados/cert_5_1.pem`, `uploads/../config.php` e `../config.php` são todos bloqueados; `uploads/flow_image_abc.jpg` passa normalmente.

### Erro de PDO exposto em `traqueamento.php`

Trocado `$mensagem = 'Erro ao salvar: ' . $e->getMessage();` por `error_log(...)` (com o `id_usuario` pra rastreio) + mensagem genérica pro usuário ("Erro ao salvar as configurações. Tente novamente."), mesmo padrão já usado em `debug_colunas_vendas.php`/`atualizacao_seguranca.php` (varredura 01).

### Fixação de sessão — ainda não aplicada

Aguardando confirmação — expliquei o impacto (herdar sessão logada de outra pessoa se um atacante conseguir plantar o `PHPSESSID` antes do login; depende de vetor auxiliar, por isso risco moderado e não crítico) mas ainda não recebi o "pode corrigir" pra esse item específico.

## Notas relacionadas

- `varredura-04-idor.md` — base de comparação pra confirmar que os endpoints revisados nesta rodada continuam corretos.
- `varredura-02-upload-xss-csrf.md` — CSRF ainda pendente, upload de mídia já corrigido lá.
- [[analise-escala-seguranca-vendas]] — rodada de correção anterior (condição de corrida, índices, cron).
- [[pendencias]] — lista consolidada.
