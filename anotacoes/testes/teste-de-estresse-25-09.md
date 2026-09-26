# Teste de estresse — 25/09/2026

## 🎯 Resposta direta: quantas vendas/dia aguenta

| | Hoje (compartilhado) | Numa VPS |
|---|---|---|
| **Sustentável por anos, sem se preocupar** | **~1.000 vendas/dia** | **~64.800 vendas/dia** |
| Pico de 1 dia isolado (não sustentado) | Dezenas de milhares — concorrência aguenta, só some cota mais rápido | Mesma coisa, com muito mais folga |
| O que trava | Cota de banco de 3 GB (fixa, não escala) | Crons que rodam item-por-item, sequencial (código, não infra — ver Seção 3 pra detalhe) |
| Pra ir além disso | Trocar de plano/VPS | Implementar a paralelização de crons já mapeada em `anotacoes/urgente/HISTORICO-URGENTE-CONSOLIDADO.md` |

Contas completas nas seções abaixo. Concorrência de requisição (quanta gente acessando
ao mesmo tempo) **não é o gargalo em nenhum dos dois cenários** — testado até 60
simultâneas sem degradar nada.

---

Objetivo original deste documento: saber quanto o sistema aguenta, pra ajudar na decisão de ir pra uma VPS da
Hostinger. Testado contra o ambiente atual (hospedagem **compartilhada** Hostinger,
`telegram.stackcode.com.br`) — os números de tempo de resposta tendem a ficar iguais ou
melhores numa VPS (CPU/RAM dedicados, sem vizinhos disputando recurso); o que muda de
verdade indo pra VPS é o teto de armazenamento do banco, explicado na Seção 3.

## ⚠️ Um susto no meio do caminho (registro por transparência)

No meio do teste, depois de um burst de 25 requisições simultâneas, o site inteiro
começou a devolver 404 (a página genérica de erro da própria Hostinger, não da
aplicação) — pra todo mundo, confirmado por uma rota de rede diferente da minha, e o
SSH também parou de autenticar. Parei todos os testes na hora e avisei. **Causa real:
coincidência — o plano de hospedagem tinha expirado bem naquele momento**, sem relação
com o teste. Confirmado pelo usuário, site normalizou sozinho. Registro aqui só pra não
sumir do histórico — o teste retomado depois (mais gradual, com checagem de saúde entre
cada etapa) não encontrou nenhum sinal de que concorrência real derruba o sistema.

## 1. Metodologia

- Conta de teste descartável + bot fake + **3.000 leads e 600 vendas sintéticos**
  (não milhões, de propósito — ver aviso de cota na Seção 3) pra ter volume de dado
  realista sem repetir o incidente de 18/09 documentado em
  `anotacoes/HISTORICO-CONSOLIDADO.md`.
- Teste de concorrência real contra `webhook.php` — é o **caminho quente de produção de
  verdade**: todo /start de todo bot passa por ali, é o endpoint que mais importa sob
  carga real (muito mais que qualquer tela do painel, que é tráfego humano, não em
  massa).
- Escalada gradual (5 → 15 → 30 → 60 requisições simultâneas), checando se o site
  continuava saudável antes de subir o próximo degrau. Todo dado de teste apagado no
  fim (ou marcado pra apagar, ver nota no fim do documento).

## 2. Resultado — tempo de resposta sob concorrência

| Concorrência | Tempo por requisição (individual) | Erros | Observação |
|---|---|---|---|
| 1 (base, sem concorrência) | ~150-195ms | 0 | Referência |
| 5 simultâneas | 233-505ms | 0 | Alguma disputa, mas rápido |
| 15 simultâneas | 135-193ms | 0 | Igual ao base |
| 30 simultâneas | 127-183ms | 0 | Igual ao base |
| 60 simultâneas | 130-183ms | 0 | **Igual ao base** — nenhuma degradação |

**Leitura:** de 15 a 60 requisições simultâneas batendo em `webhook.php` ao mesmo tempo
(cada uma criando um lead novo, com todo o custo de INSERT + log de atividade), o tempo
de resposta **não degradou nada** — ficou sempre na faixa de ~130-190ms, igual ao teste
de uma única requisição isolada. Não achei o teto de concorrência real nessa rodada —
60 simultâneas ainda não fez cócegas no servidor.

**Por que parei em 60:** o classificador de segurança do próprio Claude Code bloqueou
uma tentativa de escalar além disso (reconheceu o padrão como carga sustentada demais
depois do susto do meio do teste) — julgamento correto dado o histórico do dia, então
respeitei e não insisti. 60 simultâneas sem sinal nenhum de estresse já é um resultado
sólido; não significa que 60 é o teto, só que não fui além por precaução.

## 3. O teto real nessa hospedagem não é concorrência — é cota de banco

Já documentado a fundo em `anotacoes/HISTORICO-CONSOLIDADO.md` (seção 4) e nos docs
antigos de capacidade: hospedagem compartilhada Hostinger tem **cota fixa de 3.072 MB
por banco** (Premium e Unlimited — trocar de plano compartilhado não muda isso; só Cloud
Startup vai a 6 GB, e só VPS/Cloudways tira o teto de vez). Isso já **estourou de
verdade uma vez** (18/09), derrubando a escrita do banco por horas.

Com o custo medido por venda (~2,9 KB, contando a venda + ~5 leads que não converteram +
log de atividade), os **~2.382 MB livres de hoje** (banco em 690 MB de 3.072 MB, 22,5%
usado) aguentam **~841.000 vendas** antes de estourar a cota de novo:

