# CLAUDE.md — Guia de trabalho neste repositório

## Contexto

Painel PHP para gestão de bots de venda no Telegram (fluxos, gateways de pagamento, splits, remarketing, tracking de leads). Ver `README.md` para visão geral do projeto e estrutura de pastas.

Vai ser hospedado na **Hostinger**. Sem framework, sem build step — PHP procedural com PDO.

## Ambiente de teste em produção (Hostinger) — regras de acesso SSH

Instalado em `https://telegram.stackcode.com.br` (subdomínio `telegram` do site `stackcode.com.br`), pasta real no servidor: `domains/stackcode.com.br/public_html/telegram/`. É um **ambiente de teste** (dado fake, senha simples de propósito — ver abaixo), mas continua sendo mantido com disciplina, porque a conta Hostinger é compartilhada com **outros projetos e domínios do Caio** que não têm nada a ver com este.

Regras que valem sempre que alguém (eu ou outra sessão) tiver acesso SSH a essa conta:

- **Nunca mexer em nada fora da pasta `telegram/`.** A conta tem vários outros domínios/subdomínios (`boloebalao.com.br`, `anotando.com.br`, `lojaodosoftware.com.br` e subdomínios, `paperjobs.com.br`, `postsmy.com`, `psicologiaconexaovida.com.br`, `teobaloes.com.br`, backups, projeto Node.js, etc.) e arquivos soltos na pasta home (`config.php.bak`, `conexao.php.bak` de outro projeto, por exemplo) — nada disso é deste projeto, nunca ler/mexer/usar essas credenciais mesmo que apareçam visíveis num `ls`.
- **Nunca apagar ou alterar as credenciais reais de `config.php` no servidor sem pedir explicitamente** — e, ao pedir, sempre explicar o motivo da mudança e **fazer backup do `config.php` atual antes** (ex. copiar pra `config.php.bak-<data>`) antes de sobrescrever.
- Senha de todos os usuários/admins nesse ambiente de teste foi propositalmente definida como `123456` (pedido explícito do Caio, "esse é o ambiente de teste") — não trocar por senha forte sozinho achando que é mais seguro; se for necessário mudar, perguntar antes.
- Deploy é via `git clone`/`git pull` direto na pasta (branch `new`), não upload manual de arquivo — mantém consistência com o repositório.
- Os 7 crons (`cron/cron_verificar_pix.php`, `cron_ranking.php`, `cron_metricas_admin.php`, `cron_remarketing.php`, `cron_aviso_vencimento.php`, `cron_verificar_acessos.php`, `cron_renovacao.php`) já estão cadastrados no painel Hostinger (Avançado → Cron Jobs) — não são visíveis/editáveis via `crontab` por SSH nessa conta (comando não existe), só pelo hPanel.

## Fase atual: redesign de layout (concluído)

O layout novo descrito em `Telegram bot management redesign/design_handoff_coyote_bot_panel/README.md` (protótipo `Coyote Bot Panel.dc.html`) foi aplicado em **todas** as páginas do painel, incluindo as que originalmente estavam fora do escopo do handoff (não havia spec própria pra elas — foi seguido o mesmo padrão visual já estabelecido). Só layout — lógica, nomes de campo, queries, rotas e IDs usados pelo JS não mudaram.

- **Toda classe CSS nova ou alterada usa nomenclatura em português, kebab-case** (mesmo padrão de `painel`, `botao`, `cabecalho-pagina`, `cartao-bot-item`) — nunca inglês, nunca camelCase.
- `barra_lateral.php` é a sidebar (substituiu `sidebar.php`, removido por não ter mais nenhuma página usando). `assets/css/coyote.css` é o único CSS do painel (tokens, tema dark/light, sidebar/header/cards) — `assets/app.css` e `assets/login.css` foram removidos por ficarem 100% sem uso.
- `funcoes/relatorio_debug.php` (`exibirRelatorioDebug()`) envolve a saída em texto das rotinas de manutenção no layout novo, sem mudar o que cada rotina calcula ou grava no banco.
- Pendências que dependem de backend novo (fora do escopo de layout): badge "Online"/mini-painel "Leads 7d" nos cards de bot, "N blocos"/status Publicado-Rascunho nos cards de fluxo, paginação real de `leads.php`, painel "Top usuários" no admin.

## Ranking

`ranking.php` já é dado real (não é mais mock). Arquitetura pensada pra plataforma grande (700+ usuários): nada de agregar `vendas` ao vivo a cada carregamento de página.

