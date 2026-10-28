<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();
$caminho_base = '../';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Testes de Capacidade e Segurança - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Testes de Capacidade e Segurança</h1>
                <p>Histórico das baterias de teste de capacidade e segurança, sempre no ambiente de teste (não afeta clientes reais).</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Rodadas registradas</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Data</th><th>Tipo</th><th>Onde foi feito</th><th>Resumo</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="mono">13/10/2026</td>
                            <td>Instalação + carga pesada (funil de vendas, painel, login, pagamentos, resistência, sobrecarga)</td>
                            <td><span class="badge badge-sucesso">VPS do cliente (KVM 2)</span></td>
                            <td><a href="#rodada-20261013">≈ 23 clientes/s hoje · ≈ 65/s com ajustes · 0 dados perdidos</a></td>
                        </tr>
                        <tr>
                            <td class="mono">07/10/2026</td>
                            <td>Estresse (carga, volume de dados, resistência)</td>
                            <td><span class="badge badge-alerta">Hospedagem compartilhada</span></td>
                            <td><a href="#rodada-20261007">≈ 50–58 req/s, 0 erros</a></td>
                        </tr>
                        <tr>
                            <td class="mono">02/10/2026</td>
                            <td>Capacidade e segurança</td>
                            <td><span class="badge badge-alerta">Hospedagem compartilhada</span> <span class="badge badge-neutro">VPS temporária (só pra comparar)</span></td>
                            <td><a href="#rodada-20261002">60 req/s na hospedagem · ~400 req/s na VPS</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="cabecalho-pagina" id="rodada-20261013" style="margin-top: 28px;">
            <div>
                <h1 style="font-size: 26px;">Instalação e carga pesada na VPS — 13/10/2026</h1>
                <p>Feito na <strong>VPS do cliente</strong> (Hostinger KVM 2: 2 vCPU, 8 GB, disco NVMe, Boston/EUA; Ubuntu 26.04, PHP 8.5, MariaDB 11.8, Apache), com o Telegram e a gateway <strong>simulados, mas com a espera real</strong> (Telegram 150 ms, gateway 700 ms). Não houve envio real nem Pix real.</p>
            </div>
        </div>

        <div class="grade-kpi grade-kpi-empilha">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg></div>
                    <span class="rotulo-kpi">Funil de vendas do bot</span>
                </div>
                <div class="valor-kpi">≈ 23 clientes/s</div>
                <div class="rodape-kpi"><span>Hoje · ≈ 65/s com os ajustes</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg></div>
                    <span class="rotulo-kpi">Vendas por dia</span>
                </div>
                <div class="valor-kpi">≈ 40–85 mil</div>
                <div class="rodape-kpi"><span>Hoje · ≈ 110–215 mil com ajustes</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                    <span class="rotulo-kpi">Vendedores no painel</span>
                </div>
                <div class="valor-kpi">≈ 400–1.000</div>
                <div class="rodape-kpi"><span>Ao mesmo tempo · ≈ 4.000 com ajustes</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"></path></svg></div>
                    <span class="rotulo-kpi">Confirmações de pagamento</span>
                </div>
                <div class="valor-kpi">≈ 70/s</div>
                <div class="rodape-kpi"><span>Sem perdas · ≈ 166/s com ajustes</span></div>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Como foi feito e como ler</h2>
            </div>
            <p>Base de teste: <strong>6.021 vendedores, 12 mil bots, 5,6 milhões de leads, 7,3 milhões de starts, 1,5 milhão de vendas e 1,36 milhão de notificações (3,2 GB)</strong>, mais 1 vendedor gigante (2 milhões de leads, 500 mil vendas, 600 mil notificações). "Cliente" = quem dá /start no bot; cerca de 25% pagam. Os dados de teste continuam na VPS e serão apagados antes do uso real.</p>
            <p style="margin-top: 12px;">Os números são do <strong>servidor</strong>: o Telegram (~30 mensagens/s por bot, ≈ 6 clientes/s por bot) e a gateway (já devolveu 429) têm limites próprios que podem mandar antes. <strong>Planeje usando metade</strong>. Vendas por dia é cálculo a partir do teto (anúncio e lançamento concentram tudo em poucas horas: pico de 5 a 10×; 25% de conversão), não medição com envio real. "Vendedores no painel" = cada um pedindo 1 página a cada 15 s. As confirmações de pagamento foram em rajada, com 20% repetidas: 0 perdidas, 0 duplicadas.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Por que esse é o teto</h2>
            </div>
            <p>Hoje o funil é limitado pelo <strong>número de processos do PHP</strong> (48), não pela CPU (só 30%): cada mensagem do bot segura um processo por ~2 s esperando Telegram e gateway. Com 150 processos (cada um custa só ~3 MB) o teto foi de ~23 para ~65 clientes/s. O instalador já dimensiona isso sozinho (RAM ÷ 50, até 200); a VPS atual continua com 48. A <strong>espera pela gateway domina tudo</strong>: com Pix em 1,5 s a vazão cai pela metade; com 300 ms sobe para ~57/s.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Hoje × com os ajustes</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Situação</th><th>Hoje (48 processos PHP, Apache 200)</th><th>Com os ajustes</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Funil de vendas — 2 mil vendedores</td><td>≈ 23 clientes/s</td><td>≈ 65 clientes/s (≈ 60 sem erro)</td></tr>
                        <tr><td>Funil — 6 mil vendedores, 5,6 mi de leads</td><td><span class="badge badge-neutro">não medido</span></td><td>≈ 67 clientes/s com o índice (45/s sem erro, p95 1,6 s)</td></tr>
                        <tr><td>Gateway lenta (Pix em 1,5 s)</td><td>≈ 11/s</td><td>≈ 34/s</td></tr>
                        <tr><td>Confirmações de pagamento em rajada</td><td>≈ 70/s</td><td>≈ 166/s (CPU ainda em 45%)</td></tr>
                        <tr><td>Painel — mistura de telas, 2 mil vendedores</td><td>≈ 135 req/s</td><td>≈ 500 req/s</td></tr>
                        <tr><td>Painel — 6 mil vendedores</td><td>≈ 55 req/s (dashboard 12/s)</td><td>≈ 400 req/s (dashboard 224/s)</td></tr>
                        <tr><td>Funil + painel juntos (6 mil vendedores)</td><td><span class="badge badge-sucesso">20 clientes/s + 300 usuários: ok</span> <span class="badge badge-alerta">35 + 500: colapsa (42% de timeouts)</span></td><td><span class="badge badge-sucesso">50 + 800: 0 erros</span> <span class="badge badge-alerta">70 + 1.200: degrada</span></td></tr>
                        <tr><td>Login (bcrypt custo 12)</td><td>≈ 9,4 logins/s no servidor todo</td><td>igual (só um limite por IP resolve)</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="texto-suave" style="margin-top: 12px;">"Ajustes" = 150 processos do PHP + índice em <code>bots.token</code> + cache do ranking + Apache com 475–600 conexões. Medido numa cópia de teste; ainda não aplicado na VPS.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Resistência, sobrecarga e volume de dados</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Situação</th><th>Resultado</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Resistência: 15 min a 40 clientes/s (36 mil clientes, 9 mil vendas)</td><td><span class="badge badge-sucesso">0 erros</span> <span class="texto-suave">latência igual do início ao fim (p50 331 ms, p95 1,5 s), memória estável em 3,4 GB, +52 MB de disco</span></td></tr>
                        <tr><td>Sobrecarga: 120 clientes/s por 60 s (5× o teto)</td><td><span class="badge badge-alerta">46% de timeout de 30 s</span> <span class="badge badge-sucesso">nenhum dado perdido</span> <span class="texto-suave">normal de novo ~35 s depois do fim</span></td></tr>
                        <tr><td>6 mil vendedores, 5,6 mi de leads, 3,2 GB no banco</td><td><span class="badge badge-sucesso">funciona</span> <span class="texto-suave">acima do buffer pool de 2 GB não perde nada, o limite é a CPU (4 GB de buffer pool não ajudou)</span></td></tr>
                        <tr><td>Vendedor gigante (2 mi de leads, 500 mil vendas)</td><td><span class="badge badge-alerta">cada tela 1–2 s</span> <span class="texto-suave">Leads "pagou" e página funda passam de 4 s; notificações (a cada 30 s com o painel aberto) 0,7 s</span></td></tr>
                        <tr><td>Admin → Usuários com 6 mil usuários</td><td><span class="badge badge-alerta">1,8 s</span></td></tr>
                        <tr><td>Tentativas de login com senha errada</td><td><span class="badge badge-alerta">≈ 9/s de um atacante ocupam ~90% da CPU</span> <span class="texto-suave">o bloqueio é por e-mail, não por IP</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Gargalos achados e situação</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Gargalo</th><th>Efeito medido</th><th>Situação</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Processos do PHP-FPM (48)</td><td>Funil trava em ~23 clientes/s com CPU em 30%</td><td><span class="badge badge-neutro">Instalador já corrige</span> <span class="badge badge-alerta">VPS atual ainda com 48</span></td></tr>
                        <tr><td>Sem índice em <code>bots.token</code></td><td>Cada webhook faz 4 consultas varrendo a tabela de bots inteira; com índice, o webhook vai de 395 para 2.173 req/s</td><td><span class="badge badge-alerta">Pendente de decisão</span></td></tr>
                        <tr><td>Ranking mensal refeito a cada abertura do dashboard e do /ranking</td><td>Dashboard cai de 30 para 12 req/s com 6 mil vendedores; com retrato de 60 s: 224 req/s</td><td><span class="badge badge-alerta">Pendente de decisão</span></td></tr>
                        <tr><td>Apache com 200 conexões</td><td>Esgota com 150 processos do PHP + usuários; com 600, de 490 erros para 0</td><td><span class="badge badge-alerta">Pendente (ajuste de configuração)</span></td></tr>
                        <tr><td>Login sem limite por IP</td><td>Cada tentativa errada custa 204 ms de CPU</td><td><span class="badge badge-alerta">Pendente (limite por IP ou custo 10)</span></td></tr>
                        <tr><td>Webhook sem proteção contra repetição</td><td>A mesma atualização reenviada 4× gerou 4 Pix; 2 cliques juntos, 2 Pix</td><td><span class="badge badge-alerta">Pendente de decisão</span></td></tr>
                        <tr><td>Vendedor gigante</td><td>Contagens sobre milhões de linhas a cada tela e página funda lenta</td><td><span class="badge badge-alerta">Pendente (contadores/cache)</span></td></tr>
                        <tr><td>Cron de aviso de vencimento</td><td>Escreve 1 linha de log por assinante a cada minuto (307 MB em 13 min com 114 mil assinantes; ~0,9 GB/dia com 5 mil reais) e carrega todos na memória</td><td><span class="badge badge-alerta">Pendente (logar só o que importa, lotes)</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Outras verificações do dia (instalação)</h2>
            </div>
            <ul style="line-height: 1.9; margin: 0;">
                <li><strong>Instalação do zero</strong> (instalador, reexecução, atualizador) e verificador: <span class="badge badge-sucesso">80 ok, 0 falhas</span></li>
                <li><strong>Bateria de fluxos do bot</strong> (Telegram e Pix simulados): <span class="badge badge-sucesso">575 passaram, 0 falharam</span></li>
                <li><strong>Rastreio de 46 telas</strong> pela web (admin, vendedor, anônimo, público): sem erro de PHP</li>
                <li>Backup e restauração, troca de domínio (ida e volta), reinício da VPS, fail2ban e 2.400 requisições: ok</li>
                <li><strong>Suspensão remota</strong> (31/31 no teste unitário e pela internet) e <strong>auditoria de login SSH</strong> com o journal real: ok</li>
                <li>A 1ª instalação achou <strong>9 problemas, todos corrigidos</strong> (o pior: o restaurador de backup restaurava o estado errado se o backup de segurança caísse no mesmo minuto).</li>
            </ul>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Incidentes durante o teste</h2>
            </div>
            <ul style="line-height: 1.9; margin: 0;">
                <li><strong>A cron real de Pix consultou a OmegaPayments de verdade</strong> para Pix de teste "gerado": 75 chamadas indevidas (73 com HTTP 401 por credencial falsa, 2 com 429), entre 16:00 e 16:10. Nada foi ao Telegram. Desde então as crons reais ficaram pausadas durante os testes e os Pix de teste eram expirados ao fim de cada rodada.</li>
                <li><strong>Painel fora do ar por ~1 min 10 s</strong> (18:42–18:43) ao ajustar o pool do PHP-FPM com uma configuração inválida. O script de ajuste agora valida antes de recarregar.</li>
            </ul>
            <p class="texto-suave" style="margin-top: 12px;">Método e como zerar os dados de teste: <code>tests/estresse/vps/README.md</code>. Resultados brutos só no computador local (fora do git). Números completos em <code>anotacoes/TESTES.md</code>, gargalos em <code>anotacoes/OBSERVACOES.md</code> (6g) e o que falta decidir em <code>anotacoes/PENDENCIAS.md</code> (3c).</p>
        </div>

        <div class="cabecalho-pagina" id="rodada-20261007" style="margin-top: 36px;">
            <div>
                <h1 style="font-size: 26px;">Teste de estresse — 07/10/2026</h1>
                <p>Feito na <strong>hospedagem compartilhada</strong> (a de hoje, Hostinger/LiteSpeed), com 300 usuários, 600 bots, 290 mil leads, 42 mil vendas e 114 mil notificações de teste. Não foi feito em VPS.</p>
            </div>
        </div>

        <div class="grade-kpi grade-kpi-empilha">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"></path></svg></div>
                    <span class="rotulo-kpi">Vazão máxima do painel</span>
                </div>
                <div class="valor-kpi">≈ 50–58 req/s</div>
                <div class="rodape-kpi"><span>Mistura realista de páginas e API, sem nenhum erro</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                    <span class="rotulo-kpi">Usuários simultâneos</span>
                </div>
                <div class="valor-kpi">≈ 400–1.500</div>
                <div class="rodape-kpi"><span>Conforme o ritmo: 1 página a cada 10 s (~400–500) ou a cada 20–30 s (~1.000–1.500)</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg></div>
                    <span class="rotulo-kpi">Logins por segundo</span>
                </div>
                <div class="valor-kpi">≈ 12</div>
                <div class="rodape-kpi"><span>O bcrypt consome CPU: 150 logins levam ~12 s</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg></div>
                    <span class="rotulo-kpi">Mensagens do Telegram</span>
                </div>
                <div class="valor-kpi">≈ 11/s</div>
                <div class="rodape-kpi"><span>Webhook, com Telegram simulado (≈ 40 mil por hora)</span></div>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Por que esse é o teto</h2>
            </div>
            <p>O servidor tem 48 núcleos, mas é dividido com outros clientes. Um teste de CPU puro mostrou que <strong>esta conta só consegue usar cerca de 1,7 núcleo</strong>, e é isso que limita tudo: páginas (~50–58 req/s), logins (~12/s) e webhooks (~11/s). Passando do teto o sistema <strong>não cai</strong>: as respostas ficam mais lentas (enfileiram) e não houve nenhum erro 5xx. A solução de maior efeito é mais CPU: um plano maior ou uma VPS (a rodada de 02/10 mediu ~400 req/s numa VPS de 2 núcleos).</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Rampa de carga (usuários pedindo páginas sem pausa)</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Simultâneos</th><th>req/s</th><th>p50</th><th>p95</th><th>Erros</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>5</td><td>33,9</td><td>54 ms</td><td>466 ms</td><td><span class="badge badge-sucesso">0</span></td></tr>
                        <tr><td>10</td><td>49,6</td><td>97 ms</td><td>508 ms</td><td><span class="badge badge-sucesso">0</span></td></tr>
                        <tr><td>15 <span class="texto-suave">(pico)</span></td><td>58,1</td><td>186 ms</td><td>579 ms</td><td><span class="badge badge-sucesso">0</span></td></tr>
                        <tr><td>25</td><td>50,7</td><td>404 ms</td><td>711 ms</td><td><span class="badge badge-sucesso">0</span></td></tr>
                        <tr><td>40</td><td>29,8</td><td>706 ms</td><td>2,7 s</td><td><span class="badge badge-sucesso">0</span> <span class="badge badge-alerta">lento</span></td></tr>
                        <tr><td>60</td><td>19,7</td><td>1,2 s</td><td>9,3 s</td><td><span class="badge badge-sucesso">0</span> <span class="badge badge-perigo">fila</span></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="texto-suave" style="margin-top: 12px;">Resistência: 4 minutos contínuos a ~25 req/s = 5.978 requisições, 0 erros, p99 de 1 s. Painel a 30 req/s junto com 5 mensagens/s do bot: p95 de 0,9 s, sem erro.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Volume de dados</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr><th>Situação</th><th>Resultado</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Vendedor com 200 mil leads e 30 mil vendas — telas de Leads e Dashboard</td><td><span class="badge badge-sucesso">menos de 1 s</span> <span class="texto-suave">(mais lenta: filtro "Pagos", 0,84 s)</span></td></tr>
                        <tr><td>100 mil notificações não lidas — sino</td><td><span class="badge badge-sucesso">138 ms</span></td></tr>
                        <tr><td>Exportar CSV de 200 mil leads</td><td><span class="badge badge-alerta">5,4 s</span> <span class="texto-suave">(3 ao mesmo tempo: 6,5 s)</span></td></tr>
                        <tr><td>Link público de redirecionamento (<code>/l/…</code>)</td><td><span class="badge badge-sucesso">≈ 66 cliques/s</span></td></tr>
                        <tr><td>Admin → Transações filtrado por "pago" (~810 mil vendas na base)</td><td><span class="badge badge-alerta">≈ 7 req/s, 650 ms</span> <span class="texto-suave">(o ponto mais lento do admin)</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Limites de proteção da hospedagem</h2>
            </div>
            <ul style="line-height: 1.9; margin: 0;">
                <li><strong>Bloqueio de IP por volume:</strong> ao passar de ~60 req/s de um só IP, o firewall bloqueou o IP de quem testava por mais de 7 minutos (menos de ~50). Protege contra ataque, mas vale lembrar em testes futuros.</li>
                <li><strong>Conexões simultâneas por IP:</strong> acima de ~40 conexões do mesmo IP, parte é recusada.</li>
                <li><strong>Risco ainda não testado:</strong> o Telegram envia os webhooks de poucos IPs; um pico de campanha pode acionar esses mesmos limites. Vale confirmar com o suporte da hospedagem.</li>
            </ul>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>O que fazer para aguentar mais</h2>
            </div>
            <ul style="line-height: 1.9; margin: 0;">
                <li><strong>Mais CPU</strong> (plano maior ou VPS): é o que mais muda o teto.</li>
                <li><strong>Cache curto no Dashboard</strong> (30–60 s por usuário): é a página mais pesada (≈ 11 req/s) e a primeira que todo mundo abre.</li>
                <li>Índice/ajuste na <strong>busca de leads por nome</strong> e no filtro de <strong>transações por status</strong> quando o volume crescer.</li>
                <li>Picos de <strong>logins</strong> (lançamento com milhares entrando juntos) vão enfileirar: ~12 por segundo.</li>
            </ul>
            <p class="texto-suave" style="margin-top: 12px;">Não medido: envio real ao Telegram, OmegaPayments, Facebook e UTMfy (o webhook foi medido com o Telegram simulado). Relatório completo: <code>anotacoes/TESTES.md</code>.</p>
        </div>

        <div class="cabecalho-pagina" id="rodada-20261002" style="margin-top: 36px;">
            <div>
                <h1 style="font-size: 26px;">Capacidade e segurança — 02/10/2026</h1>
                <p>Feita na <strong>hospedagem compartilhada</strong> (a de hoje) e, só para comparar, numa <strong>VPS temporária</strong> de 2 núcleos que foi removida depois.</p>
            </div>
        </div>

        <div class="grade-kpi grade-kpi-empilha">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"></path></svg></div>
                    <span class="rotulo-kpi">Hospedagem atual</span>
                </div>
                <div class="valor-kpi">60 req/s</div>
                <div class="rodape-kpi"><span>Sem erro, depois da correção (era ~20 antes)</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"></path></svg></div>
                    <span class="rotulo-kpi">Numa VPS própria</span>
                </div>
                <div class="valor-kpi">~400 req/s</div>
                <div class="rodape-kpi"><span>Medido numa VPS de 2 núcleos, sem erro</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg></div>
                    <span class="rotulo-kpi">Teto estimado de vendas/dia</span>
                </div>
                <div class="valor-kpi">~64.800</div>
                <div class="rodape-kpi"><span>Numa VPS — limite vem do cron, não do site</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
                    <span class="rotulo-kpi">Falhas de segurança</span>
                </div>
                <div class="valor-kpi">2 corrigidas</div>
                <div class="rodape-kpi"><span>Nenhuma em aberto no momento</span></div>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Como os testes foram feitos</h2>
            </div>
            <p>Tudo foi feito no site de teste (<code>telegram.stackcode.com.br</code>), nunca em dado de cliente real. Dois tipos de teste:</p>
            <ul style="line-height: 1.9; margin: 12px 0 16px;">
                <li><strong>Teste de carga</strong> — um programa dispara dezenas a centenas de requisições por segundo contra o site (como se muita gente acessasse ao mesmo tempo), subindo a quantidade aos poucos até aparecer erro. É assim que se descobre quantas pessoas o sistema aguenta ao mesmo tempo antes de travar.</li>
                <li><strong>Teste de segurança</strong> — simula tentativas comuns de ataque (tentar entrar sem senha, adivinhar senha várias vezes seguidas, injetar código malicioso em formulários, tentar virar administrador sem permissão) pra confirmar que o sistema recusa cada uma.</li>
            </ul>
            <p>Pra comparar com uma infraestrutura melhor, o mesmo teste de carga rodou numa VPS separada (cedida só pra essa medição), com o sistema instalado do zero, testado e <strong>depois removido</strong> — não ficou nada rodando lá.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Capacidade: antes e depois da correção</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Cenário</th>
                            <th>Antes</th>
                            <th>Depois</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Hospedagem compartilhada (hoje em produção)</td>
                            <td><span class="badge badge-perigo">~20 req/s já dava erro em 70%</span></td>
                            <td><span class="badge badge-sucesso">60 req/s testados sem nenhum erro</span></td>
                        </tr>
                        <tr>
                            <td>VPS própria (2 núcleos, só pra medir)</td>
                            <td class="texto-suave">não se aplica</td>
                            <td><span class="badge badge-sucesso">~400 req/s sem erro</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="texto-suave" style="margin-top: 12px;">O que causava o erro: cada vez que uma página abria, o sistema abria uma conexão nova com o banco de dados. Sob muito acesso ao mesmo tempo, a hospedagem recusava conexão nova demais. A correção passou a reaproveitar a conexão já aberta, em vez de abrir uma do zero a cada vez.</p>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Segurança: o que foi testado</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Teste</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Recuperar senha de outra pessoa (descobrir e-mails cadastrados, spam de e-mail)</td>
                            <td><span class="badge badge-sucesso">Corrigido</span></td>
                        </tr>
                        <tr>
                            <td>Entrar sem senha ou com senha de outra pessoa, tentando várias vezes</td>
                            <td><span class="badge badge-sucesso">Bloqueia na 6ª tentativa</span></td>
                        </tr>
                        <tr>
                            <td>Acessar páginas internas sem estar logado</td>
                            <td><span class="badge badge-sucesso">Sempre pediu login</span></td>
                        </tr>
                        <tr>
                            <td>Injetar código/comando malicioso em formulários</td>
                            <td><span class="badge badge-sucesso">Bloqueado em todos os testes</span></td>
                        </tr>
                        <tr>
                            <td>Usuário comum virar administrador</td>
                            <td><span class="badge badge-sucesso">Bloqueado</span></td>
                        </tr>
                        <tr>
                            <td>Burlar o limite de tamanho/tipo de arquivo no upload</td>
                            <td><span class="badge badge-sucesso">Bloqueado em 3 tentativas diferentes</span></td>
                        </tr>
                        <tr>
                            <td>Forjar aviso falso de pagamento pra liberar acesso de graça</td>
                            <td><span class="badge badge-sucesso">Sistema sempre confirma direto com o gateway antes de liberar</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="texto-suave" style="margin-top: 4px;">Resumo de todas as rodadas de teste em <code>anotacoes/TESTES.md</code>; o que ainda falta, em <code>anotacoes/PENDENCIAS.md</code> (repositório do projeto).</p>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
