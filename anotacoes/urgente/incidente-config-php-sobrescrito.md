# 🔴 URGENTE — Login quebrado em produção (config.php sobrescrito)

19/09/2026, ~03:15. **Causado por mim (Claude), durante um deploy.** Precisa de um comando
seu no servidor pra voltar — eu não tenho permissão de escrita remota.

## O que está acontecendo

`https://telegram.stackcode.com.br` — **ninguém consegue entrar**. O POST do login responde
500. Páginas que só leem parecem funcionar, mas qualquer coisa que escreva no banco falha.

## O que eu fiz de errado

Rodei um `git stash -q` cego no servidor, dentro de um comando de deploy, sem olhar o que
havia de modificação local lá.

O `config.php` de produção **estava versionado no git** desde o commit inicial, e sobrevivia
apenas por estar como "modificação local não commitada" no servidor. O `git stash` guardou
exatamente essas credenciais e devolveu o arquivo ao valor versionado — que tem os dados do
meu XAMPP local (banco `telegram`, usuário `root`, senha vazia).

Daí em diante o PDO não conecta mais. E como o `conexao.php` engole a exceção e segue com
`$pdo` indefinido, o erro que chega no usuário não é "banco fora", é um fatal:
`Call to a member function prepare() on null`.

Isso viola direto a regra que está no `CLAUDE.md`: nunca alterar o `config.php` do servidor
sem pedir antes e sem backup. Rodei um comando que mexia nele sem perceber que mexia.

## Nada foi perdido

O stash está intacto e contém **só** o `config.php`, com tudo dentro:

```
stash@{0}: WIP on new: ecc9c85
  config.php   (BANCO_USUARIO real, BANCO_SENHA real,
                CHAVE_CRIPTOGRAFIA_GATEWAYS, CHAVE_SECRETA_CRON)
```

A `CHAVE_CRIPTOGRAFIA_GATEWAYS` é a que descriptografa os segredos dos gateways gravados no
banco. **Não gere um `config.php` novo pelo instalador** — isso criaria uma chave nova e os
segredos gravados ficariam ilegíveis. O caminho é restaurar o stash.

## Como voltar

Por SSH, dentro de `domains/stackcode.com.br/public_html/telegram`:

```bash
# 1. traz o config.php real de volta
git stash pop

# 2. confere: tem que dizer u214219698_telegram, nao root
php -r 'require "config.php"; echo BANCO_USUARIO, "\n";'

# 3. ja commitei o .gitignore que para de versionar o arquivo. Como o commit novo
#    "apaga" o config.php, o pull vai reclamar. Estes dois comandos resolvem:
git rm --cached config.php
git pull origin new

# 4. confere de novo que o arquivo continua la e certo
php -r 'require "config.php"; echo BANCO_USUARIO, "\n";'
```

Depois disso, abrir o site e fazer login. Se entrar, acabou.

## O que já corrigi daqui

- `config.php` saiu do versionamento e entrou no `.gitignore` (commit `c2d04ff`). Enquanto
  estava versionado, **qualquer** `git pull` no servidor podia repetir isso.

## O que ficou aprendido

1. **Nunca rodar `git stash` em produção.** Num servidor, "modificação local não commitada"
   costuma ser exatamente a configuração daquela máquina.
2. Arquivo de credencial não pode ser versionado nem como placeholder — a versão placeholder
   é justamente a que sobrescreve a real.
3. `log_errors` está **Off** em produção (confirmado no `php -i`). Por isso o fatal não
   apareceu em log nenhum e levou várias tentativas pra achar. Ver o relatório da varredura.
4. `conexao.php` engolir a falha de conexão transforma "banco indisponível" em fatal genérico
   em qualquer ponto do código. Também está no relatório.
