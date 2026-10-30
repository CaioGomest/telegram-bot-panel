# Testes do chat dos leads (botão "Abrir chat" em Leads) — 30/10/2026

Duas camadas. Nenhuma toca servidor, banco de produção nem Telegram de verdade.

| Camada | Comando | O que cobre | Precisa de |
|---|---|---|---|
| Integração | `node tests/chat/rodar.js [--parar]` | **banco de verdade**: ganchos de entrada e saída, escrita em lote, leitura/paginação/tempo real, posse (um usuário nunca lê nem manda pelo bot de outro), envio manual, retenção, chave estrangeira, plano de execução (usa o índice), endpoints (`ajax/chat_*.php`: 401/403/405/422/429), migração do servidor recriando a tabela, e **desempenho com 300 mil mensagens** | WAMP (MariaDB 11.3), PHP no PATH, Node 22+ |
| Tela | `node tests/chat/ui.js` | modal em Chrome real (1280, 1920, 1024x600, 2560, celular 390): montagem preguiçosa, lista lateral, 40 mensagens, rolar para cima, tempo real, envio, erro, busca, Esc/foco, cache, tema claro, cor do sistema, segurança (HTML do lead vira texto). Capturas em `%TEMP%/chat_ui/*.png` | Chrome, Node 22+ |

## Como a integração funciona

- `rodar.js` sobe um MariaDB temporário em `C:/tmp/chat_int/db` (porta 3393; reaproveita se já estiver no ar; `--parar` derruba no fim), cria o banco com o `deploy/schema.sql` **atual**, copia o painel para `C:/tmp/chat_int/app`, escreve o `config.php` e aponta o envio manual para o Telegram falso (HTTP local, dentro do próprio `rodar.js`).
- O PHP roda em processo **assíncrono**: o Telegram falso mora no Node e precisa responder enquanto o PHP espera (com `spawnSync` o laço de eventos para e cada envio espera os 10 s do curl).
- `integracao.php` roda as fases A–J; `endpoint.php` executa cada `ajax/chat_*.php` como o servidor faria (sessão, método, cabeçalho CSRF e corpo JSON simulados).
- No fim, o `rodar.js` apaga a tabela e roda `deploy/vps/atualizar-banco.php` duas vezes (a migração exige um administrador cadastrado, como no servidor).

## Armadilhas

- `exec`/`escapeshellarg` no Windows: os caminhos vão entre aspas duplas montadas à mão e a configuração vai por arquivo JSON.
- O PDO devolve inteiros como `int` (prepares nativos): compare com `(int)`, não com `'1'`.
- A consulta de leitura usa `FORCE INDEX (idx_chat_conversa)`; o teste da fase E usa a mesma função (`chatSqlLeitura`) para não divergir. Sem o índice forçado, com tabela pequena o otimizador varre a chave primária de trás para frente.
