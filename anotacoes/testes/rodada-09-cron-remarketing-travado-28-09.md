# Cron de remarketing travou ~3h em teste ao vivo (28/09/2026)

## O que aconteceu

Caio testou o recurso novo de foto/vídeo direto em produção: criou campanha real
("Comprou", mensagem "yyt", foto `logo_header.jpg`) no bot **"Bot Carlos (3 anos)"**
(bot_id=2) e clicou em "Ver" logo depois — a tela de detalhes veio vazia, "nada
acontece".

Investigando: a campanha (#1) já estava com `status = 'processando'`, mas **0 envios
registrados**. O cron real do servidor (agendado via hPanel, não fui eu que rodei)
tinha pego a campanha às 11:14 e travado — achei o processo **ainda rodando 3 horas
depois** (`ps aux` mostrou o PID vivo desde 14:14). Precisei matar o processo
manualmente via SSH (`kill -9`).

## Primeiro susto (falso alarme, mas levado a sério até confirmar)

A audiência estimada mostrada no modal de criação tinha aparecido como **1.091.493**
destinatários — assustador, campanha real, gente de verdade. Travei a campanha na hora
(`UPDATE ... SET status = 'falha' WHERE id = 1 AND enviados = 0`) antes de investigar
mais, exatamente pra garantir que nada saísse enquanto eu não entendesse o que tinha
acontecido.

**Apurado depois:** o total real calculado pelo próprio cron pra essa campanha
específica foi **872** (não 1 milhão — aquele número era de uma estimativa mais ampla,
mostrada antes de escolher a audiência certa). E mais importante: dos 536 destinatários
que o cron chegou a processar antes de travar, **os 536 falharam, nenhum foi entregue**
— confirmado com `getMe` no token do bot: `TESTE_3ANOS_...` devolve
`{"ok":false,"error_code":404}`. **Esse bot não é um bot real do Telegram** — é o mesmo
dado de demonstração (766 mil vendas sintéticas) já documentado antes nesta sessão.
Nenhum destinatário real recebeu nada, em nenhum momento.

## A causa real do travamento (corrigida)

Como o token é inválido, toda tentativa de `sendPhoto` falhava — e como falhava, o
`file_id` nunca ficava salvo, então **cada uma das 536 tentativas fez um upload
multipart completo do arquivo de novo** (em vez de cachear depois da primeira, que era
a ideia original). Em algum ponto depois da tentativa 536, alguma chamada do
`curl_multi` simplesmente nunca voltou `$active = 0` — o loop interno
(`do { curl_multi_exec... } while ($active > 0)`) não tinha teto de tempo próprio, só o
`MAX_EXEC_SEC` (4min) do loop de fora, que **nunca é conferido no meio de um tick preso**.
Resultado: o script travou pra sempre, sem nunca voltar a checar o limite de tempo.

**Fix (`cron/cron_remarketing.php`):**
1. `MAX_TICK_SEC = 40s` — teto duro dentro do próprio loop do `curl_multi`. Se algum
   request nunca terminar, força sair da rodada (salva o progresso, tenta de novo na
   próxima chamada do cron) em vez de travar o processo pra sempre.
2. Circuit breaker por bot (`MAX_FALHAS_SEGUIDAS_BOT = 10`): depois de 10 falhas
   seguidas sem nenhum sucesso no meio, descarta o resto da fila e marca a campanha
   como `falha` — em vez de insistir em todos os destinatários restantes. Teria parado
   em 10 tentativas em vez de 536.

## Limpeza feita

- Campanha #1 neutralizada (`status = 'falha'`) antes de qualquer coisa, permanece
  assim (não precisa reverter — é dado de teste real, não descartável).
- Processo travado morto via `kill -9`.
- Os 536 registros em `remarketing_envios` da campanha #1 foram deixados como estão —
  são registros verdadeiros do que aconteceu (todos "falha", nenhum "sucesso"), não
  lixo de teste pra apagar.
- Nada foi enviado a nenhum destinatário real em nenhum momento.

## O que ainda não foi confirmado

O bug de CSRF do upload (rodada anterior) e o travamento do cron foram os dois
problemas reais encontrados nesse teste ao vivo — ambos corrigidos e já deployados.
Mas **ainda não vi uma foto chegar de verdade em um chat real do Telegram**, porque o
único bot testado até agora (Bot Carlos) tem token falso. Pra confirmar que o
`sendPhoto`/cache de `file_id` funcionam de ponta a ponta, precisa de um bot real
conectado — o próximo teste do Caio, se usar um bot de verdade, é quando isso fica
confirmado.
