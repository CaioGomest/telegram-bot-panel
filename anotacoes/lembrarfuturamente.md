# Lembrar futuramente

Notas curtas de problemas que podem voltar: o que é, como reconhecer, a causa e como resolver. Pedido do dono em 08/11/2026. Itens novos entram no fim, com data. Nunca colocar senha, token nem credencial aqui.

---

## 1. "O vídeo está chegando em 1:1 (quadrado) / cortado no iPhone" (08/11/2026)

**Quem reclamou:** o Lima, vendedor com o bot @IsisGevellatoBot: "tá mandando toda mídia em formato 1:1 pro lead". Dois prints do mesmo `/start`: no iPhone o vídeo vertical aparecia quase quadrado e com o topo cortado (o rosto sumia na boca); no Android aparecia em retrato (~4:5).

**Dois problemas diferentes com o mesmo sintoma. Conferir os dois, nesta ordem:**

1. **O painel não mandava as medidas do vídeo (item 80, 06/11).** Sem `width`, `height` e `duration` no `sendVideo`, o Telegram só descobre o formato depois de baixar o arquivo inteiro e mostra a prévia quadrada. Os MP4 de celular e editor têm o índice (moov) no fim do arquivo, então isso acontecia com quase todo vídeo.
   - Correção: `funcoes/video_info.php` lê largura, altura e duração do MP4/MOV em PHP puro (sem ffprobe, que não existe na VPS) e o `requisicaoTelegram` (`funcoes/fluxo_blocos.php`) acrescenta isso a todo `sendVideo` com upload. A chave do cache de file_id de vídeo ganhou o sufixo `|v2`, para descartar file_id antigos que guardaram o vídeo sem as medidas.
   - **Como conferir que está valendo:** na VPS, rodar `parametrosFormatoVideoTelegram()` num vídeo de `uploads/` (deve devolver width/height/duration). Melhor ainda: mandar o vídeo a um chat de teste e ler a resposta do `sendVideo`, que traz `video.width/height/duration` como o Telegram guardou. Em 08/11 voltou 1080x1920, 14 s, ou seja, o formato estava certo.

2. **A legenda longa recorta o vídeo vertical no iPhone (item 94, 08/11).** Mesmo com as medidas certas, o aplicativo do Telegram no iPhone encaixa o vídeo na largura da bolha, e a bolha fica larga por causa da legenda. Resultado: vídeo vertical cortado, quase 1:1. O Android recorta menos. Isso é desenho do aplicativo, não erro do painel.
   - **Teste que provou (08/11, conta "Gomes VPS", bot de teste, Lima no iPhone):** o mesmo vídeo em 3 formatos. A) legenda longa; B) legenda curta; C) vídeo sem legenda e o texto logo depois em mensagem separada. **A C foi a que saiu inteira** (o Lima: "o C foi o melhor").
   - **Correção:** em `funcoes/guiado_ofertas.php`, `enviarMensagemComMidiaBasico()`: `$legenda_cabe = $midia_tipo !== 'video' && mb_strlen($mensagem) <= 1024;`. Vídeo vai sozinho e o texto com os botões segue em outra mensagem (o caminho que já existia para legenda acima de 1024 caracteres). Foto continua com legenda.
   - **Vale para:** boas-vindas, Upsell, Downsell e Order Bump do modo Guiado.
   - **Ainda com legenda no vídeo (pode reclamar também):** bloco de vídeo do Editor Visual (`requisicaoTelegram(... 'sendVideo' ...)` em `funcoes/fluxo_blocos.php`), prévias e packs (`funcoes/previas.php`, `funcoes/packs.php`), assinatura (`funcoes/assinatura.php`) e remarketing por cron (`cron/cron_remarketing.php`, `funcoes/remarketing_ofertas.php`). Se reclamarem desses, aplicar o mesmo: mandar o vídeo sem `caption` e o texto com `reply_markup` em seguida.
   - Testes que mudaram por causa disso: `tests/guiado/unitario_cache_midia_envio.php` e `unitario_oferta_midia.php` (agora esperam `sendVideo` seguido de `sendMessage`).

**Como testar de novo, sem mexer no bot do cliente:**
- Usar uma conta e um bot de teste, importar o fluxo por código (Fluxos, importar por código; o código copia o fluxo e não liga ninguém ao original) e mandar o link do bot a quem tem iPhone.
- Depois do `/start` da pessoa, ela vira lead do bot de teste. Dá para mandar variações do vídeo a esse chat pelo Bot API, usando o token do bot de teste.
- Cuidado ao estimar: vídeo já entregue a leads antes da correção continua como estava.

**Estado em 08/11/2026:** item 80 já estava na VPS; item 94 pronto e testado no PC, **publicação na VPS dependia do aval do dono** (ver item 94 em `tarefas_revisao_final.md` para o estado atual).

---

## 2. "O redirecionador por país/IP não bloqueia ninguém" (08/11/2026, investigado, ainda sem correção)

**Quem reclamou:** o Lima: o link de redirecionamento com filtro de país não funcionava (antes funcionou uma vez com VPN, depois deixou de funcionar). O link dele (`jgqknexn`, usuário 14011) estava sem filtro nenhum porque ele "tirou tudo".

**Causa achada:** o país do visitante vem de `ipwho.is` (`paisDoIpRedirecionamento()` em `funcoes/protecao_redirecionamento.php`). Da VPS ele devolve **HTTP 429 "Rate limit exceeded"**. Sem país, a segmentação **falha aberta** (decisão do item 57: uma queda do serviço não pode derrubar venda), então todo IP fora do cache (~2.900 IPs em 08/11) passa. É o "limite a vigiar" que a `OBSERVACOES.md` já avisava.

**Como reconhecer de novo:** na VPS, `curl -s https://ipwho.is/8.8.8.8` mostra `"success":false,"message":"Rate limit exceeded"`; `paisDoIpRedirecionamento('8.8.8.8')` devolve NULL em ~30 ms.

**Correção proposta (não feita; o dono pediu para segurar):** trocar a consulta por uma base local de IP para país (DB-IP Lite, gratuita, sem conta) numa tabela do banco, com atualização mensal, ou usar um provedor com chave via `GEOIP_URL` no `config.php`. Testar com IPs reais do Brasil e dos EUA antes de publicar.

**Atenção a uma expectativa diferente:** o Redirecionamento só sabe BLOQUEAR por país (página segura ou outra URL). Não existe "Brasil vai para o bot A, o resto vai para o bot B". Se o cliente espera isso, é funcionalidade nova. Item 93 em `tarefas_revisao_final.md`.
