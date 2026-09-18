# Editor de fluxo no celular: arrastar e zoom de pinça

Feito em 2026-09-18, a pedido do Caio ("no fluxo no mobile, dar zoom e tirar com dedo, pinça").

## O que estava acontecendo

Medido com toque real (CDP `Input.dispatchTouchEvent`, viewport Pixel 5):

| gesto | antes |
|---|---|
| 1 dedo no canvas | nada — `scrollLeft` não saía do lugar |
| 2 dedos (pinça) | zoom **da página inteira** (`visualViewport.scale` ia de 1 pra 5); o zoom do fluxo ficava em 100% |

## Por que

O pan do canvas é feito por `scrollLeft`/`scrollTop` via script, porque `.conteiner-fluxo`
é `overflow: hidden`. Pro navegador, um elemento que não rola não tem gesto de rolagem —
então o `touch-action: pan-x pan-y` que estava lá não entregava nada, e o gesto ia todo
pra página.

O único caminho de toque que existia era a ponte de arraste de bloco (feita antes nesta
sessão), e ela só escuta em cima da alça do bloco.

## Como ficou

`touch-action: none` no `.conteiner-fluxo` — todo gesto chega no JS e nós decidimos:

- **1 dedo no canvas vazio** → move o canvas.
- **1 dedo em cima de bloco/link/botão** → continua sendo deles (arrastar bloco, editar).
  A lista de exceções é a mesma do pan de mouse.
- **2 dedos** → zoom **ancorado no ponto entre os dedos**. O trecho do fluxo que está
  debaixo da mão fica parado, em vez do zoom fugir pro canto.

Dois detalhes que só aparecem testando:

1. `setZoom()` limita entre 20% e 300%. A conta de ancoragem tem que ler `zoom_level` de
   volta **depois** da chamada — se usar o valor calculado, ao bater no limite o canvas
   continua deslizando sem a escala mudar.
2. Tirar um dedo da pinça reancora o arraste no dedo que sobrou. Sem isso o canvas dava um
   salto na primeira mexida seguinte.

E a ponte de arraste agora solta o bloco quando chega um segundo dedo — senão o bloco
seguiria um dos dedos enquanto o canvas muda de escala.

## Verificado ao vivo

| gesto | resultado |
|---|---|
| 1 dedo, arrastar (−120, −72) | `scrollLeft` 346→466, `scrollTop` 171→243 |
| 2 dedos, abrir | 100% → 260%, página continua em 1 |
| 2 dedos, fechar | 260% → 26% |
| arrastar bloco pelo título | `left/top` 400,400 → 470,440 (não regrediu) |

Zero erro de console nos três.

## Armadilha do teste

O canvas começa **abaixo da dobra** no celular (container em y=726, viewport de 727px).
Os primeiros testes miravam no centro do container em coordenadas de viewport e acertavam
a paleta de blocos, não o canvas — davam "não funciona" pro motivo errado. É preciso
`scrollIntoView` antes, e conferir com `elementFromPoint` onde o toque realmente cai.

## Limitação conhecida (não corrigida)

O canvas tem tamanho de layout fixo (10000×10000) e o zoom é `transform: scale()`, que não
muda caixa de layout. Então o alcance do scroll não cresce junto com o zoom: com zoom alto,
não dá pra chegar na borda extrema do canvas. Na prática não incomoda — os fluxos ficam
perto da origem e sobram ~9600px de alcance.
