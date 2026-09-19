# Paginação sem recarregar a página

Feito em 2026-09-19.

## O critério (do Caio)

> "lugares como o log dentro da dash, pq n faz sentido recarregar a página inteira só pra
> paginar uma informação da página; páginas grandes tipo usuários, n precisa via ajax até
> pq a listagem é o principal"

Ou seja: vale pra **lista que é um bloco dentro de uma página que tem outra coisa**. Onde a
listagem *é* o produto da página, recarregar é justo — não é desperdício, é a página.

| bloco | é um bloco dentro de outra coisa? | virou AJAX |
|---|---|---|
| Log do sistema no `admin/dashboard.php` | sim (rodapé, abaixo de 5 KPIs + gráfico) | **sim** |
| Atividade no `index.php` | sim (mesma posição) | **sim** |
| `admin/logs.php` | não, a lista é a página | sim (feito antes do critério ficar claro; fica, é ganho menor) |
| `admin/transacoes.php` | não | não |
| `leads.php` | não | não |
| `admin/usuarios.php` | não | não |

## Por que doía nos dashboards

Trocar 10 linhas de uma lista no rodapé refazia as ~20 consultas de KPI e redesenhava os
gráficos. E o paginador não tinha âncora: o scroll voltava pro topo, então você rolava a
página inteira de novo pra ver o resultado que acabou de pedir.

Medido depois, no dashboard do admin: **8,1 kb num único pedido**, sem recarregar, KPI
intacto, gráfico vivo, e o scroll parado em 500px em vez de ir pra 0.

## Como funciona

Segue o idioma que o `remarketing.php` já usava: guarda no topo do próprio arquivo que sai
antes de renderizar o resto.

- `inicioBlocoPaginado()` / `fimBlocoPaginado()` marcam o container com `data-bloco`.
- `pedidoDeBloco()` **exige o cabeçalho `X-Requested-With`**. Abrir a URL na mão continua
  devolvendo a página inteira — o link de paginação segue sendo um `<a href>` de verdade,
  funciona sem JS, e ninguém cai num pedaço solto de HTML sem menu.
- `assets/js/paginacao.js` intercepta o clique, troca o bloco, ajusta a URL com `pushState`
  (sem o `?bloco=`, que é detalhe interno). Se o fetch falhar, deixa o navegador navegar.

### O detalhe que faz a economia existir

A busca da lista teve que **subir pra antes do bloco pesado** nos dois dashboards. Com a
guarda no fim do arquivo, o parcial seria devolvido certinho — mas as ~20 consultas já
teriam rodado, e a economia seria só de repintura. A lista só depende de `$user_id` e do
`?pagina=`, então içar não mudou nenhum resultado.

Os dois dashboards passaram a dividir `parciais/lista_atividades.php`. As diferenças viraram
parâmetro (`$mostrar_usuario`, `$texto_vazio`) e os ícones viraram um mapa por tipo.

## Erro de medição que cometi no caminho

Minha primeira tabela mostrava `index.php` e `admin/dashboard.php` com números idênticos
(35,1 kb / 9,8 kb). Não era coincidência: eu medi os dois logado como **admin**, e o admin
agora é redirecionado de `/index` pra `/admin/dashboard` — então medi a mesma página duas
vezes. Quando a sessão importa pro que a página faz, o teste tem que usar o perfil certo.

## Como testar isso de novo

O usuário de teste 3 (`carlos3anos`) **não tem atividades**, então o dashboard dele não mostra
paginação — um teste de clique ali dá timeout sem haver bug nenhum. Use o dashboard do admin,
que tem log de sobra.

E não compare listas pelo título do primeiro item: o log do admin repete títulos ("Login",
"Login", ...) e dá falso "não mudou". Compare o texto completo dos itens.
