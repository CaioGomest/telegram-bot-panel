# White-label: nome, logo e favicon

Feito em 2026-09-18.

## O problema

A marca "Coyote" estava escrita no código, em 5 arquivos (`barra_lateral.php`,
`login.php`, `cadastro.php`, `ranking.php`, título do `instalacao.php`). Pra revender
o sistema white-label, cada instalação precisava da sua marca — e trocar isso era
editar arquivo, o que quebra no próximo `git pull`.

## Como ficou

Tabela `configuracoes` (chave/valor) no banco. Três chaves hoje:

| chave          | padrão                      |
|----------------|-----------------------------|
| `nome_sistema` | `Painel de Bots`            |
| `logo`         | `assets/img/coyote-logo.jpg`|
| `favicon`      | vazio (cai na logo)         |

`funcoes/configuracoes.php` expõe `nomeSistema()`, `logoSistema($caminho_base)` e
`faviconSistema($caminho_base)`. O `$caminho_base` existe porque páginas dentro de
`admin/` precisam de `../` na frente — é a mesma variável que a barra lateral já usava.

A leitura tem cache por requisição (um `SELECT` só, não um por chamada) e um
`try/catch`: se a tabela ainda não existir, o painel abre normalmente nos valores
padrão em vez de dar erro. Isso importa pra uma instalação antiga que atualizou o
código mas ainda não rodou `admin/atualiza_banco.php`.

## Onde se configura

- **Na instalação:** `instalacao.php` ganhou os três campos (opcionais) no fim do
  formulário. Ficam num `try/catch` separado: um upload ruim não pode derrubar uma
  instalação que já criou o banco inteiro — nesse caso a instalação conclui e a
  mensagem avisa pra ajustar depois.
- **Depois:** `admin/configuracoes.php` (menu Administração → "Identidade Visual").
  Era um placeholder "em desenvolvimento".

## Upload

`salvarArquivoMarca()` grava em `uploads/` com nome fixo por tipo (`marca_logo.*`,
`marca_favicon.*`), então trocar a logo não acumula arquivo órfão. Valida:

- extensão numa lista fechada (png, jpg, jpeg, webp, ico) — **svg fica de fora de
  propósito**, SVG é XML e aceita `<script>` dentro;
- até 2 MB;
- `getimagesize()` no conteúdo, exceto `.ico` (que o PHP não lê de forma confiável).

A ordem é **mover primeiro, apagar a antiga depois**. Ao contrário, um `move` que
falhasse deixaria o banco apontando pra um arquivo que não existe mais.

`uploads/` já tinha `.htaccess` bloqueando execução de PHP, e está no `.gitignore` —
ou seja, a logo de cada instalação vive só no servidor dela, que é o certo aqui.

## Detalhe achado no caminho

`.linha-acoes` era usada em várias telas (`configuracao_usuario.php`,
`admin/usuarios.php`, `admin/ranking.php`), algumas com `justify-content:flex-end`
inline — mas a classe **nunca teve regra CSS**. Sem `display:flex`, o
`justify-content` não fazia nada e os botões dos modais ficavam à esquerda. Regra
adicionada em `coyote.css`.

## O que falta

O laranja de destaque continua fixo no CSS. Ficou de fora de propósito nessa rodada;
se um dia precisar, o caminho é o mesmo (chave nova + `--or` vindo do `tema_inline.php`).
