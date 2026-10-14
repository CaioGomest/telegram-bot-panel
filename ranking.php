<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/ranking.php';
bloquearAdmin();

$usuario_id = (int) $_SESSION['usuario_id'];
$campanha = buscarCampanhaVigente();
$estado = $campanha['estado'] ?? null;   // ativa | encerrada | agendada

// Duas abas: "Concurso" (a campanha do admin) e "Mensal" (quem mais vendeu no mês corrente, automático).
// Sem campanha cadastrada a tela abre direto no Mensal, que sempre existe.
$aba = (string) ($_GET['aba'] ?? ($campanha ? 'concurso' : 'mensal'));
if (!in_array($aba, ['concurso', 'mensal'], true) || ($aba === 'concurso' && !$campanha)) {
    $aba = $campanha ? 'concurso' : 'mensal';
}

$agora = new DateTime();
$n_corte = 5;            // até que posição vai a "zona de premiação" (a maior posição premiada; 5 no mensal)
$apurando = false;       // campanha acabou mas o cron ainda não fechou o placar final
$proxima = null;         // próxima campanha agendada (mostrada no resultado final)
$ganhadores = [];        // posição => apelidos, só no resultado final
if ($aba === 'mensal') {
    $estado = 'ativa';
    $ranking = buscarRankingMensal($usuario_id, $agora);
    $premios = [];
    $titulo_hero = 'Ranking mensal';
    $subtitulo_hero = 'Quem mais vendeu em ' . nomeMesRanking($agora) . '. O placar zera sozinho no dia 1.';
    $inicio_txt = $agora->format('01/m/Y');
    $fim_txt = $agora->format('t/m/Y');
    $selo_passe = 'MENSAL';
    $alvo_contagem = new DateTime($agora->format('Y-m-t 23:59:59'));
    $imagem_banner = null;
} else {
    // Campanha agendada não tem placar — o ranking_cache ainda guarda os números da janela anterior, e
    // mostrar aquilo como "placar ao vivo" de uma disputa que nem começou seria simplesmente mentira.
    $premios = $campanha ? buscarPremiosCampanha((int) $campanha['id']) : [];
    if ($premios) {
        $n_corte = max(1, min(10, max(array_map(static fn($p) => (int) $p['posicao'], $premios))));
    }
    $ranking = ($campanha && $estado !== 'agendada') ? buscarRankingCampanha((int) $campanha['id'], $usuario_id, $n_corte) : null;
    if ($campanha && $estado === 'encerrada') {
        $apurando = empty($campanha['finalizada_em']);
        $proxima = buscarProximaCampanha();
        if ($premios && !$apurando) {
            $ganhadores = buscarGanhadoresCampanha((int) $campanha['id'], $n_corte);
        }
    }
    $titulo_hero = (string) ($campanha['titulo'] ?? '');
    $subtitulo_hero = (string) ($campanha['subtitulo'] ?? '');
    $inicio_txt = $campanha ? date('d/m/Y', strtotime($campanha['data_inicio'])) : '';
    $fim_txt = $campanha ? date('d/m/Y', strtotime($campanha['data_fim'])) : '';
    $selo_passe = (string) ($campanha['slug'] ?? '');
    $imagem_banner = $campanha['imagem_banner'] ?? null;
    $alvo_contagem = null;
    if ($estado === 'ativa') {
        $alvo_contagem = new DateTime($campanha['data_fim']);
    } elseif ($estado === 'agendada') {
        $alvo_contagem = new DateTime($campanha['data_inicio']);
    }
}

// A contagem serve aos dois sentidos: quanto falta pra acabar (rodando) ou pra começar (agendada).
// Encerrada não tem contagem. O navegador continua contando segundo a segundo a partir de data-alvo.
$contagem = null;
if ($alvo_contagem) {
    $restante = $agora < $alvo_contagem ? $agora->diff($alvo_contagem) : null;
    $contagem = [
        ['v' => str_pad((string) ($restante->days ?? 0), 2, '0', STR_PAD_LEFT), 'l' => 'dias'],
        ['v' => str_pad((string) ($restante->h ?? 0), 2, '0', STR_PAD_LEFT), 'l' => 'horas'],
        ['v' => str_pad((string) ($restante->i ?? 0), 2, '0', STR_PAD_LEFT), 'l' => 'min'],
        ['v' => str_pad((string) ($restante->s ?? 0), 2, '0', STR_PAD_LEFT), 'l' => 'seg'],
    ];
}

