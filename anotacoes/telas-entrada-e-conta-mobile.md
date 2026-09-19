# Login, cadastro e Minha Conta no mobile

Feito em 2026-09-18, a partir dos protótipos do Claude Design.

## Login e cadastro

O que mais mudou não foi estilo, foi o que ocupava a tela: o bloco de marketing
(logo gigante, manchete "seus bots vendendo no automático" e as métricas fake de
1.482 leads) tomava ~1000px de altura. No celular era preciso **rolar pra chegar no campo
de e-mail** — numa tela cuja única função é entrar na conta.

Medido antes e depois, viewport de 727px:

| tela | antes | depois |
|---|---|---|
| login | 971px (rolava) | 727px (cabe) |
| cadastro | 930px (rolava) | 727px (cabe) |

No lugar: cabeçalho compacto (logo à esquerda, tema à direita) e a troca entre
entrar/criar conta em pills no topo do formulário, em vez do link "Não tem conta?"
perdido no rodapé. No desktop nada disso muda — a coluna de marca continua lá.

O olho de mostrar senha virou ícone dentro do campo, pra senha ocupar a largura toda como
os outros campos. A regra é escopada em `.login-formulario` pra não mexer no
`.campo-com-acao` das telas de admin.

### Duas decisões que não eram visuais

- **"Confirmar senha" ficou** no cadastro (o protótipo mostra 3 campos, temos 4). Sem ele,
  um erro de digitação só aparece na hora de entrar e vira recuperação de conta.
- **A frase dos termos ganhou link de verdade**: `termos.php`, página pública nova.

## `termos.php` — precisa de revisão

O texto é um rascunho meu, escrito a partir do que o sistema realmente faz (bots do
Telegram, PIX por gateway, dados de leads, LGPD). **Não passou por advogado.** Antes de
entrar cliente real vale uma revisão jurídica — já está no checklist pré-lançamento.

## Minha Conta

Era um formulário corrido de 5 campos. Virou cartão de perfil (avatar com iniciais, nome,
e-mail) + lista de linhas: Dados pessoais, Alterar senha, Gateways de pagamento, Sair.

"Dados pessoais" e "Alterar senha" são `<details>`: no mobile abrem ao toque, no desktop o
JS deixa as duas abertas e o CSS esconde o `<summary>` — senão um formulário que sempre
coube numa tela viraria dois cliques. Continua sendo **um** `<form>` com um Salvar só, então
o POST não mudou em nada.

O botão Salvar só aparece quando alguma seção está aberta (`:has(.conta-secao[open])`).
Com tudo fechado a lista fica com as 4 linhas do protótipo e mais nada.

### O que ficou de fora, de propósito

Mesma régua das "ligas" do ranking: não colocar no ar o que não existe.

- **Badge "COYOTE PRO · ATIVO".** Não há plano/assinatura de usuário no painel. O `id_plano`
  que existe no banco é do plano que o **bot vende**, não do painel.
- **Sino no header e linha "Notificações".** Já estavam anotados como item futuro em
  `urgente/notificacoes-sino-header.md`.

## Armadilha que apareceu três vezes hoje

Especificidade: um seletor mais profundo comendo uma regra de classe. Só aparece olhando a
tela renderizada — o CSS está "certo" na leitura.

1. `html[data-theme] a` (0,1,1) vencia `.login-link-esqueci` (0,1,0) → "termos de uso" e
   "Cadastre-se" saíam brancos em vez de laranja. **Era bug no desktop também**, não só nas
   telas novas.
2. `.conta-secao > summary svg` (0,1,2) vencia `.conta-item-seta svg` (0,1,1) → a seta da
   direita herdava o laranja do ícone da esquerda.

Quando uma regra de cor "não pega", o primeiro lugar pra olhar é se existe um seletor com
`html[data-theme]` ou com mais um elemento na frente.
