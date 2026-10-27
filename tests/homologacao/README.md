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
| `g11.sh` | Guiado (22/10/2026): **entrega por link, grupo ou nada** em plano, pack, Upsell e Downsell — venda grava `link_entrega`, mensagem de pagamento confirmado nos 3 caminhos (botão, webhook da Omega, cron), link inválido recusado ao salvar pela API, banco ainda sem a coluna. Precisa da migração (`vendas.link_entrega`) na cópia |
| `v1.sh` | Visual: mensagem, imagem, documento, vídeo, áudio, link, grupo, botões, casos estranhos |
| `v2.sh` | Visual: ofertas encadeadas e valor final do Pix, sorteio |
| `v3.sh` | Visual: delay curto/médio/longo, retomada pela cron, cancelamento |
| `v4.sh` | Visual: Pix único e recorrente, ramos PAGO/NÃO PAGO, ciclos de assinatura |
| `a1.sh` / `a2.sh` | API: fluxos (criar, editar, importar, exportar, limite de 50), bots (limite de 50), eventos de grupo |
| `t1.sh` | Traqueamento: eventos ao Facebook (Conversions API) e à UTMify (HTTP simulado por `sim_eventos.php`, que recusa o que as APIs recusariam): Pix gerado, os 3 caminhos de pagamento, renovação, plataforma desligada/sem token, falhas HTTP, URL de postback/SSRF, token fora do log. A config do usuário de teste é gravada por `trkcfg.php` |
| `e2e.sh` | Jornada completa do Guiado e funil do Visual com 3 clientes + números das telas |
| `ui/ui2.js`, `ui3.js`, `ui4.js` | Chrome de verdade: editor Guiado, editor Visual (arrasta os 13 blocos), lista de fluxos/bots/leads |
| `ui_engine1.sh`, `ui_engine2.sh` | Roda no motor o fluxo que o editor salvou (id passado como argumento) |
| `ui/plano_recolher.js` | **Guiado > Planos (22/10/2026)**: recolher/expandir cada plano pela seta ou pelo cabeçalho, resumo ao vivo, estado mantido ao adicionar/remover plano, celular. Usa o editor novo (não salva nada); precisa de um usuário comum (`HOMOLOG_EMAIL`, `HOMOLOG_SENHA`) — admin é redirecionado para o painel admin |
| `ui/entrega_link.js` | **Guiado > Entrega (22/10/2026)**: cria um fluxo novo pela tela e confere o campo "Entrega" no plano, pack, Upsell e Downsell (Nenhum / grupos / Link, "Igual à do plano oferecido" nas ofertas), o campo do endereço, a recusa de link vazio/sem https/com espaço, salvar, reabrir, fluxo antigo (sem `entrega_tipo`) abrindo igual e celular. Precisa de um usuário comum com um bot que tenha 2 grupos em `bot_grupos` (`-1001000000001/2`) |
| `ui/zero_guiado.js` | **Do zero (16/10/2026)**: Guiado criado na tela, como pessoa (digita, escolhe, envia mídia): as 11 seções, validações, limites (20 packs, 8 prévias), salvar, recarregar do banco e conferir que tudo voltou igual |
| `ui/zero_visual.js` | **Do zero**: Visual criado na tela: os 13 tipos de bloco (18 blocos), campos digitados, mídias enviadas, 22 ligações por clique nos conectores, renomear/mover/excluir, salvar, recarregar, exportar e importar |
| `ui/zero_lista.js` | **Do zero**: começar pela lista (escolha Guiado/Visual), importar e excluir pela lista, editor Visual no celular (toque) |
| `ui/zero_token.js` | Token do bot colado com espaço/quebra de linha (como vem do BotFather no celular): a API e a tela têm que validar; precisa de uma conta com bot real (`HOMOLOG_EMAIL`, `HOMOLOG_SENHA`, `TOKEN_BOT_ID`) |
| `ui/zero_corrida.js` | Visual: o que se digita enquanto uma mídia grande ainda sobe (rede lenta simulada) não pode se perder |
| `zero_motor_guiado.sh`, `zero_motor_visual.sh` | Rodam no motor (Telegram e Pix simulados) os fluxos criados por `zero_guiado.js` / `zero_visual.js` (id passado como argumento): tudo o que foi digitado tem que aparecer para o cliente. `zero_ids.php`, `zero_pesos.php`, `zero_conta.php`, `zero_sorteio_estat.php` são auxiliares |

## Rodar

**Testes "do zero" (ordem):** (1) `setup.sh` e `seed.php` no servidor; defina uma senha conhecida para o usuário de teste e os grupos do bot (`bot_grupos`: ids `-1001000000001/2/99`); (2) `cd ui && HOMOLOG_SENHA=... node zero_guiado.js` imprime `FLUXO_GUIADO_ID=`; `node zero_visual.js` imprime `FLUXO_VISUAL_ID=`; `node zero_lista.js` e `node zero_corrida.js` não precisam de id; (3) copie para `/tmp/pt/app/uploads` as mídias novas (`cp -n .../uploads/flow_* /tmp/pt/app/uploads/`) e rode `bash zero_motor_guiado.sh ID` e `bash zero_motor_visual.sh ID`; (4) `php /tmp/pt/cleanup.php`. Os arquivos de mídia de teste (PNG/JPG, MP4 e MP3/WAV mínimos, mais um `falso.mp4` que é texto) ficam em `ui/midia/`; as telas e os JSON de resultado, em `ui/saida/` (ignorados pelo git). Para publicar uma correção na homologação sem esquecer dependência: `php -l` **e** conferir que todo `require __DIR__` existe lá (um arquivo novo faltando derruba o salvamento de fluxos com erro 500 sem corpo).

1. Subir esta pasta para `/tmp/pt` no servidor (`pscp -r`).
2. `bash /tmp/pt/all.sh` (reconstrói a cópia e roda tudo; resultado em `/tmp/pt/FINAL_PRONTO` e `final_*.txt`).
3. UI: `HOMOLOG_SENHA=... node ui/ui2.js` (precisa do Chrome e do Node 22+; define uma senha conhecida pro usuário de teste antes).
4. `php /tmp/pt/cleanup.php` apaga o usuário/bot/fluxos de teste.

Os scripts assumem `/tmp/pt` e o painel em `~/domains/stackcode.com.br/public_html/telegram` (ajuste `setup.sh` em outra hospedagem). `shell_exec`/`proc_open` são desativados lá, por isso a orquestração é em bash.
