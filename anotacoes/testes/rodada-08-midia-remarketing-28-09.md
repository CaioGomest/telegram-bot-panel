# Foto/vídeo no remarketing (28/09/2026)

Pedido direto de cliente (print de conversa passado pelo Caio): campanha de remarketing
só mandava texto, e "mandar só msg não vinga muito" — precisava anexar foto/vídeo.
Implementação completa em `assets/remarketing.js` / `remarketing.php` / `api.php` /
`cron/cron_remarketing.php`, plano técnico registrado em
`C:\Users\Pichau\.claude\plans\chefao-fizemos-mts-coisas-snappy-badger.md` antes de
mexer no código (decisão de arquitetura: cache do `file_id` do Telegram depois do
primeiro envio de cada campanha, pra não reenviar o arquivo pra cada destinatário).

## ✅ Testado e confirmado ao vivo (produção)

- Migração rodada (`midia_caminho`, `midia_tipo`, `midia_file_id` em
  `remarketing_campanhas`).
- `upload_midia_remarketing` (novo, em `api.php`), com conta descartável:
  - Sem arquivo → rejeita com mensagem clara.
  - Extensão fora da whitelist (`.txt`) → rejeita.
  - Conteúdo fake com extensão `.jpg` (txt renomeado) → `getimagesize()` pega, rejeita
    ("Imagem inválida, vazia ou maior que 5MB").
  - Imagem PNG válida de verdade → aceita, salva em `uploads/`, devolve caminho+tipo, e
    o arquivo fica realmente acessível pela URL pública (confirmado com `curl` direto).
  - Arquivo de 6MB (>5MB) → rejeita pelo limite de tamanho.
- Dado de teste (conta + arquivo enviado) já limpo do servidor.

## ❓ Não testado (bloqueado — falta bot de teste)

Não tenho como criar um bot do Telegram (precisa do @BotFather, sem acesso). Por isso
**não testei o caminho completo de entrega**: criar campanha com foto de verdade → cron
processar → foto chegar num chat real → `midia_file_id` ficar salvo depois do primeiro
envio → próximo destinatário reusar o `file_id` sem reenviar o arquivo.

A lógica do cron foi escrita espelhando exatamente o padrão já comprovado de
`webhook.php::requisicaoTelegram()` (mesma forma de montar `CURLFile`, mesmo
`resolverCaminhoUploadSeguro`), então a confiança é alta por revisão de código — mas
**isso não substitui ver uma foto chegar de verdade**. Se o Caio (ou o cliente que pediu)
testar com um bot real e a foto não chegar, é o primeiro lugar a olhar: resposta do
`sendPhoto`/`sendVideo` no log de `remarketing_envios.resposta`.

## Como testar quando tiver um bot

1. Criar campanha em `remarketing.php` anexando uma foto pequena, agendada pra daqui a
   1-2 minutos.
2. Rodar o cron manualmente: `php cron/cron_remarketing.php` (SSH) ou via HTTP com a
   chave de cron, se configurada.
3. Conferir no Telegram que a foto chegou com a legenda certa.
4. Checar no banco: `SELECT midia_file_id FROM remarketing_campanhas WHERE id = X` — deve
   estar preenchido depois do primeiro envio.
5. Se a campanha tiver mais de 1 destinatário, conferir no log (`echo` do cron, ou
   `remarketing_envios`) que os envios seguintes não reenviam o arquivo (mais rápidos que
   o primeiro).
