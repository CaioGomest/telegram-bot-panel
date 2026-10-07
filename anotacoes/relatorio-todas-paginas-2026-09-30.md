# Relatório de todas as páginas — 30/09/2026

Homologação: `https://telegram.stackcode.com.br`. Nenhuma página devolveu erro de PHP (Warning, Notice, Fatal).

Conta de operação: QA Outras Paginas Editado. Depois entrei como administrador só para a área admin. Não abri Atualizar Banco (o GET dessa tela roda migração). Não exportei CSV, não desativei gateway, campanha nem webhook, e não consultei um ID de venda que exista (isso chamaria o gateway).

## O que não bate

- O item **Links de Rastreamento** no menu não cabe e aparece cortado (`Links de Rastreamen...`).
- Em Minha Conta a nova senha diz **mínimo de 6 caracteres**. O cadastro recusa senha com menos de 8.
- A página 404 responde certo (título "Página não encontrada", link para o login), mas o nome na aba é **Painel de Bots**, não GomesBot.
- No editor visual o bloco continua **Delay** em inglês. No fluxo guiado os mesmos tipos se chamam Upsell, Downsell e Order Bump; no editor visual estão Oferta extra, Oferta menor e Extra no pagamento.
- Em Transações, uma venda "Aguardando Pix" mostra **—** na coluna Split. O filtro ao lado distingue Pago, Pendente, Sem split e Falhou. O traço não diz qual desses é.
- A lista de transações marca a última página como **30.469**. Com 25 linhas por página isso é cerca de 762 mil registros. Só as vendas pagas da visão geral já são 767.130, e essa lista ainda inclui PIX que não foi pago. O fim da tabela pode continuar escondido. Não abri a última página.
- O mesmo token do bot `@Claude_Gomes_bot` está em duas contas. O painel usa a primeira. Um `/start` real no Telegram não cai na conta em que o fluxo e o webhook de teste foram criados.

## Conta de operação

### Dashboard (`/index`)