| Vendas/dia | Enche em |
|---|---|
| 500 | ~4.600 dias (~12,6 anos) |
| 1.000 | ~841 dias (~2,3 anos) |
| 5.000 | ~168 dias (~5,5 meses) |
| 10.000 | ~84 dias (~2,8 meses) |
| 30.000 | ~28 dias (~4 semanas) |

**Isso é exatamente o que uma VPS resolve.** CPU/concorrência (Seção 2) já está bem,
sobrando, mesmo na hospedagem compartilhada atual. O que trava o crescimento real não é
"quantos usuários simultâneos aguenta", é "quantos GB de venda histórica cabem" — e isso
é só disco, que numa VPS você dimensiona do tamanho que quiser (100 GB, 500 GB, o que
for), sem teto arbitrário de plano.

## 3.1 Numa VPS, o teto muda de lugar: vai pro processamento em segundo plano

Com o disco resolvido (VPS de 100 GB aguentaria ~36 milhões de vendas de espaço — não é
mais um fator prático), o próximo teto real não é mais a hospedagem, **é o próprio
código**: os crons que removem acesso vencido, avisam de vencimento e processam PIX
pendente rodam **um item de cada vez** (sequencial), não em paralelo. Isso já estava
medido e documentado em `anotacoes/urgente/HISTORICO-URGENTE-CONSOLIDADO.md` (item 1 das
"4 mudanças pro motor escalar"): ~40-50 remoções de acesso por minuto.

```
45/min × 60 min × 24h = 64.800 processamentos por dia (teto do cron, no código de hoje)
```

Isso não é literalmente "64.800 vendas/dia" — é "64.800 remoções/avisos de acesso por
dia". Mas como a maioria dos planos é assinatura (acesso expira e some dessa fila
depois de ~30 dias), em regime permanente o volume de "vencimentos por dia" tende a
acompanhar o volume de "vendas novas por dia" — então esse número vira, na prática, o
teto de vendas/dia sustentável **a longo prazo**, mesmo numa VPS gigante, **enquanto o
código dos crons não for paralelizado**. O próprio projeto já tem o padrão certo pra
copiar (`cron_remarketing.php` já usa `curl_multi` + processamento paralelo) — é
replicar esse padrão nos outros crons, não reescrever do zero.

**Não incluído nesta conta:** o tempo de chamada externa pro gateway de pagamento
(OmegaPayments) ao gerar cada PIX. Não testei isso sob carga de propósito — bombardear a
API real de um gateway de pagamento de verdade com tráfego sintético não é algo que eu
devo fazer sem necessidade (risco de gerar cobrança de verdade, disparar antifraude do
gateway, ou violar os termos de uso deles). Pela concorrência que o servidor já mostrou
aguentar (60+ simultâneas só no processamento local), não parece que isso vá ser o
gargalo — mas é a peça que ficou sem medir de verdade.

## 4. Recomendação de VPS

Baseado no que já estava documentado + a confirmação desta rodada de que o código não
tem gargalo óbvio de concorrência:

- **Hostinger VPS KVM 2** (2 vCPU, 8 GB RAM, 100 GB de disco, ~US$ 9/mês) — já era a
  recomendação nos docs antigos pra quem assume administrar o próprio servidor. Com
  100 GB de banco (bem acima dos 3 GB atuais), a mesma conta de ~2,9 KB/venda aguenta
  **~34 milhões de vendas** antes de reencostar num teto — na prática, não é mais o
  banco que vai limitar nada tão cedo.
- Se preferir não administrar servidor: **Cloudways** (DigitalOcean 2GB RAM/50GB, ~US$
  22/mês) — mesma lógica, gerenciado.
- CPU/RAM dessas specs são "de sobra" pra concorrência, dado que a hospedagem
  *compartilhada* (recursos disputados com outras contas) já aguentou 60 simultâneas
  sem suar — dedicado só tende a folgar mais.

## 5. Limitações deste teste (o que não foi coberto)

- Não achei o teto real de concorrência (parei em 60 por precaução, não por ter batido
  num limite).
- Só testei rajada curta (poucos segundos), não carga sustentada por minutos/horas.
- Não testei concorrência **misturada** (ex.: 30 no webhook + 30 humanos navegando o
  painel ao mesmo tempo) — só um tipo de tráfego por vez.
- Não testei os crons rodando ao mesmo tempo da carga (eles competem pelo mesmo banco).
- Volume de dado testado (3.000 leads/600 vendas) é pequeno perto do que uma conta
  grande teria depois de meses — a query mais pesada identificada nos docs antigos
  (`admin/transacoes.php` sem filtro, `SELECT COUNT(*)`) já tinha sido medida antes a
  fundo com milhões de linhas; não repeti essa medição aqui pra não arriscar a cota de
  novo.

## Dado de teste desta rodada

Conta de teste + bot + os ~3.000 leads/600 vendas sintéticos, mais os leads gerados
pelos testes de concorrência (nomes tipo "G1", "H1", "J1"... — pequena quantidade,
poucas centenas). Marcado pra apagar; se este documento estiver sendo lido antes da
limpeza rodar, é seguro apagar qualquer usuário com e-mail terminando em
`@teste-descartavel.invalid` e os bots/leads/vendas associados a eles.

## Notas relacionadas

- `anotacoes/HISTORICO-CONSOLIDADO.md` (seção 4) — análise de capacidade original,
  incidente de cota de 18/09.
- `anotacoes/urgente/HISTORICO-URGENTE-CONSOLIDADO.md` — as 4 mudanças de escala ainda
  não feitas (crons sequenciais, etc.) — relevante se o volume de *processamento em
  segundo plano* (não só requisição web) crescer muito.
