# Capacidade — quantas vendas/usuários por dia o sistema aguenta

Resumo direto, baseado no teste de estresse de 25/09 (`teste-de-estresse-25-09.md`, ali
tem a metodologia e as contas completas). Duas perguntas diferentes, respondidas
separadas: **"normal"** (ritmo que roda de boa, sem pensar duas vezes) e **"máximo"**
(o quanto aguenta antes de cair ou dar problema de verdade).

## 🎯 Resumo

| Cenário | Hoje (hospedagem compartilhada) | Numa VPS |
|---|---|---|
| **Normal — roda tranquilo, por anos, sem monitorar nada** | **até ~1.000 vendas/dia** | **até ~64.800 vendas/dia** |
| **Máximo absoluto antes de dar problema** | pico isolado: **dezenas de milhares num único dia**; sustentado todo dia: **~30.000/dia por ~1 mês** até estourar | **~64.800/dia é o próprio teto** (não é elástico como no cenário "normal" — ver por quê abaixo) |
| **O que exatamente quebra quando passa do limite** | Banco de dados fica sem espaço (cota fixa de 3 GB) → parede de escrita, sistema para de gravar venda/lead → **já aconteceu de verdade em 18/09**, ficou horas fora | Fila de processamento em segundo plano (liberação/remoção de acesso vencido) começa a atrasar — o site continua no ar, mas o cliente que devia perder acesso demora mais que deveria |

**Concorrência (gente acessando ao mesmo tempo) não é o problema em nenhum dos dois
cenários** — testado até 60 requisições simultâneas direto no `webhook.php` (o caminho
mais pesado do sistema, cada `/start` de bot passa por ali) e o tempo de resposta não
mudou nada: sempre ~130-190ms, igual a testar sozinho. Não achei o teto de concorrência
real — parei em 60 por precaução, não por ter batido em algum limite.

## Por que "normal" e "máximo" são números tão diferentes hoje, mas quase iguais na VPS

**Hoje (compartilhado):** o freio é a **cota de banco de dados, fixa em 3 GB**, que não
muda trocando de plano compartilhado. Isso dá bastante gordura pra picos — um dia
excepcional de 20.000 ou 30.000 vendas não derruba nada na hora, só consome cota mais
rápido. O problema é ritmo **sustentado**: se isso virar rotina, a cota (hoje com
~2.382 MB livres) acaba em semanas, não anos. Por isso "normal" (1.000/dia, dura ~2,3
anos) e "máximo de pico" (dezenas de milhares num dia isolado) são números tão
diferentes — um é sobre todo dia, o outro é sobre um dia só.

**Numa VPS:** o espaço em disco deixa de ser um limite prático (100 GB aguentaria
~34-36 milhões de vendas — não é algo que vai faltar). O que sobra como teto é
**código**, não infraestrutura: os processos automáticos que removem acesso de quem
venceu rodam um item de cada vez (não em paralelo), processando ~45 por minuto. Isso dá
**64.800 por dia**, todo dia, sem folga extra — diferente da cota de banco, esse teto
não "aguenta um pico e depois volta ao normal", ele é o próprio limite de throughput.
Por isso na VPS o número de "normal" e "máximo" praticamente coincidem: não tem uma
reserva de emergência como tem a cota de banco hoje, tem que resolver o código
(paralelizar os processos — o padrão já existe em `cron_remarketing.php`, só falta
replicar nos outros) pra esse teto subir.

## O que não está incluído nessa conta

- Latência real da chamada pro gateway de pagamento (OmegaPayments) sob carga — não
  testado de propósito, pra não bombardear a API real com tráfego sintético (risco de
  cobrança de verdade / antifraude do gateway). Pela folga de concorrência que sobrou em
  tudo mais, não parece que vá ser o gargalo, mas é a peça que ficou sem medir.
- Carga sustentada por horas/dias — só testei rajadas curtas (segundos). Não testei os
  processos automáticos rodando ao mesmo tempo que a carga de venda (eles competem pelo
  mesmo banco).

## Fonte

Contas completas, metodologia do teste e tabela de "quanto tempo até a cota encher em
cada ritmo" estão em [`teste-de-estresse-25-09.md`](teste-de-estresse-25-09.md).
