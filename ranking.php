<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/ranking.php';
verificarLogin();

$usuario_id = (int) $_SESSION['usuario_id'];
$campanha = buscarCampanhaAtiva('oficial');
$ranking = $campanha ? buscarRankingCampanha((int) $campanha['id'], $usuario_id) : null;
$premios = $campanha ? buscarPremiosCampanha((int) $campanha['id']) : [];

$contagem = [
    ['v' => '00', 'l' => 'dias'], ['v' => '00', 'l' => 'horas'], ['v' => '00', 'l' => 'min'], ['v' => '00', 'l' => 'seg'],
];
if ($campanha) {
    $fim_campanha = new DateTime($campanha['data_fim']);
    $agora = new DateTime();
    if ($agora < $fim_campanha) {
        $restante = $agora->diff($fim_campanha);
        $contagem = [
            ['v' => str_pad((string) $restante->days, 2, '0', STR_PAD_LEFT), 'l' => 'dias'],
            ['v' => str_pad((string) $restante->h, 2, '0', STR_PAD_LEFT), 'l' => 'horas'],
            ['v' => str_pad((string) $restante->i, 2, '0', STR_PAD_LEFT), 'l' => 'min'],
            ['v' => str_pad((string) $restante->s, 2, '0', STR_PAD_LEFT), 'l' => 'seg'],
        ];
    }
}

$progresso_top5_pct = 0;
if ($ranking && $ranking['sua_posicao'] && !empty($ranking['faturamento_top5'])) {
    $meu = (float) $ranking['sua_posicao']['faturamento'];
    $alvo = (float) $ranking['faturamento_top5'];
    $progresso_top5_pct = $alvo > 0 ? (int) min(100, round(($meu / $alvo) * 100)) : 100;
} elseif ($ranking && $ranking['sua_posicao'] && (int) $ranking['sua_posicao']['posicao'] <= 5) {
    $progresso_top5_pct = 100;
}

function estiloPodio(int $posicao): array
{
    if ($posicao === 1) {
        return ['tamanho' => '68px', 'altura' => '104px', 'cor' => 'var(--or)', 'fundo' => 'var(--orsoft)', 'tag' => 'Líder'];
    }
    return ['tamanho' => '52px', 'altura' => $posicao === 2 ? '74px' : '58px', 'cor' => 'var(--m)', 'fundo' => 'var(--p2)', 'tag' => 'Pódio'];
}

