# Observações — como o sistema funciona, decisões e armadilhas (atualizado em 08/10/2026)

Único arquivo de "por que é assim". O que falta está em `PENDENCIAS.md`; o resultado dos testes, em `TESTES.md`.

---

## 1. Pagamento e gateway

- **Só OmegaPayments** (`gatewaysSuportados()` = `['omegapayments']`; linhas antigas de EFI/PushinPay/InfoPago ficam na tabela `gateways` só pelo histórico de vendas). A InfoPago foi removida 100% do código em 25/09.
- **Credencial é de cada usuário** (tela `gateways.php`, headers `x-public-key`/`x-secret-key`, sem certificado). O dinheiro cai **na conta do próprio usuário**; a comissão da plataforma sai pelo **split nativo** (`splits: [{producerId, amount}]` na própria cobrança, `amount` em reais). `producerId` é o **ID da conta** OmegaPayments do recebedor (não é chave Pix); a coluna `usuarios_splits.chave_pix_split` guarda esse ID (nome histórico). Que o destino do split seja o admin é **convenção**: nada no código obriga.
- **Limites medidos em 06/10** (podem mudar, não estão na doc): Pix mínimo R$ 1,50, tarifa R$ 0,70 por Pix, e a API recusa se split + tarifa > valor. Com o split atual, o menor preço vendável é **R$ 2,10** (`precoMinimoOmegaPayments()`, `validarPrecosFluxoGuiado()`). Salvar fluxo abaixo do mínimo é recusado.
- **Endpoints**: base `https://app.omegapayments.com.br/api/v1`; cobrança `POST /gateway/pix/receive` (`dueDate` em ISO UTC completo; `client.document` obrigatório — usa o CNPJ fixo `CNPJ_PIX_RECORRENTE_FIXO`); consulta `GET /gateway/transactions?id=`; assinatura `POST /gateway/pix/subscription` (aceita split; WEEKS existe). Status: `PENDING`, `COMPLETED` (= pago, confirmado com Pix real), `FAILED`, `REFUNDED`, `CHARGED_BACK`, `EXPIRED`. Polling demais devolve **429** (`retryAfterSeconds: 300`): o cron só consulta a cada 5 min.
- **Gerar**: bloco Pix (Visual) ou plano/pack/renovação (Guiado) → `criarCobranca()` → `vendas` com `status='gerado'` → mensagem com código, **QR Code gerado no próprio servidor** (`funcoes/qrcode.php`, sem site externo), botão **Copiar** (`copy_text`, até 256 caracteres) e "Já fiz o pagamento". No Guiado, QR e Copiar são opções da seção Pagamentos (QR ligado por padrão; `qrcode_definido` marca escolha explícita).
- **Confirmar — 3 caminhos, todos reconsultam a API antes de liberar** (nunca confiam no aviso): webhook `webhook_omegapayments.php`, botão "Já fiz o pagamento" (`webhook.php`) e `cron_verificar_pix.php` (rede de segurança, a cada 5 min). A troca para `pago` é atômica (`UPDATE ... WHERE status != 'pago'` + `rowCount()`), então nunca processa duas vezes. Depois: atividade/notificação → evento de compra (Facebook/UTMify, `event_id` = txid) → webhooks de saída → mensagem → convite do grupo → fluxo "pago".
- **Grupo**: convite de **uso único, 15 min**, revoga o anterior, estende a partir da expiração atual. Plano sem grupo = paga e não recebe link (não há aviso no editor).
- **Assinatura recorrente** existe (OmegaPayments): **(09/10: opção OCULTA nas telas — Guiado e bloco Pix do Visual — pela constante `RECORRENCIA_LIBERADA_NA_TELA = false` no topo de `assets/edicao_fluxo.js` e `assets/edicao_fluxo_basico.js`; o motor segue funcionando e fluxos que já têm recorrência continuam mostrando a opção. Para liberar, trocar para `true` nos dois arquivos.)** (desde 09/10 também no **Fluxo Guiado**: cada plano tem "Tipo de cobrança" = Pagamento único ou Assinatura recorrente + periodicidade; o acesso dura o período, o botão mostra "/semana", "/mês"…, e o order bump não se aplica a plano recorrente) ciclo 2+ cria venda filha pela `id_assinatura`, acesso nunca abaixo do fim do ciclo do gateway. **Renovação manual** (`cron_renovacao.php`) gera Pix 3 dias antes, só para assinatura sem `id_assinatura` (o sistema atual quase não cria mais).
- Credenciais cifradas (AES) com `CHAVE_CRIPTOGRAFIA_GATEWAYS` do `config.php` — **sem essa constante tudo fica em texto puro** e um backup do banco restaurado sem ela não decifra os gateways.

## 2. Fluxos (motor)

