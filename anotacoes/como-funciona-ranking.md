# Como funciona o Ranking neste projeto

Nota de referência (não é TODO) pra não precisar reler tudo do zero da próxima vez. Criado em 2026-09-16, pensando em escala (700+ usuários) — por isso o desenho todo evita recalcular ranking a cada visita à página.

## Ideia central: cache pré-calculado, não cálculo ao vivo

`ranking.php` **nunca** faz `SUM(valor) GROUP BY usuário` na hora que alguém abre a página. Ele só lê uma tabela pequena e já pronta (`ranking_cache`), que é recalculada de tempos em tempos por um cron. Isso significa que 700 usuários abrindo a tela ao mesmo tempo custam 700 leituras por chave primária, não 700 agregações pesadas.

A tela já assumia isso desde o design ("Atualiza a cada 60s") — o cron só cumpre essa promessa de verdade.

## As 3 tabelas

- **`campanhas_ranking`** — cadastro da campanha: `slug` (único), `titulo`, `subtitulo`, `tipo` (`oficial` ou `mensal`), `data_inicio`/`data_fim`, `ativa`. Gerenciado 100% pela UI em `admin/ranking.php` — nunca precisa mexer em SQL na mão pra lançar campanha nova.
- **`campanhas_ranking_premios`** — até 5 linhas por campanha (`posicao` 1–5, `titulo`, `descricao`). É o que vira a lista "Manifesto de viagem" na tela.
- **`ranking_cache`** — `(campanha_id, id_usuario)` como chave primária, guarda `faturamento` e `posicao` já calculados. **Essa é a única tabela que `ranking.php` lê pra montar o placar.**

## O cron — `cron/cron_ranking.php`

Roda (precisa estar no **crontab da Hostinger a cada 1 minuto**, chamando `cron/cron_ranking.php` — isso ainda não tá configurado lá, só testado manualmente na linha de comando) e faz, pra cada campanha com `ativa=1` e `NOW()` dentro do período:

1. `DELETE FROM ranking_cache WHERE campanha_id = ?`
2. `INSERT ... SELECT` com `RANK() OVER (ORDER BY SUM(valor) DESC, MIN(criado_em) ASC)` direto no MySQL (confirmado versão 8.3, suporta window function nativa — nada de gambiarra com variável de sessão).
3. Faturamento = soma de `vendas.valor` com `status='pago'`, no intervalo `data_inicio`–`data_fim` da campanha, agrupado por `bots.id_usuario` (ou seja, ranking é **por usuário**, somando todos os bots dele).
4. Desempate: quem bateu aquele faturamento primeiro (`MIN(criado_em)`) fica na frente — não é por ID.

`DELETE`+`INSERT` inteiro dentro de uma transação. No tamanho real dessa tabela (no máx. ~700 linhas por campanha) isso é instantâneo, por isso não tem lógica de upsert incremental — mais simples e sem risco de ficar com linha "fantasma" de alguém que saiu do ranking.

Log próprio em `logs/cron_ranking.log`. Se o cron não rodar (esquecer de colocar no crontab, por exemplo), **nada quebra** — o ranking só fica congelado no último cálculo.

## Apelido público (`usuarios.apelido_publico`)

O ranking mostra faturamento de um usuário pros outros verem — decisão consciente de não expor nome real/e-mail nessa tela. Por isso:

- Usuário configura em **Minha Conta** (`configuracao_usuario.php`) "como quer aparecer no Ranking".
- Se não configurar, cai em `"Usuário #<ID>"` (nunca mostra nome/e-mail de cadastro).
- `funcoes/ranking.php::nomeExibicaoRanking()` centraliza essa regra — qualquer tela nova que precise mostrar alguém no ranking deve usar essa função, não ler `usuarios.nome` direto.

## Leitura da tela — `funcoes/ranking.php`

Funções (reaproveitar em vez de escrever query nova):

