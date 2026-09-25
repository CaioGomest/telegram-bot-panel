# Histórico consolidado — anotacoes/urgente (até 2026-09-25)

Substitui os 8 arquivos que estavam soltos nesta pasta. Igual ao
`anotacoes/HISTORICO-CONSOLIDADO.md` (que juntou a raiz de `anotacoes/`), aqui a Seção 1
é a que importa no dia a dia — o resto é histórico/contexto de incidentes já resolvidos.

---

## 1. 🔴 Ainda pendente hoje

1. **Bot com token revogado não avisa ninguém.** Quando o Telegram revoga o token de um
   bot, ele para de vender e de responder — e hoje nada no sistema detecta isso. A
   tabela `bots` não tem coluna de saúde. Decisão recomendada (não implementada):
   adicionar uma coluna de status em `bots`, o cron marcar quando o Telegram responder
   erro de autenticação, e mostrar aviso visível no painel do dono do bot. Enquanto
   isso não existe, o cron de acesso continua tentando remover membros vencidos pra
   sempre sem sucesso (comportamento aceitável, é a decisão B que resolve o problema
   de raiz, não a A).
2. **As 4 mudanças pro motor escalar (nenhuma feita ainda):**
   - **Crons de acesso/aviso/renovação rodam item a item** (~1,5s cada, sequencial) —
     capacidade de ~40-50 remoções/min. Solução já existe no próprio projeto:
     `cron_remarketing.php` já usa `curl_multi` + `FOR UPDATE SKIP LOCKED`, é só
     replicar o padrão pros outros 3 crons.
   - **Varreduras sem `LIMIT`** em `cron_aviso_vencimento.php`,
     `cron_verificar_acessos.php`, `cron_renovacao.php` e na 2ª query de
     `cron_verificar_pix.php` (expiração de cobrança) — sem limite superior, não
     escala com volume. Correção pequena, maior prioridade por esforço×ganho.
   - **`storage/pix_recorrente_estado.json`** guarda estado de PIX recorrente de toda a
     plataforma num arquivo único sem lock — precisa virar tabela.
   - **Webhook de pagamento ainda faz trabalho síncrono antes de responder 200**
     (confirmado hoje: `createChatInviteLink` roda antes do `http_response_code(200)`
     em `webhook.php`). A parte mais pesada que motivou esse achado — o Cash-Out da
     InfoPago rodando antes da resposta — não existe mais (OmegaPayments faz split
     nativo dentro da própria cobrança, não depois). Mas criar link de convite +
     mandar mensagem pro Telegram continua no caminho síncrono, então o efeito
     "confirmação de pagamento segura a fila" ainda pode acontecer em volume alto,
     só que mais leve que antes. Vale reavaliar o tamanho real do problema, não
     assumir que sumiu.
3. **Teto de capacidade é o banco compartilhado da Hostinger (3 GB por banco, em todo
   plano compartilhado — trocar de plano dentro da Hostinger não resolve, só Cloud
   Startup a 6GB ou sair pra VPS/Cloudways tira o teto).** A ~1.000 vendas/dia o plano
   atual aguenta ~2 anos; a partir de 5.000/dia cai pra meses. Gatilho prático:
   monitorar o tamanho do banco no hPanel, agir ao passar de 2,4 GB (80% da cota). Ver
   Seção 3 pros números completos — esse item também está espelhado no
   `anotacoes/HISTORICO-CONSOLIDADO.md` (Seção 4).
4. **Dataset sintético de teste (~1 GB, bot "Carlos 3 anos", `bot_id=2`, 766 mil vendas
   e 1,09 milhão de leads) ainda ocupa a cota do banco.** Decisão de negócio pendente:
   apagar antes de entrar cliente real (devolve ~350 mil vendas de fôlego, de graça) ou
   manter pra inspeção visual.
5. **Itens menores nunca confirmados:**
   - `log_errors` continua `Off` em produção — precisa confirmar/mudar no hPanel (fora
     do alcance do código).
   - Senha padrão `123456` e `CHAVE_SECRETA_CRON` seguem sem trocar — intencional em
     ambiente de teste, mas precisa mudar antes de cliente real.
   - Backup do banco nunca foi confirmado nem teve restauração testada.
   - Marca do painel (nome/logo/favicon) segue no genérico — configurável em
     Administração → Identidade Visual, ninguém preencheu ainda.
   - 10 fluxos de teste vazios ("Novo fluxo", ids 17-26) criados sem querer na conta do
     admin durante testes de mobile — nunca houve confirmação pra apagar.
   - Export CSV de leads gerou 36,6s pra `status=nao_pago`, perto do timeout de 60s do
     proxy — trunca sem erro se a base crescer mais.
   - Remarketing pagina por `OFFSET`; com 1M de leads pula 900 mil linhas a cada
     rodada — resolver direito pede paginar por `id > último_id`.
   - `cron_aviso_vencimento.php` carrega tudo em memória, sem limite — não pesa hoje
     (tabela pequena), pesa com volume.
   - MySQL do servidor roda em UTC; só a aplicação corrige o fuso na leitura. Qualquer
     escrita de data feita por fora (phpMyAdmin, hPanel) grava 3h adiantada.