Abre. Hoje: R$ 0,00, 0 aprovações, conversão 0%, 1 start, ticket R$ 0,00. A atividade é o lead "Teste Beeceptor" de 30/09 21:18. O card da campanha "Teste titulo" mostra o líder (Usuário #3, R$ 975,5 mil), não a posição desta conta.

### Meus Bots (`/bots`) e Configurar Bot (`/bot?id=16`)

1 bot, Gomes_bot / @Claude_Gomes_bot, fluxo "Teste webhook Beeceptor", 1 lead em 8 dias. O menu Meus Bots fica ativo na edição. O fluxo 43 aparece selecionado. O tutorial de criar bot fica escondido na edição. Token mascarado, com botão Mostrar.

### Novo Bot (`/bot`)

Título e H1 "Novo Bot". Sem erro.

### Fluxos (`/fluxos`) e editor (`/fluxo?id=43`)

1 fluxo, data 30/09/2026 21:14. O editor abre com 2 blocos e o menu Fluxos ativo. Abrir o fluxo não disparou aviso de salvamento.

### Fluxo guiado (`/fluxo_basico`)

Formulário completo (Geral, Bots, Boas-vindas, Planos, Upsell, Downsell, Order Bump, Pagamentos, Suporte). Avisa para salvar antes de vincular bot. Menu Fluxos ativo.

### Leads (`/leads`)

1 lead: Teste Beeceptor, ID 900300930, Gomes_bot, 30/09/2026 21:18, status Iniciou, plano Inativo, 0 compras, R$ 0,00, número vazio. "Já pagou" fica vazio. "Não pagou" mostra esse lead. Bate com o dashboard (1 start, R$ 0).

### Ranking (`/ranking`)

Campanha "Teste titulo", 01/09/2026 a 31/12/2026, em andamento, 1 participante. Esta conta ainda não pontuou. Líder: Usuário #3, R$ 975,5 mil. Premiação de teste (Carro, Celular, Mulher). O subtítulo na tela pública aparece cortado ("Teste subti").

### Remarketing (`/remarketing`) e detalhes (`/remarketing_detalhes?campanha=23`)

Campanha "Recuperação QA", Gomes_bot, audiência "Não comprou", agendada 15/10/2026 10:30, pendente, 0 destinatários, 0 envios. O filtro da lista usa "Acessou e não comprou"; a tabela e o detalhe usam "Não comprou". Menu Remarketing fica ativo no detalhe. Sem id, a tela de detalhe diz "Campanha não encontrada".

### Traqueamento (`/traqueamento`)

Menu, aba e H1 dizem Traqueamento. Pixel preenchido, tokens mascarados, Facebook e UTMfy ligados. Não salvei de novo.

### Links de Rastreamento (`/links_rastreamento`)

1 link, "Link Instagram QA editado", Gomes_bot, 0 vendas, R$ 0,00, 0 starts, 0 leads. O link gerado aponta para `t.me/Claude_Gomes_bot`.

### Redirecionamento (`/redirecionamento`)

1 link, "Campanha QA Meta editada", 1 clique, 0 starts, 0 leads, R$ 0,00. O clique não virou start porque ninguém concluiu o /start com o código desse link.

### Webhooks (`/webhooks`)

1 ativo, 1 bot monitorado, 1 envio com sucesso. "Beeceptor teste" ativo, 0/5 falhas, evento Novo lead. "CRM teste QA editado" inativo. Bate com o teste do Beeceptor (HTTP 200 às 21:18).

### Comunidade (`/comunidade`)

Dois itens de teste: "Grupo titulo" e "Grupo Telegram" com vagas 0/22. O 0/22 é o mesmo número da tela admin. O link "Grupo Telegram" aponta para a própria página de administração da comunidade, não para um grupo.

### Gateways (`/gateways`)

1 ativo: OmegaPayments (Pix), conectado, marcado como principal. Não desativei nem salvei.

### Minha Conta (`/configuracao_usuario`)

Nome, e-mail e apelido de ranking preenchidos. Formulário de senha separado. Não salvei.

### Termos (`/termos`)

Texto em português, 9 seções, data 18/09/2026, link de volta ao cadastro.

### Login e cadastro

Com sessão aberta, os dois endereços voltam para o dashboard. A tela de login, depois do logout, não mostra métricas falsas. "Esqueci a senha" é um link.

### Endereço inexistente

HTTP 404, "Página não encontrada", link para o login. O nome na aba fica "Painel de Bots".

### Área admin sem ser admin

Dashboard, transações, logs, usuários, campanhas, comunidade, configurações, consultar venda, índice do admin e instalação redirecionam para `/index?erro=sem_permissao` com o aviso "Você não tem acesso à área de administração."

## Conta admin

### Visão geral (`/admin/dashboard`)

Os períodos batem com o rótulo da comissão:

| Período | Volume | Vendas | Comissão |
| --- | --- | --- | --- |
| Hoje | R$ 0,00 | 0 | de hoje, R$ 0,00 |
| Ontem | R$ 0,00 | 0 | de ontem, R$ 0,00 |
| 8 dias | R$ 1.656,00 | 40 | nos 8 dias, R$ 83,00 |
| 30 dias | R$ 986.922,58 | 12.092 | nos 30 dias, R$ 293.228,17 |
| Total | R$ 63.188.655,93 | 767.130 | no período total, R$ 18.950.321,60 |

Usuários: 5 em todos os períodos. O gráfico do total se chama "COMISSÃO NOS ÚLTIMOS 12 MESES".

### Transações (`/admin/transacoes`)

Colunas ID, data, usuário/bot, valor, status, gateway, TXID e split. Primeira linha: ID 6769150, 29/09/2026, R$ 5,00, Aguardando Pix, OmegaPayments. Limpar usa a URL limpa `transacoes`. O filtro de usuário mostra nome e e-mail. Ver o item da paginação e do traço no split acima.

### Logs (`/admin/logs`)

Abre. Primeira linha é o login do administrador de 30/09/2026 23:26. Última página indicada: 721. A visão geral pagina o mesmo tipo de atividade em 1.143 páginas; os dois tamanhos de página não são iguais, então os totais não precisam coincidir.

### Usuários (`/admin/usuarios`)

6 linhas. O rótulo é Admin ou Usuário. O KPI "5 clientes" da visão geral não conta o administrador, então 5 + 1 admin = 6. Carlos Mendes concentra R$ 63.162.015,93; as outras contas de teste estão em R$ 0,00 de venda paga. Não editei nem excluí.

### Campanhas (`/admin/ranking`)

O menu diz Campanhas. O título da página é Campanhas de Ranking. Uma campanha, "Teste titulo", em andamento, 01/09/26 a 31/12/26. É a mesma da tela pública. Não criei nem desativei.

### Comunidade admin (`/admin/comunidade`)

Os dois links ativos são os mesmos da aba do usuário. Vagas 0/22 no "Grupo Telegram". Não publiquei, não desativei e não excluí.

### Configurações (`/admin/configuracoes`)

Nome GomesBot, cor de destaque preenchida, secret do Google mascarado. Não salvei.

### Consultar venda (`/admin/consultar_venda`)

ID 999999999 responde "Venda não encontrada." e não chama o gateway.

### Gateways (admin)

A tela de admin mostra o split padrão da plataforma (taxa 0, tipo percentual) e 1 gateway disponível. Não salvei o split.

### Atualizar Banco

Não abri.
