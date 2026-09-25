# Criação de fluxo no Shark Bot: Básico, Fluxo n8n e Modo IA

Documento técnico para planejar a implementação em outro projeto. Capturado em 25 de setembro de 2026, logado em sharkbot.com.br. Textos de tela, modos internos e contratos de API foram lidos da interface e das respostas reais.

O rascunho temporário usado para abrir o Modo IA foi apagado em seguida. A conta voltou a 2 fluxos de 50.

**Leitura obrigatória antes de implementar.** Fluxo n8n é o editor visual de nós da própria Shark (`mode: "avancado"`). Não há campo de URL de webhook do n8n nem payload de ida e volta com o n8n. Modo IA é um agente de conversa com créditos, e não um prompt que gera um fluxograma.

---

## 1. Como a escolha de tipo funciona

### Onde fica

A partir de Meus Fluxos (`https://sharkbot.com.br/fluxos`), o botão verde se chama **Criar Fluxo (N/50)** e aponta para `https://sharkbot.com.br/fluxos/novo`.

A escolha é uma página inteira do painel, com a sidebar normal. Não é modal, não é wizard de vários passos e não é um conjunto de rotas separadas por tipo.

### Cabeçalho da página

- Título: **Criar novo fluxo**.
- Subtítulo: **Escolha como sua operação vai conversar e vender.**
- Ícone do título: lucide `Workflow`, cor da marca.
- Botão X no canto, `aria-label` **Fechar criação de fluxo**. Leva de volta para `/fluxos`. O botão **Cancelar** do rodapé faz o mesmo.

### Campo de nome

- Rótulo: **Nome do fluxo**, com asterisco vermelho. É o único campo obrigatório nesta tela.
- Input `id="flow-name"`, autofocus, `maxlength="30"`.
- Placeholder: **Ex: Conversão VIP**.
- Ajuda à esquerda: **Use um nome fácil de encontrar depois.**
- Contador à direita: `N/30`, atualizado a cada tecla.
- Enter com o nome preenchido dispara a criação.
- Se o nome estiver vazio e a criação for tentada, aparece o alerta: **Preencha o nome do fluxo para continuar.**

### Os três cards

Seção com título **Como deseja criar?** e o texto: **Você pode manter os fluxos atuais e testar o Modo IA em um rascunho separado.**

Os cards ficam numa grade de 1 coluna no mobile e 3 colunas a partir de `md`. Cada card é um `button` com `aria-pressed`, altura mínima 162px, cantos 16px. O selecionado ganha um ícone de check no canto. O padrão ao abrir a página é **Básico**.

#### Básico

- Ícone: raio (lucide `Zap`). Acento da marca (azul/ciano da Shark).
- Sem badge.
- Descrição literal: **Configuração guiada de mensagens, planos, upsell e entrega.**
- Valor interno do modo: `basico`.

#### Fluxo n8n

- Ícone: ramificação (lucide `GitBranch`). Acento âmbar.
- Sem badge de "em breve". O card está clicável.
- Descrição literal: **Editor visual com nós, condições, esperas e caminhos personalizados.**
- Valor interno do modo: `avancado`. O nome "n8n" é só o rótulo da interface.

#### Modo IA

- Ícone: cérebro com circuito (lucide `BrainCircuit`). Acento violeta.
- Badge no card: **Disponível**. Pill, 9px, negrito, maiúsculas, fundo violeta translúcido.
- Descrição literal: **Atendimento automatizado com identidade, ofertas e créditos gerenciados pela Shark Bot.**
- Valor interno do modo: `ia`.

### Caixa que muda conforme o card

Abaixo dos cards há uma caixa.

- Nos modos Básico e Fluxo n8n o título é **Bots vinculados depois** e o texto é: **Depois da criação, você escolhe os bots que executarão este fluxo. Seus bots atuais não serão alterados.**
- No Modo IA o título vira **Atendimento com IA** e o texto vira: **Crie o atendimento, escolha uma estratégia e teste em privado. No editor, você vincula o bot e decide exatamente quando publicar.**

### Coluna lateral: O que muda em cada modo

- **Básico**, ícones da marca: Mensagens prontas. Planos e pagamentos. Upsell e downsell.
- **Fluxo n8n**, ícones âmbar: Nós e condições. Esperas e caminhos.
- **Modo IA**, com o texto Disponível em violeta: Motor de conversa gerenciado. Créditos pré-pagos.

### Rodapé fixo

- Sem nome: **Dê um nome ao fluxo para continuar.** Botão **Criar fluxo** desabilitado.
- Com nome, Básico ou n8n: **Pronto para criar.** Botão **Criar fluxo**, ícone Workflow.
- Com nome, Modo IA: **O rascunho não será conectado a nenhum bot.** O botão muda o rótulo para **Criar rascunho IA** e o ícone para BrainCircuit.
- Enquanto a requisição corre, o botão mostra **Criando...**.
- Erro de API aparece num alerta vermelho com a mensagem do servidor, ou o fallback **Erro ao criar fluxo**.

---

## 2. Contrato da criação

Os três tipos nascem do mesmo endpoint. O que muda é o campo `mode` e, no cliente, a rota de destino.

```http
POST /api/flows
Content-Type: application/json
credentials: include
```

```json
{
  "name": "nome com no maximo 30 caracteres",
  "mode": "basico"
}
```

`mode` aceita `"basico"`, `"avancado"` ou `"ia"`.

Resposta observada ao criar o Modo IA: HTTP 201, `success: true`, `data.flow` com o documento inteiro. O cliente lê `data.flow._id`.

- Se `mode` é `ia`, navega para `/fluxos/{id}/ia`.
- Nos outros dois modos, navega para `/fluxos/{id}`. O editor dessa URL muda conforme o `mode` já salvo no documento.

### Diferença de status no nascimento

- Básico e Fluxo n8n já existentes na conta nasceram com `status: "ativo"`. O editor abre direto.
- Modo IA nasce com `status: "rascunho"`. O documento de fluxo traz `botId: "000000000000000000000000"` e `bots: []`. Na listagem isso aparece como **Rascunho seguro, sem execução no bot**.
- O restante do documento de fluxo (`initial`, `plans`, `upsell`, `downsell`, `delivery`, `messages`) é criado junto, mesmo no Modo IA, com os mesmos defaults do fluxo de formulário. O comportamento de conversa da IA fica noutro recurso: `/api/ai/flows/{id}/config`.

### Defaults relevantes do documento de fluxo

- `audience`: `"br"`.
- `initial.message`: `Olá {nome}! Bem-vindo ao @{bot.username}`.
- `initial.ctaText`: emoji de carrinho + `Ver Planos`. `initial.ctaAction`: `show_plans`. `ctaEnabled`: `false`.
- `initial.mediaType`: `none`. `secondaryMessageEnabled`: `false`.
- `delivery.type`: `grupo_telegram`. `delivery.requireEmail`: `false`.
- `upsell` e `downsell`: `enabled: false`, `discountPercent: 0`, `sequences: []`.
- `orderBump`: `enabled: false`, `price: 0`, `deliveryType: "mensagem"`.
- `protectContent`: `false`. `singleStartMode`: `false`. `editor`: `null`. `nodes`, `edges` e `steps` vazios.
- `messages.pixGenerated` e `messages.paymentApproved` começam com `mediaType: "none"` e `message` vazia.

---

## 3. Tipo Básico

Depois de criar, abre `/fluxos/{id}`. Com `mode: "basico"` isso é um editor de seções, guiado, e não o canvas de nós.

O fluxo observado se chama `teste`, id `6ab6b6efc14ee87783950e3a`, criado em 25/09/2026, status `ativo`, sem bot vinculado, starts 0.

### Barra do editor

- Voltar: botão **Fluxos**, aria **Voltar para Meus Fluxos**.
- Nome do fluxo com lápis, aria **Editar nome do fluxo**.
- Selo **EDITOR DE FLUXO**.
- Select **Idioma automático**. Opções: **Manter idioma atual** (valor vazio), **Português** (`pt-BR`), **English** (`en-US`), **Español** (`es-ES`). Há um botão **Explicar idioma automático**.
- Estado **Tudo salvo**, botão **Salvar alterações**, botão **Compartilhar**.

### Navegação: 13 seções em 4 grupos

A lateral mostra o índice, por exemplo **1 de 13 seções**, e pode ser recolhida. A trava depende de existir um canal de cache (`supportChannelId`). Sem esse canal, a maior parte das seções fica com cadeado âmbar e `aria-disabled`. O aviso clicável diz: **Configure o cache em Bots para liberar as demais seções.** Ele devolve o usuário para a seção Bots.

No fluxo `teste` o cache não estava configurado. Por isso Boas-vindas, Planos, Upsell, Downsell, Order Bump, Packs, Prévias, Pagamentos, Assinatura e Top Assinantes não abriram na tela. Bots, Conversões e Botões abriram. Os campos das seções travadas abaixo vêm do código do editor e do JSON do fluxo.