- Dois editores: **Guiado** (`fluxo_basico.php`, formulário; tudo dentro de `dados_fluxograma`, sem tabelas novas) e **Visual** (`fluxo.php`, 13 blocos). Uma só "caminhada" do grafo em `funcoes/execucao_fluxo.php`; envio de cada bloco em `processarEEnviarBloco()` (`funcoes/fluxo_blocos.php`).
- **Delay**: até 5 s dorme; 6–10 s solta o Telegram (`litespeed_finish_request`) e dorme; acima, grava em `fluxo_continuacoes` e o cron de 1 min retoma (precisão ~1 min, máx. 7 dias). `/start` novo cancela esperas.
- **Ofertas** (upsell/downsell/order bump): a escolha fica em `fluxo_ofertas_aceitas` (12 h) e o próximo Pix aplica desconto/extra, nunca abaixo do mínimo do gateway.
- **Prévias, auto-deletar, "sumir após clique"**: fila `mensagens_agendadas` (o cron apaga até ~1 min depois; o Telegram só deixa apagar até 48 h). Prévia vista 1 vez por 24 h (`previas_vistas`).
- **Pix pendente sem bloco de fluxo expira em silêncio** (`cron_verificar_pix.php`): qualquer venda `gerado` sem `id_operador_fluxo` vence pelo `tempo_expiracao_minutos` (inclui o Pix de renovação manual do `cron_renovacao`, que antes nunca expirava e ocupava o LIMIT 200 do polling). Pagamento tardio de venda `expirado` ainda libera o acesso. `cron_verificar_acessos` relê o membro antes de banir, então renovação confirmada durante a rodada não derruba quem acabou de pagar.
- **Avisos de assinatura**: marcos 5/2/0 dias e horários, sem repetir (`avisos_assinatura_enviados`, o marco leva a data — renovar reinicia). Sem avisos configurados vale o aviso genérico (<24 h; planos em minutos: 5/15 min).
- **Expiração**: compra única sai na hora; assinatura tem **2 dias de carência**; "Expulsar do grupo" desligado encerra sem banir. Bot sem permissão de banir: o dono é avisado no sino (1x/dia) e o cron segue tentando.
- **Texto do cliente fora dos botões** não tem resposta no Guiado. Ciclo A↔B no grafo envia até ~200 mensagens (limite de passos).

## 3. Rastreio, redirecionamento e traqueamento

- **Links de rastreamento**: `t.me/<bot>?start=<id>` direto; grava `origem_rastreio` no lead só no 1º toque; conta starts/leads. Sem clique nem robô.
- **Redirecionamento** (`/l/<slug>`, `l.php`): para tráfego pago. Domínio próprio opcional (admin cadastra; DNS aponta para o servidor), vários bots (aleatório ou sequencial), conta clique por dia e por `utm_campaign`, "filtrar robôs" só deixa de **contar** (o robô vai para o bot igual — não é cloaking). Manda `?start=rd_<slug>`. Slug não muda depois de criado. LiteSpeed entrega `HEAD` como `GET` (conta clique).
- **Traqueamento**: Facebook Conversions API e UTMify por usuário; eventos: **Pix gerado** (`InitiateCheckout` / `waiting_payment`) e **compra** (`Purchase` / `paid`) pelos 3 caminhos e renovações. Telegram não dá e-mail/IP: o `user_data` usa nome/telefone/origem (`montarUserDataTraqueamento()`). TikTok tem código pronto mas está **desligado** (falta `content_type` e conta para validar). Log em `logs/traqueamento.log` com tokens mascarados.

## 4. E-mail

- SMTP próprio (`funcoes/email.php`, sem biblioteca), configurado em Admin → Configurações → Envio de e-mail. **A Hostinger bloqueia o `mail()` do PHP: sem SMTP nada sai** (fila espera sem gastar tentativa).
- Fila `fila_emails` esvaziada pelo `cron_aviso_vencimento.php` (1 min, até 25 por rodada); 5 tentativas (1/5/15/60 min). O código de recuperação de senha sai na hora e vale 15 min.
- Confirmação de e-mail: token de 256 bits (só o hash no banco), 48 h, uso único; **não é obrigatória** (só aviso no menu). Recuperação de senha: código de 6 dígitos preso na sessão de quem pediu, resposta igual exista ou não o e-mail, 5 tentativas/15 min. `url_base` fixa evita forjar link pelo cabeçalho Host.
- Configurar na Hostinger: caixa `no-reply@dominio`, `smtp.hostinger.com:465` SSL, "Enviar teste", conferir spam (SPF/DKIM).

## 5. Ranking

