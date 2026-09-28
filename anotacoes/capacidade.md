# Capacidade — quanto o sistema aguenta

Nota de referência rápida, só sobre capacidade. Os números completos, a metodologia do
teste e as contas detalhadas estão em `anotacoes/testes/teste-de-estresse-25-09.md` e
`anotacoes/testes/capacidade-vendas-dia-26-09.md` — aqui é só o resumo pronto pra
consultar (inclusive os números que foram efetivamente usados na conversa com cliente,
que são mais conservadores que o teto real medido, de propósito).

## Números usados com cliente (conservadores, com margem)

| | Hoje (hospedagem compartilhada) | Numa VPS |
|---|---|---|
| **Número passado pro cliente** | ~600 vendas/dia | ~40.000 vendas/dia |
| **Teto real medido/calculado** | ~1.000 vendas/dia (sustentável ~2,3 anos) | ~64.800 vendas/dia (teto do cron) |
| **Margem de segurança usada** | ~40% abaixo do teto real | ~38% abaixo do teto real |

Motivo de arredondar pra baixo: número que vai pro cliente é melhor errar pra menos —
dá folga se o ambiente real tiver alguma variável que o teste não cobriu (ver seção
"O que não foi medido" abaixo).

## Quanto tempo cada ritmo dura

**Hoje (cota de banco fixa em 3 GB, 2.382 MB livres no momento do teste):**

| Vendas/dia | Dura |
|---|---|
| 600 (número do cliente) | ~3,8 anos |
| 1.000 (teto real) | ~2,3 anos (841 dias) |

**Numa VPS (disco de 100 GB, ~36.157.793 vendas de espaço — bem mais folgado, o disco
deixa de ser o fator prático):**

| Vendas/dia | Dura (até o disco de 100GB encher) |
|---|---|
| 40.000 (número do cliente) | ~2,5 anos |
| 64.800 (teto real do cron) | ~1,5 ano (558 dias) |

Importante: na VPS, "durar" não é uma parede fixa como a cota de 3GB de hoje — é só
espaço em disco, que dá pra aumentar quando quiser, sem trocar de plano nem migrar nada.
Quem trava de verdade na VPS não é o disco, é o **código dos crons rodando sequencial**
(ver próxima seção) — isso sim precisa de trabalho de engenharia pra subir, não é só
"comprar mais espaço".

## O que trava cada ambiente

- **Hoje:** cota de banco de dados fixa em 3.072 MB (Hostinger, hospedagem
  compartilhada — não muda trocando de plano compartilhado, só indo pra Cloud
  Startup/VPS). Já estourou de verdade uma vez (18/09), derrubou a escrita do banco por
  horas.
- **Numa VPS:** os crons que processam remoção/aviso de acesso vencido rodam um item
  de cada vez (sequencial), ~45/min = 64.800/dia. É limitação de código, não de
  hospedagem — sobe implementando a paralelização já mapeada em
  `anotacoes/urgente/HISTORICO-URGENTE-CONSOLIDADO.md` (mesmo padrão que
  `cron_remarketing.php` já usa).

## O que não foi medido (ainda)

- Latência real da chamada externa pro gateway de pagamento (OmegaPayments) sob carga —
  não testado de propósito, pra não gerar cobrança real/disparar antifraude do gateway.
- Carga sustentada por horas/dias seguidos — só rajadas curtas foram testadas.
- Crons rodando ao mesmo tempo que pico de carga de venda (competem pelo mesmo banco).

## Concorrência não é gargalo em nenhum cenário

Testado até 60 requisições simultâneas direto no `webhook.php` (o caminho mais pesado
do sistema — todo `/start` de bot passa por ali) sem nenhuma degradação de tempo de
resposta (~130-190ms, igual a uma requisição isolada). O limite em qualquer ambiente
é sempre armazenamento/processamento em lote, nunca "quantas pessoas acessando ao mesmo
tempo".
