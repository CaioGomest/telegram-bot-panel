#!/bin/bash
# Pré-condições para rodar a bateria (all.sh) numa VPS NOVA, de banco vazio — a homologação já tem tudo isto configurado à mão.
# Rode como root ANTES de all.sh e rode vps_pos.sh DEPOIS (e php /tmp/pt/cleanup.php) para deixar a instalação como o instalador entregou.
#
#   1. gateway OmegaPayments ligada, com o split fixo de R$ 1,40 da homologação (os testes de "valor mínimo R$ 2,10" dependem dele);
#      a chave Pix do split é FICTÍCIA (nada é enviado à gateway: o simulador responde)
#   2. um "outro usuário" com 1 fluxo e 1 bot (os testes de isolamento tentam mexer no fluxo/bot de OUTRO usuário e esperam 404)
#   3. as crons do sistema pausadas (senão rodariam sobre o bot de teste com token falso)
set -e
BANCO="${BANCO_NOME:-painel}"
mkdir -p /tmp/pt
mysql -N "$BANCO" -e "SELECT ativo, taxa_split, tipo_split, IFNULL(chave_pix_split,'') FROM gateways WHERE nome='omegapayments'" > /tmp/pt/gateway_antes.txt
mysql "$BANCO" <<'SQL'
UPDATE gateways SET ativo = 1, taxa_split = 1.40, tipo_split = 'fixo', chave_pix_split = 'sim-chave-split' WHERE nome = 'omegapayments';
INSERT IGNORE INTO usuarios (nome, email, senha, perfil) VALUES ('OUTRO USUARIO TESTE', 'outro-usuario-vps@example.invalid', 'x-nao-usar', 'usuario');
INSERT INTO fluxos (id_usuario, nome, modo, dados_fluxograma)
  SELECT id, 'Fluxo alheio de teste', 'avancado', '{}' FROM usuarios WHERE email = 'outro-usuario-vps@example.invalid'
  AND NOT EXISTS (SELECT 1 FROM fluxos f WHERE f.id_usuario = usuarios.id);
INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome)
  SELECT id, 'SIMBOT:outro-usuario-vps', 7000009, 'sim_outro_bot', 'Bot Alheio' FROM usuarios WHERE email = 'outro-usuario-vps@example.invalid'
  AND NOT EXISTS (SELECT 1 FROM bots b WHERE b.id_usuario = usuarios.id);
SQL
[ -f /etc/cron.d/painel ] && mv /etc/cron.d/painel /root/painel-cron.pausado
echo "pré-condições prontas (gateway ligada com split 1,40; outro usuário criado; crons pausadas)"
