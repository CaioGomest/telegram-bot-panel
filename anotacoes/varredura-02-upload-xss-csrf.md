# Varredura 02 — Upload, XSS, CSRF, sessão

Data: pós-correções da varredura 01 (branch `new`).

## 🔴 Alta prioridade

### 1. Upload de arquivo sem whitelist de extensão em `api.php` (`enviar_imagem_teste`)
No case `enviar_imagem_teste` (~linha 660), quando vem `$_FILES['image']`, a extensão é pega direto do nome do arquivo enviado (`pathinfo(...)`) **sem validar contra uma lista permitida** (diferente dos outros uploads do mesmo arquivo, que checam `jpg/jpeg/png`, `mp4/avi/mov/mkv`, etc). Um usuário logado (não precisa ser admin) poderia enviar um arquivo `.php` disfarçado de imagem.

### 2. Pasta `/uploads` sem proteção contra execução de PHP
Não existe nenhum `.htaccess` no projeto. Isso significa que qualquer arquivo `.php` que pare dentro de `/uploads` (pública, servida direto pelo Apache/LiteSpeed na Hostinger) **pode ser executado como código** se alguém acessar a URL. Combinado com o item 1, é um caminho pra RCE (remote code execution) — usuário comum sobe um "webshell.php" disfarçado de imagem e acessa `seusite.com/uploads/webshell.php`.

**Correção recomendada:** validar extensão em todos os uploads (whitelist) **e** adicionar `.htaccess` em `/uploads` e `/certificados` bloqueando execução de PHP, como segunda camada de proteção.

### 3. Sanitização de path em `enviar_imagem_teste` é frágil
```php
$candidato = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $candidato);
```
Um `str_replace` de `'..'` só troca uma vez — não é recursivo. Um path tipo `....//....//etc/passwd` pode escapar do filtro (depois de remover `..` uma vez, sobra `../`). Risco de path traversal / leitura de arquivo fora de `/uploads`. Melhor usar `realpath()` e confirmar que o resultado começa com o diretório esperado.

## 🟡 Média prioridade

### 4. `sanitizarTexto()` não sanitiza HTML/script
```php
function sanitizarTexto(?string $valor, int $tamanho_maximo = 0): string {
    $valor = trim((string) $valor);
    ...
    return $valor;
}
```
Apesar do nome, essa função só faz `trim` + corta tamanho — não remove tags nem escapa nada. Se algum valor passado por ela for depois exibido em HTML sem `htmlspecialchars`, é XSS. Vale renomear pra `limitarTexto()` (nome mais correto) e criar uma função separada de sanitização de verdade pra quando o valor for pra HTML.

### 5. Sem proteção CSRF em nenhuma rota
Nenhuma rota (`ajax/`, `login.php`, `cadastro.php`, `gateways.php`, `usuarios.php`) usa token CSRF. Ações administrativas (deletar usuário, editar gateway, etc.) feitas via POST são vulneráveis a CSRF — um admin logado que clique num link malicioso em outro site pode disparar uma ação sem querer.

### 6. Sem rate limiting / bloqueio de tentativas no login
`fazerLogin()` não tem nenhum controle de tentativas — dá pra fazer brute-force de senha sem limite.

### 7. Senha mínima no cadastro era só 6 caracteres
`cadastro.php`/`criarUsuario()` já validava tamanho mínimo (6), só não estava documentado na varredura 01. Subi pra 8.

### 8. Cookie de sessão sem flags (repetindo da varredura 01)
Ainda sem `session_set_cookie_params` com `Secure`/`HttpOnly`/`SameSite`.

## 🟢 Positivo

- Uploads de certificado (`gateways.php`) e de mídia de fluxo (`api.php`, exceto o `enviar_imagem_teste`) já validam extensão com whitelist.
- Upload de foto/imagem também confere com `getimagesize()` antes de aceitar (bom, evita arquivo não-imagem disfarçado nesses casos específicos).
- `api.php` exige login (`usuarioLogado()`) logo no topo, antes de qualquer ação.

## Próximos passos sugeridos (por prioridade)

1. ~~Whitelist de extensão em `enviar_imagem_teste`~~ **Feito.**
2. ~~`.htaccess` em `/uploads` e `/certificados` desativando execução de PHP~~ **Feito.**
3. ~~Corrigir sanitização de path~~ **Feito** (agora só aceita `basename()` dentro de `/uploads`, resolvido com `realpath()`).
4. **Adicionar CSRF token nas rotas administrativas — ainda NÃO feito.** Escopo grande (toda rota POST do painel: `ajax/*`, `gateways.php`, `usuarios.php`, `bots.php`, `fluxo.php`, `configuracoes.php`, etc.) e arriscado de fazer tudo de uma vez sem conseguir testar rodando. Fica pra uma próxima etapa, feita com calma por grupo de páginas.
5. ~~Rate limiting básico no login~~ **Feito** (bloqueia por 15min após 5 tentativas erradas, tabela `tentativas_login` — precisa rodar `atualiza_banco.php` pra criar a tabela).
6. ~~Exigir senha mínima no cadastro~~ **Feito** (já existia com 6 caracteres, subi pra 8).
7. ~~Configurar cookie de sessão com `Secure`/`HttpOnly`/`SameSite`~~ **Feito.**

## Correções aplicadas (varredura 02 → fix)

- `api.php` (`enviar_imagem_teste`): agora valida a imagem com `getimagesize()` e limita extensão a `jpg/jpeg/png`, igual aos outros uploads do arquivo. O reaproveitamento de imagem já enviada (`$caminho`) agora usa só o `basename()` do valor recebido e resolve com `realpath()` dentro de `/uploads` — path traversal não é mais possível ali.
- `uploads/.htaccess` e `certificados/.htaccess`: bloqueiam execução de PHP nessas pastas (segunda camada de proteção, mesmo que algum arquivo `.php` consiga parar lá).
- `.gitignore` ajustado pra não ignorar os `.htaccess` novos.
- `funcoes/usuario.php`: cookie de sessão agora sai com `HttpOnly`, `SameSite=Lax` e `Secure` (quando HTTPS). Adicionado rate limiting no login (`loginEstaBloqueado`, `registrarTentativaLoginFalha`, `resetarTentativasLogin`) — 5 tentativas erradas bloqueiam por 15 minutos. Senha mínima no cadastro subiu de 6 pra 8 caracteres.
- `atualiza_banco.php`: cria a tabela `tentativas_login` (precisa rodar essa página, pelo menu Debug, depois do deploy — ou vai rodar sozinho no próximo redeploy se já tiver admin logado acessando).
- `login.php`: mostra mensagem clara quando a conta está temporariamente bloqueada por tentativas.

## Pendências que continuam em aberto

- CSRF (item 4 acima) — maior item pendente, precisa de uma rodada própria.
- `sanitizarTexto()` continua só cortando texto, não sanitizando de verdade (nome enganoso) — trocar nome/criar função separada numa limpeza futura.
- Mensagem de erro do PDO ainda exposta na tela em alguns arquivos de debug (item já sinalizado na varredura 01).
- ~~Host forçado `127.0.0.1` em `debug_colunas_grupos.php`~~ **Resolvido** — ver `pendencias.md`.
