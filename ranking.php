<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/ranking.php';
verificarLogin();

$dados = buscarDadosRankingStub();
$campanha = $dados['campanha'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Ranking</h1>
                <p>Campanha oficial de faturamento — acompanhe sua posição em tempo real.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="abas-status" style="margin-bottom:14px;">
            <span class="aba-status ativa">Dubai 2026</span>
            <span class="aba-status">Ranking mensal</span>
            <span class="aba-status">Minhas ligas</span>
        </div>

        <div class="grade-ranking">
            <div>
                <div class="hero-ranking">
                    <div class="hero-ranking-halo"></div>
                    <img src="assets/img/coyote-logo.jpg" alt="" class="hero-ranking-logo">
                    <div class="hero-ranking-conteudo">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <span class="pill-campanha" style="background:var(--orsoft);color:var(--or);">Campanha oficial</span>
                            <span class="pill-campanha" style="background:var(--oksoft);color:var(--ok);"><span class="ponto-vivo"></span>Em disputa</span>
                        </div>
                        <div class="hero-ranking-eyebrow">Brasil · Emirados Árabes</div>
                        <h2 class="hero-ranking-titulo">A rota para <span style="color:var(--or);">Dubai</span></h2>
                        <p class="hero-ranking-desc">Cinco posições, cinco passaportes. Cada venda aprovada aproxima você do embarque.</p>
                        <div class="hero-ranking-meta">
                            <span><?php echo htmlspecialchars($campanha['periodo']); ?></span>
                            <span><?php echo number_format($campanha['participantes'], 0, ',', '.'); ?> participantes</span>
                        </div>
                    </div>
                </div>

                <div class="painel">
                    <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">
                        <span class="rotulo-kpi">Classificação oficial</span>
                        <div style="flex:1;"></div>
                        <span style="display:flex;align-items:center;gap:7px;font:700 11px 'Manrope',sans-serif;color:var(--ok);"><span class="ponto-vivo"></span>Atualiza a cada 60s</span>
                    </div>
                    <h2 style="margin:6px 0 0;font-size:28px;">Placar ao vivo</h2>

                    <div class="podio-ranking">
                        <?php foreach ($dados['podium'] as $p): ?>
                            <div>
                                <div class="podio-avatar" style="width:<?php echo $p['tamanho']; ?>;height:<?php echo $p['tamanho']; ?>;border-color:<?php echo $p['cor']; ?>;color:<?php echo $p['cor']; ?>;"><?php echo htmlspecialchars($p['iniciais']); ?></div>
                                <div class="podio-nome"><?php echo htmlspecialchars($p['nome']); ?></div>
                                <div class="podio-valor"><?php echo htmlspecialchars($p['valor']); ?></div>
                                <div class="podio-base" style="height:<?php echo $p['altura']; ?>;background:<?php echo $p['fundo']; ?>;">
                                    <span class="podio-posicao" style="color:<?php echo $p['cor']; ?>;"><?php echo htmlspecialchars($p['pos']); ?></span>
                                    <span class="podio-tag"><?php echo htmlspecialchars($p['tag']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cabecalho-ranking-lista">
                        <span style="width:46px;">Posição</span><span style="flex:1;">Competidor</span><span>Faturamento</span>
                    </div>
                    <?php foreach ($dados['linhas'] as $r): ?>
                        <div class="linha-ranking">
                            <span class="linha-ranking-pos"><?php echo htmlspecialchars($r['pos']); ?></span>
                            <div class="linha-ranking-avatar"><?php echo htmlspecialchars($r['iniciais']); ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="linha-ranking-nome"><?php echo htmlspecialchars($r['nome']); ?></div>
                                <div class="linha-ranking-zona" style="color:<?php echo $r['zona_cor']; ?>;"><?php echo htmlspecialchars($r['zona']); ?></div>
                            </div>
                            <div>
                                <div class="linha-ranking-valor"><?php echo htmlspecialchars($r['valor']); ?></div>
                                <div class="linha-ranking-gap"><?php echo htmlspecialchars($r['gap']); ?> do líder</div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="font:800 10px 'Manrope',sans-serif;letter-spacing:.12em;text-transform:uppercase;color:var(--m);margin:22px 4px 8px;">Sua posição</div>
                    <div class="faixa-sua-posicao">
                        <span class="linha-ranking-pos"><?php echo (int) $dados['sua_posicao']['pos']; ?></span>
                        <div class="linha-ranking-avatar"><?php echo htmlspecialchars($dados['sua_posicao']['iniciais']); ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="linha-ranking-nome"><?php echo htmlspecialchars($dados['sua_posicao']['nome']); ?></div>
                            <div class="linha-ranking-zona texto-suave">Sua conta</div>
                        </div>
                        <div>
                            <div class="linha-ranking-valor"><?php echo htmlspecialchars($dados['sua_posicao']['valor']); ?></div>
                            <div class="linha-ranking-gap"><?php echo htmlspecialchars($dados['sua_posicao']['gap_top5']); ?> do Top 5</div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="painel">
                    <div class="cartao-passe-titulo">
                        <span class="rotulo-kpi">Passe do competidor</span>
                        <span class="badge badge-neutro">DXB 2026</span>
                    </div>
                    <div style="font:800 15px 'Manrope',sans-serif;margin-top:12px;"><?php echo htmlspecialchars($dados['sua_posicao']['nome']); ?></div>
                    <div class="rotulo-kpi" style="margin-top:18px;">Sua posição</div>
                    <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-top:4px;">
                        <span class="passe-posicao"><?php echo (int) $dados['sua_posicao']['pos']; ?></span>
                        <div style="margin-bottom:8px;">
                            <div class="rotulo-kpi">Faturamento</div>
                            <div class="mono" style="font-weight:700;font-size:15px;margin-top:3px;"><?php echo htmlspecialchars($dados['sua_posicao']['valor']); ?></div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--m);margin:16px 0 6px;">
                        <span><?php echo htmlspecialchars($dados['sua_posicao']['gap_top5']); ?> para o Top 5</span>
                        <span><?php echo (int) $dados['progresso_top5_pct']; ?>%</span>
                    </div>
                    <div class="barra-progresso"><div class="barra-progresso-fill" style="width:<?php echo (int) $dados['progresso_top5_pct']; ?>%;"></div></div>

                    <div class="rotulo-kpi" style="margin:20px 0 8px;">A corrida termina em</div>
                    <div class="grade-contagem">
                        <?php foreach ($dados['contagem'] as $c): ?>
                            <div class="caixa-contagem">
                                <div class="caixa-contagem-valor"><?php echo htmlspecialchars($c['v']); ?></div>
                                <div class="caixa-contagem-label"><?php echo htmlspecialchars($c['l']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="painel">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--or)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"></path></svg>
                        <span class="rotulo-kpi">Manifesto de viagem</span>
                    </div>
                    <h2 style="margin:8px 0 16px;font-size:22px;">Cinco vagas para Dubai</h2>
                    <div class="lista-premios">
                        <?php foreach ($dados['premios'] as $p): ?>
                            <div class="item-premio">
                                <span class="item-premio-tag" style="background:<?php echo $p['fundo']; ?>;color:<?php echo $p['cor']; ?>;"><?php echo htmlspecialchars($p['tag']); ?></span>
                                <div>
                                    <div class="item-premio-label"><?php echo htmlspecialchars($p['label']); ?></div>
                                    <div class="item-premio-desc"><?php echo htmlspecialchars($p['desc']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="texto-suave" style="margin:14px 0 0;line-height:1.55;">Os cinco maiores faturamentos aprovados no período garantem classificação para a premiação oficial.</p>
                </div>

                <div class="painel">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <span class="rotulo-kpi">Ligas Coyote</span>
                        <span class="badge badge-sucesso">Disponível</span>
                    </div>
                    <p class="texto-suave" style="margin:10px 0 14px;line-height:1.55;">Crie ou entre em uma liga privada com outros competidores e dispute um ranking à parte.</p>
                    <button type="button" class="botao botao-bloco" disabled title="Em breve">Abrir minhas ligas</button>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/tema.js"></script>
</body>
</html>
