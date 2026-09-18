# 🔴 URGENTE (fazer depois) — as 4 mudanças que destravam o motor

Anotado em 2026-09-18, a pedido do Caio, pra resolver mais pra frente. Vem do relatório
`anotacoes/capacidade-700-usuarios-1000-vendas-dia.md` (parte 2). Nenhuma delas depende de plano
de hospedagem — é jeito de fazer, não força de máquina.

**Contexto em uma frase:** o painel (as telas) aguenta crescer bastante, está medido. O que não
aguenta é o trabalho que o sistema faz sozinho em segundo plano.

---

## 1. Os crons trabalham "um de cada vez" (o mais grave)

**Em linguagem simples:** é um porteiro único que precisa avisar e remover milhares de pessoas por
dia, mas atende uma por vez e cada atendimento leva ~1,5 s. A fila nunca zera — na prática, quem
não pagou continua no grupo de graça porque o porteiro não chega nele.

**Tecnicamente:** `cron_verificar_acessos.php`, `cron_aviso_vencimento.php` e `cron_renovacao.php`
fazem laço sequencial chamando a API do Telegram/gateway item a item. Capacidade ≈ 40-50 itens/min
no corte de acesso (3-4 chamadas ao Telegram por membro) e ~200/min no aviso.

**Solução:** replicar o padrão que **já existe e funciona** em `cron_remarketing.php` — `curl_multi`
(várias chamadas em voo), pacing de ~40 ms por bot e `FOR UPDATE SKIP LOCKED` pra várias instâncias
trabalharem em paralelo sem colidir. Não é inventar: é copiar o que o próprio projeto já acertou.

## 2. Pagamento confirmado segura a fila

**Em linguagem simples:** é o caixa que, depois de receber seu dinheiro, vai ao banco fazer a
transferência do lucro do dono, volta, e só então libera a fila. Todo mundo atrás esperando. Pior:
a InfoPago fica sem resposta rápida, acha que falhou e **reenvia o aviso** — justamente no pico.

**Tecnicamente:** `dispararSplitInfopago()` roda antes do `http_response_code(200)`
(`webhook_infopago.php:268` e `:416`). Cada confirmação = consulta InfoPago + OAuth Cash-Out +
1 transferência por destino (timeout 30 s cada) + `createChatInviteLink` + mensagens, tudo dentro
da requisição. 2-5 s por confirmação.

**Solução:** responder 200 na hora e processar o resto numa fila. Mesmo volume passa a caber em
~10× menos workers.

## 3. Varreduras sem `LIMIT`

**Em linguagem simples:** a ordem é "confira *todos* os pedidos vencidos", sem dizer "confira 200
por vez". Com pouco volume tudo bem; com muito, a tarefa não fecha no tempo que tem e trava.

**Tecnicamente:** conferido — `cron_aviso_vencimento.php`, `cron_verificar_acessos.php` e
`cron_renovacao.php` têm **zero** ocorrências de `LIMIT`; a segunda query de `cron_verificar_pix.php`
(a que expira cobranças) também não tem. Correção de quase uma linha em cada.

## 4. Um arquivo JSON para todos os tenants

**Em linguagem simples:** 700 lojas anotando no mesmo caderno de papel ao mesmo tempo, sem combinar
quem escreve — uma apaga o que a outra acabou de anotar.

**Tecnicamente:** `storage/pix_recorrente_estado.json` (`webhook.php:85`) guarda o estado de PIX
recorrente de toda a plataforma num arquivo só, com leitura sem lock. Precisa virar tabela no banco.

---

## Ordem sugerida quando for atacar

1. `LIMIT` nas varreduras (menor esforço, tira o risco de travar rodada).
2. Paralelizar os crons no padrão do remarketing (maior ganho).
3. Tirar split/liberação de acesso do caminho síncrono do webhook (precisa de fila).
4. Mover o estado de PIX recorrente pro banco.

## Notas relacionadas

- `capacidade-700-usuarios-1000-vendas-dia.md` — relatório completo com as contas.
- `analise-potencia-e-escala.md` — medições das telas e do banco.
- `banco-sem-permissao-insert-update.md` — o episódio da cota estourada (parte de infra, não daqui).
