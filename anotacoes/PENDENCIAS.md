# Pendências — o que falta (atualizado em 10/10/2026)

Único lugar de "o que falta". Ao resolver um item, apague a linha. Como as coisas funcionam: `OBSERVACOES.md`. Resultados de teste: `TESTES.md`.

---

## 🔴 Urgente / antes de lançar

1. **Configurar o SMTP** (o dono vai fazer depois — lembrar) (Admin → Configurações → Envio de e-mail): sem ele **nenhum e-mail sai** (cadastro, confirmação, recuperação de senha). Criar `no-reply@<domínio>` no hPanel, `smtp.hostinger.com:465` SSL, "Enviar teste", conferir spam/SPF/DKIM. Os e-mails parados na fila saem sozinhos depois.
2. **Senha SSH antiga (21/09) no histórico público do GitHub** (`anotacoes/varredura-15-completa.md`, commit `b5405ab`): já foi trocada; confirmar que não é usada em nenhum outro lugar. Apagar do histórico = reescrever o git (`git filter-repo`) + *force push* — só com decisão.
3. **VPS: instalada e validada (13/10/2026), falta a parte do cliente.** Hostinger KVM 2 Boston, IP 179.236.76.180, painel em `https://srv2059665.hstgr.cloud/login` (endereço provisório), Ubuntu 26.04/PHP 8.5; instalação em `deploy/PLANO-VPS.md` seção 13. O que falta: (a) o **domínio do cliente** (trocar com `instalar.sh --dominio NOVO`, seção 9.7, **antes de criar bots**); (b) **trocar as senhas** que passaram pelo chat (root da VPS; admin do painel está em `/root/painel-credenciais.txt` — guardar num cofre e apagar o arquivo e `/var/log/painel-instalacao.log`); (c) **chave SSH sua** + `--endurecer-ssh` (hoje o SSH ainda aceita senha); (d) **backup fora da VPS** (`/etc/painel/offsite.conf`, rclone) e **aviso de login por Telegram** (`/etc/painel/ssh-acessos.conf`); (e) configurar no painel: gateway OmegaPayments (credenciais + split 1,40 + chave Pix), SMTP, marca, bot e **1 venda real de R$ 2,10**; (f) a chave privada da suspensão remota está em `~/.painel-controle/vps-cliente/` **no PC do dono** — copiar para um cofre; (g) o painel e o webhook de teste ainda apontam para a homologação (o bot real @Claude_Gomes_bot continua lá).
3b. **Controle do mantenedor da VPS** (código pronto e testado em 13/10; falta usar): (a) **decidir quem fica com a conta/root da VPS** — se for a conta do cliente, a suspensão remota só vale contra quem tem apenas o painel (`deploy/PLANO-VPS.md` 8.2); (b) rodar `php deploy/controle/gerar-chaves.php CLIENTE --url=https://DOMINIO/controle`, **guardar a `privada.pem` num cofre** e passar `--controle-id`/`--controle-chave-publica` ao `instalar.sh`; (c) criar um bot no @BotFather e preencher `/etc/painel/ssh-acessos.conf` (Telegram) — sem isso o registro de logins SSH só existe dentro da VPS; (d) combinar no contrato a suspensão por inadimplência. Na 1ª execução real conferir `journalctl`/systemd do `painel-ssh-acessos` (nunca rodou numa VPS).
3c. **Escala — melhorias medidas em 13/10 (decidir quais aplicar; ganhos em `TESTES.md`, causas em `OBSERVACOES.md` 6g):**
   (1) **Índice em `bots.token`** — 1 linha em `instalacao.php`/`atualiza_banco.php`/`schema.sql`; webhook 5,5× mais rápido, funil +37%. (2) **Aplicar na VPS atual** o dimensionamento que o `instalar.sh` já traz (PHP-FPM 48→158 processos, Apache 200→475, banco 308 conexões): funil ~3×; passo a passo em `deploy/PLANO-VPS.md` seção 14. (3) **Cache do ranking** em `buscarRankingMensal()`: dashboard 12→224 req/s com 6 mil vendedores. (4) **Idempotência do webhook** (ignorar `update_id` repetido; reaproveitar Pix pendente do mesmo lead+plano ~60 s): hoje reenvio/duplo clique gera Pix em dobro. (5) **Limite de login por IP** (hoje ~9 tentativas/s ocupam 90% da CPU). (6) Crons em lotes (`aviso_vencimento` 300 MB) **e parar de logar 1 linha por assinante por minuto** (307 MB em 13 min com 114 mil assinantes; ~0,9 GB/dia com 5 mil reais — enche o disco). (7) Vendedor gigante: contadores/cache e "99+" nas notificações. Nenhuma foi aplicada ao código do painel; só o `instalar.sh` e o `verificar.sh` mudaram.
