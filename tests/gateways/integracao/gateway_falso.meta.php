<?php
declare(strict_types=1);

return [
    'nome'                     => 'falso',
    'titulo'                   => 'Gateway Falso (teste)',
    'arquivo'                  => __DIR__ . '/falso.php',
    'fabrica'                  => static fn(array $config): ProvedorGateway => new GatewayFalso((string) $config['client_secret']),
    'credenciais_obrigatorias' => ['client_secret'],
    'status_pagos'             => ['PAID'],
    'preco_minimo'             => static fn(?array $split): float => 3.0,
    'aceita_recorrencia'       => false,
    'usa_chave_pix'            => false,
    'letra'                    => 'F',
    'icone_classe'             => 'icone-gateway-padrao',
    'subtitulo'                => 'Teste',
];