| id interno | Rótulo | Grupo | No fluxo teste | Função |
|---|---|---|---|---|
| `bots` | Bots | Estrutura | Aberta | Bots vinculados e canal de cache |
| `inicial` | Boas-vindas | Estrutura | Cadeado sem cache | Mensagem inicial, mídia, CTA |
| `produtos` | Planos | Estrutura | Cadeado sem cache | Planos, preço, entrega |
| `upsell` | Upsell | Conversão | Cadeado sem cache | Sequências depois da compra |
| `downsell` | Downsell | Conversão | Cadeado sem cache | Sequências se não comprar |
| `order_bump` | Order Bump | Conversão | Cadeado sem cache | Oferta extra no checkout |
| `packs` | Packs | Conversão | Cadeado sem cache | Produtos avulsos |
| `previas` | Prévias | Conversão | Cadeado sem cache | Mídias de amostra que se apagam |
| `mensagens` | Pagamentos | Operação | Cadeado sem cache | Textos de PIX gerado e pago |
| `assinatura` | Assinatura | Operação | Cadeado sem cache | Renovação fora do grafo |
| `top_subscribers` | Top Assinantes | Operação | Cadeado sem cache | Ranking e prêmios |
| `conversoes` | Conversões | Análise | Aberta | Funil e origens |
| `botoes` | Botões | Análise | Aberta | Cor dos botões inline |

### Seção Bots, vista na tela

- Resumo do fluxo à direita: **LEADS**, **VIPS**, **RECEITA** (no teste, 0, 0 e R$ 0,00).
- Título: **Bots Vinculados**. Subtítulo: **Gerencie os bots que executam este fluxo.**
- Contador **0/10 bots**. Texto vazio: **Nenhum bot vinculado. Adicione bots para executar este fluxo.**
- Botões: **Criar Bot** e **Adicionar Bot**. Combobox: **Selecione um bot para adicionar...** Há um botão **Atualizar lista de bots** e outro **Criar novo bot**.
- Ajuda: **Adicione múltiplos bots para executar este fluxo (máx. 10).**
- Dica: **Com múltiplos bots, você pode distribuir o atendimento entre eles. Cada bot receberá leads independentemente e executará o mesmo fluxo.**
- **Cache de Mídia**. Rótulo: **Canal ou grupo para cache do Telegram**. Combobox **Selecionar canal/grupo**, desabilitado até haver bot.
- Instruções literais: **Crie um canal ou grupo no Telegram. Adicione o bot como administrador. Dê permissão de postar mensagens. Clique no botão Atualizar ao lado.**
- Aviso: **Necessário para enviar mídia.**
- Campo **@ Suporte**, marcado como opcional, placeholder `@username`.

### Seção Botões, vista na tela

Título: **Estilos dos Botões**. Texto: **Personalize a cor dos botões inline do Telegram. Funciona apenas em bots com Premium Business API.** Cada item tem as cores **Padrão**, **Azul**, **Verde** e **Vermelho**.

Boas-vindas:

- **Botão CTA**. Botão principal (ex: Ver Planos).
- **Botão Redirect**. Botão de prévias / canal externo.
- **Botão Prévias**. Botão que envia as prévias (aba Prévias).

Fluxo de compra:

- **Lista de Planos**. Botões na lista de escolha de plano.
- **Confirmar Compra**. Botão para confirmar e gerar PIX.
- **Verificar Pagamento**. Botão após gerar o PIX.

Upsell, Downsell, Order Bump e Packs:

- **Aceitar Upsell** e **Recusar Upsell**.
- **Aceitar Downsell** e **Recusar Downsell**.
- **Aceitar Bump** (botão "Sim, quero adicionar") e **Recusar Bump** ("Não, obrigado").
- **Comprar Pack** e **Verificar Pagamento** do pack.

### Seção Conversões, aberta e vazia neste fluxo

O componente existe e a seção não tem cadeado. No fluxo `teste` não havia fontes de conversão, então a tela cai no estado vazio. O código do componente nomeia os blocos **Resumo Geral**, **PIX Gerados** e **Conversão**, e agrupa origens por tipo.

### O que o código das seções travadas declara

Isto não foi clicado na UI porque o cadeado impediu. São rótulos e regras presentes nos bundles do editor básico.

#### Boas-vindas

- Campo **Mensagem de Boas-vindas**, com placeholder **Digite a mensagem de boas-vindas...** e inserção de variáveis e **Emojis Premium** (`tg-emoji`).
- Upload de mídia, inclusive converter para **Video Nota (Redondo)** e voltar a vídeo normal.
- Mídia dinâmica de cidade: nome da modelo (placeholder **Nome da sua modelo**) e idade (placeholder **Ex: 21**). A novidade pública da Shark descreve o vídeo gerado no `/start` com cidade do lead, vinda do IP do clique no link de divulgação.
- Há um mini-app de prévia social com presets internos `feedback`, `dm` e `clients-chat` (botão, mensagem de conteúdo bloqueado, mensagens simuladas). Isso é recurso de prova social da boas-vindas, separado da aba Prévias.

#### Planos

- Botão **Adicionar Plano**. Cada plano tem nome, preço e tipo de entrega.
- Tipos citados na interface do componente: **Entrega padrão**, **Video Call**, entrega por outro bot que cobra taxa (o lead recebe um link para esse bot) e roleta como Mini App.
- Cobrança do plano: **Sem cobrança (grátis)**, **Cobrar após preview (lead vê prévia grátis)**, **Cobrar antes (lead paga pra entrar)**.
- Mensagens de entrega no grupo/canal, com **Delay em segundos**.
- Opção **Mesmo delivery do plano**.
- Aviso de moeda: **Desative a variação automática de preço para configurar preços por moeda. Planos de videochamada e roleta continuam usando os preços atuais.**
- No código do plano há um teto de 10 itens numa lista.

#### Upsell e Downsell

- Componente compartilhado de sequência. Teto: **Até 20 sequências**. Teto de espera: **7 dias**. Mensagens: **Já chegou no delay máximo (7 dias)** e **Limite de 7 dias atingido**.
- Upsell é descrito como **Ofertas premium** e **Aumento do ticket médio**.
- Downsell é descrito como **Ofertas alternativas** e **Recuperação de vendas**. Texto do componente: **Downsell é enviado automaticamente a cada X minutos após o /start se o cliente não comprar.**
- Placeholders de mensagem: **Oferta especial para você...** e **Não conseguiu pagar? Temos uma oferta especial...**
- Cada item da sequência pode ter vídeo e grupo/canal de entrega próprios. Se o vídeo da sequência não for configurado, usa o vídeo da aba do fluxo.

#### Order Bump

- Quatro contextos de exibição, cada um com checkbox:
  - **Fluxo Inicial** — quando o cliente seleciona um plano principal. Rótulo curto: **Exibido junto aos planos principais**.
  - Upsell — quando aceita a oferta. **Exibido nas ofertas de upsell**.
  - Downsell — quando aceita a oferta. **Exibido nas ofertas de downsell**.
  - Packs — quando seleciona um pack avulso. **Exibido na compra de packs avulsos**.

#### Packs

- Botão **Adicionar Pack**. Campos vistos no componente: nome, preço, pricing, mídia de preview e grupo/canal de entrega (`deliveryGroupId`).

#### Prévias

- Máximo de **8 mídias**.
- Tempos de apagar citados: 5, 10, 15, 20, 25 e 30 segundos. Também: **Nunca — 1 visualização por lead**, **Após 1 hora**, **Após 24 horas**, **Após 7 dias**.
- Comportamento descrito no componente: o lead clica no botão de prévias e o botão some do teclado (1 uso por lead). As mídias chegam protegidas (sem encaminhar/salvar). Depois do tempo, tudo é apagado. No lugar entra a mensagem de conversão com o botão de planos.

#### Pagamentos

- Duas abas: **Pagamento Gerado** e **Pagamento Aprovado**.
- Variáveis usadas nos textos: `{pix_code}`, `{plano}`, `{duracao}`, `{valor}`, `{nome}`.
- Há formato do código PIX, mensagens antes/depois, botões de copiar, QR e verificar status, e menção a **Álbum**.
- Texto padrão de instrução, visto também no fluxo n8n: **Como realizar o pagamento**, com os 4 passos de abrir o banco, PIX, copia e cola.
- Exemplo de confirmação de plano no componente: **Você selecionou o seguinte plano**, com Plano, Duração e Valor.

#### Assinatura

- Frase do componente: **A renovação não roda o seu grafo: quando o assinante paga, ele volta direto para o grupo ou canal escolhido aqui.**
- Dá para configurar um grupo/canal diferente para a renovação, útil quando a venda inicial usa outro destino e a renovação deve entregar direto no grupo VIP.
- Notificações no dia da expiração reutilizam a mesma mensagem e mídia, em horários do dia. Mídia: **Imagem**, **Vídeo**, **Áudio**.
- Há lista de planos da assinatura, com **Nome do plano** e **Adicionar Plano**.

