<?php
define('BANCO_HOST', 'localhost');
define('BANCO_NOME', 'telegram');
define('BANCO_USUARIO', 'root');
define('BANCO_SENHA', '');

// Chave usada só pra criptografar client_secret/cert_password/chave_pix dos gateways de
// pagamento no banco. TROQUE por um valor único gerado na sua própria instalação antes de
// ir pra produção (nunca reaproveite o valor de exemplo abaixo, e nunca commite a chave real
// num repositório). Pra gerar uma nova: php -r "echo bin2hex(random_bytes(32));"
define('CHAVE_CRIPTOGRAFIA_GATEWAYS', 'TROQUE_ESTA_CHAVE_ANTES_DE_IR_PRA_PRODUCAO_7f65e727cb32517658158ef7bfab6dbc');

// Chave usada pra proteger scripts de cron contra chamada por qualquer um que descubra a URL
// (ex. cron_ranking.php, cron_metricas_admin.php). Chamada via CLI (crontab rodando "php
// cron_x.php" direto) sempre passa, sem precisar da chave. Se o crontab da Hostinger estiver
// configurado via HTTP/wget, use "?chave=SEU_VALOR" na URL cadastrada lá. TROQUE antes de ir
// pra produção — pra gerar uma nova: php -r "echo bin2hex(random_bytes(24));"
define('CHAVE_SECRETA_CRON', 'TROQUE_ESTA_CHAVE_ANTES_DE_IR_PRA_PRODUCAO_9c1a4e2f8b6d3057');