- Nunca calcula ao vivo: `cron_ranking.php` grava `ranking_cache` (RANK por faturamento pago no período, conta pela **data de criação** da venda). Uma campanha em cartaz: em andamento > última encerrada > próxima agendada; o resultado final fica no ar **até a próxima começar**. Entre o fim e o fechamento pelo cron aparece "Apurando". Mudar as datas de uma campanha fechada reabre a apuração. Corte da "zona de premiação" = maior posição premiada.
- **Mensal**: automático, zera no dia 1, lê `metricas_horarias_usuario` (cron de métricas a cada 5 min).
- Só o **apelido** aparece (nunca nome/e-mail); sem apelido, "Usuário #id".

**Admin → Campanhas de Ranking: classificação e ganhadores (13/10/2026).** Tela própria, `admin/ranking_classificacao.php?id=ID` (a lista de campanhas `admin/ranking.php` ficou só com a lista: o troféu e o título de cada campanha levam para ela; o menu "Campanhas" continua marcado). Segue o padrão do painel: título + "‹ Voltar para Campanhas de Ranking" (igual ao Remarketing), 3 cards (participantes, faturamento somado, placar parcial/final), barra de busca por nome/e-mail/apelido com abas **Todos | Premiados** e **uma tabela só** (posição com medalha, vendedor com e-mail, apelido público, faturamento, prêmio em selo; linhas premiadas com marca laranja; 25 por página). Quem ganhou = aba "Premiados" (ou "Pódio" se a campanha não tem prêmio cadastrado). O admin vê nome e e-mail reais; o vendedor só vê o apelido público. Lê do `ranking_cache`. Só administrador. **O botão "Exportar CSV" está comentado** (a pedido); a rota `?id=ID&exportar=csv` continua viva (com BOM, 6.022 linhas no teste) e o botão volta tirando o comentário no cabeçalho do arquivo. A função `situacaoCampanhaRanking()` agora fica em `funcoes/ranking.php`. Testado na VPS com 6.021 participantes (busca, abas, paginação, id inexistente/parâmetros malucos/XSS, vendedor barrado) e conferido em tela no desktop e no celular.

**Sino de notificações e erro em modal (13/10/2026).** (1) Abrir o sino não pode mexer a tela: antes ele punha `overflow: hidden` no `<html>`, a barra de rolagem sumia e todo o conteúdo andava ~15 px para a direita. Agora a página atrás é travada só barrando roda/toque no JS (`barrarRolagem` em `assets/js/notificacoes.js`; a lista do sino continua rolando) e a barra fica onde está. Medido no navegador: largura útil, sino, título e cards idênticos antes/depois de abrir; a roda não rola a página com o sino aberto e volta a rolar ao fechar. (2) Modal que reabre depois de erro do servidor (Comunidade, Campanhas de Ranking; o Webhooks já fazia) mostra o aviso **dentro do modal**, no topo do corpo, e só mostra fora dele se o modal não estiver aberto (antes o erro ficava escondido atrás do modal).

## 6. Crons

| Arquivo | Frequência | Faz |
|---|---|---|
| `cron_verificar_pix` | 1 min (consulta o gateway a cada 5) | confirma Pix pendente, expira os vencidos (ramo "não pago") |
| `cron_verificar_acessos` | 1 min | remove do grupo quem venceu (carência de assinatura) |
| `cron_aviso_vencimento` | 1 min | avisos, **fila de e-mails**, retomada de delays, apagar prévias |
| `cron_ranking` | 1 min | placar das campanhas |
| `cron_remarketing` | 1 min | campanhas agendadas (`curl_multi`, teto de 40 s por rodada, para após 10 falhas seguidas de bot — falha do destinatário não conta) |
| `cron_metricas_admin` | 5 min | cache do dashboard e do ranking mensal (`--completo` refaz o histórico) |
| `cron_renovacao` | 1 h | Pix de renovação manual |
| `cron_rotacionar_logs` | 1x/dia | rotaciona `logs/*.log` acima de 5 MB (guarda `.1`–`.3`); crons ociosos (acessos, avisos, ranking) não gravam mais linha quando não há nada a fazer |
| `cron_limpar_stories`, `cron_limpar_remarketing_envios` | 1x/dia | limpeza |

**Só rodam pela linha de comando ou URL com `CHAVE_SECRETA_CRON`** (`funcoes/cron_guarda.php`; recusas em `logs/cron_acesso_negado.log`). Todos têm `flock`. Na VPS: bloco pronto de `crontab` no `INSTALACAO.md`. O cron do Pix **não grava log quando não há nada a fazer** (log parado ≠ cron parado: veja se vendas `gerado` antigas viraram `expirado`).

## 6b. Operação em escala (08/10/2026) — `funcoes/operacao.php`