#### Top Assinantes

- O componente manda adicionar, na aba Boas-vindas, em **Botões Customizados**, um botão com a ação deste recurso.
- Abas internas: **Configuração** e **Prêmios por Posição**. Dá para adicionar prêmios com posição e descrição.

### Forma dos dados do fluxo básico

`GET /api/flows` devolve o fluxo básico com `nodes`, `edges` e `steps` vazios. O conteúdo mora nos campos de formulário. Campos de primeiro nível observados:

```text
_id, ownerId, name, folder, mode: "basico", status: "ativo",
audience, messageLanguage, botId, bots[],
initial { text, mediaType, mediaUrl, message, secondaryMessageEnabled,
  secondaryMessage, ctaEnabled, ctaText, ctaAction, buttons[], orderBump },
plans[], packs, videoCall, upsell, downsell, downsellPix,
orderBump, orderBumps, delivery, subscription, topSubscribers,
messages { pixGenerated, paymentApproved },
buttons { enabled, items }, supportUsername, protectContent,
nodes[], edges[], steps[], editor, stats
```

`stats` no básico: `totalPaid`, `totalGenerated`, `starts`, `stepsCount`, `conversion`. No avançado o mesmo objeto também traz `starts`, `stepsCount`, `conversion`, `totalPaid`, `totalGenerated`.

---

## 4. Tipo Fluxo n8n

O card **Fluxo n8n** grava `mode: "avancado"` e abre o mesmo caminho `/fluxos/{id}`. O componente carregado é outro: o texto de loading é **Carregando editor avançado...** A validação interna se chama `validateN8NEditorGraph`.

Nos bundles desse editor não há URL de webhook, campo para colar um endereço do n8n, nem exemplo de JSON enviado a um n8n externo. O nome "n8n" é a marca do editor visual da Shark.

O fluxo observado se chama `teste fluxo`, id `69b0430e67d89a3492f76b5f`, criado em 10/03/2026, `mode: "avancado"`, status `ativo`, sem bot vinculado no momento da leitura, stats `starts: 3`, `conversion: 100`, `totalPaid: 3`, `totalGenerated: 3`.

### O que a tela mostra

- Canvas de nós ligados por arestas, no padrão React Flow (`useReactFlow`, `useNodesState`, `useEdgesState`). Os ids de aresta seguem o formato `xy-edge__origem+handle-destino`.
- Barra superior: voltar, nome editável, moeda **BRL**, botão **+ Adicionar bot** com a lista dos bots da conta, **Editar**, **Desempenho**, estado **Salvo**, **Compartilhar**, **Salvar**.
- Painel esquerdo: **Blocos Disponíveis**, agrupados. Cada bloco é arrastado para o canvas. Há busca de texto dentro dos nós.
- O nó **Início** (`type: "start"`, `id: "start"`) não pode ser apagado. `data: { label: "Início" }`. Na tela: **Quando o usuário inicia**.
- Undo/redo guarda até 50 snapshots. Há modo somente leitura (`readOnly`) e `singleStartMode`.

### Catálogo de blocos, com type e defaultData

Copiado do array de paleta do editor.

| Grupo | Rótulo na paleta | type | Badge | defaultData |
|---|---|---|---|---|
| COMUNICAÇÃO | Mensagem Composta | `composite_message` | NOVO | `{ elements: [] }` |
| COMUNICAÇÃO | Texto | `message` | | `{ message, mediaType: "none", mediaUrl }` |
| COMUNICAÇÃO | Imagem | `media` | | `{ mediaType: "image", mediaUrl, caption }` |
| COMUNICAÇÃO | Vídeo | `media` | | `{ mediaType: "video", mediaUrl, caption }` |
| COMUNICAÇÃO | Áudio | `media` | | `{ mediaType: "audio", mediaUrl, caption }` |
| COMUNICAÇÃO | Arquivo | `media` | | `{ mediaType: "document", mediaUrl, caption }` |
| COMUNICAÇÃO | Video Nota | `media` | | `{ mediaType: "video_note", mediaUrl, caption }` |
| COMUNICAÇÃO | Digitando... | `typing` | | `{ duration: 3, durationUnit: "segundos" }` |
| COMUNICAÇÃO | Botões | `buttons` | | `{ message, buttons: [{ id, text, type: "callback", value }] }` |
| COMUNICAÇÃO | Input do Usuário | `user_input` | | `{ prompt, variableName: "user_input", inputType: "text", timeout: 60 }` |
| COMUNICAÇÃO | Localização | `location` | | `{}` |
| LÓGICA & FLUXO | Atraso | `wait` | | `{ delay: 5, delayUnit: "segundos" }` |
| LÓGICA & FLUXO | Smart Delay | `smart_delay` | | `{ minDelay: 1, maxDelay: 5, delayUnit: "segundos" }` |
| LÓGICA & FLUXO | Gatilho | `condition` | | `{ conditionType: "responded", timeout: 60, timeoutUnit: "segundos" }` |
| LÓGICA & FLUXO | Randomizer | `randomizer` | | `{ paths: [{ id: "path_1", weight: 50 }, { id: "path_2", weight: 50 }] }` |
| LÓGICA & FLUXO | Go To | `goto` | | `{ targetNodeId }` |
| PAGAMENTO | Gerar pagamento | `charge` | | `planName`, `price: 5`, `methods: []`, `durationType: "mensal"`, `days: 30`, `notPaidTimeoutMinutes: 30`, flags de QR e copia-e-cola, `pixCodeFormat: "code_block"`, textos de mensagem e botões |
| PAGAMENTO | Order Bump | `order_bump` | | `actionType: "order_bump"`, `planName`, `planPrice`, `originalPrice`, `durationType`, `message`, `description`, `mediaFiles`, `delivery.type: "default"` |
| SEQUÊNCIAS | Upsell | `upsell` | | `{ actionType: "upsell", message, discountPercent: 0, selectedPlanIds: [] }` |
| SEQUÊNCIAS | Downsell | `downsell` | | `{ actionType: "downsell", message, discountPercent: 0, selectedPlanIds: [] }` |
| ENTREGA | Grupo Temporário | `add_to_group` | | `{ actionType: "add_to_group", groupId }` |
| ENTREGA | Entrega | `action` | | `{ actionType: "send_delivery" }` |
| OUTROS | Nota/Comentário | `comment` | | `{ content, color: "#fef08a" }` |

### Tipos que existem no mapa de rótulos e não estão na paleta nova

O dicionário de nomes do editor ainda conhece `pix` (**Pagamento PIX**) e `card_payment` (**Pagamento por cartão**). O fluxo `teste fluxo`, mais antigo, usa nós `type: "pix"`. A paleta atual insere `type: "charge"` com o rótulo **Gerar pagamento**. Os dois convivem. As saídas de `pix`, `charge` e `card_payment` são as mesmas: `paid` (Pago) e `not_paid` (Nao pago).

### Saídas (handles) por tipo

- `pix`, `charge`, `card_payment`: `paid` = Pago, `not_paid` = Nao pago. Na tela do nó PIX isso aparece como **PAGO** e **NÃO PAGO**.
- `upsell` e `downsell`: `accepted` = Aceito, `declined` = Recusado. No canvas compacto: **ACEITOU** e **RECUSOU**.
- `condition` (Gatilho): `yes` = Sim, `no` = Nao. O rótulo do próprio nó muda com `conditionType`.
- `user_input`: `success` = **RESPONDEU**, `timeout` = **TEMPO ESGOTADO**.
- `buttons`: uma saída por botão, usando o `id` do botão como `sourceHandle`. O texto da aresta é o texto do botão.
- `randomizer`: uma saída por path.

### Gatilho: conditionType

O default é `responded`. Os rótulos mapeados no código são:

- `responded`: **Se responder**.
- `not_responded`: **Se NÃO responder**.
- `clicked_button`: **Se clicar**.
- `paid`: **Se pagou**.
- `not_paid`: **Se NÃO pagou**.

Campos do nó: `conditionType`, `timeout` (default 60), `timeoutUnit` (default `segundos`).

### Input do usuário

- Campos: `prompt`, `variableName` (default `user_input`), `inputType` (default `text`), `timeout` (default 60).
- Opções do select de `inputType` vistas no componente: texto (default), **Número** (`number`), **Email** (`email`), **Telefone** (`phone`).

### Nó de mídia, visto no canvas

- Mostra o nome do arquivo e **Clique para trocar**.
- Toggles: **Spoiler**, **Auto-deletar**, **Mídia Dinâmica** com badge **NOVO**.
- `data` extra observada em nós salvos: `caption`, `hasSpoiler`, `filename`, `autoDeleteDelay`, `mediaType`, `mediaUrl`, `r2Key`, `telegramCache` (lista de `botId`, `fileId`, `cachedAt`).

### Nó de botões, visto no canvas