$podio_ordem_visual = [];
if ($ranking) {
    $podio_ordem_visual = count($ranking['top3']) === 3
        ? [$ranking['top3'][1], $ranking['top3'][0], $ranking['top3'][2]]
        : $ranking['top3'];
}
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

        <?php if (!$campanha): ?>
            <div class="estado-vazio">
                <h3>Nenhuma campanha em andamento</h3>
                <p>Quando o admin ativar uma campanha, o placar aparece aqui.</p>
            </div>
        <?php else: ?>

        <div class="abas-status" style="margin-bottom:14px;">
            <span class="aba-status ativa"><?php echo htmlspecialchars($campanha['titulo']); ?></span>
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
                        <h2 class="hero-ranking-titulo"><?php echo htmlspecialchars($campanha['titulo']); ?></h2>
                        <?php if ($campanha['subtitulo']): ?>
                            <p class="hero-ranking-desc"><?php echo htmlspecialchars($campanha['subtitulo']); ?></p>
                        <?php endif; ?>
                        <div class="hero-ranking-meta">
                            <span><?php echo date('d/m/Y', strtotime($campanha['data_inicio'])); ?> — <?php echo date('d/m/Y', strtotime($campanha['data_fim'])); ?></span>
                            <span><?php echo number_format($ranking['total_participantes'], 0, ',', '.'); ?> participantes</span>
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

                    <?php if (empty($ranking['top3'])): ?>
                        <div class="estado-vazio" style="margin-top:16px;">Ninguém pontuou nesta campanha ainda.</div>
                    <?php else: ?>
                        <div class="podio-ranking">
                            <?php foreach ($podio_ordem_visual as $p):
                                $estilo = estiloPodio((int) $p['posicao']);
                                $nome_exib = nomeExibicaoRanking($p['apelido_publico'], (int) $p['id_usuario']);
                            ?>
                                <div>
                                    <div class="podio-avatar" style="width:<?php echo $estilo['tamanho']; ?>;height:<?php echo $estilo['tamanho']; ?>;border-color:<?php echo $estilo['cor']; ?>;color:<?php echo $estilo['cor']; ?>;"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></div>
                                    <div class="podio-nome"><?php echo htmlspecialchars($nome_exib); ?></div>
                                    <div class="podio-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $p['faturamento'])); ?></div>
                                    <div class="podio-base" style="height:<?php echo $estilo['altura']; ?>;background:<?php echo $estilo['fundo']; ?>;">
                                        <span class="podio-posicao" style="color:<?php echo $estilo['cor']; ?>;"><?php echo str_pad((string) $p['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                                        <span class="podio-tag"><?php echo $estilo['tag']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($ranking['linhas']): ?>
                        <div class="cabecalho-ranking-lista">
                            <span style="width:46px;">Posição</span><span style="flex:1;">Competidor</span><span>Faturamento</span>
                        </div>
                        <?php foreach ($ranking['linhas'] as $r):
                            $nome_exib = nomeExibicaoRanking($r['apelido_publico'], (int) $r['id_usuario']);
                            $zona = (int) $r['posicao'] <= 5 ? 'Zona de embarque' : 'Em disputa';
                            $zona_cor = (int) $r['posicao'] <= 5 ? 'var(--ok)' : 'var(--m)';
                            $gap = $ranking['faturamento_lider'] !== null ? $ranking['faturamento_lider'] - (float) $r['faturamento'] : null;
                        ?>
                            <div class="linha-ranking">
                                <span class="linha-ranking-pos"><?php echo str_pad((string) $r['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                                <div class="linha-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div class="linha-ranking-nome"><?php echo htmlspecialchars($nome_exib); ?></div>
                                    <div class="linha-ranking-zona" style="color:<?php echo $zona_cor; ?>;"><?php echo $zona; ?></div>
                                </div>
                                <div>
                                    <div class="linha-ranking-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $r['faturamento'])); ?></div>
                                    <?php if ($gap !== null): ?>
                                        <div class="linha-ranking-gap"><?php echo htmlspecialchars(formatarReaisResumido($gap)); ?> do líder</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div style="font:800 10px 'Manrope',sans-serif;letter-spacing:.12em;text-transform:uppercase;color:var(--m);margin:22px 4px 8px;">Sua posição</div>
                    <?php if (!$ranking['sua_posicao']): ?>
                        <div class="estado-vazio">Você ainda não pontuou nesta campanha — sua primeira venda aprovada te coloca no placar.</div>
                    <?php else:
                        $meu_nome = nomeExibicaoRanking($ranking['sua_posicao']['apelido_publico'], $usuario_id);
                        $meu_gap_top5 = $ranking['faturamento_top5'] !== null ? max(0, $ranking['faturamento_top5'] - (float) $ranking['sua_posicao']['faturamento']) : null;
                    ?>
                    <div class="faixa-sua-posicao">
                        <span class="linha-ranking-pos"><?php echo str_pad((string) $ranking['sua_posicao']['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                        <div class="linha-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($meu_nome)); ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="linha-ranking-nome"><?php echo htmlspecialchars($meu_nome); ?></div>
                            <div class="linha-ranking-zona texto-suave">Sua conta</div>
                        </div>
                        <div>
                            <div class="linha-ranking-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $ranking['sua_posicao']['faturamento'])); ?></div>
                            <?php if ($meu_gap_top5 !== null): ?>
                                <div class="linha-ranking-gap"><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> do Top 5</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="painel">
                    <div class="cartao-passe-titulo">
                        <span class="rotulo-kpi">Passe do competidor</span>
                        <span class="badge badge-neutro"><?php echo htmlspecialchars($campanha['slug']); ?></span>
                    </div>
                    <?php if (!$ranking['sua_posicao']): ?>
                        <p class="texto-suave" style="margin-top:14px;">Faça sua primeira venda aprovada no período da campanha pra entrar no ranking.</p>
                    <?php else: ?>
                        <div style="font:800 15px 'Manrope',sans-serif;margin-top:12px;"><?php echo htmlspecialchars($meu_nome); ?></div>
                        <div class="rotulo-kpi" style="margin-top:18px;">Sua posição</div>
                        <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-top:4px;">
                            <span class="passe-posicao"><?php echo (int) $ranking['sua_posicao']['posicao']; ?></span>
                            <div style="margin-bottom:8px;">
                                <div class="rotulo-kpi">Faturamento</div>
                                <div class="mono" style="font-weight:700;font-size:15px;margin-top:3px;">R$ <?php echo number_format((float) $ranking['sua_posicao']['faturamento'], 2, ',', '.'); ?></div>
                            </div>
                        </div>
                        <?php if ($meu_gap_top5 !== null && $progresso_top5_pct < 100): ?>
                        <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--m);margin:16px 0 6px;">
                            <span><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> para o Top 5</span>
                            <span><?php echo $progresso_top5_pct; ?>%</span>
                        </div>
                        <div class="barra-progresso"><div class="barra-progresso-fill" style="width:<?php echo $progresso_top5_pct; ?>%;"></div></div>
                        <?php else: ?>
                        <div class="aviso aviso-sucesso" style="margin-top:16px;">Você está no Top 5! 🎉</div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="rotulo-kpi" style="margin:20px 0 8px;">A corrida termina em</div>
                    <div class="grade-contagem">
                        <?php foreach ($contagem as $c): ?>
                            <div class="caixa-contagem">
                                <div class="caixa-contagem-valor"><?php echo htmlspecialchars($c['v']); ?></div>
                                <div class="caixa-contagem-label"><?php echo htmlspecialchars($c['l']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($premios): ?>
                <div class="painel">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--or)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"></path></svg>
                        <span class="rotulo-kpi">Manifesto de viagem</span>
                    </div>
                    <h2 style="margin:8px 0 16px;font-size:22px;">Premiação da campanha</h2>
                    <div class="lista-premios">
                        <?php foreach ($premios as $p):
                            $eh_primeiro = (int) $p['posicao'] === 1;
                        ?>
                            <div class="item-premio">
                                <span class="item-premio-tag" style="background:<?php echo $eh_primeiro ? 'var(--orsoft)' : 'var(--p3)'; ?>;color:<?php echo $eh_primeiro ? 'var(--or)' : 'var(--m)'; ?>;">P<?php echo (int) $p['posicao']; ?></span>
                                <div>
                                    <div class="item-premio-label"><?php echo htmlspecialchars($p['titulo']); ?></div>
                                    <?php if ($p['descricao']): ?>
                                        <div class="item-premio-desc"><?php echo htmlspecialchars($p['descricao']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="texto-suave" style="margin:14px 0 0;line-height:1.55;">Os maiores faturamentos aprovados no período garantem classificação para a premiação oficial.</p>
                </div>
                <?php endif; ?>

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
        <?php endif; ?>
    </main>
</div>

<script src="assets/js/tema.js"></script>
</body>
</html>
