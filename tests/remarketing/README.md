# Testes do Remarketing em etapas (26/10/2026)

Três camadas, da mais rápida para a mais completa. Nenhuma toca servidor, banco de produção ou Telegram de verdade.

| Camada | Comando | O que cobre | Precisa de |
|---|---|---|---|
| Regras puras | `php tests/remarketing/unitario_remarketing.php` | período, SQL do público, validação de nome/fluxo/mensagem/variantes/planos/redirect/order bump, variáveis, teclado dos botões, rodízio A/B, e (26/10) frequência do modal Agendar campanha, próxima execução da recorrência e o relógio de 3 horas por fluxo (207) | só PHP |
| Tela | `node tests/remarketing/ui/teste.js` | lista (cartões, grupos por fluxo, busca/filtro/ordem, iniciar/reenviar/agendar com recorrência/excluir) e assistente de 5 etapas, edição, 390/1500/1920/2560 px, escuro e claro; capturas em `%TEMP%/remarketing_ui` (224) | Chrome e internet (jQuery pela CDN). `gerar.php` monta o HTML das páginas reais e `api_falsa.js` simula a API |
| Integração | `node tests/remarketing/integracao/rodar.js [--parar]` | **banco de verdade**: migração do schema antigo pelo "Atualizar Banco", funções, cron (vários bots, lotes, variantes, mídia, bot ruim, bloqueio), cliques do webhook (plano, order bump, venda ligada à campanha), API com CSRF e isolamento entre usuários, CSV, páginas e a fase F: reenvio, intervalo de 3 horas (também no cron), recorrência e banco sem as colunas novas (296) | WAMP (MariaDB 11.3 em `C:/wamp64`), PHP no PATH, Node 22+ |

## Como a integração funciona

- `rodar.js` sobe um MariaDB temporário em `C:/tmp/rm_int/db` (porta 3392; reaproveita se já estiver no ar; `--parar` derruba no fim), cria o banco com o **schema do último commit** (`git show HEAD:deploy/schema.sql`) e copia o painel para `C:/tmp/rm_int/app` com um `config.php` local.
- Na cópia: o Telegram do cron vai para o `telegram_falso.js` (HTTP local que **recusa o que o Telegram recusaria**: texto vazio ou maior que 4096, legenda maior que 1024, HTML inválido, teclado inválido, `callback_data` maior que 64 bytes, foto sem arquivo nem file_id; token "BAD…" = 401, chat terminado em "403" = bot bloqueado e em "777" = resposta lenta de 1,5 s, para apagar a campanha no meio do envio) e o Telegram/Pix dos fluxos vai para `tests/homologacao/sim.php`. O lote do cron cai para 7, a pausa entre envios para 0,3 ms e o corte por falhas seguidas para 3 (só na cópia), para provar o avanço do `OFFSET` e o bot parado sem esperar.
- `integracao.php` roda as fases A (migração e schemas iguais), B (funções), C (cron), D (cliques), E (lista, API, CSV, detalhes) e F (reenvio, intervalo de 3 horas por fluxo, recorrência; o helper `envelhecer()` faz passar 4 horas empurrando `processado_em` para trás). Os helpers `hook_rmk.php`, `api_cli.php`, `render_pagina.php` e `pos_pagamento.php` chamam o painel da cópia por linha de comando.
- `capturas.js` tira capturas das páginas de detalhes renderizadas (rodar depois do `rodar.js`).

## Armadilhas

- No Windows, `escapeshellarg` troca aspas por espaço: JSON vai para os scripts por arquivo (`@caminho`, função `arq()`).
- No Bash do Claude Code, barra invertida e acento em script feito com heredoc se perdem: escreva scripts de edição com a ferramenta de arquivo.
- `colunaExiste()` guarda o resultado por processo: a migração roda em outro processo, antes de o teste carregar o painel.