- Lista de botões, **Adicionar botão**, **Sumir após clique** (`hideAfterClick`), **Continuar se não clicar**.
- Cada botão tem um seletor de estilo com letras P, A, V, V. No código: valor vazio = **Padrão** (`#6b7280`), `primary` = **Azul** (`#3b82f6`), e as outras cores são verde e vermelho, no mesmo conjunto do editor básico.
- Botão salvo: `{ id, text, type: "callback", value: "btn_N" }`.

### Nó PIX / Gerar pagamento, visto no canvas

O nó antigo `type: "pix"` aberto na tela mostra estes controles. O `defaultData` do bloco novo `charge` cobre o mesmo conjunto.

- Cabeçalho do nó: **QR Code + Copia e Cola**.
- **Nome do Plano**, **Valor (R$)**, **Duração do Acesso**. Opções vistas: **Semanal (7 dias)**, **Mensal (30 dias)**, **Vitalício**. No dado salvo, `durationType` pode ser `semanal`, `mensal` ou `vitalicio`, com `days` 7, 30 ou 99999.
- **Formato do Código PIX**: **Bloco de código**, com prévia de um code block `00020101...`.
- Toggles: **Mostrar Botão QR Code**, **Mostrar Botão Copia e Cola**, **Mostrar Botão Verificar Status**, **PIX Inline (Modo Compacto)** com a ajuda **Código PIX dentro da mensagem de instrução**, **Mensagem CTA Separada** com a ajuda **Se desativado, botões colam na última mensagem**, **Mostrar Plano Antes do PIX** com a ajuda **Exibe detalhes do plano antes de gerar o código PIX**.
- Mensagens personalizadas: **Mensagem antes do código** (padrão **Copie o código abaixo:**) e **Mensagem após o código** (padrão **Após efetuar o pagamento, clique no botão abaixo**).
- Botões personalizados: Verificar (padrão **Verificar Pagamento**), Copiar (padrão **Copiar Código**), Ver QR Code (padrão **Ver QR Code**).
- **Instruções de Pagamento**. Placeholder do título **Como realizar o pagamento...**. Vazio usa a mensagem padrão.
- **Prova Social**: **Mensagem aleatória enviada após o PIX ser gerado**. No dado: `socialProofEnabled` e `socialProofMessages`.
- **Tempo para "Não Pago"**, em minutos. Ajuda: **Se não pagar, segue pelo caminho "NÃO PAGO" após esse tempo**. Campo `notPaidTimeoutMinutes`. No fluxo antigo estava em 1.
- **Variação de Preço**: **Preço único por cliente (anti-fraude)**. Campos: `priceVariationEnabled`, `priceVariationCents` (exemplo salvo 50), `priceVariationDirection` (exemplo `both`).

### Smart Delay, visto no canvas

- Título **Smart Delay**. Texto: **Delay aleatório para humanização**.
- Campos visíveis: mínimo, até, máximo, unidade (seg). Toggle **Mostrar "digitando..."**. No `defaultData` da paleta o toggle não vem nomeado; no nó salvo o campo é `showTyping`.

### Ações de entrega

O bloco **Entrega** da paleta cria `type: "action"` com `actionType: "send_delivery"`. O fluxo antigo também tem nós `action` com `actionType: "send_link"`, rotulados **Enviar Link**. O componente da ação trata estes casos:

- `send_delivery`, rótulo **Entrega**. **Destino da entrega**. `deliveryType` default no dado salvo do fluxo antigo: `grupo_telegram`. Outros valores aceitos na validação: `canal_telegram`, `grupo_temporario`, `bot_taxa`.
- `grupo_telegram`, `canal_telegram` e `grupo_temporario` exigem `groupId` numérico. A mensagem de erro é: **Selecione um grupo ou canal valido para a entrega**.
- `bot_taxa` exige `destinationFlowId` e `destinationBotId`.
- Campos de entrega vistos no estado do componente: `deliveryType`, `groupId`, `deliveryMessage`, `mediaFiles`, `kickEnabled`, `kickDelay` (default 30), `kickUnit` (default `segundos`), `url`, `videoCallUrl`, e textos de videochamada / bump em nós mais antigos.
- `send_link`, rótulo **Enviar Link**. Campos na tela: **URL** e **Texto do botao**.
- Há também, no seletor de destino, a opção **Link Externo** e o valor interno `__mensagem__`.
- O mapa de rótulos de ação ainda cita `add_tag` (**Adicionar tag**), `remove_tag` (**Remover tag**) e `set_variable` (**Definir variável**). Esses três não estão na paleta de 23 blocos copiada acima.

### Como o grafo é salvo

Antes de persistir, o editor reduz cada nó a `id`, `type`, `position`, `data`, `parentId`, `extent` e `deletable` (o start nunca é deletável). Cada aresta vira `id`, `source`, `target`, `sourceHandle`, `targetHandle` e `type`. O snapshot de undo é `JSON.stringify({ nodes, edges })`.

`GET /api/flows` já devolve esse grafo dentro do fluxo, junto com uma cópia mais achatada em `steps` (`id`, `type`, `config`) usada como espelho de alguns nós.

O botão **Salvar** do canvas não foi clicado, então o verbo HTTP desse save não foi capturado ao vivo. O cliente do editor recebe `onSave`. A leitura completa do grafo está no GET.

### O que este tipo não faz

- Não pede URL de webhook do n8n.
- Não mostra instrução de como conectar uma instância n8n.
- Não documenta um JSON de mensagem do Telegram repassada ao n8n, nem um JSON de resposta esperado de volta.

Remarketing tem uma aba N8N separada (novidade de 14/03/2026): campanha de remarketing pode usar um fluxo visual com PIX, entrega, botões, condições e delays. Isso continua sendo o editor da Shark, escolhido em **Remarketing → aba N8N → Nova Campanha**. Públicos citados: todos os leads, compradores, não compradores, membros de grupo.

---

## 5. Tipo Modo IA

Criar com `mode: "ia"` redireciona para `/fluxos/{id}/ia`. A tela não é um chat que descreve o bot para a IA desenhar nós, e também não é o canvas. É um editor de atendimento conversacional: a Shark opera o motor, cobra créditos pré-pagos e mantém esse fluxo separado do Básico e do n8n.

A novidade de 27/08/2026 diz que está disponível para todas as contas, e que configuração, histórico, créditos e ativação dos bots ficam separados dos outros tipos.

Para abrir essa tela foi criado um rascunho chamado `tmp analise ia` (id `6ab6ba9ec14ee87783a5112d`) e, depois da leitura, ele foi removido com `DELETE /api/flows/{id}`. A listagem voltou aos dois fluxos originais.

### Casca da página

- Selo **MODO IA** e estado **TUDO SALVO**.
- Nome do fluxo.
- Subtítulo: **Configure a conversa, teste e publique no Telegram.**
- Botão de saldo, no teste **R$ 0,00**. Abre a seção de créditos.
- Botão **Visualizar** (começa recolhido) e botão **Salvar**, desabilitado enquanto não há alteração.
- Botão **Sair do editor**.
- Título interno ao carregar: **Preparando atendimento**. Texto: **Configure a conversa, teste e publique no Telegram.** Depois: **Siga os grupos abaixo ou abra diretamente o recurso que deseja ajustar.**

### Menu lateral, na ordem

**Começar**

- **Resumo do atendimento**. Subtítulo no menu: **2 de 5 itens essenciais**. Estado **Disponível**.
- **Estratégias prontas**. Subtítulo: **3 opções para começar**.

**Configuração essencial**

- **Persona e identidade**. No rascunho novo já vinha **Concluída**, com o nome da conta.
- **Negócio e ofertas**. Subtítulo: **0 ofertas ativas**.
- **Jeito de conversar**. Subtítulo: **Tom acolhedor**. Também já vinha **Concluída**, porque o template padrão preenche o estilo.

**Conteúdo e automações**

- **Mídias da conversa**. Subtítulo: **Adicionar conteúdo**.
- **Downsell**. Subtítulo: **Configurar sequência**.
- **Upsell**. Subtítulo: **Configurar oferta pós-compra**.

**Teste e publicação**

- **Testar conversa**. Subtítulo: **Pronto para testar**.
- **Publicar no bot**. Subtítulo: **Vincular bot**.

**Depois que entrar no ar**

- **Memória das conversas**. Subtítulo: **Perfis e histórico comercial**.
- **Aprendizado de vendas**. Subtítulo: **O que mais converte**.

**Conta**

- **Saldo da IA**. Subtítulo com o valor.

Cartões fixos no fim do menu: **Preparação essencial 2/5**, **Configuração Salva**, **Bot real Não vinculado**.

### Resumo do atendimento

