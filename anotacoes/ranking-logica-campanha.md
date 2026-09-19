# Ranking: uma campanha por vez, e o que acontece quando acaba

Refeito em 2026-09-18, a pedido do Caio: tirar as "ligas" e simplificar para
"uma campanha só por tempo e pronto".

## O que saiu, e por quê

- **Card "Ligas privadas"** e as abas "Ranking mensal" / "Minhas ligas". Eram promessa de
  uma funcionalidade que não existe — o botão era `disabled title="Em breve"` e as abas não
  clicavam em nada.
- **`tipo ENUM('oficial','mensal')`.** A tela chamava `buscarCampanhaAtiva('oficial')`, então
  uma campanha criada como "Mensal" no admin **nunca apareceria pra ninguém**. Não era um
  recurso, era uma armadilha: o admin preenchia tudo, salvava, e o ranking continuava vazio
  sem nenhuma mensagem explicando.

## A regra nova

Existe **uma** campanha em cartaz. Quem decide qual são as datas — `ativa` na tabela é só o
interruptor do admin ("essa campanha conta?"), não significa "está rolando agora".

| ordem | se existe... | estado |
|---|---|---|
| 1 | uma rodando agora | `ativa` |
| 2 | senão, a última que já terminou | `encerrada` |
| 3 | senão, a próxima agendada | `agendada` |

## A pergunta que originou isso: "quando acabar, continua mostrando?"

**Continua.** Antes a busca exigia `NOW() BETWEEN data_inicio AND data_fim`, então a campanha
desaparecia no segundo em que acabava. Quem ganhou nunca via que ganhou: às 23:59:59 havia um
pódio, às 00:00:01 a tela dizia "Nenhuma campanha em andamento".

O resultado agora fica no ar **até a próxima campanha começar**. É uma regra que cabe numa
frase e não tem prazo pra calibrar. Se o admin quiser tirar antes, desliga a campanha (`ativa
= 0`) e a tela volta ao estado vazio.

A alternativa considerada foi "some depois de N dias". Descartada: obriga a escolher um N, e
cria um período em que a tela fica vazia mesmo havendo um resultado pra mostrar.

## O que cada estado mostra

| estado | hero | placar | contagem | prêmios |
|---|---|---|---|---|
| `agendada` | "Começa em dd/mm" | **não** — só "a disputa ainda não começou" | pro início | sim |
| `ativa` | "Em disputa" + ponto vivo | "Placar ao vivo" + "atualiza a cada 60s" | pro fim | sim |
| `encerrada` | "Encerrada em dd/mm" | "Resultado final" | não | sim |
| sem campanha | — | estado vazio | — | — |

O `agendada` não mostra placar de propósito: o `ranking_cache` ainda guarda os números da
janela anterior, e exibir aquilo como "placar ao vivo" de uma disputa que nem começou seria
número real em contexto errado. Foi o que aconteceu no primeiro teste.

## Bug de placar no cron (corrigido junto)

O cron só processava campanha com `NOW() BETWEEN data_inicio AND data_fim`. Consequência: a
última execução acontecia **antes** do prazo, e toda venda entre ela e o fim da campanha
nunca entrava no resultado. Numa campanha que fecha à meia-noite, até 1 minuto de vendas
sumia — o suficiente pra trocar o primeiro lugar numa disputa apertada.

Agora ele pega `ativa = 1 AND data_inicio <= NOW() AND finalizada_em IS NULL`, ou seja:
também a campanha vencida e ainda não fechada. Faz o recálculo final e grava `finalizada_em`
**na mesma transação** — ou os dois entram, ou nenhum. Depois disso a campanha sai da
consulta e o cache dela não é mais tocado.

Verificado ao vivo, nesta ordem no `logs/cron_ranking.log`:

```
Campanha #1: ranking recalculado (1 participantes).
Campanha #1: ranking recalculado (1 participantes). — placar final, campanha fechada.
Nenhuma campanha a recalcular no momento.
```

## No admin

A coluna "Tipo" virou **"Situação"** (Agendada / Em andamento / Encerrada / Desligada,
derivada das datas com a mesma leitura da tela do usuário). A coluna "Status" (Ativa/Inativa)
saiu por dizer a mesma coisa pior.

## Cenário ainda não coberto

Duas campanhas rodando ao mesmo tempo: a consulta pega a de `data_fim` mais próxima e ignora
a outra, sem avisar ninguém. Hoje não acontece porque só existe uma cadastrada, mas se virar
rotina criar a próxima antes de a atual acabar, vale o admin alertar sobre a sobreposição.