4. **Trocar segredos de teste**: senha `123456` (admin e contas de teste) e a `CHAVE_SECRETA_CRON`. Preencher a marca em Administração → Identidade Visual.
5. **Apagar dado de teste** (quando o dono liberar):
   - Teste de estresse: 300 usuários `clienteN@gmial.com` + `clienteadm@gmial.com`, 600 bots, 290 mil leads, 42 mil vendas, 114 mil notificações — o dono quis manter para olhar; para apagar, reenviar `tests/estresse/servidor/zz_estresse.php` e rodar `php zz_estresse.php del`. Aparecem no Ranking Mensal e nos totais do admin.
   - Dataset sintético de ~1 GB (Carlos Mendes, bot_id=2, ~766 mil vendas e 1,09 mi de leads), fluxos vazios/duplicados ("Novo fluxo", ids 17–26), conta bugtest (id 24), Bug Test 22/23, contas `@teste-descartavel.invalid`, "TESTE DE CARGA - NAO APAGAR SEM AVISAR".
   - Campanha de ranking "Teste titulo" (prêmios de teste) e o banner de teste dela; links da Comunidade de teste ("Grupo titulo" → betterplannerbr.com, "Grupo Telegram" → página do admin); fluxos #59/#60 de teste na conta caiogomesteodoro (#44 com Semanal a R$ 2,50).
   - **Dados do teste de carga na VPS (13/10)** — 6.021 vendedores `e1-N@estresse.invalid`/`e2-N@…`/`mega-1@…` (senha `Estresse#2026`), 12 mil bots `SIMBOT:…`, 5,6 mi de leads, 7,3 mi de starts, 1,5 mi de vendas, 1,36 mi de notificações, 3,2 GB. **Zerar só quando o dono mandar**: `/root/estado-zero` guarda o banco logo depois da instalação (`echo RESTAURAR | painel-restaurar /root/estado-zero/banco.sql.gz /root/estado-zero/arquivos.tar.gz`) ou `php /root/estresse/semear.php apagar --tag=e1` (e `e2`, `mega`, `s0`); passo a passo em `tests/estresse/vps/README.md`. Enquanto existirem, o painel da VPS mostra esses vendedores no Ranking e em Admin, e as crons de aviso/métricas ficam mais lentas.
   - `storage/backups-deploy/` no servidor (backups de código; apagar quando não servirem mais).
   - Certificados físicos antigos da InfoPago no servidor.
6. **Termos de uso / LGPD**: `termos.php` é rascunho nunca revisado por advogado.
7. **Login com Google**: código pronto (botão escondido sem credencial; passo a passo no `INSTALACAO.md` seção 8); confirmar as credenciais no ambiente final.

## 🧪 Falta testar de verdade

- **Remarketing com envio real** (texto, foto, vídeo; contadores; lead que bloqueou o bot): o bot real tem 2 leads (`Bruno`, `Gomes`) — só mandar se forem do dono/de teste. A correção de "falha do destinatário não derruba a campanha" foi testada só por teste unitário (11 casos).
- **Facebook / UTMify com conta real**: a conta do dono não tem nada configurado; confirmar no Gerenciador de Eventos que a compra chega com valor e nome, sem duplicar.
- **E-mail real** (depois do SMTP): cadastro + link, recuperação de senha, "Reenviar" do menu. **Login com Google** no navegador.
- **Assinatura recorrente: liberar na tela** quando for testar (constante `RECORRENCIA_LIBERADA_NA_TELA` nos 2 editores, hoje `false`). Ela existe no motor e no fluxo "TESTE REAL 4" (id 1265) da conta caiogomesteodoro.
- **Assinatura recorrente real** (fluxo "TESTE REAL 4 · n8n" já criado no bot, 09/10: pagar o 1º ciclo e aguardar ~7 dias) (Pix semanal de R$ 2): evento de pagamento, ciclo 2 (`cycle=2`) e se o **split se repete nos ciclos** seguintes. Confirmar no painel da OmegaPayments quais eventos assinar e o split com vários destinos.
- **Redirecionamento com `/start` real** (clicar o `/l/<slug>` no Telegram e ver start/lead/venda somarem no link), com domínio extra e no celular.
- **Celular físico** (toque, teclado, rolagem): editores de fluxo, ranking, gateways, remarketing — só conferido por emulação (390 px) e capturas.
- **Causa do Pix recusado** pelo banco como "não é mais válido" (1º Pix de 08/10; gateway e provedor diziam ativo; não repetiu). Se voltar a acontecer, anotar banco/horário e levar ao suporte da OmegaPayments.
- `cron_metricas_admin.php --completo` nunca rodou no servidor.