- Título: **Veja o que já está pronto e qual é o próximo passo.**
- Estado **EM PREPARAÇÃO**. Texto: **Prepare uma conversa que vende do seu jeito. Siga os itens essenciais abaixo. Você pode voltar e ajustar qualquer detalhe antes de ativar o bot.**
- **Preparação essencial: 2 de 5 concluídos.** Atalhos: **Cadastrar as ofertas** e **Usar estratégia pronta**.
- Os 5 itens, literais:
  1. **Definir a persona** — Nome, história e personalidade — Concluído.
  2. **Cadastrar as ofertas** — Contexto, preços e dúvidas comuns — Pendente.
  3. **Ajustar a conversa** — Tom, ritmo e momento da venda — Concluído.
  4. **Conectar um bot** — Bot que atenderá os novos leads — Pendente.
  5. **Definir a entrega** — O que o comprador recebe após pagar — Pendente.
- Bloco **Aprimore quando quiser**: Estratégias prontas, Mídias da conversa (**Fotos, vídeos, GIFs e áudios**), Downsell (**Retome conversas em etapas, sem repetir**), Upsell (**Uma nova oferta após a compra**), Aprendizado de vendas.
- Prévia lateral, **Versão salva**: nome da conta, papel **Assistente virtual**, abertura default `oii` com coração roxo e a linha **chegou agora por aqui?**

### Estratégias prontas

Título: **Aplique uma base completa e personalize cada detalhe.**

Texto: **Cada estratégia já traz estilo de conversa, ofertas de exemplo e dúvidas comuns configuradas. Seus dados pessoais continuam como estão e tudo pode ser personalizado antes de publicar.**

Aviso no rodapé: **Aplicar uma estratégia substitui biografia, traços, contexto, ofertas, dúvidas respondidas e estilo da conversa. Você poderá revisar tudo antes de salvar e colocar o bot no ar.**

- **MAIS HUMANIZADO — Conexão que converte.** Cria proximidade primeiro, entende o interesse e apresenta a oferta no momento certo. Melhor para: **Tráfego frio e leads que precisam ganhar confiança.** Selos: **Conversa natural**, **Lembra preferências**, **Oferta gradual**. Configuração pronta: 3 planos, 8 dúvidas respondidas. Plano em destaque **R$ 24,90**. Botão **Aplicar esta estratégia**.
- **MAIS DIRETO — Venda rápida.** Respostas curtas, qualificação rápida e oferta cedo para quem já chega decidido. Melhor para: **Remarketing, audiência quente e campanhas com promessa clara.** Selos: **Ritmo ágil**, **Responde a intenção**, **Oferta cedo**. 3 planos, 8 dúvidas. Destaque **R$ 19,90**.
- **MAIOR VALOR PERCEBIDO — Experiência premium.** Posicionamento mais exclusivo, ritmo calmo e foco em valor antes de falar de preço. Melhor para: **Ticket maior, marca pessoal forte e audiência recorrente.** Selos: **Exclusividade**, **Ticket maior**, **Tom elegante**. 3 planos, 8 dúvidas. Destaque **R$ 79,90**.

O preset da estratégia de conexão, lido no bundle, cria três produtos. Esse é o formato real de uma oferta:

```json
{
  "id": "connection-7d",
  "name": "Acesso 7 dias",
  "description": "Sete dias para conhecer as fotos, videos e conteudos privados disponiveis no VIP.",
  "priceCents": 1490,
  "currency": "BRL",
  "days": 7,
  "emoji": "✨",
  "isHighlight": false,
  "enabled": true
}
```

- VIP mensal: `priceCents` 2490, `days` 30, `isHighlight` true.
- VIP vitalício: `priceCents` 4990, `days` 99999, `isHighlight` false.

### Persona e identidade

- Título: **Defina quem conversa: nome, história e personalidade.**
- Texto: **Escolha nome, apresentação e história. A persona usa tudo isso para conversar em primeira pessoa com consistência.**
- Campos: **Nome de exibição**. **Idade**. **Apresentação da persona**, com a ajuda **Controla pronomes, flexões e como a persona fala sobre si.**
- Opções de apresentação:
  - **Feminina** — Fala e se apresenta no feminino.
  - **Masculina** — Fala e se apresenta no masculino.
  - **Neutra** — Evita marcações de gênero.
- **Cidade**. Marcada como **Opcional**.
- **Biografia curta**. Ajuda: **Conte a história comercial e os detalhes que ajudam a manter respostas coerentes.** Contador observado **151/600**.
- **Traços de personalidade**. Ajuda: **Separe por vírgulas. Use até 6 características.**
- Aviso: **A identidade deve representar uma pessoa adulta. Informações de login, documentos, dados bancários e dados privados de leads não devem ser inseridos aqui.**

Valores default gravados no config desse rascunho novo, antes de qualquer edição:

- `displayName`: nome da conta.
- `presentation`: `feminina`.
- `age`: `18`.
- `traits`: `espontanea`, `atenta`, `bem-humorada`.
- Biografia default: **Criadora adulta, espontanea e atenta. Conversa como em um chat privado, lembra o que a pessoa contou e apresenta o proprio VIP sem parecer atendimento.**

### Negócio e ofertas

- Título: **Explique o VIP, cadastre preços e responda dúvidas comuns.**
- **Contexto da criadora e do VIP**. Ajuda: **Detalhes concretos evitam respostas genéricas. A persona usa este texto como fonte principal da conversa.** Contador **589/4.000**.
- O próprio texto da tela lista o que um bom contexto precisa responder: o que existe no VIP, o que torna a oferta diferente, como pagamento e entrega funcionam, quando chamar o suporte, o que nunca pode ser prometido ou inventado.
- **Ofertas**: **Cadastre até 10 opções. No teste elas orientam a conversa; com o bot ativo, aparecem como planos reais para o visitante comprar.** Botão **Adicionar**. Vazio: **Nenhuma oferta cadastrada. O atendimento não poderá informar preços até você adicionar uma.**
- **Negociação de preço**. Texto: **Quando o visitante trava no valor, a IA pode oferecer uma condição melhor dentro do limite que você definir. Ela nunca escolhe o preço — o sistema calcula a partir destas regras e mostra num botão.** Há um controle **Ativar**. O objeto salvo se chama `discount`, com `enabled: false` e `oncePerLead: false`. O formulário expandido desse desconto não foi aberto.
- **Dúvidas que a IA já sabe responder**. **Cadastre perguntas comuns e a resposta correta para cada uma.** Botão **Adicionar dúvida**. Vazio: **Adicione as dúvidas mais frequentes para manter respostas consistentes.** No config isso é o array `faqs`, vazio no rascunho.

O `businessContext` default, pelo que a API gravou: o atendimento representa uma criadora adulta que vende acesso ao próprio VIP pelo Telegram. Reagir primeiro ao que o visitante disser. Usar só benefícios cadastrados nas ofertas. Chamar o produto de "meu VIP" ou "meu conteúdo", nunca de "clube". O pagamento começa pelo botão seguro e a entrega configurada só acontece depois da confirmação. Falha de pagamento ou acesso vai para o suporte cadastrado. Proibido inventar desconto, bônus, quantidade de conteúdos, atualizações ou experiências que não estejam configuradas.

### Jeito de conversar

Título: **Ajuste tom, ritmo, abertura e momento de apresentar a oferta.**

Bloco **Humanização da venda**: **Faz a persona reagir, lembrar preferências e escolher o momento certo de apresentar a oferta sem parecer um roteiro.**

**Estratégia comercial.** Define em que momento a oferta entra na conversa.

- **Conexão primeiro**. Conversa e entende o interesse antes de vender. É o default (`salesApproach: "conexao"`).
- **Equilibrado**. Cria uma troca curta e aproveita o primeiro gancho.
- **Direto**. Apresenta a oferta cedo, sem perder naturalidade.

**Nível de flerte**

- **Nenhum**.
- **Leve**. É o default (`flirtLevel: "leve"`).
- **Sensual**.
- **Provocante, sem descrição gráfica**.

**Uso de gírias**

- **Nenhuma**.
- **Natural**. É o default (`slangLevel: "natural"`).
- **Marcante**.

**Cadência.** Controla a sensação de velocidade e pressão na conversa.

- **Ágil**. Balões curtos e avanço rápido.
- **Natural**. Alterna reação, pergunta e próximo passo. É o default (`cadence: "natural"`).
- **Calmo**. Mais acolhimento e menos pressão.

**Toggles**

- **Espelhar o jeito do visitante**. Acompanha energia, tamanho das mensagens e nível de informalidade. Default `mirrorVisitorStyle: true`.
- **Usar o nome do lead**. Memoriza depois que a pessoa se apresenta, sem repetir demais. Default `useLeadName: false`.

**Ritmo e personalidade**

- **Tom de voz**: **Acolhedor** (default `tone: "acolhedor"`), **Direto**, **Descontraído**, **Premium**.
- **Tamanho das respostas**: **Curta** (default `replyLength: "curta"`), **Média**, **Detalhada**.
- **Uso de emojis**: **Nenhum**, **Leve** (default `emojiLevel: "leve"`), **Moderado**.

**Respostas em áudio**

