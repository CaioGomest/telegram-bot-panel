# Relatório: aguenta 700 usuários vendendo 1.000/dia?

Pedido do Caio em 2026-09-18. Cenário: **700 usuários × 1.000 vendas/dia cada = 700.000 vendas/dia**
na plataforma inteira (21 milhões/mês, 255,5 milhões/ano, ~8,1 vendas por segundo em média).

**Resposta curta: não, não neste plano — e o limite que estoura primeiro não é velocidade, é o
disco do banco.** A conta acabou de provar isso na prática hoje, com 1/8 desse volume.

---

## 1. O muro: cota de disco do banco (3 GB)

Bytes por linha **medidos no banco agora** (dado real, com índices):

| Tabela | bytes/linha |
|---|---|
| `vendas` | 429 |
| `leads` | 272 |
| `atividades` | 166 |
| `metricas_horarias_usuario` | 75 |

Projeção no cenário pedido (a parte de `leads` depende da taxa de conversão, que **não temos dado
real** — uso 33%, ou seja 3 leads por venda, que é otimista pra funil de Telegram):

| Tabela | linhas/dia | por dia | por mês | por ano |
|---|---|---|---|---|
| `vendas` | 700.000 | 300 MB | 9 GB | 110 GB |
| `leads` (3× vendas) | 2.100.000 | 571 MB | 17 GB | 208 GB |
| `atividades` (2× vendas) | 1.400.000 | 232 MB | 7 GB | 85 GB |
| **Total** | | **≈ 1,1 GB** | **≈ 33 GB** | **≈ 400 GB** |

A cota atual do banco é **3.072 MB**. Nesse ritmo:

- o banco estoura em **menos de 3 dias**;
- mesmo se `leads` e `atividades` não existissem, só `vendas` estoura em **10 dias**;
- com conversão de 10% (mais realista que 33%), são ~2,5 GB/dia — **estoura em pouco mais de 1 dia**.

**Isso não é teórico.** Hoje (2026-09-18) o banco bateu 4.435 MB de 3.072 MB e a Hostinger revogou
INSERT/UPDATE automaticamente: o painel abria e mostrava dados, mas o produto parou — não salvava
fluxo, não registrava lead, não gerava PIX e **não confirmava pagamento**. Foram ~10 milhões de
linhas (um teste de carga), ou seja **1/8 do volume de um único dia** do cenário pedido.

## 2. Requisições por segundo

Cada venda não é uma requisição: é o funil inteiro (`/start`, navegação, gerar PIX, "já paguei",
confirmação) — tipicamente 5-15 hits no `webhook.php` — e ainda tem todo lead que **não** converte.

Com 2,1 milhões de conversas/dia × ~8 hits = **~17 milhões de requisições/dia ≈ 200 req/s de
média**. Tráfego de venda concentra em horário de pico: com 70% do volume em ~10h, a média no pico
sobe pra ~600 req/s, com rajadas acima disso.

Dois limites concretos do lado da aplicação:
- **`MAX_USER_CONNECTIONS = 50`** (medido) e o projeto abre uma conexão nova por requisição
  (`conexao.php` não usa conexão persistente). A 600 req/s com ~100 ms por requisição, a conta
  precisa de ~60 conexões simultâneas — acima do teto.
- Hospedagem compartilhada tem número limitado de workers PHP. O teste de carga local desta série
  mediu ~150-170 req/s no caminho mais barato possível (sem lógica de fluxo, sem chamada ao
  Telegram), com o gerador de carga competindo pela mesma CPU. Ordem de grandeza: **uma a duas
  ordens abaixo do necessário**.

## 3. Crons não acompanham

- `cron_verificar_pix.php` processa `LIMIT 200` por rodada, 1×/minuto = **288.000/dia**. O cenário
  gera 700.000 PIX/dia. Como a maioria das confirmações chega por webhook, o fallback não precisa
  dar conta de tudo — mas a **segunda query do mesmo arquivo, a que expira cobranças vencidas, não
  tem `LIMIT`**: com PIX expirando em 15 minutos e 700 mil gerados por dia, essa varredura sozinha
  não fecha dentro da janela de 1 minuto.
- Os crons de aviso/corte de acesso e renovação varrem `membros_grupos` sem `LIMIT` também.

## 4. O que já era o teto antes disso (continua valendo)

- **Conta InfoPago única compartilhada** pelos 700 usuários: 255 milhões de transações/ano numa
  única conta mercante. Isso precisa ser validado direto com a InfoPago — ver
  `anotacoes/criticas/conta-infopago-unica-compartilhada.md`.
- **Split síncrono dentro do webhook**, segurando a resposta ao gateway.
- Telegram **não** é gargalo: os limites são por token de bot, e são 700 tokens diferentes.

## 5. O que seria preciso pra esse volume

Em ordem de urgência:

1. **Sair do banco compartilhado** — o cenário pede centenas de GB/ano. Isso é VPS/banco dedicado,
   não plano compartilhado de 3 GB.
2. **Política de retenção/arquivamento** — `leads` e `atividades` crescem mais rápido que `vendas`
   e são as que menos precisam ficar online pra sempre. Sem isso, qualquer plano enche.
3. **Conexão persistente/pool no MySQL** e revisão do teto de conexões.
4. **`LIMIT` na varredura de expirados** e paralelizar/escalonar os crons.
5. **Fila assíncrona** pro split e pro pós-pagamento, tirando trabalho do caminho do webhook.
6. **Validar volume com a InfoPago** e ter plano de contingência se a conta for suspensa.

## 6. Onde está o limite realista hoje

Com a infra atual (3 GB de banco, hospedagem compartilhada), mantendo uns 2 GB úteis pra dados e
supondo 1 ano de retenção antes de arquivar: o teto fica na ordem de **4 a 5 milhões de vendas
acumuladas** — algo como **500 usuários vendendo ~25/dia por um ano**, ou **700 usuários vendendo
~20/dia**. Esse patamar o sistema aguenta bem: a análise de escala desta mesma série mediu as
telas e os crons com 6,7 milhões de vendas no banco e tudo no caminho do cliente continuou abaixo
de 1 segundo (ver `analise-potencia-e-escala.md` seção 11).

De 1.000 vendas/dia por usuário pra cima, o problema deixa de ser o código e passa a ser
infraestrutura.