## 🔧 Falta no código (sem decisão pendente)

**Robustez**
- `webhook.php` cria convite e manda mensagem antes de responder 200 ao Telegram (segura o worker em volume alto).
- Escala (Admin → Transações e Leads já resolvidos em 08/10): o filtro "só nome/ID" de Leads (busca `LIKE %texto%`) e a busca de Transações por TXID não usam índice; só importa com milhões de linhas. Remarketing pagina por `OFFSET` (com 1 mi de leads pula muito por rodada): paginar por `id > último`. CSV de leads em `nao_pago` levou 36 s (perto do timeout).
- `tokens_email` não apaga tokens expirados/usados. `storage/pix_recorrente_estado.json` ainda referenciado em `webhook.php` (provável código morto — confirmar antes de remover).

**Telas e textos**
- Admin → Transações: split "Pendente"/"—" ambíguo em venda sem split; faixa de totais. Card "Receita líquida": rodapé fixo "Sua comissão acumulada" mesmo em "Hoje"; a taxa real varia e a tela não diz; o vendedor não vê a comissão (os termos dizem que é informada no painel).
- Dashboard: período personalizado sem botão marcado; gráfico "últimos 12 meses" x botão "Total".
- Cabeçalho "Campanhas de Rank…" cortado no menu do admin; botão Configurar de Gateways sob a barra inferior em janela baixa (~650x600); "Links de Rastreamen…" cortado no menu.
- Ranking: sem apelido aparece "Usuário #3" até na linha "você"; faturamento da campanha aparece no dashboard sem dizer que é da campanha.
- Feed/sino sem log de fluxo (só venda, Pix gerado, lead); sem filtro nas páginas de Logs (700+ páginas). Conversões: `taxa_lead_pix` pode passar de 100%; funil de ofertas exigiria tabela de eventos.
- Auto-deletar do Visual tem até ~1 min de atraso (cron de 1 min).
- Venda/eventos: bloco Grupo/Link sem configuração manda a mensagem de erro de configuração ao cliente final; boas-vindas é texto puro (`*negrito*` aparece com asteriscos).

## ❓ Falta decidir (dono)

- Mascarar ou não o token do bot e os tokens de integração na tela.
- Blocos do editor em português ou inglês.
- Exigir confirmação de e-mail para usar o painel (hoje é só aviso).
- Apagar ou não a senha antiga do histórico do GitHub (item 2).
- Guiado: "Top Assinantes" (redundante com Ranking?), "Entrega específica para renovação", `{uf}` nos avisos (não há UF do lead), mensagem padrão para texto livre do cliente.
- Redirecionamento: Códigos de venda, Página intermediária, aba Domínios (ficaram de fora).
- Pix recorrente: não há como cancelar assinatura (cancelar = deixar de pagar; acesso cai 2 dias após o fim do período).
- Dashboard padrão (8 dias) zerado se a última venda é antiga: precisa de aviso?

## 💡 Não existe ainda (ideias com especificação)

- **Bio Link** (`/b/{slug}`, tipo Linktree, só domínio próprio): tabelas `bio_links` e `bio_link_itens` (título, URL, ícone, ordem), página `biolink.php`, rota pública `b.php`, item no menu.
- **TikTok Pixel**: código pronto e desligado (`funcoes/tiktok.php`); para religar: `properties.content_type = 'product'`, usar `montarUserDataTraqueamento()`, descomentar em `funcoes/traqueamento.php` e `traqueamento.php`. Precisa de conta TikTok Ads para validar.
- **Mapa de leads por estado** (pausado): o Telegram não expõe IP; só daria com um redirecionador HTTP próprio capturando IP antes do bot.
- **Bloco Condição** no Visual (`clicked_button`, `paid`, `not_paid`, saídas sim/não) — reaproveita o que o Pix já resolve.
- **Entrada do usuário / Condição com tempo** ("respondeu / não respondeu"): tabela `leads_estado_fluxo` (bot, lead, nó esperando, tipo, variável, `expira_em`; um lead espera uma coisa por vez), coluna `leads.variaveis_fluxo` (JSON, usado como `{variavel}` nos textos), checagem no início do `webhook.php` e cron novo de timeouts (1 min).