- Rótulo **VOZ NATURAL**. Texto: **Cria novas mensagens de voz a partir da resposta e usa o saldo da IA. Áudios já gravados ficam em Mídias da conversa e funcionam mesmo com esta opção desligada.**
- No config, `voice.enabled` começa `false`. Também vêm gravados, sem controle correspondente visto nesta tela: `mode: "automatic"`, `voice: "marin"`, `style: "natural"`, `speed: 1`, `maxCharacters: 600`. Esses quatro não apareceram como campos na seção que foi aberta.

**Abertura, objetivo e regras**

- **Abertura da conversa**. Ajuda: **Use uma abertura que combine com sua identidade, sem presumir que a pessoa acabou de chegar. Evite apresentar planos antes de ouvir o interesse.**
- **Variações de abertura**. **No Telegram, apenas uma das aberturas é escolhida a cada /start. O teste nesta tela usa a principal.** Botão **Adicionar variação**.
- Ideias exibidas na tela: apresente o que a pessoa encontra aqui, retome o assunto sem supor intimidade ou pergunte o que ela quer saber. Use apenas informações reais da sua oferta.
- Default de `openingMessage`: `oii` com coração roxo, quebra de linha, **chegou agora por aqui?**
- **Objetivo principal**. Default: **Entender a intencao, lembrar preferencias e conduzir naturalmente para o acesso mais adequado.**
- **Regras próprias**. **Uma regra por linha. Use no máximo 10.** Defaults: reagir ao detalhe mais importante da última mensagem antes de outra pergunta; não reiniciar a conversa nem repetir pergunta já respondida; quando houver intenção de compra, abrir os planos sem criar etapas artificiais.
- **Palavras de encaminhamento**. **Separe por vírgulas. Essas palavras poderão acionar atendimento humano em uma fase futura.** Defaults: `suporte`, `atendente`, `problema no pagamento`.
- Fecho da seção: **A persona conversa em primeira pessoa, acompanha o jeito do visitante e evita respostas robóticas. Pagamentos, links e entregas continuam vindo somente das ações reais configuradas no sistema.**

Não há, nesta seção nem no JSON do config, campo de modelo (GPT ou outro), temperatura, `top_p` ou system prompt editável. O motor é da Shark: a resposta de créditos traz `engineConfigured: true`.

### Mídias da conversa

- Título: **Adicione fotos, vídeos, GIFs e áudios com contexto de uso.**
- Texto: **Diga o que cada arquivo mostra e quando ele ajuda. A IA escolhe pelo contexto, sem receber URL ou acesso ao arquivo original.**
- Botão **Adicionar mídias**.
- Aviso: **Configure o canal de cache em Publicar no bot. O upload funciona sem ele, mas o primeiro envio no Telegram pode demorar mais.**
- Filtros: **Todas**, **Fotos**, **Vídeos**, **GIFs**, **Áudios**. Contador **0/30 arquivos**.
- Vazio: **Sua biblioteca ainda está vazia. Adicione fotos, vídeos, GIFs ou áudios e explique o papel de cada um na conversa.**
- No config: `media.items` é um array vazio.

### Downsell do Modo IA

Isto é uma sequência de mensagens escritas pelo seller, enviadas pelo bot sem a IA reescrever, e sem consumir token. É diferente do downsell de nós do editor n8n.

- Título: **Organize mensagens, mídias e ofertas para quem deixou de responder.**
- **Downsell · sem resposta**. **Retome a conversa, no tempo certo.**
- **Monte até 8 etapas com mensagens escritas por você, mídias e ofertas diferentes. Cada etapa é enviada uma única vez por lead, enquanto ele estiver sem responder e ainda não tiver comprado.**
- **Uma resposta, clique ou checkout cancela a sequência pendente. Após uma nova resposta da IA, o contador da próxima etapa recomeça, sem repetir as que já foram enviadas. Pagamento aprovado encerra o Downsell.**
- Estado inicial: **Downsell desativado. Você pode preparar as etapas abaixo e ativar quando quiser.** Contador da sequência **1/8**.
- A etapa 1 já vem criada: título **Apresentar planos**, espera **1 min**, **0 mídia(s)**. Previsão: **até 28 dias no total**. Cada espera começa após o envio anterior. Etapas pausadas não entram na conta.
- Botão **Adicionar etapa**.
- Etapa 1, rótulo **Primeiro contato após o silêncio**. **Quando enviar**: **Tempo de silêncio contado após a última resposta da IA.**
- Atalhos de tempo: **1 min**, **5 min**, **15 min**, **1h**, **1 dia**, e **Todos os intervalos**. Valor mostrado: **1 minuto**. Faixa: **De 30 segundos a 7 dias de espera nesta etapa.**
- Aviso de intervalo curto: **esta mensagem pode chegar enquanto a pessoa ainda lê sua resposta. Para uma conversa mais tranquila, experimente 5 ou 15 minutos.**
- **Mensagem do Downsell**. **Você escreve; o bot envia sem a IA reescrever. Esta mensagem é separada da legenda e também funciona sem mídia.** Variável `{nome}`. Contador **60/1.000**. Default da mensagem: **sumiu por aí?** e **quer que eu te mostre as opções de acesso?**
- **O que acompanha a mensagem?** **O preço especial só existe se você criar a oferta abaixo. Não prometa desconto em uma mensagem sem oferta.**
- Opção **Botão com os planos atuais** (no config, `showPlans: true` e `action: "plans"`).
- **Mídias deste envio**, **0/5**. **Adicione arquivos próprios para esta etapa. Eles não entram em Mídias da conversa e são enviados na ordem abaixo, antes da mensagem.** Foto, vídeo, GIF ou áudio, **até 50 MB por arquivo**. **Salve o fluxo para aplicar os novos anexos.**
- **Antes de publicar, escolha o canal de FILE ID em Publicar no bot. Os arquivos usam o mesmo cache do seu fluxo.**
- Prévia: **No Telegram, o visitante recebe a mensagem que você escreveu, com o botão para ver os planos. Estes envios automáticos não consomem tokens de IA.**

No config isso mora em `automations.inactivityFollowUp`: `enabled: false`, `delaySeconds: 60`, `message`, `showPlans: true`, `action: "plans"`, `customOffer` (`id`, `name` vazio, `priceCents: 0`, `currency: "BRL"`, `days: 30`, `emoji`, `enabled: true`), `mediaIds: null`.

### Upsell do Modo IA

- Título: **Ofereça um novo acesso ou complemento depois da entrega inicial.**
- **A primeira entrega vem antes. Depois, ofereça uma extensão de acesso ou uma opção extra, com mensagem e mídias próprias.**
- Estado: **DESATIVADAS**. Fluxo: **PAGAMENTO APROVADO**.
- Texto: **Depois da entrega inicial, apresente outra oferta usando o mesmo checkout, webhook e destino configurados.**
- Trava vista na tela: **Ative pelo menos uma oferta em “Negócio e ofertas” antes de configurar esta etapa.** Por isso o formulário completo do upsell não abriu.
- **Os envios ficam em fila durável e não se perdem em reinícios. Uma oferta de Upsell só sai depois da aprovação e da entrega inicial.**

No config, `automations.postPurchaseUpsell`: `enabled: false`, `delaySeconds: 60`, message default **agora que seu acesso foi liberado, tenho uma opção extra que combina com você**, `productId` vazio, `offerMode: "existing"`, `customOffer` no mesmo formato do downsell, `mediaIds: null`.

### Testar conversa

- Título: **Converse com a persona e revise as respostas antes de ativar.**
- **Conversa privada de teste**. Linha: **Testando como {nome}. nenhum lead ou bot recebe estas mensagens.**
- Botão **Limpar teste**.
- A simulação já mostra a abertura salva. Ajuda do campo: **Enter envia · Shift + Enter quebra a linha**. Botão **Enviar teste**.
- Mostra o saldo e repete: **Só você vê este teste. Nenhum lead ou bot recebe as mensagens enviadas nesta tela.**

Contrato do teste, lido no cliente:

```http
POST /api/ai/flows/{id}/preview
Content-Type: application/json
```

```json
{
  "messages": [
    { "role": "user", "content": "texto" }
  ]
}
```

O cliente manda no máximo as últimas 16 mensagens. Cada item tem `role` (`user` ou `assistant`) e `content`.

Resposta esperada pelo cliente:

- `data.message` — texto do assistant.
- `data.usage.charged` — valor cobrado, se houver.
- `data.billingPending` — `true` se o saldo ainda está atualizando.

`GET` nesse mesmo path respondeu **405 Method Not Allowed**. O teste é só POST. Com saldo zero nenhuma mensagem foi enviada, para não disparar cobrança nem erro de saldo na conta.

### Publicar no bot

