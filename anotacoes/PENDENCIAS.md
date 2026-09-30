# Pendências (atualizado em 29/09/2026)

Único arquivo de "o que falta". Só tem o que ainda está aberto: o que já foi resolvido
foi retirado (o que aconteceu e por quê está em `HISTORICO-CONSOLIDADO.md`).
Ao resolver um item, apague a linha daqui.

Arquivos que continuam em `anotacoes/`:

| Arquivo | Para quê |
|---|---|
| `PENDENCIAS.md` | este, o que falta |
| `HISTORICO-CONSOLIDADO.md` | por que o código é assim, incidentes, capacidade, rodadas de teste |
| `como-funciona-pagamento-gateway.md` | como o pagamento/gateway/split funciona hoje |
| `REFERENCIA-SHARKBOT.md` | captura do SharkBot + specs do que ainda não foi construído |
| `credenciais-ssh-hostinger.md` | acesso ao servidor (gitignored, nunca no GitHub) |

---

## 🔧 Falta no código (sem decisão pendente)

**Segurança e robustez**

1. **Crons sem trava de acesso.** Só 4 dos 9 têm a trava CLI/`CHAVE_SECRETA_CRON`
   (limpar_stories, limpar_remarketing_envios, metricas_admin, ranking). Faltam
   `cron_remarketing` (o mais grave: dispara mensagem real, risco de ban do bot),
   `cron_verificar_pix`, `cron_renovacao`, `cron_aviso_vencimento`,
   `cron_verificar_acessos`. Não há `.htaccess` em `cron/`. Antes de aplicar, ver como o
   crontab do hPanel chama cada um (todos já têm `flock`).
2. **Bot com token revogado** (Telegram responde `Not Found`/`Unauthorized`): ninguém é
   avisado e os crons tentam para sempre. Plano: coluna de saúde em `bots`, cron marca ao
   receber erro de autenticação, aviso no painel do dono.
3. Rotação de logs (`logs/*.log` crescem sem limite) e sweeps sem `LIMIT` em
   `cron_aviso_vencimento`, `cron_verificar_acessos`, `cron_renovacao` e na 2ª query de
   `cron_verificar_pix`.
4. Token de integração (Facebook/TikTok/UTMify) em texto claro no formulário de
   `traqueamento.php` (mesmo caso do token do bot: falta decidir, ver abaixo).
5. `webhook.php` ainda cria link de convite e manda mensagem antes do
   `http_response_code(200)`. Menos pesado que na era InfoPago, mas em volume alto ainda
   segura o worker.

**Telas e textos**

