# Pedido: filtro de período na dashboard (Hoje / Ontem / 8d / 30d / Total)

Print de referência mandado pelo Caio (print de conversa no Telegram) mostrando um exemplo de
dashboard com abas/filtro de período: **Hoje, Ontem, 8d, 30d, Total**.

## Pedido

Colocar esse mesmo tipo de filtro na dashboard do painel (`index.php` / `admin_dashboard.php`) —
deixar a pessoa alternar entre esses períodos e as métricas da tela se atualizarem de acordo.

## Ainda não implementado

Isso é só a anotação do pedido — não mexi no código ainda. Quando for fazer, checar:

- Onde ficam as métricas atuais da dashboard (vendas, leads, etc. — provavelmente somando tudo
  sem filtro de data hoje).
- Se dá pra fazer com filtro simples de `WHERE criado_em >= ...` nas queries existentes, ou se
  precisa de uma tela nova.