$progresso_top5_pct = 0;
if ($ranking && $ranking['sua_posicao'] && !empty($ranking['faturamento_top5'])) {
    $meu = (float) $ranking['sua_posicao']['faturamento'];
    $alvo = (float) $ranking['faturamento_top5'];
    $progresso_top5_pct = $alvo > 0 ? (int) min(100, round(($meu / $alvo) * 100)) : 100;
} elseif ($ranking && $ranking['sua_posicao'] && (int) $ranking['sua_posicao']['posicao'] <= 5) {
    $progresso_top5_pct = 100;
}
$meu_nome = $ranking && $ranking['sua_posicao'] ? nomeExibicaoRanking($ranking['sua_posicao']['apelido_publico'], $usuario_id) : '';
$meu_gap_top5 = ($ranking && $ranking['sua_posicao'] && $ranking['faturamento_top5'] !== null)
    ? max(0, $ranking['faturamento_top5'] - (float) $ranking['sua_posicao']['faturamento']) : null;

$podio_ordem_visual = [];
if ($ranking) {
    $podio_ordem_visual = count($ranking['top3']) === 3
        ? [$ranking['top3'][1], $ranking['top3'][0], $ranking['top3'][2]]
        : $ranking['top3'];
}

function iconeRanking(string $nome, int $t = 16): string
{
    $caminhos = [
        'coroa' => '<path d="M3 8l4 4 5-7 5 7 4-4-2 11H5L3 8z"/>',
        'calendario' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'pessoas' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'relogio' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'trofeu' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2z"/>',
        'aviao' => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
        'calendario-mes' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h2M14 14h2M8 18h2"/>',
    ];
    return '<svg width="' . $t . '" height="' . $t . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($caminhos[$nome] ?? '') . '</svg>';
}