- Título: **Conecte o bot, defina a entrega e ative o atendimento.**
- Texto: **Conecte o bot, defina o que será entregue e revise o checklist. O atendimento só começa depois que você ativar.**
- **Bot do atendimento**. **Escolha o bot que receberá os novos leads deste fluxo. Cada fluxo IA usa um bot por vez.**
- Combobox: **Escolha um bot disponível**. Botão **Vincular bot**. No teste: **2 bot(s) disponível(is)**. Link **Criar ou gerenciar bots**.
- **Canal de cache das mídias**. **Preserva o file_id de fotos, vídeos, GIFs e áudios. Depois do primeiro envio, o Telegram reutiliza o arquivo sem baixar da origem novamente.**
- **Grupo ou canal de preservação**. **O bot precisa ser administrador e poder publicar. Pode ser um grupo ou canal privado criado somente para cache.** Sem bot: **Vincule um bot primeiro**.
- **Entrega depois do pagamento**. **Escolha o que o comprador receberá automaticamente assim que o pagamento for confirmado.** Rótulo do campo: **Destino da entrega**.
- **Usuário de suporte no Telegram**. **Opcional**. Campo com prefixo `@`.
- **Checklist para publicar**, itens literais: **Configuração salva**. **Bot ativo e vinculado**. **Oferta ativa**. **Entrega configurada**. **Cache das midias**. **Saldo disponível**. Texto: **Complete os itens abaixo e ative o bot no card acima.**

Ligações de bot vistas no cliente do editor IA:

- `POST /api/flows/{id}/bots` para vincular.
- `DELETE /api/flows/{id}/bots?botId=` para remover.
- `PATCH /api/flows/{id}/bots/{botId}/toggle`.

O config traz `delivery.type` default `grupo_telegram`. A lista de destinos não foi aberta porque não havia oferta nem bot vinculado.

### Memória das conversas

- Título: **Veja preferências, objeções e a jornada de cada lead.**
- Texto: **Veja o que a conversa aprendeu e acompanhe o avanço real de cada lead, do primeiro clique até pagamento, entrega e upsell.**
- Botão **Atualizar**.
- Aviso: **Esta visão espelha a jornada para orientar a conversa. Pagamentos e entregas continuam validados pelos sistemas oficiais da Shark.**
- Filtro: **Todas as etapas**. Métrica: **LEADS LEMBRADOS**, 0.
- Vazio: **A memória começa na próxima conversa. Quando um lead interagir com este fluxo, o resumo e a jornada aparecerão aqui.**
- Painel: **Escolha um lead. Abra um perfil para ver o resumo da conversa, preferências, objeções e toda a jornada comercial confirmada.**

A novidade de agosto descreve ainda: o seller pode apagar só a memória da IA sem remover o lead, os pagamentos ou os acessos. Esse botão de apagar não apareceu porque não havia lead.

### Aprendizado de vendas

- Título: **Compare resultados e aprove melhorias baseadas em vendas.**
- Selo: **SOMENTE ESTE FLUXO**.
- Texto: **Compara quem comprou com quem não comprou, encontra padrões e prepara recomendações. Nada muda nas conversas sem sua aprovação.**
- Aviso: **Vendas são confirmadas na base oficial de pagamentos. O aprendizado guarda métricas e estratégias, não copia a conversa para outros sellers.**
- Régua: **30 até a primeira amostra**. Métricas em zero: **RESULTADOS AVALIADOS**, **COMPRAS CONFIRMADAS** com **janela de 24 horas**, **CHECKOUTS NÃO PAGOS** com **analisado Ainda não analisado**, **CONVERSAS SEM COMPRA**.
- Texto da trava: **O sistema espera pelo menos 30 resultados e 10 em cada abordagem antes de sugerir uma mudança.** **0 resultados válidos**, **mínimo 30**.
- **Sinais deste fluxo**: **Leitura descritiva da janela recente, limitada a 90 dias. Estes dados não alteram o bot sozinhos.** Blocos: **OBJEÇÕES MAIS COMUNS** (vazio: **Nenhuma objeção apareceu com frequência suficiente**) e **PLANOS MAIS ESCOLHIDOS** (vazio: **As primeiras compras aparecerão aqui**).
- **Histórico**: **Versões aprovadas podem ser reativadas sem apagar resultados.** Vazio: **A primeira versão aparecerá após uma recomendação ser aprovada.**

`GET /api/ai/flows/{id}/learning` respondeu 200:

```json
{
  "status": "collecting",
  "minimumSample": 30,
  "minimumGroupSample": 10,
  "summary": {
    "total": 0,
    "paid": 0,
    "checkoutAbandoned": 0,
    "conversationNoPurchase": 0,
    "conversionRate": 0,
    "commonObjections": null,
    "topPlans": null
  },
  "versions": []
}
```

### Saldo da IA

- Título: **Consulte o saldo, faça uma recarga e acompanhe pagamentos.**
- **SALDO DISPONÍVEL R$ 0,00**.
- **Cobrança por uso real. As respostas do bot são o principal consumo. Depois que a IA termina, os tokens medidos são descontados do saldo.**
- Passos na tela:
  1. **A IA lê a conversa.** Instruções, contexto e histórico contam como tokens de entrada.
  2. **Ela cria a resposta.** O texto gerado para o lead conta como tokens de saída.
  3. **O uso real é descontado.** A cobrança acontece após concluir. Se falhar, a reserva volta ao saldo.
- Nota: **Token não é mensagem. É um pequeno pedaço de texto. Históricos e respostas maiores usam mais tokens. Resumos de memória também podem consumir uma pequena parcela.**
- Tarifas exibidas, batendo com `GET /api/ai/credits` em 25/09/2026:
  - Contexto lido: **R$ 1,45 por 1 milhão de tokens**.
  - Resposta gerada: **R$ 8,70 por 1 milhão**.
  - Áudio gerado: **R$ 87,00 por 1 milhão de tokens de áudio**.
- A API ainda devolve `cachedInputPerMillionBRL` cerca de R$ 0,145 e `speechInputPerMillionBRL` cerca de R$ 4,35. Esses dois não estavam escritos na tela de saldo.
- Referência de custo usada na estimativa da API: 3500 tokens de entrada + 500 de saída, cerca de R$ 0,009426 por resposta de referência.

#### Recarga

- Título: **RECARGA PRÉ-PAGA**. **Escolha quanto saldo adicionar.**
- **Não é mensalidade. Todo o valor pago vira saldo para o Modo IA e o consumo acompanha as conversas dos seus bots.**
- **Liberação automática após o PIX.** **O saldo entra somente após a confirmação assinada do pagamento.**
- Valores para começar, com o de R$ 100 selecionado por default no input `recharge-amount`:
  - R$ 50 — cerca de 21,2 mi tokens.
  - R$ 100 — cerca de 42,4 mi tokens.
  - R$ 250 — cerca de 106,1 mi tokens.
  - R$ 500 — cerca de 212,2 mi tokens.
- Para mais volume:
  - **Essencial** R$ 1.000 — cerca de 424,4 mi tokens e 106,1 mil respostas de referência. **Base para operações em escala.** **Para começar com folga e validar a operação.**
  - **Crescimento**, selo **MAIS ESCOLHIDO**, R$ 2.000 — 2 vezes o Essencial, cerca de 848,7 mi tokens e 212,2 mil respostas. **Para bots com conversas frequentes ao longo do dia.**
  - **Escala**, selo **MAIOR VOLUME**, R$ 3.000 — 3 vezes o Essencial, cerca de 1,3 bi tokens e 318,3 mil respostas. **Para operações com alto volume de atendimento.**
- Link **Usar outro valor**. Botão **Gerar PIX de R$ 100,00**, acompanhando o valor selecionado.
- A API declara `minimumRecharge: 10` e `maximumRecharge: 10000`, moeda `BRL`, `balanceMicros` e `balanceCents`. Recargas recentes vêm em `recentRecharges`. O POST de recarga no cliente é `POST /api/ai/credits/recharges`. O PIX não foi gerado.
- Flags da conta nesta leitura: `accessEnabled: true`, `engineConfigured: true`, `rechargeEnabled: true`, `speechConfigured: true` no config do fluxo, `liveBotEnabled: true`.

### Config completo que a API devolve num rascunho novo

`GET /api/ai/flows/{id}/config`. O save da tela é `PUT` no mesmo path. `schemaVersion` observado: `9`. `revision`: `0`. `isSaved` começou `false` até o usuário salvar. Campos de data `createdAt`/`updatedAt` vieram zerados (ano 0001) nesse rascunho, porque a config default ainda não tinha sido persistida pelo botão Salvar.

```text
persona: displayName, presentation, age, biography, traits[]
businessContext: string
products[]: id, name, description, priceCents, currency, days, emoji, isHighlight, enabled
faqs[]
conversation: tone, replyLength, emojiLevel, salesApproach, flirtLevel,
  slangLevel, cadence, mirrorVisitorStyle, useLeadName, openingMessage,
  objective, additionalRules[], humanEscalationKeywords[]
voice: enabled, mode, voice, style, speed, maxCharacters
automations.inactivityFollowUp: enabled, delaySeconds, message, showPlans,
  action, customOffer, mediaIds
automations.postPurchaseUpsell: enabled, delaySeconds, message, productId,
  offerMode, customOffer, mediaIds
discount: enabled, oncePerLead
media.items[]
delivery.type
```

