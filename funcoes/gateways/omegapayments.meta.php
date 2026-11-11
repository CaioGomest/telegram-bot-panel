<?php
declare(strict_types=1);

return [
    'nome'                     => 'omegapayments',
    'titulo'                   => 'OmegaPayments (Pix)',
    'arquivo'                  => __DIR__ . '/../omegapayments_banco.php',
    'fabrica'                  => static fn(array $config): ProvedorGateway => new OmegaPaymentsBanco((string) $config['client_id'], (string) $config['client_secret']),
    'credenciais_obrigatorias' => ['client_id', 'client_secret'],
    'campos'                   => [
        ['coluna' => 'client_id', 'rotulo' => 'Client ID', 'obrigatorio' => true, 'segredo' => false],
        ['coluna' => 'client_secret', 'rotulo' => 'Client Secret', 'obrigatorio' => true, 'segredo' => true],
    ],
    'split_rotulo'             => 'ID do produtor que recebe (producerId da OmegaPayments)',
    'split_ajuda'              => 'Quem recebe a comissão, pelo <strong>ID do produtor</strong> na OmegaPayments (<span class="mono">producerId</span>) — não é chave Pix, CPF nem telefone.',
    'status_pagos'             => ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'],
    'preco_minimo'             => static fn(?array $split): float => precoMinimoOmegaPayments($split),
    'aceita_recorrencia'       => true,
    'usa_chave_pix'            => false,
    'site'                     => 'https://app.omegapayments.com.br',
    'letra'                    => 'O',
    'icone_classe'             => 'icone-gateway-omegapayments',
    'subtitulo'                => 'Chave Pública/Secreta (API Key)',
];
