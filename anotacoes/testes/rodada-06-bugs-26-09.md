# Rodada de testes — mais bugs (26/09/2026, continuação)

Segunda passada pelos itens do relatório de homologação não commitado
(`anotacoes/tests/relatorio-homologacao-2026-09-25.md`), verificando cada um no código
antes de mexer. Critério: só corrijo o que é bug de código de verdade, reproduzível
independente do dado usado pra testar. Itens que são só reflexo do dataset de
demonstração antigo, ou escolha de copy/design sem quebrar nada, ficam de fora — já
documentado o porquê em `rodada-05-bugs-graves-26-09.md`.

## 1. ✅ CORRIGIDO — Login vazava o e-mail da conta admin pra qualquer visitante

**Onde:** `login.php`.

**O bug:** `value="<?php echo htmlspecialchars($_POST['email'] ?? 'admin@admin.com'); ?>"`
— o campo de e-mail do formulário de login vinha **pré-preenchido com
`admin@admin.com`** sempre que não havia um POST anterior, ou seja, em toda visita
nova, sem sessão, sem cookie, sem nada. Confirmado ao vivo com `curl` limpo (sem
cookiejar) direto em `https://telegram.stackcode.com.br/login`: o HTML retornado já vem
com `value="admin@admin.com"`.

**Impacto:** qualquer pessoa que abrisse a tela de login descobria de graça que
`admin@admin.com` é uma conta válida do sistema (provavelmente a de admin, pelo nome) —
informação que ajuda quem tentar adivinhar senha por força bruta (não precisa mais
adivinhar o e-mail, só a senha). É diferente da senha `123456`, que é intencional pro
ambiente de teste (ver `CLAUDE.md`) — aqui o problema é vazar o e-mail/usuário pra
qualquer visitante anônimo, não a força da senha em si.

**Fix:** removido o fallback fixo, o campo volta a nascer vazio quando não há
`$_POST['email']` (mantendo o comportamento de re-preencher o que a pessoa digitou se o
login falhar, que já funcionava certo e não mudou).

## 2. ✅ CORRIGIDO — `[hidden]` perdia pra qualquer classe com `display: flex/grid`

**Onde:** `assets/css/coyote.css` (regra base, seção Reset).

**O bug:** o atributo HTML `hidden` esconde um elemento via uma regra do próprio
navegador (`[hidden] { display: none }`), mas essa regra é do "user-agent stylesheet" —
perde pra qualquer regra equivalente escrita no CSS do site, mesmo com especificidade
igual, porque regra de autor sempre bate regra de navegador na ordem de cascata. Como
`.grade { display: grid; }` é uma classe de autor, todo elemento com
`class="grade ..." hidden` ficava **visível mesmo assim** — o hidden virava decoração,
não escondia nada.

**Onde isso já mordeu:** exatamente o item 13 do relatório de homologação — em
`bot.php`, os campos de Nome/Username que deveriam sumir na edição (`hidden` quando
`$eh_edicao`) apareciam vazios com placeholder "Automático"/"@automatico_bot" por cima
do cabeçalho que já mostrava o nome/username reais. O código já tinha percebido esse
padrão de bug antes e remendado **caso a caso** (`.visualizador-story-excluir[hidden]`,
`.folha-menu[hidden]`) — mas sem corrigir a causa, qualquer novo `hidden` em cima de uma
classe de display customizado cai na mesma armadilha de novo.

**Fix:** uma regra global `[hidden] { display: none !important; }` na seção de reset —
`!important` aqui é o uso correto do escape hatch: garante que o atributo semântico do
HTML sempre vence qualquer classe de layout, em qualquer combinação, pro resto do
projeto. As duas correções pontuais antigas ficaram redundantes mas inofensivas (não
precisou remover).

## 3. ✅ CORRIGIDO — 23 páginas não usavam o "Nome do sistema" configurado no título da aba

**Onde:** `admin/configuracoes.php` (campo "Nome do sistema") + 23 arquivos com `<title>`
fixo.