- `campanhas_ranking` / `campanhas_ranking_premios` — cadastro de campanha (nome, período, tipo `oficial`/`mensal`) e prêmios (até 5 posições), gerenciados em `admin/ranking.php`.
- `ranking_cache` (`campanha_id`, `id_usuario`, `faturamento`, `posicao`) — tabela pré-calculada. `ranking.php` só lê daqui, nunca faz `SUM(valor)` na hora.
- `cron/cron_ranking.php` (precisa estar no crontab a cada 1 min) recalcula `ranking_cache` de toda campanha ativa via `RANK() OVER (...)` direto no MySQL (confirmado 8.3, tem window function). Se esquecer de colocar no crontab, o ranking fica com o cache antigo — não quebra, só para de atualizar.
- `usuarios.apelido_publico` — nome que aparece pros outros usuários no ranking (nunca nome real/e-mail). Editável em Minha Conta. Sem apelido, cai em "Usuário #ID".
- `funcoes/ranking.php` tem as funções de leitura (`buscarCampanhaAtiva`, `buscarRankingCampanha`, etc.) — reaproveitar essas em vez de escrever query nova se for mexer na tela.

## Pasta `/admin`

As páginas que exigem `verificarAdmin()`/`verificarAdminOuInstalacao()` moraram sempre na raiz (ex.: `admin_dashboard.php`) e agora ficam em `admin/`, com o prefixo `admin_` removido por ficar redundante (`admin_dashboard.php` → `admin/dashboard.php`, `usuarios.php` → `admin/usuarios.php`, etc. — inclui também `configuracoes.php`, `atualiza_banco.php`, `debug_ultima_venda.php`). Objetivo é organização (não é uma medida de segurança por si só — quem barra acesso continua sendo `verificarAdmin()` dentro de cada arquivo). Tem um `admin/index.php` (redireciona pra `dashboard.php`) e `.htaccess` com `Options -Indexes` em `admin/`, `funcoes/` e `ajax/` pra não listar arquivo pela URL.

`atualizacao_seguranca.php`, `debug_colunas_vendas.php` e `setup_menus.php` foram removidos (não renomeados) por ficarem redundantes/inúteis: as duas primeiras faziam exatamente o que `atualiza_banco.php` já faz (mesmas colunas), e a terceira recriava a tabela `menus`, que não é mais lida por nenhum código (a sidebar usa array fixo em PHP). `debug_colunas_grupos.php` também saiu por baixo uso. Ficaram no menu Debug só `atualiza_banco.php` (migração real, idempotente) e `debug_ultima_venda.php` (inspeciona a última venda, útil pra conferir status de gateway/split).

## Pasta `/seeds`

Scripts que só populam o banco com **dado fake/de teste** (nunca dado de produção, nunca chamado por webhook/cron/fluxo real) ficam em `seeds/`, separado de `admin/` (que é rotina de manutenção real, tipo migração de schema). Hoje só tem `seeds/popular_banco.php` (cria usuários/bots/leads/vendas fictícios pra testar paginação e dashboards). Mesma proteção do `admin/`: `.htaccess` com `Options -Indexes` e a própria página exige `verificarAdminOuInstalacao()`. Como `seeds/` fica na mesma profundidade de `admin/` (um nível abaixo da raiz), os `require_once __DIR__ . '/../funcoes/...'` continuam funcionando sem ajuste — só os links relativos *dentro* da página (ex.: link de volta pra uma página de `admin/`) precisam do prefixo `../admin/`.

Pontos que **têm que** ser respeitados em qualquer página nova dentro de `admin/`:
- `require_once __DIR__ . '/../funcoes/...'` (não `/funcoes/...`) pra tudo que a página precisa de `funcoes/`.
- Antes de incluir a sidebar, definir `$caminho_base = '../';` e incluir com `include __DIR__ . '/../barra_lateral.php';` (e o mesmo pra `tema_inline.php`). Sem isso os links do menu e o logo saem quebrados.
- Links pra `assets/...` na própria página (CSS, JS) precisam do prefixo `../`.
- Os 3 redirects de `funcoes/usuario.php` (`verificarLogin`, `verificarAdmin`, `fazerLogout`) usam caminho absoluto (`/login.php`, `/index.php`) exatamente por causa disso — **não trocar de volta pra relativo**, senão quebra o redirect vindo de dentro de `admin/`.

## Fase anterior: limpeza de código

Etapa de **organização e code clean** (regras abaixo continuam valendo para mudanças em PHP/lógica, mesmo durante o redesign de layout).

Regras para esta fase:

- **Código mínimo e necessário.** Remover o que não é usado (código morto, comentários redundantes). Arquivos de debug/teste que ainda são úteis ficam protegidos por login e organizados no menu "Debug" (não deletados) — ver `/anotacoes/varredura-01-seguranca.md`.
- **Responsabilidade única.** Cada função deve fazer uma coisa só. Quebrar funções grandes (ex. arquivos em `funcoes/` e `webhook.php` que hoje concentram várias responsabilidades) em funções menores e nomeadas com clareza.
- **Altamente escalável.** Evitar acoplamento desnecessário, preferir funções puras quando possível, isolar acesso a banco e integrações externas (gateways, Telegram, pixels) em camadas bem definidas dentro de `funcoes/`.
- **Não alterar comportamento visível.** Refatoração é interna — mesma funcionalidade, mesmo output, mesmas rotas/nomes de arquivo (a menos que combinado explicitamente).
- **Não mexer em layout/CSS/HTML visual** fora do trabalho de redesign descrito acima — mudanças de limpeza de código continuam sendo só estrutura/organização do PHP.
- **`declare(strict_types=1)`** deve estar presente em todos os arquivos PHP.
- Nomenclatura em português deve ser mantida (é o padrão já usado no projeto: `verificarLogin`, `id_usuario`, etc.) — não traduzir para inglês.
- **Limpeza contínua.** Sempre que for mexer em um arquivo, analisar se dá pra deixar mais limpo/organizado — mas só aplicar a mudança se não quebrar nada existente. Na dúvida, não arriscar.

