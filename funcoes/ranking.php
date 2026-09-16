<?php
declare(strict_types=1);

/**
 * STUB — dados de exemplo isolados, sem consulta ao banco.
 * A campanha de ranking ainda não tem tabela/fonte de dados própria.
 * Quando existir, trocar o corpo desta função por uma consulta real
 * (campanha ativa, top competidores por faturamento, posição do usuário logado)
 * mantendo o mesmo formato de retorno usado por ranking.php.
 */
function buscarDadosRankingStub(): array
{
    $fim_campanha = new DateTime('2026-12-01 23:59:59');
    $agora = new DateTime();
    $restante = $agora < $fim_campanha ? $agora->diff($fim_campanha) : null;

    return [
        'campanha' => [
            'nome' => 'Dubai 2026',
            'periodo' => '01 ago — 01 dez',
            'participantes' => 6901,
        ],
        'podium' => [
            ['pos' => '02', 'tag' => 'Pódio', 'nome' => '@alesonmartins', 'iniciais' => 'AM', 'valor' => 'R$ 541,2 mil', 'tamanho' => '52px', 'altura' => '74px', 'cor' => 'var(--m)', 'fundo' => 'var(--p2)'],
            ['pos' => '01', 'tag' => 'Líder', 'nome' => '@coyotehot1', 'iniciais' => 'CH', 'valor' => 'R$ 1,8 mi', 'tamanho' => '68px', 'altura' => '104px', 'cor' => 'var(--or)', 'fundo' => 'var(--orsoft)'],
            ['pos' => '03', 'tag' => 'Pódio', 'nome' => '@kativip', 'iniciais' => 'KV', 'valor' => 'R$ 301,2 mil', 'tamanho' => '52px', 'altura' => '58px', 'cor' => 'var(--m)', 'fundo' => 'var(--p2)'],
        ],
        'linhas' => [
            ['pos' => '04', 'iniciais' => 'RM', 'nome' => '@renatamk', 'zona' => 'Zona de embarque', 'zona_cor' => 'var(--ok)', 'valor' => 'R$ 260,1 mil', 'gap' => 'R$ 2,3 mil'],
            ['pos' => '05', 'iniciais' => 'JP', 'nome' => '@joaopvendas', 'zona' => 'Zona de embarque', 'zona_cor' => 'var(--ok)', 'valor' => 'R$ 257,8 mil', 'gap' => 'R$ 4,6 mil'],
            ['pos' => '06', 'iniciais' => 'LS', 'nome' => '@leandroshop', 'zona' => 'Em disputa', 'zona_cor' => 'var(--m)', 'valor' => 'R$ 198,4 mil', 'gap' => 'R$ 64 mil'],
            ['pos' => '07', 'iniciais' => 'BC', 'nome' => '@brunacoins', 'zona' => 'Em disputa', 'zona_cor' => 'var(--m)', 'valor' => 'R$ 176,9 mil', 'gap' => 'R$ 85,5 mil'],
            ['pos' => '08', 'iniciais' => 'DV', 'nome' => '@diegov', 'zona' => 'Em disputa', 'zona_cor' => 'var(--m)', 'valor' => 'R$ 151,2 mil', 'gap' => 'R$ 111,2 mil'],
            ['pos' => '09', 'iniciais' => 'MF', 'nome' => '@marfontes', 'zona' => 'Em disputa', 'zona_cor' => 'var(--m)', 'valor' => 'R$ 130,7 mil', 'gap' => 'R$ 131,7 mil'],
            ['pos' => '10', 'iniciais' => 'PT', 'nome' => '@pedrotop', 'zona' => 'Em disputa', 'zona_cor' => 'var(--m)', 'valor' => 'R$ 112,3 mil', 'gap' => 'R$ 150,1 mil'],
        ],
        'sua_posicao' => [
            'pos' => 6902,
            'iniciais' => 'VC',
            'nome' => 'Sua conta',
            'valor' => 'R$ 4.280',
            'gap_top5' => 'R$ 262,4 mil',
        ],
        'contagem' => $restante ? [
            ['v' => str_pad((string) $restante->days, 2, '0', STR_PAD_LEFT), 'l' => 'dias'],
            ['v' => str_pad((string) $restante->h, 2, '0', STR_PAD_LEFT), 'l' => 'horas'],
            ['v' => str_pad((string) $restante->i, 2, '0', STR_PAD_LEFT), 'l' => 'min'],
            ['v' => str_pad((string) $restante->s, 2, '0', STR_PAD_LEFT), 'l' => 'seg'],
        ] : [
            ['v' => '00', 'l' => 'dias'], ['v' => '00', 'l' => 'horas'], ['v' => '00', 'l' => 'min'], ['v' => '00', 'l' => 'seg'],
        ],
        'progresso_top5_pct' => 2,
        'premios' => [
            ['tag' => 'P1', 'cor' => 'var(--or)', 'fundo' => 'var(--orsoft)', 'label' => '1º lugar', 'desc' => 'Pacote completo para Dubai + acompanhante'],
            ['tag' => 'P2', 'cor' => 'var(--m)', 'fundo' => 'var(--p3)', 'label' => '2º lugar', 'desc' => 'Pacote completo para Dubai'],
            ['tag' => 'P3', 'cor' => 'var(--m)', 'fundo' => 'var(--p3)', 'label' => '3º lugar', 'desc' => 'Pacote completo para Dubai'],
            ['tag' => 'P4', 'cor' => 'var(--m)', 'fundo' => 'var(--p3)', 'label' => '4º lugar', 'desc' => 'Passagem + hospedagem'],
            ['tag' => 'P5', 'cor' => 'var(--m)', 'fundo' => 'var(--p3)', 'label' => '5º lugar', 'desc' => 'Passagem + hospedagem'],
        ],
    ];
}