---

## 6. Meus Fluxos

URL `https://sharkbot.com.br/fluxos`. Título: **Meus Fluxos**. Subtítulo: **Gerencie seus fluxos de automação e chatbots.**

### Topo

- Botão **Importar Fluxo**, estilo outline.
- Botão **Criar Fluxo (N/50)**. O 50 vem de `data.maxFlows` no `GET /api/flows`. O N é `data.pagination.total`. É um teto único da conta, não um teto por tipo. Com 2 fluxos a tela mostrava **2/50**. Com o rascunho de IA, passou a **3/50**. Depois da exclusão, voltou a 2.

### Quatro contadores

- **VINCULADOS**: fluxos com bot ligado. Estava 0.
- **BÁSICOS**: contagem de `mode: "basico"`.
- **FLUXOS N8N**: contagem de `mode: "avancado"`. O rótulo do contador é **Fluxos N8N**. O badge do card é só **N8N**.
- **MODO IA**: contagem de `mode: "ia"`.

### Card de cada fluxo

- Nome em negrito.
- Badge **BÁSICO**: fundo quase branco, texto na cor da marca, borda branca suave. Classe observada: `bg-white/[0.02] text-[var(--brand-500)] border-white/[0.06]`.
- Badge **N8N**: fundo roxo 10%, texto roxo, borda roxa. Classe: `bg-purple-500/10 text-purple-400 border-purple-500/20`.
- Badge **MODO IA**: fundo violeta 10%, texto violeta, borda violeta. Classe: `bg-violet-500/10 text-violet-300 border-violet-500/20`. É a única cor que coincide com o card de criação.
- Linha de vínculo: **Nenhum bot vinculado ainda**, quando `bots` está vazio.
- No básico e no n8n: métricas **STARTS** e **CONVERSÃO**, link **Remarketing** e link **Editar Fluxo** (`href /fluxos/{id}`).
- No Modo IA em rascunho, essas métricas e o link de remarketing não aparecem. No lugar: **Rascunho seguro · sem execução no bot**. O link se chama **Abrir Modo IA** e aponta para `/fluxos/{id}/ia`.
- Menu de ícones do card, igual nos três enquanto o card existe: **Mover para pasta**, **Exportar fluxo**, **Substituir fluxo**, **Excluir fluxo**.

### Importar Fluxo

Modal, não página. Título **Importar Fluxo**. Texto: **Cole o código de exportação e selecione um bot para vincular o fluxo importado.**

- **Código de Exportação**. Textarea, placeholder **Cole aqui o código de exportação...**
- **Nome do Fluxo**, opcional. Placeholder **Deixe em branco para usar o nome original**. Ajuda: **Se não informar, será usado o nome original + "(Importado)".**
- **Vincular ao Bot**. Combobox **Selecione um bot**. Lista no formato `@username - nome interno`.
- **Cancelar** e **Importar Fluxo**. O botão Importar fica desabilitado até haver código e bot.

O código de exportação não foi colado, então o POST de importação não foi disparado. **Exportar** e **Substituir** também não foram clicados, para não baixar nem sobrescrever os fluxos da conta. A exclusão foi usada só no rascunho temporário.

### Excluir

```http
DELETE /api/flows/{id}
credentials: include
```

```json
{ "success": true, "message": "Flow deletado com sucesso" }
```

---

## 7. Endpoints vistos

Todos com cookie de sessão (`credentials: include`). Onde o verbo não foi disparado ao vivo, a origem é o cliente JavaScript.

| Verbo | Caminho | Para que serve |
|---|---|---|
| GET | `/api/flows` | Lista, `maxFlows`, paginação e o documento de cada fluxo, inclusive `nodes` e `edges` |
| POST | `/api/flows` | Cria. Body `{ name, mode }`. 201 com `data.flow._id` |
| DELETE | `/api/flows/{id}` | Apaga o fluxo. 200 **Flow deletado com sucesso** |
| POST | `/api/flows/{id}/bots` | Vincula bot. Visto no cliente do Modo IA |
| DELETE | `/api/flows/{id}/bots?botId=` | Remove o vínculo do bot |
| PATCH | `/api/flows/{id}/bots/{botId}/toggle` | Liga ou desliga o bot naquele fluxo |
| GET | `/api/ai/flows/{id}/config` | Config do Modo IA. `schemaVersion` 9 |
| PUT | `/api/ai/flows/{id}/config` | Salva a config. Visto no cliente, não disparado |
| POST | `/api/ai/flows/{id}/preview` | Teste de conversa. Body `{ messages }`. GET devolve 405 |
| GET | `/api/ai/flows/{id}/learning` | Aprendizado: status, amostras mínimas, summary, versions |
| GET | `/api/ai/credits` | Saldo, tarifas por milhão de tokens, mínimos de recarga |
| POST | `/api/ai/credits/recharges` | Gera a recarga PIX. Visto no cliente, não disparado |
| GET | `/api/ai/credits/recharges` | Histórico de recargas, referenciado pelo cliente |
| GET | `/api/bots` | Bots da conta, usados no vincular e no importar |
| GET | `/api/bots/{id}/chats` | Canais e grupos para cache e entrega |
| POST | `/api/upload/cdn` | Upload de mídia do editor |

`GET /api/flows` devolve `success`, `data.maxFlows` (50 nesta conta), `data.pagination` `{ page, limit: 500, total, pages }`, `data.flows[]` e `data.flowFolders`. Cada fluxo já vem completo, não é um resumo.

---

## 8. Limites que a conta mostrou

- Fluxos por conta: **50**, somando os três tipos. A tela escreve isso em **Criar Fluxo (N/50)**.
- Bots por fluxo básico: **10**, escrito na seção Bots.
- Modo IA: **um bot por vez**, escrito em Publicar no bot.
- Nome do fluxo na criação: **30 caracteres**.
- Ofertas do Modo IA: **até 10**.
- Mídias da conversa da IA: **até 30 arquivos**.
- Etapas de downsell da IA: **até 8**. Espera de cada etapa: **30 segundos a 7 dias**. Soma prevista **até 28 dias**.
- Mídias por etapa de downsell da IA: **até 5**, **50 MB** por arquivo, mensagem **até 1.000 caracteres**.
- Biografia da persona: **600** caracteres. Contexto do negócio: **4.000**. Traços: **até 6**. Regras: **até 10 linhas**.
- Sequências de upsell/downsell do editor básico, no código: **até 20**, espera **até 7 dias**.
- Prévias do básico, no código: **até 8 mídias**.
- Planos do básico, no código: a lista para de aceitar item no **10**.
- Recarga de IA: mínimo declarado **10**, máximo **10.000**. Atalhos de **R$ 50 a R$ 3.000**.
- Aprendizado: só sugere mudança com **30 resultados** e **10 em cada abordagem**. Janela descritiva de **90 dias**.
- Não apareceu badge **Em breve** em nenhum dos três tipos. Os três estavam utilizáveis nesta conta.

---

## 9. O que esta leitura não abriu

- As seções com cadeado do fluxo básico `teste`, porque não havia canal de cache. Os rótulos dessas seções vieram do bundle, não de um clique.
- O formulário expandido de uma oferta nova do Modo IA, o desconto ativado e o upsell depois de existir uma oferta. O formato do produto veio do preset da estratégia e do config.
- Uma mensagem real no chat de teste, para não consumir saldo.
- A geração de PIX da recarga.
- Exportar, substituir e importar de verdade. O modal de importar foi aberto e fechado sem colar código.
- O verbo HTTP do botão Salvar do canvas n8n. O formato lido do grafo está no `GET /api/flows`.
- Não há, em lugar nenhum do que foi aberto, um exemplo de payload Telegram indo para um n8n externo ou voltando de lá.

---

## 10. Mapa curto para quem for implementar

- Uma criação, três modos: `basico`, `avancado`, `ia`. O nome "n8n" na interface corresponde a `avancado`.
- `basico` persiste formulário (`initial`, `plans`, sequences, `delivery`, `messages`) e abre 13 seções. O cache de mídia é a chave que destrava o miolo.
- `avancado` persiste `nodes` e `edges` no estilo React Flow, com a paleta da seção 4. O start é fixo. Pagamento tem duas saídas, pago e não pago.
- `ia` persiste um segundo documento em `/api/ai/flows/{id}/config` e nasce rascunho. O fluxo só atende depois de bot, oferta, entrega e saldo. O teste é um POST de mensagens, não uma geração de grafo.
- Crédito é pré-pago, por token medido depois da resposta, com tarifas diferentes para entrada, saída e áudio. Downsell e upsell automáticos da IA não passam pelo modelo.
- Listagem diferencia os três por badge e, no rascunho de IA, esconde starts, conversão e remarketing.

---

Fonte: sharkbot.com.br em 25/09/2026, sessão logada. Arquivo para o Claude Code planejar a implementação. Nenhum fluxo permanente da conta foi alterado.
