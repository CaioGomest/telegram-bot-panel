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
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Ranking</h1>
                <p class="a-sub"><?php echo htmlspecialchars($subtitulo_pagina); ?></p>
            </div>
            <nav class="a-seg" aria-label="Tipo de ranking">
                <?php if ($campanha): ?>
                    <a href="<?php echo $url_aba('concurso'); ?>"<?php echo $aba === 'concurso' ? ' class="on" aria-current="page"' : ''; ?>><?php echo iconeRanking('trofeu', 16); ?>&nbsp;Concurso</a>
                <?php endif; ?>
                <a href="<?php echo $url_aba('mensal'); ?>"<?php echo $aba === 'mensal' ? ' class="on" aria-current="page"' : ''; ?>><?php echo iconeRanking('calendario-mes', 16); ?>&nbsp;Mensal</a>
            </nav>
        </header>

        <section class="a-rhero<?php echo !empty($imagem_banner) ? ' tem-capa' : ''; ?>" style="margin-bottom: 16px" aria-label="Campanha">
            <?php if (!empty($imagem_banner)): ?>
                <img class="a-rhero-capa" src="<?php echo htmlspecialchars((string) $imagem_banner); ?>" alt="Arte da campanha" loading="lazy">
            <?php endif; ?>
            <div class="a-rhero-c">
                <div class="a-acts">
                    <span class="a-pill or"><?php echo iconeRanking($aba === 'mensal' ? 'calendario-mes' : 'trofeu', 14); ?><?php echo $aba === 'mensal' ? 'Ranking mensal' : 'Ranking oficial'; ?></span>
                    <?php if ($estado === 'ativa'): ?>
                        <span class="a-pill ok"><span class="a-bd ok" style="padding: 0; height: auto; background: none"><i></i></span>Em disputa</span>
                    <?php elseif ($estado === 'encerrada'): ?>
                        <span class="a-pill">Encerrada em <?php echo htmlspecialchars($fim_txt); ?></span>
                    <?php else: ?>
                        <span class="a-pill" style="background: var(--was); color: var(--wat)">Começa em <?php echo htmlspecialchars($inicio_txt); ?></span>
                    <?php endif; ?>
                </div>
                <div class="a-lab" style="margin-top: 18px; color: var(--ort)">Comunidade <?php echo htmlspecialchars(nomeSistema()); ?></div>
                <h2><?php echo htmlspecialchars($titulo_hero); ?></h2>
                <?php if ($subtitulo_hero !== ''): ?>
                    <p class="a-sub" style="margin: 0"><?php echo htmlspecialchars($subtitulo_hero); ?></p>
                <?php endif; ?>
                <div class="meta">
                    <span class="a-pill"><?php echo iconeRanking('calendario', 14); ?><?php echo htmlspecialchars($inicio_txt); ?> — <?php echo htmlspecialchars($fim_txt); ?></span>
                    <?php if ($ranking): ?>
                        <span class="a-pill"><?php echo iconeRanking('pessoas', 14); ?><?php echo number_format($ranking['total_participantes'], 0, ',', '.'); ?> participante<?php echo ((int) $ranking['total_participantes']) === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($imagem_banner)): ?>
                <div class="a-rarte" aria-hidden="true"><img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt=""></div>
            <?php endif; ?>
        </section>

        <div class="a-grid">
            <section id="bloco-placar" class="a-card s8" aria-label="Placar">
                <?php if (!$ranking): ?>
                    <span class="a-lab">Classificação</span>
                    <h2 class="a-h2-placar">A disputa ainda não começou</h2>
                    <p class="a-mut" style="margin: 0; line-height: 1.55;">O placar aparece aqui quando a campanha abrir, em <?php echo date('d/m/Y \à\s H:i', strtotime((string) ($campanha['data_inicio'] ?? 'now'))); ?>.</p>
                <?php else: ?>
                    <div class="a-ch">
                        <div>
                            <span class="a-lab">Classificação oficial</span>
                            <h2 style="margin-top: 6px"><?php echo $estado === 'encerrada' ? 'Resultado final' : 'Placar ao vivo'; ?></h2>
                        </div>
                        <?php if ($estado !== 'encerrada'): ?>
                            <span class="a-bd ok"><i></i>Atualiza a cada 60 segundos</span>
                        <?php elseif ($apurando): ?>
                            <span class="a-bd wa">Apurando o resultado final…</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($ranking['top3'])): ?>
                        <div class="a-empty"><?php echo $aba === 'mensal' ? 'Ninguém faturou neste mês ainda — a primeira venda aprovada abre o placar.' : 'Ninguém pontuou nesta campanha ainda.'; ?></div>
                    <?php else: ?>
                        <div class="a-podio n<?php echo count($podio_ordem_visual); ?>">
                            <?php foreach ($podio_ordem_visual as $p):
                                $pos = (int) $p['posicao'];
                                $nome_exib = nomeExibicaoRanking($p['apelido_publico'], (int) $p['id_usuario']);
                                $eh_voce = (int) $p['id_usuario'] === $usuario_id;
                            ?>
                                <div class="a-pod p<?php echo $pos; ?>">
                                    <?php if ($pos === 1): ?><span style="color: var(--ort)"><?php echo iconeRanking('coroa', 26); ?></span><?php endif; ?>
                                    <span class="av"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></span>
                                    <b><?php echo htmlspecialchars($nome_exib); ?><?php if ($eh_voce): ?> <span class="a-tag">você</span><?php endif; ?></b>
                                    <span class="vl"><?php echo htmlspecialchars(formatarReaisResumido((float) $p['faturamento'])); ?></span>
                                    <div class="base">
                                        <strong><?php echo str_pad((string) $pos, 2, '0', STR_PAD_LEFT); ?></strong>
                                        <small><?php echo $pos === 1 ? 'Líder' : 'Pódio'; ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($ranking['linhas']): ?>
                        <div class="a-lab" style="display: flex; justify-content: space-between; padding: 0 4px 8px"><span>Posição · Competidor</span><span>Faturamento</span></div>
                        <div class="a-rank"><ul>
                        <?php foreach ($ranking['linhas'] as $r):
                            $nome_exib = nomeExibicaoRanking($r['apelido_publico'], (int) $r['id_usuario']);
                            $no_top5 = (int) $r['posicao'] <= $n_corte;
                            $gap = $ranking['faturamento_lider'] !== null ? $ranking['faturamento_lider'] - (float) $r['faturamento'] : null;
                            $eh_voce = (int) $r['id_usuario'] === $usuario_id;
                        ?>
                            <li<?php echo $eh_voce ? ' class="voce"' : ''; ?>>
                                <span class="pos"><?php echo str_pad((string) $r['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                                <span class="av"><?php echo htmlspecialchars(iniciaisRanking($nome_exib)); ?></span>
                                <div class="nm">
                                    <b><?php echo htmlspecialchars($nome_exib); ?><?php if ($eh_voce): ?> <span class="a-tag">você</span><?php endif; ?></b>
                                    <span<?php echo $no_top5 ? ' class="ok"' : ''; ?>><?php echo $estado === 'encerrada' ? ($no_top5 ? 'Premiado' : 'Fim da disputa') : ($no_top5 ? 'Zona de premiação' : 'Em disputa'); ?></span>
                                </div>
                                <div class="vl">
                                    <b><?php echo htmlspecialchars(formatarReaisResumido((float) $r['faturamento'])); ?></b>
                                    <?php if ($gap !== null && $gap > 0): ?>
                                        <span><?php echo htmlspecialchars(formatarReaisResumido($gap)); ?> do líder</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        </ul></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="a-lab" style="margin: 22px 4px 8px">Sua posição</div>
                    <?php if (!$ranking['sua_posicao']): ?>
                        <div class="a-empty"><?php echo $aba === 'mensal' ? 'Você ainda não vendeu neste mês — sua primeira venda aprovada te coloca no placar.' : 'Você ainda não pontuou nesta campanha — sua primeira venda aprovada te coloca no placar.'; ?></div>
                    <?php else: ?>
                        <div class="a-rank"><ul>
                            <li class="voce">
                                <span class="pos"><?php echo str_pad((string) $ranking['sua_posicao']['posicao'], 2, '0', STR_PAD_LEFT); ?></span>
                                <span class="av"><?php echo htmlspecialchars(iniciaisRanking($meu_nome)); ?></span>
                                <div class="nm"><b><?php echo htmlspecialchars($meu_nome); ?></b><span>Sua conta</span></div>
                                <div class="vl">
                                    <b>R$ <?php echo number_format((float) $ranking['sua_posicao']['faturamento'], 2, ',', '.'); ?></b>
                                    <?php if ($estado !== 'encerrada' && $meu_gap_top5 !== null && $progresso_top5_pct < 100): ?>
                                        <span><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> do Top <?php echo $n_corte; ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        </ul></div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <div class="stack s4">
                <?php if ($ranking): ?>
                <section id="bloco-passe" class="a-passe" aria-label="Passe do competidor">
                    <div class="cima">
                        <div class="lab">Passe do competidor <span class="selo"><?php echo htmlspecialchars($selo_passe); ?></span></div>
                        <?php if (!$ranking['sua_posicao']): ?>
                            <div class="vazio">
                                <?php echo iconeRanking('trofeu', 26); ?>
                                <strong>Você ainda não está no placar</strong>
                                <p><?php echo $aba === 'mensal' ? 'Sua primeira venda aprovada neste mês coloca você no ranking.' : 'Sua primeira venda aprovada no período da campanha coloca você no ranking.'; ?></p>
                            </div>
                        <?php else: ?>
                            <div class="nome"><?php echo htmlspecialchars($meu_nome); ?></div>
                            <div class="pp">
                                <div>
                                    <div class="lab">Sua posição</div>
                                    <span class="pos"><small>P</small><?php echo (int) $ranking['sua_posicao']['posicao']; ?></span>
                                </div>
                                <div>
                                    <div class="lab" style="text-align: right">Faturamento</div>
                                    <div class="fat">R$ <?php echo number_format((float) $ranking['sua_posicao']['faturamento'], 2, ',', '.'); ?></div>
                                </div>
                            </div>
                            <?php if ($estado === 'encerrada'): ?>
                                <div class="prog"><div><span><?php echo (int) $ranking['sua_posicao']['posicao'] <= $n_corte ? 'Você foi premiado! 🎉' : 'Resultado final: P' . (int) $ranking['sua_posicao']['posicao']; ?></span></div></div>
                            <?php elseif ($meu_gap_top5 !== null && $progresso_top5_pct < 100): ?>
                                <div class="prog">
                                    <div><span><?php echo htmlspecialchars(formatarReaisResumido($meu_gap_top5)); ?> para o Top <?php echo $n_corte; ?></span><span><?php echo $progresso_top5_pct; ?>%</span></div>
                                    <div class="trilho"><i style="width: <?php echo $progresso_top5_pct; ?>%"></i></div>
                                </div>
                            <?php else: ?>
                                <div class="prog"><div><span>Você está no Top <?php echo $n_corte; ?>! 🎉</span></div></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="perf"></div>
                    <div class="baixo">
                        <?php if ($contagem): ?>
                            <div class="lab"><?php echo iconeRanking('relogio', 13); ?> <?php echo $estado === 'agendada' ? 'A corrida começa em' : ($aba === 'mensal' ? 'O mês termina em' : 'A corrida termina em'); ?></div>
                            <div class="cont" data-alvo="<?php echo (int) $alvo_contagem->getTimestamp(); ?>">
                                <?php foreach ($contagem as $c): ?>
                                    <div><b><?php echo htmlspecialchars($c['v']); ?></b><small><?php echo htmlspecialchars(ucfirst($c['l'])); ?></small></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="barras" aria-hidden="true"></div>
                        <div class="cod" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(nomeSistema())); ?> · RANK<?php echo $ranking['sua_posicao'] ? ' · POS-' . str_pad((string) $ranking['sua_posicao']['posicao'], 4, '0', STR_PAD_LEFT) : ''; ?></div>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($premios): ?>
                <section class="a-card a-prem" aria-label="Premiação">
                    <div class="a-lab" style="display: flex; align-items: center; gap: 8px"><?php echo iconeRanking('trofeu', 16); ?>Premiação</div>
                    <div class="a-ch" style="margin: 6px 0 10px"><h2><?php echo $estado === 'encerrada' ? 'Premiados da campanha' : 'Premiação da campanha'; ?></h2></div>
                    <ul>
                        <?php foreach ($premios as $p):
                            $eh_primeiro = (int) $p['posicao'] === 1;
                        ?>
                            <li>
                                <span class="tg<?php echo $eh_primeiro ? ' um' : ''; ?>">P<?php echo (int) $p['posicao']; ?></span>
                                <div>
                                    <b><?php echo htmlspecialchars($p['titulo']); ?></b>
                                    <?php if ($p['descricao']): ?>
                                        <span><?php echo htmlspecialchars($p['descricao']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($estado === 'encerrada' && !$apurando): ?>
                                        <span class="ganhador"><?php echo !empty($ganhadores[(int) $p['posicao']]) ? htmlspecialchars(implode(' · ', $ganhadores[(int) $p['posicao']])) : 'Sem ganhador'; ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="a-mut" style="margin-top: 12px; font-size: 12.5px"><?php echo $estado === 'encerrada' ? ($apurando ? 'Os ganhadores aparecem aqui assim que o resultado final for apurado.' : 'Resultado final apurado com as vendas aprovadas do período da campanha.') : 'Os maiores faturamentos aprovados no período garantem classificação para a premiação oficial.'; ?></p>
                </section>
                <?php endif; ?>

                <?php if ($aba === 'concurso' && $estado === 'encerrada'): ?>
                <section class="a-card a-prem" aria-label="Próxima campanha">
                    <div class="a-lab" style="display: flex; align-items: center; gap: 8px"><?php echo iconeRanking('calendario', 16); ?>Próxima campanha</div>
                    <?php if ($proxima): ?>
                        <div class="a-ch" style="margin: 6px 0 10px"><h2><?php echo htmlspecialchars((string) $proxima['titulo']); ?></h2></div>
                        <p class="a-mut" style="margin: 0; line-height: 1.6; font-size: 13px;">Começa em <?php echo date('d/m/Y \à\s H:i', strtotime((string) $proxima['data_inicio'])); ?>. Este resultado fica no ar até lá.</p>
                    <?php else: ?>
                        <div class="a-ch" style="margin: 6px 0 10px"><h2>Em breve</h2></div>
                        <p class="a-mut" style="margin: 0; line-height: 1.6; font-size: 13px;">Ainda não há uma próxima campanha marcada. Este resultado fica no ar até ela começar — enquanto isso, acompanhe o ranking mensal.</p>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if (!$premios && $aba === 'mensal'): ?>
                <section class="a-card a-prem" aria-label="Como funciona">
                    <div class="a-lab" style="display: flex; align-items: center; gap: 8px"><?php echo iconeRanking('calendario-mes', 16); ?>Como funciona</div>
                    <div class="a-ch" style="margin: 6px 0 10px"><h2>Ranking do mês</h2></div>
                    <p class="a-mut" style="margin: 0; line-height: 1.6; font-size: 13px;">Conta o faturamento das vendas <strong>aprovadas</strong> de <?php echo htmlspecialchars(nomeMesRanking($agora)); ?>. O placar zera sozinho no dia 1 e atualiza de poucos em poucos minutos. Só o seu apelido aparece para os outros — nunca seu nome ou e-mail.</p>
                </section>
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
        document.querySelectorAll('.a-passe .cont[data-alvo]').forEach(function (g) {
            var falta = Math.max(0, Math.floor(parseInt(g.getAttribute('data-alvo'), 10) - Date.now() / 1000));
            var v = [Math.floor(falta / 86400), Math.floor(falta % 86400 / 3600), Math.floor(falta % 3600 / 60), falta % 60];
            g.querySelectorAll('b').forEach(function (el, i) { el.textContent = dois(v[i]); });
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
