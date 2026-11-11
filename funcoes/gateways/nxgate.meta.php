<?php
declare(strict_types=1);

return [
    'nome'                     => 'nxgate',
    'titulo'                   => 'NxGate (Pix)',
    'arquivo'                  => __DIR__ . '/nxgate.php',
    'fabrica'                  => static fn(array $config): ProvedorGateway => new NxGate((string) $config['client_secret']),
    // client_secret = API Key (cifrada). Sem campo de segredo HMAC na tela: o suporte da NxGate (10/11/2026) disse que plataforma multi-cliente nao deve usar HMAC; o pagamento e sempre confirmado na API. webhook_nxgate.php ainda valida a assinatura se houver cert_password salvo.
    'credenciais_obrigatorias' => ['client_secret'],
    'campos'                   => [
        ['coluna' => 'client_secret', 'rotulo' => 'API Key (Chaves API no painel da NxGate)', 'obrigatorio' => true, 'segredo' => true],
    ],
    'status_pagos'             => ['PAID'],
    'preco_minimo'             => static fn(?array $split): float => 1.00,
    'aceita_recorrencia'       => false,
    'usa_chave_pix'            => false,
    'site'                     => 'https://nxgate.com.br',
    'letra'                    => 'N',
    'icone_classe'             => 'icone-gateway-nxgate',
    'subtitulo'                => 'API Key',
    'split_rotulo'             => 'username do recebedor na NxGate',
    'split_ajuda'              => 'Quem recebe a comissão, pelo <strong>username</strong> na NxGate. O split total não pode passar de 90% do valor (50% abaixo de R$ 2,00). Usuário inexistente faz o valor ser descontado sem ir para ninguém: confira antes.',
    'limites'                  => 'Valor mínimo de um Pix: <strong>R$ 1,00</strong> (limite da API). Chave de teste (<code>nxg_test_</code>) usa o sandbox.',
];