6. "semanal" no dropdown de periodicidade do `edicao_fluxo.js` (só 1 ocorrência da palavra).
7. Esconder a nota "PIX Recorrente disponível apenas para contas PJ".
8. `index.php` ignora `?erro=sem_permissao`: quem é barrado no admin cai no dashboard sem aviso.
9. 404 em português (`ErrorDocument`): hoje `/nao-existe` mostra "This Page Does Not Exist" da Hostinger.
10. Ranking diz "Atualiza a cada 60s" mas não há timer (o placar só muda ao recarregar ou quando o cron grava o cache).
11. Remarketing: filtro da lista diz "Não comprou/Comprou"; o formulário diz "Acessou e não comprou/comprou". Unificar. Cuidado: "não comprou" usa o vínculo da venda pelo ID do Telegram, que não enxerga as vendas do dataset sintético.
12. Formato de número: `admin/dashboard.php` mostra `766560`, o painel do usuário `766.530`. Padronizar milhar.
13. `admin/transacoes.php`: coluna com o ID numérico da venda (Consultar Venda exige esse ID, a tabela só mostra o TXID); gateway "-" e split "Pendente" em venda paga (a lista trata split 0/chave vazia como pendente, não como "sem split"); faixa de totais (volume, pagos, expirados, split pendente); `SELECT COUNT(*)` sem filtro (1,6 s a 6,8 mi de linhas) e paginador que não alcança o começo do histórico (a contagem é estimativa do banco).
14. Card "Receita líquida" do admin: rodapé fixo "Sua comissão acumulada" mesmo em "Hoje"; a taxa real varia (~5% nas vendas novas, ~30% no histórico) e a tela não diz. Quem vende não vê a comissão (os termos dizem que é informada no painel).
15. Dashboard: o botão "Personalizado" some mas o período custom fica sem nenhum botão marcado; o gráfico "Histórico total (últimos 12 meses)" não bate com o botão Total (vida inteira).
16. `fluxo_basico.php` diz "Meus Bots > editar o bot > Fluxo Conectado", mas o botão é "Configurar" e o campo é "Fluxo de conversa"; o modal diz "Preenche um formulário" e a página "Preencha as seções abaixo". Campo se chama "Fluxo padrão" em bot novo e "Fluxo de conversa" na edição.
17. Modal de link de rastreamento: "Preencha as informações da sua conta" e bot mostrado só como `@username`.
18. Cabeçalho "Campanhas de Rank..." cortado no menu do admin; botão Configurar de Gateways fica sob a barra inferior em janela baixa (~650x600).
19. Ranking: selo "Campanha oficial" e status "Em disputa" (o admin diz "Em andamento"); sem apelido aparece "Usuário #3" até na linha "você"; tarja NOVO no menu; faturamento da campanha (R$ 975 mil) aparece no dashboard sem dizer que é da campanha.
20. Login: números fixos "1.482 leads / 4 bots / 14,7%" (conferir se ainda estão no código).
21. Limpeza: rótulo "sem_credenciais", comentários do InfoPago; `storage/pix_recorrente_estado.json` ainda referenciado em `webhook.php:85` (provável código morto, confirmar antes de remover, senão vira tabela).
22. Campos `msg_instrucoes` / `msg_confirmado` do modo Básico não têm efeito.
23. Feed de atividade e sino não mostram log de fluxo (só venda, PIX gerado e lead). Filtro nas páginas Logs (717 páginas) e no log do admin.
24. Remarketing pagina por `OFFSET` (com 1 mi de leads pula 900 mil linhas por rodada): paginar por `id > último_id`. Export CSV de leads levou 36,6 s em `status=nao_pago`, perto do timeout de 60 s.
25. MySQL roda em UTC e só a aplicação corrige o fuso: qualquer escrita feita por fora (phpMyAdmin, hPanel) grava 3 h adiantada.

**Não existe ainda (specs em `REFERENCIA-SHARKBOT.md`)**

26. Bloco Condição (`clicked_button`/`paid`/`not_paid`) e a Seção 3 do plano de expansão (`leads_estado_fluxo`, `leads.variaveis_fluxo`, timeouts, Input do usuário).
27. Bio Link (`/b/{slug}`); TikTok Pixel reativado (`funcoes/traqueamento.php` e `traqueamento.php`, precisa de `content_type:'product'`); mapa de geolocalização (pausado, precisa de redirecionador HTTP).
28. Escala, só importa perto de 60 mil vendas/dia: paralelizar com `curl_multi` os crons de acessos, aviso e renovação (padrão do `cron_remarketing.php`; cada membro é uma sequência de passos dependentes: revogar link, banir, desbanir, avisar).

## ❓ Falta definir (decisão do Caio)

- Mascarar o token do bot (e os tokens de integração) na tela ou deixar visível.
- Blocos do editor de fluxo em português ou inglês.
- Escopo do modo Básico (em 29/09 ganhou menu de seções, Upsell/Downsell/Order Bump, Suporte, Ativo/Desligado, Resumo e vínculo de bots): ainda faltam Packs, Prévias, Assinatura/renovação fora do grafo, Top Assinantes (redundante com Ranking?), Conversões (duplica Traqueamento?) e Cache de mídia. (Botões coloridos, estilo do Telegram, já estão feitos.)
- Redirecionamento (nova tela, modelada na "Links e campanhas" da SharkBot; feita em 29/09): ficaram de fora Códigos de venda, Página intermediária e a aba Domínios. Domínio próprio hoje é só o que o admin cadastra em Administração > Configurações (o DNS de cada domínio precisa apontar pra este servidor). O endereço (slug) não muda depois de criado. "Filtrar robôs" só deixa de contar o clique; o robô continua indo pro bot (não é cloaking).
- Editor de fluxo: ordem de implementação, sequência de upsell, nomes das tabelas de estado.
- TikTok Pixel e mapa de geolocalização: voltam ou não.
- PIX recorrente: a venda inicial expira em 15 min mas o PIX da assinatura fica pagável ~6 dias; não há endpoint de cancelar assinatura (cancelar = deixar de pagar, acesso cai 2 dias após o fim do período).
- Migrar para VPS e quando (teto de 3 GB no plano compartilhado; gatilho: agir ao passar de 2,4 GB no hPanel).
- LGPD e revisão jurídica do `termos.php` (rascunho nunca revisado por advogado).
- Tela padrão do dashboard (8 dias) fica zerada se a última venda é mais antiga: precisa de aviso? Hoje não é problema com venda diária.