Funções carregadas por `conexao.php` (existem em toda página, webhook e cron):
- **`leads.pagou`**: "esse lead já comprou?" fica no próprio lead, com índice `(bot_id, pagou, criado_em)`. É marcado por `aposPagamentoConfirmado()` nos **3 caminhos** que confirmam pagamento (webhook da gateway, botão "Já fiz o pagamento", cron). Os filtros Pagou/Não pagou de Leads e o público do remarketing leem essa coluna via `condicaoLeadPagou()` (sem a coluna, cai no EXISTS por venda, mais lento). Antes cada filtro procurava venda lead por lead: com 200 mil leads, 0,7–5 s e piorando com o volume; agora ~0,2–0,4 s. Venda excluída ou estornada não desmarca o lead (hoje não existe estorno).
- **Venda sem grupo** (Guiado, plano/pack sem grupo vinculado): o cliente recebe só a mensagem de aprovado e **o dono é avisado no sino ("Venda sem grupo"), no máximo 1x por dia**. No editor, o campo de grupo mostra o aviso fixo "Sem grupo…" até um grupo ser escolhido.
- **Token de bot recusado**: todo ponto que fala com o Telegram passa a resposta por `registrarRespostaTelegram()`; **401** ou **404 "Not Found"** marca `bots.token_invalido_em` e avisa o dono uma vez ("Bot desconectado"). O card do bot mostra o selo "Token recusado"; salvar token novo limpa o aviso. Erros comuns de destinatário (403, 400 "chat not found") **não** contam. O cron de remarketing também passa as respostas por essa função.
- **Starts do dashboard (`leads_starts`, 09/10)**: antes "Total starts" contava `leads.criado_em`, e lead só nasce uma vez; quem já era lead e voltava a comprar aparecia como venda com 0 starts. Agora todo /start (novo ou de quem voltou) grava uma linha em `leads_starts` (`registrarInicioConversa()`, repetição do mesmo lead em < 10 min não conta). Dashboard (card, gráfico) e o cache `metricas_horarias_usuario.qtd_leads` leem dessa tabela. Na criação da tabela (Atualizar Banco) cada lead existente vira 1 start na data em que nasceu, então o histórico antigo não muda; quem voltou antes de 09/10 não tem como ser recuperado.
- **Página Vendas (`vendas.php`, 09/10)**: registro das vendas do próprio usuário (bots dele; admin continua em Admin → Transações). Padrão de leads.php: ids da página primeiro, depois os dados; a busca cobre cliente (nome resolvido em `leads`, até 200 ids), ID do Telegram (exato) e código da transação (prefixo) — nome do produto não entra na busca de propósito (LIKE %x% em vendas ~5 s em 766 mil linhas). Não mostra split/comissão da plataforma. **Tipo da venda (calculado na leitura, sem coluna nova):** "Renovação" = o cliente já tinha uma compra paga anterior do MESMO plano (mesmo `nome_produto` ou mesmo grupo) ou é cobrança de ciclo de assinatura (`venda_pai_id`); "Recompra" = já era cliente mas comprou outra coisa; sem selo = primeira compra. A coluna Fluxo vem de `vendas.id_fluxo` (fluxo conectado ao bot no momento da venda; vendas antigas sem ele mostram —).
- **Contagem em cache**: `contagemEmCache()` guarda por 60 s (arquivo em `storage/cache/`) o COUNT do Admin → Transações com filtro (era 390 ms e cresce com a base; agora ~90 ms nos cliques seguintes).
- **Logs**: `resumoPayloadGateway()` grava só evento/transação/status/valor/ciclo do webhook da gateway (sem dados do pagador nem código Pix); a verificação manual do botão deixou de gravar a resposta completa.
- Armadilha: o `patch.php` da bateria de fluxos fixa o isolamento das crons no id do bot de teste; rodar `seed.php` **antes** do `setup.sh` (o `all.sh` já faz).

## 6d. Menu lateral em seções (11/10/2026)

Pedido do cliente (referência: outro sistema com cabeçalhos tipo "AUTOMAÇÕES"). `barra_lateral.php` monta o vendedor em 5 seções — **Visão geral** (Dashboard, Vendas, Leads), **Automações** (Meus Bots, Fluxos, Remarketing, Redirecionamento), **Rastreamento** (Traqueamento, Links de Rastreamento, Webhooks), **Comunidade** (Ranking, Comunidade), **Conta** (Gateways, Minha Conta) — e o admin em Administração / Conta / Debug. Recolhida, cada título vira um traço separador entre os ícones; o menu "Mais" do celular usa os mesmos grupos. Para mover um item de seção, mude o array `$grupo_*` correspondente.

## 6c. VPS: o que muda em relação à hospedagem de homologação (11/10/2026)

