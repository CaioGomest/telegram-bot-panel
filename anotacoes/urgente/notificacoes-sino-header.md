# 🔔 Notificações (sino no header) — a fazer

Nota criada em 2026-09-17, a pedido do Caio, enquanto alinhávamos o mobile com o protótipo do
Claude Design. O sino aparece no protótipo em **todas as telas**, ao lado do botão de tema, mas
ficou de fora de propósito nesta rodada ("vamos deixar de lado por hr") — esta nota é pra quando
formos fazer.

## O que o protótipo mostra

- **Header de toda página:** dois botões redondos à direita do título — ☀ (tema, **já feito**) e
  🔔 (notificações, **não existe**).
- **Tela de Dashboard:** painel "ATIVIDADE" com selo verde pulsante **"● AO VIVO"** e itens tipo
  `PIX pago · Abner · Assinatura 20 reais · 2 min`.

## O que já existe hoje (não precisa começar do zero)

| Peça | Estado |
|---|---|
| Tabela `atividades` (+ `registrarAtividade()` em `funcoes/log.php`) | ✅ pronta, já grava em toda venda/split/login/aviso |
| Índice `idx_atividades_tipo` | ✅ criado em 2026-09-17 |
| Painel "Atividade" no `index.php` | ✅ existe, lista de `atividades` com ícone por tipo e tempo relativo ("agora", "5m", "2h") |
| `.ponto-vivo` (bolinha verde pulsante, CSS) | ✅ já existe, usada hoje só em `ranking.php` |
| Sino no header | ❌ não existe em nenhuma tela |
| "AO VIVO" no painel de atividade | ❌ o painel é estático, só atualiza se recarregar a página |
| Estado de lido/não lido | ❌ `atividades` não tem nada disso |

Tipos de atividade que já são gravados hoje: `venda`, `pix_gerado`, `sistema`, `lead`.

## O que precisa ser decidido antes de codar

1. **O sino mostra o quê?** As mesmas linhas de `atividades` (aí é só uma "view" diferente do que
   já existe) ou só um subconjunto que faça sentido como notificação (ex. venda paga, split que
   falhou, acesso cortado — e não login/lead)?
2. **Lido/não lido:** precisa de contador de não-lidas na bolinha do sino? Se sim, `atividades`
   precisa de uma coluna (`lida_em`) ou de uma tabela de leitura por usuário — decisão de schema,
   e a tabela é a que mais cresce no banco (ver `analise-potencia-e-escala.md`).
3. **"Ao vivo" de verdade ou polling?** O padrão já usado no projeto é polling simples (o ranking
   diz "atualiza a cada 60s"). Polling de 30-60s num endpoint leve resolve e é coerente com o
   resto; WebSocket/SSE seria a primeira dependência desse tipo no projeto.
4. **Notificação também fora do painel?** (push do navegador, e-mail, mensagem no Telegram do dono
   do bot). Isso muda bastante o escopo — o protótipo só mostra o sino dentro do painel.

## Por onde começar quando for fazer

- O sino entra no mesmo lugar do botão de tema (`.acoes-cabecalho` de cada página) — no mobile ele
  já cai na linha do título por causa da regra `.alternador-tema { order: 2 }` em `coyote.css`;
  vale dar o mesmo tratamento pro sino pra ficar do lado, como no protótipo.
- Ler de `atividades` com o mesmo `listarAtividades()` que o painel do dashboard já usa, filtrando
  por tipo conforme a decisão do item 1.
- O "AO VIVO" é só aplicar `.ponto-vivo` (já existe) + recarregar a lista por AJAX.

## Notas relacionadas

- `pedido-filtro-periodo-dashboard.md` — rodadas de alinhamento do mobile com o protótipo, onde o
  sino foi adiado.
- `analise-potencia-e-escala.md` — `atividades` é a tabela que mais cresce; qualquer contador de
  não-lidas precisa levar isso em conta.
