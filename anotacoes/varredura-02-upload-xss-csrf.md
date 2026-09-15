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

### 7. Sem exigência de senha forte no cadastro
`cadastro.php` só checa se as duas senhas digitadas conferem — não valida tamanho mínimo nem complexidade.

### 8. Cookie de sessão sem flags (repetindo da varredura 01)
Ainda sem `session_set_cookie_params` com `Secure`/`HttpOnly`/`SameSite`.

## 🟢 Positivo

- Uploads de certificado (`gateways.php`) e de mídia de fluxo (`api.php`, exceto o `enviar_imagem_teste`) já validam extensão com whitelist.
- Upload de foto/imagem também confere com `getimagesize()` antes de aceitar (bom, evita arquivo não-imagem disfarçado nesses casos específicos).
- `api.php` exige login (`usuarioLogado()`) logo no topo, antes de qualquer ação.

## Próximos passos sugeridos (por prioridade)

1. Whitelist de extensão em `enviar_imagem_teste` (igual aos outros uploads do mesmo arquivo).
2. `.htaccess` em `/uploads` e `/certificados` desativando execução de PHP.
3. Corrigir sanitização de path (usar `realpath()` + checagem de prefixo).
4. Adicionar CSRF token nas rotas administrativas.
5. Rate limiting básico no login (ex.: bloquear por alguns minutos após N tentativas).
6. Exigir senha mínima (ex. 8 caracteres) no cadastro.
7. Configurar cookie de sessão com `Secure`/`HttpOnly`/`SameSite`.
