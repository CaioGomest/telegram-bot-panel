# CLAUDE.md — Guia de trabalho neste repositório

## Contexto

Painel PHP para gestão de bots de venda no Telegram (fluxos, gateways de pagamento, splits, remarketing, tracking de leads). Ver `README.md` para visão geral do projeto e estrutura de pastas.

Vai ser hospedado na **Hostinger**. Sem framework, sem build step — PHP procedural com PDO.

## Fase atual: redesign de layout

Estamos aplicando o novo layout descrito em `Telegram bot management redesign/design_handoff_coyote_bot_panel/README.md` (protótipo `Coyote Bot Panel.dc.html`) nas páginas do painel, em fases com checkpoint. Só layout — lógica, nomes de campo, queries, rotas e IDs usados pelo JS não mudam.

- **Toda classe CSS nova ou alterada usa nomenclatura em português, kebab-case** (mesmo padrão de `painel`, `botao`, `cabecalho-pagina`, `cartao-bot-item`) — nunca inglês, nunca camelCase.
- `barra_lateral.php` é a sidebar nova (páginas redesenhadas); `sidebar.php` é a sidebar antiga e continua servindo as páginas fora do escopo do redesign — as duas coexistem de propósito, não é duplicação esquecida.
- `assets/css/coyote.css` é o CSS novo (tokens, tema dark/light, sidebar/header/cards novos). `assets/app.css` não é tocado — segue servindo as páginas fora de escopo.
- Páginas fora do escopo do handoff (`remarketing.php`, `traqueamento.php`, `links_rastreamento.php`, `usuarios.php`, `atualizacao_seguranca.php`, `atualiza_banco.php`, `setup_menus.php`, `debug_*.php`, `cadastro.php`, `instalacao.php`, `configuracoes.php`) ficam como estão nesta etapa.

## Fase anterior: limpeza de código

Etapa de **organização e code clean** (regras abaixo continuam valendo para mudanças em PHP/lógica, mesmo durante o redesign de layout).

Regras para esta fase:

- **Código mínimo e necessário.** Remover o que não é usado (código morto, comentários redundantes). Arquivos de debug/teste que ainda são úteis ficam protegidos por login e organizados no menu "Debug" (não deletados) — ver `/anotacoes/varredura-01-seguranca.md`.
- **Responsabilidade única.** Cada função deve fazer uma coisa só. Quebrar funções grandes (ex. arquivos em `funcoes/` e `webhook.php` que hoje concentram várias responsabilidades) em funções menores e nomeadas com clareza.
- **Altamente escalável.** Evitar acoplamento desnecessário, preferir funções puras quando possível, isolar acesso a banco e integrações externas (gateways, Telegram, pixels) em camadas bem definidas dentro de `funcoes/`.
- **Não alterar comportamento visível.** Refatoração é interna — mesma funcionalidade, mesmo output, mesmas rotas/nomes de arquivo (a menos que combinado explicitamente).
- **Não mexer em layout/CSS/HTML visual** fora do trabalho de redesign descrito acima — mudanças de limpeza de código continuam sendo só estrutura/organização do PHP.
- **`declare(strict_types=1)`** deve estar presente em todos os arquivos PHP (hoje falta em alguns, ex. `remarketing.php`, `leads.php`, `sidebar.php`, `setup_menus.php`, `instalacao.php`, `login.php`, `cadastro.php`, `logs.php`, `configuracao_usuario.php`).
- Nomenclatura em português deve ser mantida (é o padrão já usado no projeto: `verificarLogin`, `id_usuario`, etc.) — não traduzir para inglês.
- **Limpeza contínua.** Sempre que for mexer em um arquivo, analisar se dá pra deixar mais limpo/organizado — mas só aplicar a mudança se não quebrar nada existente. Na dúvida, não arriscar.

## Convenção de nomenclatura (PHP e JS)

- **Funções: camelCase, em português.** Ex.: `atualizaUsuario()`, `verificarLogin()`, `calcularSplit()`.
- **Variáveis: snake_case, em português.** Ex.: `$contagem_usuarios`, `$id_usuario`, `$valor_total`, `let taxa_split`.
- **Nomes de página (arquivos .php):** sempre em português, simples e claros — o nome tem que deixar óbvio o que a página faz. Ex.: `funcoes_usuarios.php`, `debug_ultima_venda.php`. Evitar prefixo genérico tipo `temp_`, `fix_` sem dizer o que faz.
- Vale pra PHP e JS (`assets/*.js`), sempre, sem exceção. Nunca traduzir pra inglês.
- **Exceção: não renomear `cron_*.php` e `webhook*.php`.** Esses nomes são referenciados fora do repositório (crontab da Hostinger e URLs de webhook cadastradas no Telegram/InfoPago) — renomear quebraria a integração em produção sem o Caio saber.
- Antes de renomear qualquer outro arquivo, checar com `grep` se ele é referenciado em algum outro lugar do código (require, link, JS) e atualizar tudo junto.

## Segurança

Segurança é prioridade máxima em toda mudança:

- Prevenir SQL Injection — sempre prepared statements via PDO (nunca concatenar variável em query).
- Sanitizar e validar todo input externo (`$_GET`, `$_POST`, `$_REQUEST`, payloads de webhook).
- Escapar output que vai pro HTML (prevenir XSS).
- Nunca logar ou expor dados sensíveis (tokens de bot, chaves de gateway, senhas) em logs, mensagens de erro ou respostas de API.
- Validar autenticação/autorização em toda rota admin e endpoint AJAX (`verificarLogin`/`verificarAdmin` sempre presentes onde deveriam estar).
- Webhooks (Telegram, InfoPago) devem validar origem/assinatura da requisição quando o gateway suportar.
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

## O que evitar

- Não introduzir framework, ORM ou dependências pesadas — manter compatível com hospedagem compartilhada Hostinger.
- Não commitar credenciais reais (`config.php` deve manter placeholders).
- Não versionar `logs/`, `uploads/`, `certificados/` (já no `.gitignore`).