## 🧹 Antes do lançamento (dado de teste)

- Trocar a senha 123456 e a `CHAVE_SECRETA_CRON`; confirmar backup (nunca testada a restauração); preencher a marca em Administração > Identidade Visual.
- Apagar o dataset sintético de ~1 GB (Carlos 3 anos, bot_id=2, ~766 mil vendas e 1,09 mi de leads), os 10 fluxos vazios (ids 17-26), os ~20 fluxos duplicados "Novo fluxo", a conta bugtest (id_usuario=24), os Bug Test 22/23, contas `@teste-descartavel.invalid`, "TESTE DE CARGA - NAO APAGAR SEM AVISAR".
- Trocar o link de teste da Comunidade ("Grupo titulo", aponta para betterplannerbr.com) e a campanha de ranking "Teste titulo" (prêmios "Carro", "Xiome", "Mulher/Loira").
- Confirmar os 7 crons no crontab do hPanel (`crontab -l` não é visível por SSH), `log_errors` (estava Off) no hPanel.
- Apagar certificados físicos do InfoPago no servidor.
- Login com Google: código pronto, botão escondido até haver credencial (passo a passo no `INSTALACAO.md`, seção 8); não confirmado se já foi preenchido em produção.

## 🧪 Falta testar de verdade

- PIX recorrente semanal real de R$ 2 (evento de pagamento e status ATIVO, ciclo 2 com `TRANSACTION_CREATED`/`cycle=2`, 429 na reconsulta do webhook).
- Confirmar no painel da OmegaPay quais eventos assinar (limite de 20 webhooks por integração; usamos uma URL fixa) e o schema de `splits[]` com vários destinos (mapeado como `{pixKey, value}`, testar 80/20 e ler a resposta bruta).
- Fluxo id=41 (user 36, bot 15 @Claude_Gomes_bot) com Randomizer/Upsell/Downsell/Order Bump/Grupo/PIX: resultado nunca registrado. Bloco Grupo usa id falso; o PIX (R$ 2 recorrente semanal) é real. Em 29/09 o fluxo passou a usar todos os tipos de bloco e a mandar "📍 Passou pelo bloco: X" após cada um (39 blocos, 44 links).
- Modo Básico ponta a ponta com bot real, incluindo a cadeia upsell → downsell → order bump (só simulada em PHP local: valores e callbacks conferidos, nunca no Telegram) e a tela nova (menu de seções, vínculo de bots, Resumo) que não foi aberta no navegador. Auto-save ao abrir fluxo só foi conferido no código, não no navegador.
- Redirecionamento: testado em PHP local (32 checagens de criar/editar/validar/isolamento entre usuários/vendas, e `l.php` com sequencial, robô, HEAD, inativo, 404) e com Apache local (regra do `.htaccess`); a tela foi vista em navegador headless com API simulada. Nunca rodou no servidor de verdade: falta clicar num link real, ver o `/start rd_slug` chegar no bot (contadores starts/leads e vendas no `webhook.php`) e testar com domínio extra e no celular.
- Passe visual no navegador: Stories (incl. lixeira), Comunidade, Sino, Webhooks (5 falhas desativam, HMAC), Cor primária, drag-and-drop e mobile.
- Segurança: XSS em `admin/logs`, rate limit da recuperação de senha, abuso de regra de negócio (plano negativo, split acima de 100%), escalonamento em `editar_usuario`, reuso de CSRF.
- Estresse: carga sustentada, tráfego misto, crons durante a carga, latência real do gateway.
- `cron_metricas_admin.php --completo` nunca foi executado no servidor.