**O bug:** o texto de ajuda do campo diz: *"Aparece na barra lateral, no topo do celular
e no título das abas do navegador."* Isso é verdade só pra 3 páginas
(`cadastro.php`, `login.php`, `termos.php` — todas de antes do login). Todas as 23
páginas logadas (`index.php`, `bots.php`, `leads.php`, `fluxo.php`, `gateways.php`,
`admin/*.php`, etc.) tinham `<title>` com texto fixo, nunca lendo `nomeSistema()` — quem
troca o nome do sistema pra sua marca nunca vê isso refletido em nenhuma aba do
navegador depois de logar.

**Fix:** todas as 23 páginas passaram a usar o mesmo padrão já usado em
`login.php`/`cadastro.php`: `<title>Nome da Página - <?php echo
htmlspecialchars(nomeSistema()); ?></title>`. `nomeSistema()` já vem cacheado por
requisição (uma query só, reaproveitada), então não pesa nada a mais. Todas as páginas
editadas já carregam `funcoes/usuario.php` (que já exige `funcoes/configuracoes.php`),
então a função já estava disponível em todas sem precisar de nenhum `require` novo —
confirmado antes de editar.

## 4. ✅ CORRIGIDO — Ranking: "1 participantes" (plural errado) e "R$ 0,00 do Top 5" pro líder

**Onde:** `ranking.php`.

**Bug A (plural):** `<?php echo $total_participantes; ?> participantes` sempre no
plural, mesmo com 1 participante só. Fix: sufixo condicional (`participante` sem "s"
quando o total é exatamente 1).

**Bug B (gap zerado exibido como se fosse informação útil):** a faixa "Sua posição" no
topo da página sempre mostrava "`R$ X do Top 5`", mesmo quando `X = 0` — o que acontece
exatamente quando a pessoa **já está no Top 5** (não falta nada, o gap é zero por
definição). "R$ 0,00 do Top 5" não comunica "você está liderando", só parece um valor
quebrado. O painel "Passe do competidor", mais abaixo na mesma página, já tinha a
checagem certa pra esse caso (esconde a barra de progresso e mostra "Você está no Top 5!
🎉" quando `$progresso_top5_pct >= 100`) — só a faixa de cima, redundante com essa
informação, não tinha a mesma condição. Fix: aplicada a mesma condição
(`$progresso_top5_pct < 100`) nos dois lugares, consistente agora.

## Reconfirmado: por que não mexi no resto

- **Conversão 100%, gateway "-"/split "Pendente" nas vendas antigas** (rodada-05): dataset
  de demo de antes das colunas existirem — não é bug.
- **"Em disputa" (ranking.php) vs "Em andamento" (admin/ranking.php)**: verifiquei o
  código — são a *mesma condição exata* (`ativa` + intervalo de datas), só com
  vocabulário diferente de propósito: o admin usa linguagem neutra de painel, a tela
  pública usa linguagem de "competição" (gamificação), combinando com o resto do texto
  daquela tela (zonas, placar, etc.). Não é inconsistência de dado, é escolha de copy.
- **Token do bot em campo de texto puro** (não mascarado): não tratei como bug — é
  discutível (token precisa ser copiado com frequência, mascarar sempre atrapalharia
  esse uso normal), então fica como sugestão pro Caio decidir, não corrigi sozinho.
- Itens de copy/nomenclatura menores (Traqueamento vs Links de Rastreamento sendo dois
  nomes pra mesma coisa no menu, blocos do editor de fluxo em inglês, texto do modo
  guiado citando nomes de campo que mudaram) — cosméticos, não achei risco de o usuário
  ficar travado por causa disso, deixei de fora pra focar no que é erro de verdade.

## Pendente de deploy

Tudo commitado e no GitHub. SSH pro servidor segue bloqueado (mesmo erro
`/sbin/nologin`), nada disso está sincronizado no servidor ainda.