- **Servidor web:** a homologação roda LiteSpeed (tolera `.htaccess` fora do padrão); a VPS usa **Apache**, que recusa `<DirectoryMatch>` em `.htaccess` (erro 500 em toda requisição). O bloqueio de `.git` virou `RewriteRule` e o `.htaccess` ficou válido nos dois.
- **MariaDB:** a homologação roda `sql_mode=NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` (**sem modo estrito**) e `READ-COMMITTED`. O padrão do MariaDB é estrito: texto maior que a coluna (ex.: nome de lead > 100 caracteres) falha o INSERT e o `/start` engole o erro (lead some). O `instalar.sh` aplica o mesmo `sql_mode` e isolamento. A hospedagem também cria índices `wdbi_*` sozinha (não fazem parte do schema; `idx_vendas_bot_criado` e `idx_membros_plano_ativo` os substituem na VPS) e usa `wait_timeout=20` (a VPS usa 3600).
- **Sessões:** o Ubuntu apaga sessão com mais de 24 min sem uso (cron do sistema, lê o php.ini); o painel usa 30 dias (`ini_set` em `funcoes/usuario.php`, que o cron do sistema não enxerga). O pool `painel` usa pasta de sessões própria e coleta pelo próprio PHP com `gc_maxlifetime=2592000`.
- **URL pública:** webhooks do Telegram, links de e-mail e o `callbackUrl` da OmegaPayments saem de `HTTP_HOST` na web; em cron (CLI) não há host. `url_base` (Admin → Configurações) passou a valer também para o `callbackUrl` das cobranças criadas por cron (renovação), que antes iam com `http://localhost`. O `instalar.sh` grava `url_base`.
- **Crons:** `/etc/cron.d/painel` como `www-data`, 10 rotinas. Atrás de proxy/Cloudflare o `REMOTE_ADDR` seria o do proxy (limite de login por IP): exigiria `mod_remoteip`.
- **Schema:** `deploy/schema.sql` = o que o instalador cria (39 tabelas), conferido contra o banco de homologação (11/10). O instalador não criava `avisos_assinatura_enviados`, `fluxo_continuacoes`, `fluxo_ofertas_aceitas`, `mensagens_agendadas`, `previas_vistas` nem `leads.id_fluxo`/`vendas.id_fluxo`/`vendas.nome_produto` (sem `leads.id_fluxo`, o `INSERT` de lead falha): corrigido.

**Seção Debug do admin (13/10/2026):** ficou só com **Consultar Venda** e **Testes de Desempenho**. "Atualizar Banco" e "Checklist de Testes" saíram do menu a pedido, mas as páginas continuam existindo pela URL (`admin/atualiza_banco` é usada pelo `atualizar.sh`, pelos testes e pelas mensagens "Rode Atualizar banco"; `admin/checklist_testes` é um checklist temporário e pode ser apagado quando o dono quiser). **Testes de Desempenho** ganhou a seção "Instalação e carga pesada na VPS — 13/10/2026" (cards, comparação hoje × com os ajustes, resistência/sobrecarga, gargalos, verificações e incidentes), copiada de `TESTES.md`/6g; quando os ajustes forem aplicados na VPS ou o teste for refeito, atualizar a página junto. Classe `grade-kpi-empilha` (cards em uma coluna no celular) vale para qualquer grade de cards com número grande.

**Gateways do admin (13/10/2026):** `gateways.php` mostra ao admin só o cartão de cima — liga/desliga o gateway **para os usuários** (`gateways.ativo`; desligado, ninguém vê nem usa) e o split da plataforma. A parte "de usuário" (credenciais, "Gateways Ativos"/"Gateways Disponíveis") não aparece mais para ele: o admin não opera bot (`bloquearAdmin()` barra bots, fluxos e vendas) e a cobrança, a cron de Pix, o webhook e o Consultar Venda sempre usam a credencial do **dono do bot**, nunca a do admin. O cartão ganhou a dica "Liberado para os usuários" / "Os usuários não veem este gateway". Os formulários de usuário (`salvar_user` etc.) seguem no PHP, só não têm tela para o admin. A caixa "Valores mínimos da OmegaPayments" (`avisoLimitesOmegaPayments()`, no modal do vendedor e no cartão do admin) lista mínimo da API, tarifa, **taxa da plataforma = o split padrão que o admin configurou, com o valor de agora** (ou "nenhuma no momento") e o menor preço aceito; a frase "Valores medidos em 06/10/2026…" fica numa linha própria.

## 6e. Controle do mantenedor da VPS (13/10/2026)

