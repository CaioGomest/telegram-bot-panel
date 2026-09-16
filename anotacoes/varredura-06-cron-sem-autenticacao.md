# Varredura 06 — Cron jobs acessíveis sem autenticação

Data: pós-criptografia das credenciais de gateway (branch `new`).

## 🔴 Alta prioridade

### Todos os `cron_*.php` estão abertos pra qualquer um na internet

Nenhum dos 5 arquivos de cron (`cron_verificar_pix.php`, `cron_renovacao.php`, `cron_aviso_vencimento.php`, `cron_remarketing.php`, `cron_verificar_acessos.php`) tem qualquer proteção — sem login, sem checagem de que a chamada veio do crontab do servidor. Como eles ficam na raiz do site (pasta pública), **qualquer pessoa que souber a URL pode acessar e disparar a rotina na hora, quantas vezes quiser**, sem estar logada em nada.

O que cada um faz, e o risco de deixar aberto:

- **`cron_remarketing.php`** — o mais grave. Manda mensagens reais no Telegram pra até 1000 leads por campanha, em até 20 campanhas por execução. Se alguém ficar disparando essa URL repetidamente (é só um script simples fazendo requisições em loop), os bots dos seus usuários vão mandar mensagem de remarketing muito mais rápido e mais vezes do que deveriam — risco real do **Telegram banir os bots por spam**, e de incomodar os clientes de verdade dos seus usuários. Esse arquivo até tem uma trava (`flock`) pra não rodar duas vezes *ao mesmo tempo*, mas não impede alguém de disparar de novo assim que a trava libera — ou seja, não limita a frequência.
- **`cron_renovacao.php`** — processa renovação automática de assinatura. Sem trava nenhuma contra execução concorrente — se disparado duas vezes ao mesmo tempo (de propósito ou não), pode processar a mesma renovação em duplicidade.
- **`cron_aviso_vencimento.php`** — manda aviso de vencimento pro cliente via Telegram. Sem trava — execuções repetidas mandam a mesma mensagem de novo e de novo pro mesmo cliente.
- **`cron_verificar_acessos.php`** — remove acesso de quem expirou. Sem trava — execução repetida pode tentar remover o mesmo usuário várias vezes (não é destrutivo em si, mas desperdiça chamadas de API do Telegram à toa).
- **`cron_verificar_pix.php`** — confere pagamento pendente direto na API do gateway (esse aqui, pelo menos, sempre reconfirma na fonte antes de agir, então é o de menor risco de conteúdo — mas ainda assim gasta chamada de API à toa se martelado sem parar).

Diferente do `webhook.php`/`webhook_infopago.php` (que **precisam** ficar públicos, porque é o Telegram/InfoPago quem chama de fora), os `cron_*.php` só deveriam ser chamados pelo agendador de tarefas do próprio servidor (crontab da Hostinger) — nunca por alguém de fora.

## Correção recomendada

Duas opções, que podem ser combinadas:

1. **Restringir a execução via linha de comando** (`php_sapi_name() === 'cli'`) — se o cron da Hostinger roda chamando `php cron_x.php` direto (comum em cPanel/Hostinger), isso sozinho já bloqueia 100% o acesso via navegador, sem precisar mexer em mais nada.
2. **Exigir uma chave secreta** (ex. `?chave=algum_valor_aleatório_só_seu`, comparado com uma constante nova em `config.php`) pro caso do cron da Hostinger estar configurado via `wget`/`curl` numa URL (também comum) — nesse caso a restrição por CLI não adianta, porque a chamada também é HTTP.

O mais seguro é aceitar as duas formas (CLI sempre libera; chamada HTTP só libera com a chave certa), assim funciona independente de como o cron tá configurado no seu painel da Hostinger.

## Próximos passos sugeridos

1. Adicionar a proteção (CLI-ou-chave) nos 5 arquivos de cron.
2. Adicionar trava (`flock`, igual já existe em `cron_remarketing.php`) nos outros 4 arquivos, pra evitar processamento duplicado em execução concorrente.
