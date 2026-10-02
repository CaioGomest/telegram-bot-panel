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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                <p>Resultado da bateria de testes feita em 02/10/2026 no ambiente de teste (não afeta clientes reais).</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="grade-kpi">
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

        <p class="texto-suave" style="margin-top: 4px;">Detalhe técnico completo de cada teste fica em <code>anotacoes/HISTORICO-CONSOLIDADO.md</code> (Rodada 12) e <code>anotacoes/PENDENCIAS.md</code>, no repositório do projeto.</p>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
