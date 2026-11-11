<?php
declare(strict_types=1);

return [
    'nome'                     => 'wiinpay',
    'titulo'                   => 'WiinPay (Pix)',
    'arquivo'                  => __DIR__ . '/wiinpay.php',
    'fabrica'                  => static fn(array $config): ProvedorGateway => new WiinPay((string) $config['client_secret']),
    // client_secret = API Key (cifrada)
    'credenciais_obrigatorias' => ['client_secret'],
    'campos'                   => [
        ['coluna' => 'client_secret', 'rotulo' => 'API Key', 'obrigatorio' => true, 'segredo' => true],
    ],
    'status_pagos'             => ['PAID'],
    'preco_minimo'             => static fn(?array $split): float => 3.00,
    'aceita_recorrencia'       => false,
    'usa_chave_pix'            => false,
    'site'                     => 'https://wiinpay.com.br',
    'letra'                    => 'W',
    'icone_classe'             => 'icone-gateway-wiinpay',
    'subtitulo'                => 'API Key',
    'split_rotulo'             => 'user_id do recebedor na WiinPay',
    'split_ajuda'              => 'Quem recebe a comissão, pelo <strong>user_id</strong> na WiinPay. O valor da comissão em reais é calculado por venda (percentual ou fixo) e enviado em <span class="mono">split.value</span>.',
    'limites'                  => 'Valor mínimo de um Pix: <strong>R$ 3,00</strong> (limite da API). Planos abaixo disso passam para o próximo gateway da fila.',
];