$url_aba = static fn(string $a): string => 'ranking?aba=' . $a;
$subtitulo_pagina = $aba === 'mensal'
    ? 'Quem mais vendeu neste mês. Reinicia todo dia 1.'
    : ($estado === 'encerrada' ? 'Campanha encerrada — veja o resultado final abaixo.'
        : ($estado === 'agendada' ? 'A próxima campanha de faturamento já tem data.' : 'Acompanhe sua posição na campanha de faturamento.'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Ranking</h1>
                <p><?php echo htmlspecialchars($subtitulo_pagina); ?></p>
            </div>
            <div class="acoes-cabecalho">
                <nav class="abas-ranking" aria-label="Tipo de ranking">
                    <?php if ($campanha): ?>
                        <a href="<?php echo $url_aba('concurso'); ?>" class="aba-ranking<?php echo $aba === 'concurso' ? ' ativa' : ''; ?>"><?php echo iconeRanking('trofeu', 15); ?><span>Concurso</span></a>
                    <?php endif; ?>
                    <a href="<?php echo $url_aba('mensal'); ?>" class="aba-ranking<?php echo $aba === 'mensal' ? ' ativa' : ''; ?>"><?php echo iconeRanking('calendario-mes', 15); ?><span>Mensal</span></a>
                </nav>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="hero-ranking<?php echo !empty($imagem_banner) ? ' tem-capa' : ''; ?>">
            <?php if (!empty($imagem_banner)): ?>
                <div class="hero-ranking-fundo" aria-hidden="true" style="background-image:url('<?php echo htmlspecialchars((string) $imagem_banner); ?>');"></div>
            <?php endif; ?>
            <div class="hero-ranking-conteudo">
                <div class="hero-ranking-selos">
                    <span class="pill-campanha pill-oficial"><?php echo iconeRanking($aba === 'mensal' ? 'calendario-mes' : 'trofeu', 13); ?><?php echo $aba === 'mensal' ? 'Ranking mensal' : 'Ranking oficial'; ?></span>
                    <?php if ($estado === 'ativa'): ?>
                        <span class="pill-campanha" style="background:var(--oksoft);color:var(--ok);"><span class="ponto-vivo"></span>Em disputa</span>
                    <?php elseif ($estado === 'encerrada'): ?>
                        <span class="pill-campanha" style="background:var(--p3);color:var(--m);">Encerrada em <?php echo htmlspecialchars($fim_txt); ?></span>
                    <?php else: ?>
                        <span class="pill-campanha" style="background:var(--wasoft);color:var(--wa);">Começa em <?php echo htmlspecialchars($inicio_txt); ?></span>
                    <?php endif; ?>
                </div>
                <div class="hero-ranking-eyebrow">Comunidade <?php echo htmlspecialchars(nomeSistema()); ?></div>
                <h2 class="hero-ranking-titulo"><?php echo htmlspecialchars($titulo_hero); ?></h2>
                <?php if ($subtitulo_hero !== ''): ?>
                    <p class="hero-ranking-desc"><?php echo htmlspecialchars($subtitulo_hero); ?></p>
                <?php endif; ?>
                <div class="hero-ranking-meta">
                    <span><?php echo iconeRanking('calendario', 15); ?><?php echo htmlspecialchars($inicio_txt); ?> — <?php echo htmlspecialchars($fim_txt); ?></span>
                    <?php if ($ranking): ?>
                        <span><?php echo iconeRanking('pessoas', 15); ?><?php echo number_format($ranking['total_participantes'], 0, ',', '.'); ?> participante<?php echo ((int) $ranking['total_participantes'] === 1) ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($imagem_banner)): ?>
                <img class="hero-ranking-moldura" src="<?php echo htmlspecialchars((string) $imagem_banner); ?>" alt="Arte da campanha" loading="lazy">
            <?php else: ?>
                <div class="hero-ranking-arte" aria-hidden="true">
                    <span class="arte-anel arte-anel-1"></span><span class="arte-anel arte-anel-2"></span>
                    <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="" class="hero-ranking-logo">
                </div>
            <?php endif; ?>
        </div>

        <div class="grade-ranking">
            <div id="bloco-placar" class="painel painel-placar">
                <?php if (!$ranking): ?>
                    <span class="rotulo-kpi">Classificação</span>
                    <h2 class="titulo-placar">A disputa ainda não começou</h2>
                    <p class="texto-suave" style="margin:0;line-height:1.55;">O placar aparece aqui quando a campanha abrir, em <?php echo date('d/m/Y \à\s H:i', strtotime((string) ($campanha['data_inicio'] ?? 'now'))); ?>.</p>
                <?php else: ?>
                    <div class="placar-cabecalho">
                        <div>
                            <span class="rotulo-kpi">Classificação oficial</span>
                            <h2 class="titulo-placar"><?php echo $estado === 'encerrada' ? 'Resultado final' : 'Placar ao vivo'; ?></h2>
                        </div>
                        <?php if ($estado !== 'encerrada'): ?>
                            <span class="placar-atualizacao"><span class="ponto-vivo"></span>Atualiza a cada 60 segundos</span>
                        <?php elseif ($apurando): ?>
                            <span class="placar-atualizacao">Apurando o resultado final…</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($ranking['top3'])): ?>
                        <div class="estado-vazio" style="margin-top:16px;"><?php echo $aba === 'mensal' ? 'Ninguém faturou neste mês ainda — a primeira venda aprovada abre o placar.' : 'Ninguém pontuou nesta campanha ainda.'; ?></div>
                    <?php else: ?>
                        <div class="podio-ranking podio-n<?php echo count($podio_ordem_visual); ?>">
                            <?php foreach ($podio_ordem_visual as $p):
                                $pos = (int) $p['posicao'];
                                $nome_exib = nomeExibicaoRanking($p['apelido_publico'], (int) $p['id_usuario']);
                                $eh_voce = (int) $p['id_usuario'] === $usuario_id;
                            ?>
                                <div class="podio-item podio-<?php echo $pos; ?>">
                                    <?php if ($pos === 1): ?><span class="podio-coroa"><?php echo iconeRanking('coroa', 22); ?></span><?php endif; ?>
                                    <div class="podio-avatar"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></div>
                                    <div class="podio-nome"><?php echo htmlspecialchars($nome_exib); ?><?php if ($eh_voce): ?> <span class="tag-voce">você</span><?php endif; ?></div>
                                    <div class="podio-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $p['faturamento'])); ?></div>
                                    <div class="podio-base">
                                        <span class="podio-posicao"><?php echo str_pad((string) $pos, 2, '0', STR_PAD_LEFT); ?></span>
                                        <span class="podio-tag"><?php echo $pos === 1 ? 'Líder' : 'Pódio'; ?></span>
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
                            $no_top5 = (int) $r['posicao'] <= $n_corte;
                            $gap = $ranking['faturamento_lider'] !== null ? $ranking['faturamento_lider'] - (float) $r['faturamento'] : null;
                            $eh_voce = (int) $r['id_usuario'] === $usuario_id;
                        ?>
                            <div class="linha-ranking<?php echo $eh_voce ? ' e-voce' : ''; ?>">
                                <span class="linha-ranking-pos"><?php echo str_pad((string) $r['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                                <div class="linha-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div class="linha-ranking-nome"><?php echo htmlspecialchars($nome_exib); ?><?php if ($eh_voce): ?> <span class="tag-voce">você</span><?php endif; ?></div>
                                    <div class="linha-ranking-zona" style="color:<?php echo $no_top5 ? 'var(--ok)' : 'var(--m)'; ?>;"><?php echo $estado === 'encerrada' ? ($no_top5 ? 'Premiado' : 'Fim da disputa') : ($no_top5 ? 'Zona de premiação' : 'Em disputa'); ?></div>
                                </div>
                                <div>
                                    <div class="linha-ranking-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $r['faturamento'])); ?></div>
                                    <?php if ($gap !== null && $gap > 0): ?>
                                        <div class="linha-ranking-gap"><?php echo htmlspecialchars(formatarReaisResumido($gap)); ?> do líder</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="rotulo-sua-posicao">Sua posição</div>
                    <?php if (!$ranking['sua_posicao']): ?>
                        <div class="estado-vazio"><?php echo $aba === 'mensal' ? 'Você ainda não vendeu neste mês — sua primeira venda aprovada te coloca no placar.' : 'Você ainda não pontuou nesta campanha — sua primeira venda aprovada te coloca no placar.'; ?></div>
                    <?php else: ?>
                    <div class="faixa-sua-posicao">
                        <span class="linha-ranking-pos"><?php echo str_pad((string) $ranking['sua_posicao']['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                        <div class="linha-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($meu_nome)); ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="linha-ranking-nome"><?php echo htmlspecialchars($meu_nome); ?></div>
                            <div class="linha-ranking-zona texto-suave">Sua conta</div>
                        </div>
                        <div>
                            <div class="linha-ranking-valor">R$ <?php echo number_format((float) $ranking['sua_posicao']['faturamento'], 2, ',', '.'); ?></div>
                            <?php if ($estado !== 'encerrada' && $meu_gap_top5 !== null && $progresso_top5_pct < 100): ?>
                                <div class="linha-ranking-gap"><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> do Top <?php echo $n_corte; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="coluna-lateral-ranking">
                <?php if ($ranking): ?>
                <div id="bloco-passe" class="passe-ingresso">
                    <div class="passe-parte-cima">
                        <div class="cartao-passe-titulo">
                            <span class="passe-rotulo">Passe do competidor</span>
                            <span class="passe-selo"><?php echo htmlspecialchars($selo_passe); ?></span>
                        </div>
                        <?php if (!$ranking['sua_posicao']): ?>
                            <div class="passe-vazio">
                                <span class="passe-vazio-icone"><?php echo iconeRanking('trofeu', 24); ?></span>
                                <strong>Você ainda não está no placar</strong>
                                <p><?php echo $aba === 'mensal' ? 'Sua primeira venda aprovada neste mês coloca você no ranking.' : 'Sua primeira venda aprovada no período da campanha coloca você no ranking.'; ?></p>
                            </div>
                        <?php else: ?>
                            <div class="passe-nome"><?php echo htmlspecialchars($meu_nome); ?></div>
                            <div class="passe-corpo">
                                <div>
                                    <div class="passe-rotulo">Sua posição</div>
                                    <span class="passe-posicao"><small>P</small><?php echo (int) $ranking['sua_posicao']['posicao']; ?></span>
                                </div>
                                <div class="passe-faturamento">
                                    <div class="passe-rotulo">Faturamento</div>
                                    <div class="passe-valor">R$ <?php echo number_format((float) $ranking['sua_posicao']['faturamento'], 2, ',', '.'); ?></div>
                                </div>
                            </div>
                            <?php if ($estado === 'encerrada'): ?>
                            <div class="passe-top5"><?php echo (int) $ranking['sua_posicao']['posicao'] <= $n_corte ? 'Você foi premiado! 🎉' : 'Resultado final: P' . (int) $ranking['sua_posicao']['posicao']; ?></div>
                            <?php elseif ($meu_gap_top5 !== null && $progresso_top5_pct < 100): ?>
                            <div class="passe-progresso-linha">
                                <span><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> para o Top <?php echo $n_corte; ?></span>
                                <span><?php echo $progresso_top5_pct; ?>%</span>
                            </div>
                            <div class="barra-progresso"><div class="barra-progresso-fill" style="width:<?php echo $progresso_top5_pct; ?>%;"></div></div>
                            <?php else: ?>
                            <div class="passe-top5">Você está no Top <?php echo $n_corte; ?>! 🎉</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="passe-perfuracao" aria-hidden="true"></div>
                    <div class="passe-parte-baixo">
                        <?php if ($contagem): ?>
                        <div class="passe-rotulo" style="margin-bottom:8px;"><?php echo iconeRanking('relogio', 13); ?> <?php echo $estado === 'agendada' ? 'A corrida começa em' : ($aba === 'mensal' ? 'O mês termina em' : 'A corrida termina em'); ?></div>
                        <div class="grade-contagem" data-alvo="<?php echo (int) $alvo_contagem->getTimestamp(); ?>">
                            <?php foreach ($contagem as $c): ?>
                                <div class="caixa-contagem">
                                    <div class="caixa-contagem-valor"><?php echo htmlspecialchars($c['v']); ?></div>
                                    <div class="caixa-contagem-label"><?php echo htmlspecialchars($c['l']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="passe-codigo" aria-hidden="true">
                            <span class="passe-barras"></span>
                            <span class="passe-codigo-texto"><?php echo htmlspecialchars(strtoupper(nomeSistema())); ?> · RANK<?php echo $ranking['sua_posicao'] ? ' · POS-' . str_pad((string) $ranking['sua_posicao']['posicao'], 4, '0', STR_PAD_LEFT) : ''; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($premios): ?>
                <div class="painel painel-premios">
                    <div class="premios-eyebrow"><?php echo iconeRanking('trofeu', 15); ?><span class="rotulo-kpi">Premiação</span></div>
                    <h2 class="titulo-premios"><?php echo $estado === 'encerrada' ? 'Premiados da campanha' : 'Premiação da campanha'; ?></h2>
                    <div class="lista-premios">
                        <?php foreach ($premios as $p):
                            $eh_primeiro = (int) $p['posicao'] === 1;
                        ?>
                            <div class="item-premio">
                                <span class="item-premio-tag<?php echo $eh_primeiro ? ' primeiro' : ''; ?>">P<?php echo (int) $p['posicao']; ?></span>
                                <div>
                                    <div class="item-premio-label"><?php echo htmlspecialchars($p['titulo']); ?></div>
                                    <?php if ($p['descricao']): ?>
                                        <div class="item-premio-desc"><?php echo htmlspecialchars($p['descricao']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($estado === 'encerrada' && !$apurando): ?>
                                        <div class="item-premio-ganhador"><?php echo !empty($ganhadores[(int) $p['posicao']]) ? htmlspecialchars(implode(' · ', $ganhadores[(int) $p['posicao']])) : 'Sem ganhador'; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="texto-suave" style="margin:14px 0 0;line-height:1.55;font-size:12.5px;"><?php echo $estado === 'encerrada' ? ($apurando ? 'Os ganhadores aparecem aqui assim que o resultado final for apurado.' : 'Resultado final apurado com as vendas aprovadas do período da campanha.') : 'Os maiores faturamentos aprovados no período garantem classificação para a premiação oficial.'; ?></p>
                </div>
                <?php endif; ?>

                <?php if ($aba === 'concurso' && $estado === 'encerrada'): ?>
                <div class="painel painel-premios">
                    <div class="premios-eyebrow"><?php echo iconeRanking('calendario', 15); ?><span class="rotulo-kpi">Próxima campanha</span></div>
                    <?php if ($proxima): ?>
                        <h2 class="titulo-premios"><?php echo htmlspecialchars((string) $proxima['titulo']); ?></h2>
                        <p class="texto-suave" style="margin:0;line-height:1.6;font-size:13px;">Começa em <?php echo date('d/m/Y \à\s H:i', strtotime((string) $proxima['data_inicio'])); ?>. Este resultado fica no ar até lá.</p>
                    <?php else: ?>
                        <h2 class="titulo-premios">Em breve</h2>
                        <p class="texto-suave" style="margin:0;line-height:1.6;font-size:13px;">Ainda não há uma próxima campanha marcada. Este resultado fica no ar até ela começar — enquanto isso, acompanhe o ranking mensal.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!$premios && $aba === 'mensal'): ?>
                <div class="painel painel-premios">
                    <div class="premios-eyebrow"><?php echo iconeRanking('calendario-mes', 15); ?><span class="rotulo-kpi">Como funciona</span></div>
                    <h2 class="titulo-premios">Ranking do mês</h2>
                    <p class="texto-suave" style="margin:0;line-height:1.6;font-size:13px;">Conta o faturamento das vendas <strong>aprovadas</strong> de <?php echo htmlspecialchars(nomeMesRanking($agora)); ?>. O placar zera sozinho no dia 1 e atualiza de poucos em poucos minutos. Só o seu apelido aparece para os outros — nunca seu nome ou e-mail.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
(function () {
    // Contagem regressiva segundo a segundo a partir do horário final calculado no servidor.
    function dois(n) { return String(n).padStart(2, '0'); }
    function tique() {
        document.querySelectorAll('.grade-contagem[data-alvo]').forEach(function (g) {
            var falta = Math.max(0, Math.floor(parseInt(g.getAttribute('data-alvo'), 10) - Date.now() / 1000));
            var v = [Math.floor(falta / 86400), Math.floor(falta % 86400 / 3600), Math.floor(falta % 3600 / 60), falta % 60];
            g.querySelectorAll('.caixa-contagem-valor').forEach(function (el, i) { el.textContent = dois(v[i]); });
        });
    }
    tique();
    setInterval(tique, 1000);

    // Placar ao vivo: a cada 60 s busca a página de novo e troca só o placar e o passe (sem recarregar a tela).
    var emAndamento = <?php echo ($estado === 'ativa' && $ranking) ? 'true' : 'false'; ?>;
    if (!emAndamento) return;
    setInterval(function () {
        if (document.hidden) return;
        fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['bloco-placar', 'bloco-passe'].forEach(function (id) {
                    var novo = doc.getElementById(id), atual = document.getElementById(id);
                    if (novo && atual) atual.replaceWith(novo);
                });
                tique();
            })
            .catch(function () { /* sem rede agora: tenta de novo no próximo minuto */ });
    }, 60000);
})();
</script>
</body>
</html>