Pedido do dono (que vai manter a VPS do cliente): (1) saber **quem entra na VPS (Linux/SSH)**, de que IP e de onde; (2) poder **suspender o sistema por fora**, mesmo que ele e o cliente tenham as senhas e o cliente as troque e suma. *Primeiro entendi (1) como login do painel web — foi um engano, desfeito; nenhum código disso chegou ao servidor ou ao banco.*
- **Auditoria de SSH** = `deploy/vps/ssh-acessos.php`, serviço systemd que lê o journal do sshd (não mexe no SSH/PAM, então não há como se trancar fora). Grava JSONL em `/var/log/painel-ssh/`, cache de localização (ipwho.is, reserva ipinfo.io) em `/var/lib/painel-ssh/`, aviso por Telegram para IP novo (a cópia fora da VPS). Falhas são registradas mas só os IPs mais ativos são localizados no relatório (não estoura limite do provedor de geolocalização).
- **Suspensão remota** = comando assinado (RSA-SHA256/OpenSSL) para `/controle`; o servidor guarda só a chave **pública** (`CONTROLE_ID`, `CONTROLE_CHAVE_PUBLICA` no `config.php`, que na VPS é do root). Escolhi RSA e não Ed25519 porque o PHP da homologação e o XAMPP **não têm a extensão sodium** (têm openssl). Guarda em `conexao.php`, antes do banco, só HTTP: manutenção por SSH nunca é barrada. Dois modos: `painel` (telas fora; bots, links /l/ e pagamentos seguem) e `total` (bot e links também; webhook de pagamento, `verificar_pix` e `verificar_acessos` seguem para quem já pagou). Prazo opcional: suspensão temporária ou licença renovável. Falha de leitura do estado = ativo.
- **Limite que precisa ser dito ao cliente/dono:** quem tem root (ou a conta do provedor) desliga isso; a proteção real é você manter o root/a conta e o backup fora da VPS. Se a VPS ficar na conta do cliente, a suspensão só vale contra quem tem apenas o painel. Detalhes em `deploy/PLANO-VPS.md` seção 8.2.
- **Hora:** o servidor aceita comando a ±5 min e exige hora estritamente crescente; `enviar.php` usa a hora do servidor (um relógio adiantado deixaria "o último comando" no futuro e recusaria os seguintes).

## 6f. VPS real: Ubuntu 26.04 / PHP 8.5 (13/10/2026)

- A Hostinger entrega **Ubuntu 26.04** quando se escolhe "Ubuntu" sem versão e **não tem datacenter em São Paulo** no checkout (só EUA/Europa/Ásia; escolhida Boston, ~145 ms). O painel roda no **PHP 8.5** sem mudança de código além de remover `curl_close()` (deprecated desde o 8.5, sem efeito desde o 8.0) — 106 arquivos compilam sem aviso e 575 verificações de fluxo passam.
- O SSH do Ubuntu novo loga como **`sshd-session`** (não `sshd`): o auditor (`ssh-acessos.php`) e o fail2ban já cobrem (a jail filtra por `_SYSTEMD_UNIT=ssh.service`).
- O nome que a Hostinger dá à VPS (`srvNNN.hstgr.cloud`) aponta para o IP e aceita certificado Let's Encrypt: serve de endereço provisório até o cliente ter domínio (trocar com `instalar.sh --dominio NOVO`, seção 9.6 do plano). O `/etc/hosts` da VPS resolve esse nome para 127.0.1.1 — por isso o teste de DNS do instalador pergunta a um DNS público.
- `cmd | grep -q` com `set -o pipefail` dá falso negativo quando o comando escreve em várias partes (o PHP 8.5 faz isso no `php -m`/`php -i`): capture a saída numa variável antes. Vale para qualquer script novo.
- O `www-data` não lê `/root`: nada que rode como ele pode ficar em `/root/painel-src`.
- Backup: nunca nomear por minuto. O `painel-restaurar` tira um backup de segurança antes de importar; com nome igual ele destruía o arquivo escolhido.

## 6g. Gargalos medidos no teste de carga da VPS (13/10/2026) — números em `TESTES.md`, método em `tests/estresse/vps/README.md`

