# Teste das páginas de rastreio (30/09/2026)

Conta criada do zero no homolog e usada só nela: Teste Paginas, perfil usuário, sem bots, fluxos, links ou webhooks.

## O que não bate

- O menu dizia **Rastreamento** enquanto o título, o H1 e o aviso já diziam **Traqueamento**. O menu do homolog foi alinhado para **Traqueamento** em 30/09.
- No menu, **Links de Rastreamento** não cabe e aparece cortado: "Links de Rastreamen...".

## O que a conta vazia mostrou

- Traqueamento: campos vazios, interruptores desligados. Salvar vazio grava e confirma.
- Links de Rastreamento: contador 0 e "Nenhum link cadastrado". O modal só oferece "Escolha o bot". Sem título, a tela avisa "Informe o título." e não cria link.
- Redirecionamento: links, cliques, starts e vendas em zero. O formulário avisa que não há bot com @usuário e aponta para cadastrar um. Sem nome, avisa "Dê um nome ao link.". A aba Preparar campanha pede para criar um link antes.
- Webhooks: ativos, bots e envios em zero, lista vazia. URL `http://` é recusada ("Use um endereço https:// público...") e nada é gravado. O exemplo de payload mostra telefone preenchido e e-mail/IP nulos, igual ao texto da página.
