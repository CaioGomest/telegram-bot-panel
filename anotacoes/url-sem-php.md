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

## Links internos limpos também (mesmo dia)

Na primeira versão os links internos continuavam apontando pro `.php`, então **cada clique no menu
virava duas requisições** (a original + o 301). Corrigido em 29 pontos: `href`, `action`,
`header(Location:)`, os menus de `barra_lateral.php` e os links dos JS de tela. Medido depois:
navegação agora responde **200 com 0 redirects**.

**A pegadinha dessa limpeza:** `renderizarItemNav()` marca o item ativo comparando
`basename(href)` com `basename($_SERVER["PHP_SELF"])` — e PHP_SELF continua valendo `bots.php`
mesmo servindo `/bots`. Mexer só nos `href` **quebraria o destaque do menu**; os dois lados
precisaram ser normalizados juntos. Conferido ao vivo depois: o item ativo funciona nas cinco telas
testadas, tanto na barra lateral quanto na folha "Mais" do mobile.

`api.php`, `ajax/` e `webhook*.php` seguem com `.php` de propósito.
