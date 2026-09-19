# Documentação interna servida pela web

Achado em 2026-09-18, numa varredura geral pedida pelo Caio ("veja se não tem nada pra
arrumar, página quebrada"). **Corrigido na mesma passada.**

## O que estava aberto

Sem login, sem nada:

| URL | O que entregava |
|---|---|
| `/CLAUDE.md` | pasta real do projeto no servidor; lista dos **outros 7 domínios** do Caio na mesma conta Hostinger; menção aos `.bak` na home; e a frase de que **a senha de todos os usuários e admins é `123456`** |
| `/anotacoes/*.md` (32 arquivos) | as varreduras de segurança e o checklist pré-lançamento, que enumera quais segredos ainda **não** foram trocados (`CHAVE_SECRETA_CRON`) e qual é o usuário do banco |
| `/README.md`, `/.gitignore` | arquitetura e estrutura de pastas |

O `CLAUDE.md` é o pior dos três: junta "o admin é `admin@admin.com`" (dedutível do painel)
com "a senha é 123456". Isso é um login pronto, não uma pista.

## Por que passou batido até agora

`.git/`, `logs/`, `uploads/`, `certificados/` e `storage/` já tinham bloqueio próprio — cada
um foi lembrado porque *obviamente* é sensível. A documentação não parecia sensível: ela
entrou na pasta pública **de carona**, porque o deploy é um `git pull` da raiz do projeto
dentro do `public_html`. Tudo que está versionado vai junto, inclusive o que só existe pra
quem desenvolve.

O `credenciais-ssh-hostinger.md` estava salvo porque está no `.gitignore` — nunca chegou ao
servidor. Foi sorte de um cuidado tomado por outro motivo, não um bloqueio de verdade.

## Correção

- `anotacoes/.htaccess` com `Require all denied`, mesmo padrão de `logs/` e `uploads/`.
- No `.htaccess` da raiz, um `FilesMatch` negando `.md`, `.markdown`, `.yml`, `.yaml`,
  `.lock`, `.dist`, `.example`, `.bak`, `.sql` e os dotfiles `.gitignore`/`.gitattributes`/`.env`.

Nada disso é lido pelo painel em runtime, então bloquear não mexe no produto. Confirmado
depois: os 7 arquivos dão 403, e as 19 telas continuam 200 sem erro, mobile e desktop.

## Regra que fica

Deploy por `git pull` dentro da pasta pública significa que **todo arquivo versionado é uma
URL**. Antes de commitar documentação nova no repo, vale lembrar que ela nasce pública a
menos que a extensão esteja negada — hoje `.md` está coberto.
