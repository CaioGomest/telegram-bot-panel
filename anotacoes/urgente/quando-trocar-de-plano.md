# 🔴 URGENTE (acompanhar) — quando o plano precisa ser trocado

Anotado em 2026-09-18. Resposta curta: **o limite é o BANCO, não o disco** — e, até ~1.000
vendas/dia, o plano atual segura por uns 2 anos.

## Por que é o banco e não o disco

| | Uso | Teto do plano |
|---|---|---|
| Arquivos do site (`du -sh ~`) | 7,4 GB | 20-50 GB |
| **Banco MySQL** | bateu **4.435 MB** | **3.072 MB** |

O aviso "Você usou todo o seu espaço em disco" no hPanel era sobre o banco. O teto de 3 GB **por
banco** vale pra todos os planos compartilhados da Hostinger (Premium e Unlimited) — subir de plano
compartilhado não muda isso. Cloud Startup vai pra 6 GB; só VPS/Cloudways tira o teto.

## Quanto custa de espaço cada venda (medido no banco real)

| Item | bytes |
|---|---|
| 1 linha em `vendas` | 429 |
| ~5 leads que não converteram (conversão de 20%) | 1.360 |
| ~7 linhas de log em `atividades` | 1.162 |
| **total por venda** | **≈ 2,9 KB** |

## Capacidade e prazo

Sobram ~2 GB livres (depois da limpeza de 2026-09-18) = **~730 mil vendas no total**.

| Vendas/dia | Enche em |
|---|---|
| 100 | ~20 anos |
| 500 | ~4 anos |
| **1.000** | **~2 anos** |
| 5.000 | ~5 meses |
| 10.000 | ~2 meses |
| 30.000 | ~3 semanas |

**Gatilho prático:** monitorar o tamanho do banco no hPanel. Passou de **2,4 GB (80% da cota)**, é
hora de agir — seja trocando de plano, seja ligando o arquivamento.

## Duas ressalvas

1. **O "5 leads por venda" é estimativa** — não temos dado real de conversão. Conversão melhor →
   cabe até ~1 milhão de vendas; conversão de 10% → cai pra ~420 mil. Quando houver tráfego real,
   dá pra recalcular com o número verdadeiro e ajustar esta nota.
2. **1 GB dos 3 GB ainda é dado sintético de teste** (bot "Carlos 3 anos", `bot_id=2`: 766 mil
   vendas e 1,09 milhão de leads). Apagar isso antes de entrar cliente real devolve a cota inteira
   — **~350 mil vendas a mais de fôlego, sem custo**. O Caio ainda não decidiu se apaga (o dataset
   serve pra inspeção visual).

## Se/quando trocar (resumo — detalhes em `capacidade-700-usuarios-1000-vendas-dia.md`)

| Situação | Indicação |
|---|---|
| Não quer administrar servidor | **Cloudways** DigitalOcean 2 GB RAM / 50 GB (~US$ 22/mês): gerenciado, sem teto de banco, permite fila e crons paralelos |
| Quer o caminho mais simples e volume < ~15 mil vendas/dia | **Hostinger Cloud Startup** (6 GB de banco) **+ arquivamento** de `leads`/`atividades` (código que ainda não existe, eu teria que escrever) |
| Assume administrar servidor | **Hostinger VPS KVM 2** (100 GB, 8 GB RAM, ~US$ 9/mês) |

Banco gerenciado separado (app num lugar, MySQL em outro) foi descartado: cada página faz várias
consultas seguidas e a latência de rede entre provedores estragaria o tempo de resposta.

## Notas relacionadas

- `capacidade-700-usuarios-1000-vendas-dia.md` — relatório completo (infra + sistema).
- `quatro-mudancas-pro-motor-escalar.md` — o que trava no código, independente de plano.
- `banco-sem-permissao-insert-update.md` — o dia em que a cota estourou e a escrita foi revogada.