Ordem de impacto (o que travou primeiro):
1. **Processos do PHP-FPM (config).** O funil de vendas é limitado pelo número de processos, não pela CPU: cada webhook segura um processo ~2 s esperando Telegram e gateway. 48 processos → ~23 clientes/s (CPU 30%); 150 → ~65/s. Cada processo extra custa só ~3 MB. **O `instalar.sh` já calcula `RAM/50` (até 200) e dimensiona o Apache (3× os processos) e o banco; a VPS atual continua com 48/200** (aplicar: ver `deploy/PLANO-VPS.md` seção 14).
2. **`bots.token` não tem índice.** Todo webhook do Telegram faz **4 consultas por token, cada uma varrendo a tabela `bots` inteira** (`type: ALL`, 12 mil linhas) — em 10 pontos do código. Custo cresce com o número de bots. Com índice: webhook 395 → 2.173 req/s e funil (6 mil vendedores) 48,9 → 67,1 clientes/s. Correção: `ALTER TABLE bots ADD INDEX idx_bots_token (token)` em `instalacao.php`, `admin/atualiza_banco.php` e `deploy/schema.sql`. **Não aplicada** (decisão do dono).
3. **Ranking mensal refeito a cada abertura do dashboard e de `/ranking`** (`funcoes/ranking.php`, `buscarRankingMensal()`): 3 consultas que varrem as métricas de TODOS os vendedores (165 mil linhas com 6 mil vendedores). O dashboard cai de 30 req/s (2 mil vendedores) para 12 req/s (6 mil) e passa a ser o que limita o painel. Experimento só na cópia de teste — retrato do ranking de 60 s compartilhado, resposta idêntica em 25 usuários: dashboard 12 → 224 req/s, ranking 14 → 370, mistura de telas ~55 → 400. **Não aplicado.**
4. **Apache `MaxRequestWorkers 200`** se esgota (`AH00484`) quando os 150 processos do PHP e os usuários do painel disputam: com 600, o cenário 50 clientes/s + 800 usuários foi de 490 erros para 0.
5. **Login com bcrypt custo 12** (padrão do PHP 8.4+; o 8.1 usava 10): 204 ms por hash → **~9,4 logins/s no servidor todo**, e senha errada custa o mesmo: ~9 tentativas/s de um atacante ocupam ~90% da CPU e derrubam os demais de ~135 para ~80 req/s. O bloqueio é por e-mail; quem roda entre e-mails diferentes não é barrado. Sugestão: limite por IP (fail2ban lendo o log do painel, ou `mod_evasive`) e/ou custo 10.
6. **Sem idempotência no webhook.** Mesma atualização reenviada 4× (o que o Telegram faz quando o servidor demora) → **4 Pix**; 2 cliques simultâneos → 2 Pix (e, em 1 de 120 clientes, 2 leads). `/start` repetido ×5 → 1 lead (certo). Sob sobrecarga isso vira tempestade (timeout → reenvio → mais trabalho). Sugestão: ignorar `update_id` já visto (por bot, TTL curto) e reaproveitar o Pix pendente do mesmo lead e plano por ~60 s.
7. **Vendedor gigante** (2 mi de leads, 500 mil vendas, 600 mil notificações): `COUNT(*)` sobre milhões a cada tela; a contagem de notificações não lidas (1,26 mi de linhas) roda a cada 30 s com o painel aberto e leva 0,7 s; `OFFSET` profundo e `status=pagou` passam de 4 s. Sugestão: contadores/cache, "99+" com `LIMIT`, paginação por chave.
8. **Crons que crescem com os dados:** `cron_aviso_vencimento` (todo minuto) carrega todos os assinantes ativos na memória — 100 mil linhas, 300 MB e 2,7 s com 85 mil assinantes; `metricas_admin` 2,4 s a cada 5 min com 7,6 mi de starts. Sugestão: lotes e só quem vence nas próximas janelas. **E o log:** a linha 146 de `cron_aviso_vencimento.php` ("Analisando membro #…") escreve **uma linha por assinante ativo a cada minuto** (~120 bytes): com 114 mil assinantes foram **307 MB em ~13 minutos** (~10 MB/min); com 5 mil assinantes reais já seria ~0,9 GB/dia, e a rotação só roda 1×/dia (limite de 5 MB, 3 gerações), então o arquivo não tem teto entre uma rotação e outra. Sugestão: logar só o que importa (aviso enviado, erro) e um resumo por rodada. Na VPS os assinantes de teste foram marcados `status='expirado'` (com validade futura) para as crons reais os ignorarem, e o log foi esvaziado.
9. **`verificar.sh`** levava dezenas de minutos com milhares de usuários (`password_verify` em todos): agora verifica no máximo 30 (admins primeiro).
10. **Não ajudaram:** buffer pool de 4 GB (a CPU é o limite; 2 GB bastam até 3,2 GB de dados); mais conexões HTTP.

Outras lições do teste: a espera pela gateway domina tudo (Pix em 1,5 s corta a vazão pela metade); `/admin/usuarios` leva 1,8 s com 6 mil usuários; as métricas do dashboard só existem depois da cron `metricas_admin` (medir antes dá resultado falso); a cron real de Pix **consulta a gateway de verdade** para todo Pix "gerado" de qualquer usuário com credencial — nunca deixe Pix de teste "gerado" com as crons ligadas (aconteceu: 75 chamadas indevidas à OmegaPayments); pool do PHP-FPM nunca se recarrega sem `php-fpm -t` (derrubei o painel por ~1 min).

## 7. Segurança (modelo atual)

- **Arquivos só de inclusão (10/10):** o `.htaccess` da raiz responde 403 para `funcoes/*` (exceto `funcoes/usuario`, a API de recuperação de senha chamada por `login.php`), `parciais/*`, `config`, `conexao` e `tema_inline`, com ou sem `.php`. Antes eles executavam pela URL (corpo vazio, sem vazamento, mas desnecessário). Se um dia uma página chamar um arquivo de `funcoes/` por JS, ele precisa entrar na exceção.