## Convenção de nomenclatura (PHP e JS)

- **Funções: camelCase, em português.** Ex.: `atualizaUsuario()`, `verificarLogin()`, `calcularSplit()`.
- **Variáveis: snake_case, em português.** Ex.: `$contagem_usuarios`, `$id_usuario`, `$valor_total`, `let taxa_split`.
- **Nomes de página (arquivos .php):** sempre em português, simples e claros — o nome tem que deixar óbvio o que a página faz. Ex.: `funcoes_usuarios.php`, `debug_ultima_venda.php`. Evitar prefixo genérico tipo `temp_`, `fix_` sem dizer o que faz.
- Vale pra PHP e JS (`assets/*.js`), sempre, sem exceção. Nunca traduzir pra inglês.
- **Exceção: não renomear `webhook*.php`** (fica na raiz). URL cadastrada no Telegram/OmegaPayments — renomear quebra a integração em produção sem o Caio saber.
- **`cron_*.php` moraram na raiz e agora ficam em `cron/`** (mesma ideia do `admin/`: `require_once __DIR__ . '/../...'`). Isso muda o caminho que o **crontab da Hostinger** chama — ⚠️ conferir se as entradas de crontab lá já apontam pra `cron/cron_verificar_pix.php` etc. (e não mais pra `cron_verificar_pix.php` na raiz) antes de considerar isso resolvido, senão os crons de produção param de rodar silenciosamente.
- Antes de renomear qualquer outro arquivo, checar com `grep` se ele é referenciado em algum outro lugar do código (require, link, JS) e atualizar tudo junto.

## Segurança

Segurança é prioridade máxima em toda mudança:

- Prevenir SQL Injection — sempre prepared statements via PDO (nunca concatenar variável em query).
- Sanitizar e validar todo input externo (`$_GET`, `$_POST`, `$_REQUEST`, payloads de webhook).
- Escapar output que vai pro HTML (prevenir XSS).
- Nunca logar ou expor dados sensíveis (tokens de bot, chaves de gateway, senhas) em logs, mensagens de erro ou respostas de API.
- Validar autenticação/autorização em toda rota admin e endpoint AJAX (`verificarLogin`/`verificarAdmin` sempre presentes onde deveriam estar).
- Webhooks (Telegram, OmegaPayments) devem validar origem/assinatura da requisição quando o gateway suportar.
- Nunca commitar credenciais reais, tokens ou chaves — `config.php` só com placeholder.

## Comando: "varredura"

Sempre que o Caio pedir uma **varredura**, fazer uma análise do código em busca de:
- Brechas e problemas de segurança (SQL Injection, XSS, falta de validação/autenticação, exposição de dados sensíveis)
- Código desnecessário (morto, duplicado, arquivos de teste/debug esquecidos)
- Oportunidades de melhoria (responsabilidade única, nomenclatura, organização)

Reportar os achados antes de aplicar qualquer mudança — varredura é análise, não é refatoração automática.

## Branch

**Trabalhar sempre na branch `new`.** Nunca commitar direto na `main`.

## Pasta /anotacoes

Pasta pra guardar lembretes, notas e coisas pra fazer depois (não é código, não afeta a aplicação). Usar arquivos `.md` simples. Exemplos: ideias de melhoria adiadas, débitos técnicos identificados durante a limpeza, pontos pra revisar na etapa de layout, dúvidas pra confirmar com o Caio.

### Pasta /anotacoes/testes

Todo plano de teste e todo resultado/rodada de teste (o que foi testado, o que passou, o que ficou pendente) é registrado em `anotacoes/testes/`, não solto direto em `anotacoes/`. Um mapa por tópico pode ser atualizado conforme os testes acontecem (estado ✅ verificado / 🟡 parcial / ❓ não testado / 🔴 problema conhecido, por tópico); uma rodada de teste específica (data, o que foi feito, achados) vira um arquivo próprio referenciando o mapa. Exemplo já nessa pasta: `plano-de-testes-24-09.md`. `anotacoes/mapa-de-testes-por-topico.md` e `anotacoes/rodada-de-testes-19-09.md` são de antes dessa convenção e continuam soltos na raiz — não precisam ser movidos, mas testes novos a partir de agora vão em `anotacoes/testes/`.

## O que evitar

- Não introduzir framework, ORM ou dependências pesadas — manter compatível com hospedagem compartilhada Hostinger.
- Não commitar credenciais reais (`config.php` deve manter placeholders).
- Não versionar `logs/`, `uploads/`, `certificados/` (já no `.gitignore`).