| Função | O que faz |
|---|---|
| `buscarCampanhaAtiva(string $tipo = 'oficial')` | Campanha ativa agora, por tipo (`oficial` ou `mensal`). `null` se nenhuma. |
| `buscarPremiosCampanha(int $campanha_id)` | Os até-5 prêmios cadastrados. |
| `buscarRankingCampanha(int $campanha_id, int $usuario_atual_id)` | Top 3, linhas 4–10, a posição do usuário logado (`null` se ele ainda não pontuou), total de participantes, faturamento do líder e do 5º colocado. |
| `nomeExibicaoRanking()` / `iniciaisRanking()` | Nome de exibição (apelido ou fallback) e iniciais pro avatar. |
| `formatarReaisResumido()` | `R$ 1,8 mi` / `R$ 541,2 mil` — formatação curta pro placar. |

`ranking.php` (a view) calcula em cima disso: contagem regressiva (a partir de `campanha.data_fim`), barra de progresso até o Top 5, e o mapeamento visual do pódio (tamanho/altura/cor por posição 1º/2º/3º) — isso é só apresentação, não fica salvo como dado.

## Estados vazios tratados

- Sem campanha ativa → aviso "Nenhuma campanha em andamento", esconde o resto da tela.
- Campanha ativa mas ninguém pontuou ainda → aviso no lugar do pódio.
- Usuário logado sem venda paga no período → "Sua posição" e "Passe do competidor" mostram aviso em vez de quebrar/mostrar zero.

## Admin de campanhas — `admin/ranking.php`

CRUD simples (criar/editar campanha + os 5 prêmios num formulário só, ativar/desativar). Sem exclusão definitiva pela UI de propósito — desativar (`ativa=0`) já tira a campanha do cálculo do cron e da tela, exclusão de verdade cascade apaga `ranking_cache`/prêmios junto (`ON DELETE CASCADE`), então só via banco direto se precisar mesmo.

## Ranking mensal / Ligas Coyote (ainda não implementado)

A tela já tem as abas "Ranking mensal" e "Minhas ligas", mas só a aba "oficial" busca dado real hoje:

- **Ranking mensal**: o schema já suporta (`tipo='mensal'`) — falta só um processo (cron ou o próprio `admin/ranking.php`) que crie automaticamente uma campanha `mensal` no início de cada mês (data_inicio = dia 1, data_fim = último dia). Não existe ainda.
- **Minhas ligas**: botão desabilitado na tela ("Em breve"). Precisaria de tabelas novas (`ligas`, `ligas_membros`) — não desenhado ainda, é feature futura.

## Mapa de arquivos-chave

| Responsabilidade | Arquivo |
|---|---|
| Funções de leitura do ranking | `funcoes/ranking.php` |
| Tela pública do ranking | `ranking.php` |
| Recalcula `ranking_cache` (cron) | `cron/cron_ranking.php` |
| CRUD de campanha/prêmios | `admin/ranking.php` |
| Apelido público (form + validação) | `configuracao_usuario.php`, `funcoes/usuario.php::atualizarPerfilUsuario()` |
| Schema (tabelas + coluna + índice) | `admin/atualiza_banco.php` |

## ⚠️ Pontos de atenção

1. **🔴 `cron/cron_ranking.php` não está no crontab ainda** — só rodado manualmente até agora. Sem ele no crontab da Hostinger (a cada 1 min), o ranking para de atualizar sozinho. **Atenção**: os outros `cron_*.php` foram movidos de "na raiz" pra dentro de `cron/` — se o crontab da Hostinger já tinha entrada pra eles apontando pro caminho antigo, essas entradas precisam ser atualizadas também, senão pararam de rodar quando o arquivo foi movido.
2. **🟡 Mesma pendência de segurança que os outros crons** (ver `varredura-06-cron-sem-autenticacao.md`) — fica na raiz pública, sem checar `php_sapi_name()==='cli'` nem chave secreta.
3. **Ranking mensal e Ligas** ainda não têm lógica real por trás (ver seção acima) — as abas na tela hoje não trocam de conteúdo.
