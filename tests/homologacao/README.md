# Testes de fluxos em homologação (Telegram e Pix simulados)

Valida na prática o **Fluxo Guiado** e o **Editor Visual (n8n)**, de ponta a ponta, sem enviar nada ao Telegram nem à OmegaPayments.

## Como funciona

- `setup.sh` copia o painel do servidor para `/tmp/pt/app` e `patch.php` aplica o simulador (`sim.php`): toda chamada ao Telegram e à API da gateway é registrada em `sim.log` e responde como a real (inclusive recusando o que o Telegram recusaria: Markdown/HTML inválido, legenda > 1024, botão sem texto, `callback_data` > 64 bytes, link inválido...).
- As crons da cópia só enxergam o **bot de teste** (isolamento no `patch.php`), então nunca "processam" dados de outro usuário com Telegram simulado.
- `seed.php` cria um usuário, uma gateway e um bot **fictícios** (`teste-homolog-fluxos@example.invalid`). `cleanup.php` apaga tudo no fim.
- Cada suíte é um script bash que chama os webhooks/crons/API e confere o resultado com `chk.php`.

## Suítes

| Script | O que cobre |
|---|---|
| `g1.sh` | Guiado: webhook, `/start`, leads, rastreamento, boas-vindas (mídia/cache), planos, fluxo desligado |
| `g2.sh` | Guiado: upsell/downsell/order bump, valor do Pix, unidades de acesso, flags de pagamento, falhas da gateway |
| `g3.sh` | Guiado: confirmação de pagamento (botão, webhook, cron), convite do grupo, renovação, expiração, cliques simultâneos |
| `g4.sh` | Guiado: prévias que somem, packs, renovação com desconto |
| `g5.sh` | Guiado: avisos de vencimento, remoção do grupo, carência, renovação manual |
| `g6.sh` | Guiado (08/10/2026): bot sem permissão de banir no grupo (dono avisado 1x/dia, tenta de novo), texto do aviso quando o fluxo não remove do grupo, QR Code em foto e botão Copiar no Pix |
| `g7.sh` | Operação em escala (08/10/2026): `leads.pagou` nos 3 caminhos de pagamento, aviso de venda sem grupo (1x/dia), token de bot recusado (401/404) avisa o dono, resumo do payload e rotação de logs |
| `v1.sh` | Visual: mensagem, imagem, documento, vídeo, áudio, link, grupo, botões, casos estranhos |
| `v2.sh` | Visual: ofertas encadeadas e valor final do Pix, sorteio |
| `v3.sh` | Visual: delay curto/médio/longo, retomada pela cron, cancelamento |
| `v4.sh` | Visual: Pix único e recorrente, ramos PAGO/NÃO PAGO, ciclos de assinatura |
| `a1.sh` / `a2.sh` | API: fluxos (criar, editar, importar, exportar, limite de 50), bots (limite de 50), eventos de grupo |
| `t1.sh` | Traqueamento: eventos ao Facebook (Conversions API) e à UTMify (HTTP simulado por `sim_eventos.php`, que recusa o que as APIs recusariam): Pix gerado, os 3 caminhos de pagamento, renovação, plataforma desligada/sem token, falhas HTTP, URL de postback/SSRF, token fora do log. A config do usuário de teste é gravada por `trkcfg.php` |
| `e2e.sh` | Jornada completa do Guiado e funil do Visual com 3 clientes + números das telas |
| `ui/ui2.js`, `ui3.js`, `ui4.js` | Chrome de verdade: editor Guiado, editor Visual (arrasta os 13 blocos), lista de fluxos/bots/leads |
| `ui_engine1.sh`, `ui_engine2.sh` | Roda no motor o fluxo que o editor salvou (id passado como argumento) |

## Rodar

1. Subir esta pasta para `/tmp/pt` no servidor (`pscp -r`).
2. `bash /tmp/pt/all.sh` (reconstrói a cópia e roda tudo; resultado em `/tmp/pt/FINAL_PRONTO` e `final_*.txt`).
3. UI: `HOMOLOG_SENHA=... node ui/ui2.js` (precisa do Chrome e do Node 22+; define uma senha conhecida pro usuário de teste antes).
4. `php /tmp/pt/cleanup.php` apaga o usuário/bot/fluxos de teste.

Os scripts assumem `/tmp/pt` e o painel em `~/domains/stackcode.com.br/public_html/telegram` (ajuste `setup.sh` em outra hospedagem). `shell_exec`/`proc_open` são desativados lá, por isso a orquestração é em bash.
