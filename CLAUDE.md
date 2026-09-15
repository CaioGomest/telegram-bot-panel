# CLAUDE.md — Guia de trabalho neste repositório

## Contexto

Painel PHP para gestão de bots de venda no Telegram (fluxos, gateways de pagamento, splits, remarketing, tracking de leads). Ver `README.md` para visão geral do projeto e estrutura de pastas.

Vai ser hospedado na **Hostinger**. Sem framework, sem build step — PHP procedural com PDO.

## Fase atual: limpeza de código

Estamos numa etapa de **organização e code clean**, sem mexer em layout/UI. O layout será refeito depois, em outra etapa, com o Claude Code.

Regras para esta fase:

- **Código mínimo e necessário.** Remover o que não é usado (arquivos de teste/debug tipo `debug_cron.php`, `debug_fix_db.php`, `temp_check_db.php`, `teste_infopago_*.php`, código morto, comentários redundantes).
- **Responsabilidade única.** Cada função deve fazer uma coisa só. Quebrar funções grandes (ex. arquivos em `funcoes/` e `webhook.php` que hoje concentram várias responsabilidades) em funções menores e nomeadas com clareza.
- **Altamente escalável.** Evitar acoplamento desnecessário, preferir funções puras quando possível, isolar acesso a banco e integrações externas (gateways, Telegram, pixels) em camadas bem definidas dentro de `funcoes/`.
- **Não alterar comportamento visível.** Refatoração é interna — mesma funcionalidade, mesmo output, mesmas rotas/nomes de arquivo (a menos que combinado explicitamente).
- **Não mexer em layout/CSS/HTML visual** nesta fase — só estrutura/organização do PHP.
- **`declare(strict_types=1)`** deve estar presente em todos os arquivos PHP (hoje falta em alguns, ex. `remarketing.php`, `leads.php`, `sidebar.php`, `setup_menus.php`, `instalacao.php`, `login.php`, `cadastro.php`, `logs.php`, `configuracao_usuario.php`).
- Nomenclatura em português deve ser mantida (é o padrão já usado no projeto: `verificarLogin`, `id_usuario`, etc.) — não traduzir para inglês.
- **Limpeza contínua.** Sempre que for mexer em um arquivo, analisar se dá pra deixar mais limpo/organizado — mas só aplicar a mudança se não quebrar nada existente. Na dúvida, não arriscar.

## Convenção de nomenclatura (PHP e JS)

- **Funções: camelCase.** Ex.: `verificarLogin`, `buscarUsuario`, `calcularSplit`.
- **Variáveis: snake_case.** Ex.: `$id_usuario`, `$valor_total`, `let taxa_split`.
- Vale tanto para PHP quanto para JS (`assets/*.js`). Sempre, sem exceção.

## Segurança

Segurança é prioridade máxima em toda mudança:

- Prevenir SQL Injection — sempre prepared statements via PDO (nunca concatenar variável em query).
- Sanitizar e validar todo input externo (`$_GET`, `$_POST`, `$_REQUEST`, payloads de webhook).
- Escapar output que vai pro HTML (prevenir XSS).
- Nunca logar ou expor dados sensíveis (tokens de bot, chaves de gateway, senhas) em logs, mensagens de erro ou respostas de API.
- Validar autenticação/autorização em toda rota admin e endpoint AJAX (`verificarLogin`/`verificarAdmin` sempre presentes onde deveriam estar).
- Webhooks (Telegram, InfoPago) devem validar origem/assinatura da requisição quando o gateway suportar.
- Nunca commitar credenciais reais, tokens ou chaves — `config.php` só com placeholder.

## Branch

**Trabalhar sempre na branch `new`.** Nunca commitar direto na `main`.

## Pasta /anotacoes

Pasta pra guardar lembretes, notas e coisas pra fazer depois (não é código, não afeta a aplicação). Usar arquivos `.md` simples. Exemplos: ideias de melhoria adiadas, débitos técnicos identificados durante a limpeza, pontos pra revisar na etapa de layout, dúvidas pra confirmar com o Caio.

## O que evitar

- Não introduzir framework, ORM ou dependências pesadas — manter compatível com hospedagem compartilhada Hostinger.
- Não commitar credenciais reais (`config.php` deve manter placeholders).
- Não versionar `logs/`, `uploads/`, `certificados/` (já no `.gitignore`).