6. **Checklist de cron no crontab da Hostinger** — os 7 crons originais
   (`cron_verificar_pix`, `cron_ranking`, `cron_metricas_admin`, `cron_remarketing`,
   `cron_aviso_vencimento`, `cron_verificar_acessos`, `cron_renovacao`) foram cadastrados
   juntos numa sessão anterior, mas **isso não é reconfirmável por código** (SSH não
   expõe `crontab -l` nessa conta) — vale um clique em Cron Jobs no hPanel pra bater o
   olho de vez em quando. O 8º item que essa lista cobrava (`cron_retry_split.php`) **não
   existe mais** — foi apagado junto da remoção completa da InfoPago (2026-09-25), então
   não precisa mais ser cadastrado.

## 2. ✅ Resolvido / já não se aplica

- **Sino de notificações no header** — já implementado (`parciais/sino_notificacoes.php`,
  `ajax/notificacoes.php`, `assets/js/notificacoes.js`, coluna `atividades.lido_em`).
  Ficou pendente de fazer numa nota de 17/09; hoje está no ar.
- **Login com Google** — código pronto desde 21/09 (botão escondido até ter credencial).
  O passo a passo de criar as credenciais no Google Cloud Console foi incorporado ao
  `INSTALACAO.md` (Seção 8) — **mas não há confirmação de que as credenciais já foram
  preenchidas em produção**, só que o caminho está documentado.
- **Conta InfoPago compartilhada + risco de chargeback/PIX MED** — deixou de fazer
  sentido como pendência: a InfoPago foi 100% removida do sistema em 2026-09-25, e a
  OmegaPayments usa credencial própria por usuário desde o início (sem conta
  compartilhada). Ver `anotacoes/criticas/conta-infopago-unica-compartilhada.md`.

## 3. Histórico de incidentes (contexto, não pendência)

### Banco estourou a cota (18/09/2026)
Um teste de capacidade de 2 anos (5 milhões de vendas + 5 milhões de leads sintéticos)
levou o banco a **4.435 MB de uma cota de 3.072 MB**. A Hostinger revoga
INSERT/UPDATE/CREATE/INDEX automaticamente quando isso acontece (SELECT/DELETE
continuam liberados) — sintoma era erro `1142` ao salvar qualquer coisa, inclusive
confirmar pagamento. Resolvido apagando o dataset de teste em lotes e rodando
`ALTER TABLE ... FORCE` (que devolve espaço ao InnoDB, diferente de `DELETE` sozinho) —
banco caiu pra 1.033 MB e a Hostinger restaurou a escrita sozinha. **Lição:** teste de
carga nesse ambiente precisa caber na cota de 3 GB; gerar em volume menor e
extrapolar, e limpar logo depois de coletar os números.

### `config.php` sobrescrito em produção (19/09/2026, causado pelo Claude)
Um `git stash -q` cego dentro de um comando de deploy — sem checar o que havia de
modificação local no servidor — apagou as credenciais reais de produção (o
`config.php` do servidor "sobrevivia" só como modificação local não commitada, já que
na época o arquivo ainda estava versionado no git). Login caiu (500 em qualquer POST)
por ~6h30, restaurado via `git checkout stash@{0} -- config.php` (não copiado à mão,
pra não arriscar erro de transcrição na chave de criptografia). **Corrigido
estruturalmente:** `config.php` saiu do versionamento (`.gitignore` + `git rm --cached`)
nos dois lados, então um `git pull`/`git stash` no servidor não pode mais repetir isso.
**Lição permanente:** nunca rodar `git stash` em produção — "modificação local não
commitada" num servidor costuma ser a configuração real daquela máquina, não lixo.

## 4. Notas relacionadas (fora desta consolidação)

- `anotacoes/HISTORICO-CONSOLIDADO.md` — relatório da raiz de `anotacoes/`, mesma lógica
  de consolidação.
- `INSTALACAO.md` — guia de instalação, já incorpora o passo a passo de Login com Google.
- `anotacoes/criticas/conta-infopago-unica-compartilhada.md` — marcado resolvido.
- `anotacoes/pendente/` — pendências de sandbox OmegaPayments e outros planos, não
  tocado nesta consolidação.
