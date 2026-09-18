# URL sem `.php` (`/bots` em vez de `/bots.php`)

Feito em 2026-09-18 a pedido do Caio ("fica mais profissional"). Implementado no `.htaccess` da
raiz, sem tocar em PHP.

## Como funciona

Duas regras:

- **(a) 301 do `.php` pra URL limpa** — quem chegar em `/bots.php` é mandado pra `/bots`.
- **(b) rewrite interno da URL limpa pro arquivo real** — `/bots` serve `bots.php` sem mudar o que
  aparece na barra de endereço.

## As três exclusões (e por que cada uma existe)

Não são preciosismo — sem elas o sistema quebra:

1. **Só redireciona `GET`.** Os formulários do painel dão POST direto no `.php` (login, gateways,
   traqueamento, remarketing). Um 301 em POST faz o navegador **descartar o corpo da requisição** —
   o login pararia de funcionar.
2. **`webhook.php` e `webhook_infopago.php` fora.** Essas URLs com `.php` estão **registradas no
   Telegram e na InfoPago**. Não dá pra apostar que robô de terceiro siga redirect — e se não
   seguir, para de entrar pagamento.
3. **`api.php` e `ajax/` fora.** São chamados pelo JS com o `.php` literal, inclusive em GET (o
   `$.getJSON` de `exportar_fluxo`). Redirect ali só adiciona ida-e-volta.

## Verificado ao vivo depois do deploy

| Teste | Resultado |
|---|---|
| `/login`, `/bots`, `/fluxos`, `/leads`, `/admin/dashboard` | 200 (logado) |
| `/bots.php`, `/login.php`, `/admin/dashboard.php` | 301 pra URL limpa |
| `GET` e `POST` em `webhook.php` e `webhook_infopago.php` | 200, **sem redirect** |
| `GET /api.php?action=...` | sem redirect |
| Login por POST + navegação logada em 8 telas | funcionando |
| CSS e imagens | 200 |

## Ponta solta (não quebra nada, mas vale fazer)

Os links internos ainda apontam pro `.php`: **12 `href`** em 9 arquivos e **12 `header('Location: ...php')`**.
Como a regra (a) redireciona, tudo funciona — só que **cada clique no menu vira duas requisições**
em vez de uma (a original + o 301).

Pra limpar isso é preciso, junto:
- trocar os `href`/`action`/`Location` pra sem `.php`;
- **ajustar o `renderizarItemNav()`** em `barra_lateral.php`, que marca o item ativo comparando
  `basename($item['href']) === $pagina_atual`, sendo que `$pagina_atual` vem de
  `basename($_SERVER['PHP_SELF'])` e continua valendo `bots.php`. Se mudar só os `href`, o
  **destaque do menu para de funcionar** — é a pegadinha dessa limpeza.
