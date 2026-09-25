# Sessão 2026-09-25 — login, conta, ranking e leads

Pedido do Caio, em produção (`https://telegram.stackcode.com.br`). Deploy foi só dos
arquivos alterados, por SSH, para `domains/stackcode.com.br/public_html/telegram/`.
`config.php` do servidor não foi mexido.

## 1. Abrir o site não é erro de login

`verificarLogin()` mandava qualquer visita sem sessão para `/login?erro=acesso`. A home
(`/`, `/index`, `/index.php`) caía nisso e a tela de login mostrava aviso de erro, como
se a pessoa tivesse tentado entrar e falhado.

Agora a raiz vai para `/login` limpo. Página interna protegida (ex.: `/bots`) continua
com `?erro=acesso` e a frase "Você precisa fazer login para acessar esta página."

Arquivo: `funcoes/usuario.php`.

## 2. Botão do Google abaixo do Entrar

O "Continuar com Google" ficava acima dos campos, longe do botão principal. Passou a
ficar depois do **Entrar** (e do **Criar conta** no cadastro), com um "ou" no meio:
formulário primeiro, alternativa em seguida.

Arquivos: `login.php`, `cadastro.php`.

## 3. Ranking do dashboard no celular

No widget da home, avatar, `#1`, nome e valor dividiam a mesma linha estreita e o texto
encavalava (nome em cima do valor). No mobile o valor desce para a linha de baixo e o
nome encolhe com reticências. No desktop continua em uma linha, com o nome truncado em
vez de vazar por cima do valor.

Arquivo: `assets/css/coyote.css` (`.mini-ranking-*`).

## 4. Banner do Ranking sem degradê

O hero de `/ranking` tinha `linear-gradient` escuro e a mancha `.hero-ranking-halo`.
Isso cobre qualquer banner que entrar depois. Os dois saíram. O fundo ficou chapado
(`var(--p)`). A logo decorativa da direita continua, sem opacidade reduzida.

Arquivos: `ranking.php` (sumiu o `<div class="hero-ranking-halo">`), `coyote.css`.

## 5. Foto de perfil do usuário

Não existia. Em Minha Conta o avatar aceita JPG, PNG ou WebP até 2 MB (**Trocar foto** /
**Remover**). O arquivo vai para `uploads/perfis/perfil_{id}_{time}.ext`. A coluna nova
é `usuarios.foto_perfil` (VARCHAR 255, NULL).

- Criada no banco de produção nesta sessão (`ALTER TABLE`), e também em
  `admin/atualiza_banco.php` e no `CREATE TABLE` de `instalacao.php`.
- Se a coluna ainda não existir num ambiente novo, `garantirColunaFotoPerfil()` tenta o
  `ALTER` na hora de salvar. Leitura que não acha a coluna só devolve vazio — não derruba
  o login.
- Aparece na barra lateral, no menu "Mais" do celular e no anel do story (quando o dono
  tem foto).

Arquivos: `funcoes/usuario.php`, `configuracao_usuario.php`, `barra_lateral.php`,
`funcoes/stories.php`, `assets/stories.js`, `coyote.css`, `atualiza_banco.php`,
`instalacao.php`.

## 6. Excluir o próprio story

A API `excluir_story` já existia e só apaga story do usuário logado. Faltava o botão.
No visualizador, **Excluir** aparece só quando o grupo é do próprio usuário
(`data-usuario-id` na barra). Story de outra pessoa não mostra o botão.

Arquivos: `parciais/barra_stories.php`, `assets/stories.js`, `coyote.css`.

## 7. Botão de tema duplicado no login

No celular havia dois: o flutuante `position:absolute` (filho do `body`) e o do
cabeçalho compacto. O CSS tentava esconder o flutuante com `.tela-login > .alternador-tema`,
seletor que não casava — o botão não está dentro de `.tela-login`.

O flutuante ganhou a classe `alternador-tema-flutuante` e some em viewport até 860px.
No desktop ele continua (o cabeçalho compacto fica `display:none`).

Arquivos: `login.php`, `cadastro.php`, `coyote.css`.

## 8. Busca de leads que "não achava" o Lead 500

A busca no banco já existia (`nome` / `id_telegram` com `LIKE`, conta e pagina certo).
Por cima disso ainda havia um `input` em JS que só escondia as `<tr>` **da página
aberta**. Lead 500 está na última página (ordenado por data, os antigos ficam no fim).
Na página 1 o script escondia todo mundo e a lista parecia vazia, com o paginador ainda
contando todos os leads.

O filtro local saiu. Digitar na caixa (pausa de 400ms), Enter ou a lupa enviam o
formulário. O GET não manda `pagina`, então a busca começa na página 1 e olha a tabela
inteira.

O mesmo tipo de falha — página antiga presa depois que o filtro diminui o resultado,
offset além do total, lista vazia mesmo havendo linha — existia sem o clamp em:

- `admin/transacoes.php`
- `remarketing.php`

`leads.php` já tinha esse clamp. Abas de status, filtro de bot e o período do dashboard
já tiravam `pagina` do link. Não havia outro filtro só no JS.

## 9. Fonte preta nos botões da cor principal

No tema claro (e a cor principal do painel é customizável, hoje um azul), botão
primário, página ativa do paginador, período ativo, selo de prioridade e a posição `#1`
do mini-ranking usavam `#0a0b0e`. Passaram a branco. Link com `html[data-theme] a { color: inherit }`
não come essa cor: as regras novas são mais específicas (`a.botao-primario`,
`a.paginacao-link.active`).

Arquivo: `coyote.css`.