- CSRF em todo POST; sessão regenerada no login; limite de 5 tentativas/15 min (login e recuperação); prepared statements em todo lugar (`EMULATE_PREPARES=false`); cookies `Secure`/`HttpOnly`/`SameSite=Lax`.
- `.htaccess` da raiz: URLs sem `.php` (exceto POST, webhooks, `api.php`, `ajax/`), bloqueia `.md`, `.bak*`, `error_log`, `.git`, `_ids.txt`; cabeçalhos anti-clickjacking, nosniff e HSTS. Pastas `logs/`, `storage/`, `uploads/` (não executa script), `certificados/`, `anotacoes/`, `seeds/` protegidas. O `/l/` manda `no-referrer` de propósito.
- Webhook do Telegram exige o segredo do bot (`X-Telegram-Bot-Api-Secret-Token`). Webhook do OmegaPayments não tem assinatura (o provedor não oferece) — mitigado pela reconsulta.
- Uploads exigem tipo real (imagem/vídeo), não só extensão. Caminho de mídia do fluxo validado (contra LFI).

## 8. Decisões de produto e de tela

- **SharkBot é referência visual, nunca cópia 1:1**: só leva o que faz sentido aqui; recurso dela sem equivalente é ignorado; recurso nosso que ela não tem é mantido. Já portados: modo Guiado (Básico), ofertas e sorteio no Visual, Stories, Comunidade, Webhooks de saída, sino, layout (sidebar recolhida, cabeçalho com faturamento, cards de bots/fluxos, ranking com passe e aba Mensal, redirecionamento "Links e campanhas"). **Não portados** (decisão): Ligas do ranking, multi-conta, Modo IA (atendimento por IA com créditos pré-pagos), domínios alternativos/cloaking.
- Ações de lista sempre em **ícone** (`btn-icon`, `iconeAcao()` em `funcoes/icones.php`), nunca texto; abas em `.abas-status/.aba-status`; liga/desliga em chave (`.chave-gateway`).
- Paginação sem recarregar só para lista que é um bloco dentro de outra página; quando a lista é o produto da página, recarregar é aceitável.
- Filtro "8 dias" = hoje + 7 dias (pedido original). Limites: 50 fluxos, 50 bots e 50 redirecionadores por usuário.
- Sidebar recolhida (abre no hover) só no desktop; mobile usa barra inferior + "Mais". Botão "Fixar sidebar" comentado em `barra_lateral.php`.

## 9. Armadilhas técnicas (já custaram tempo)

- **Nunca `git stash`/`pull`/`reset` às cegas no servidor** (apagou o `config.php` real em 19/09). Deploy = `pscp` só dos arquivos alterados, comparando antes com o do servidor. Backup antes de sobrescrever vai para `storage/backups-deploy/` (nunca `x.php.bak` ao lado do original: ficava baixável).
- O git do servidor de teste está dessincronizado (51 arquivos "sujos" contra o próprio índice, branches divergentes): não usar `git pull` lá.
- `wait_timeout` do MySQL é **20 s**: depois de qualquer espera, `garantirConexaoBanco()` (em `conexao.php`); quem recebe `$pdo` por parâmetro refaz `$pdo = $GLOBALS['pdo']`.
- MySQL em UTC, o PHP corrige o fuso (`America/Sao_Paulo`); escrita feita por fora (phpMyAdmin) fica 3 h adiantada.
- Hostinger: CPU da conta ≈ 1,7 núcleo; firewall bloqueia IP acima de ~60 req/s sustentados e limita ~40 conexões por IP; criou sozinha índices `wdbi_*` em `leads` que mudaram o plano da consulta (por isso Leads busca os ids da página primeiro).
- Arquivos com CRLF (`webhook.php`, crons): substituição multilinha por script precisa respeitar. O terminal Bash do Claude reduz `\\` para `\` em heredoc/`node -e` (escrever scripts em arquivo).
- Um bot só aceita **um** webhook: se ele apontar para outra plataforma (já aconteceu com a SharkBot), o painel não recebe nada.

## 10. Incidentes (com a lição)

- **18/09 — banco estourou a cota de 3 GB** num teste de 2 anos: a Hostinger corta INSERT/UPDATE (nem pagamento confirma). Resolvido apagando em lotes + `ALTER TABLE ... FORCE`. Lição: teste de carga cabe na cota; limpar logo.
- **19/09 — `config.php` sobrescrito** por um `git stash` (6h30 fora do ar). `config.php` saiu do git.
- **28/09 — cron de remarketing travado 3 h** com bot de token inválido: teto de 40 s por rodada e corte após 10 falhas seguidas.
- **02/10 — "Atualizar Banco" colidia com o cron de métricas** (erro 1020): retry nos INSERTs de métricas.
- **25/09 — site fora do ar no meio de um teste**: era o plano da hospedagem vencendo, não o sistema.
