#!/usr/bin/env bash
# =====================================================================================================================
# 09_marco_zero.sh — guarda o estado LIMPO de lançamento (banco zerado + configuração pronta) e aposenta o /root/estado-zero antigo.
# Rode só depois do 08_verificar.sh dizer "PRONTO PARA LANÇAR". Não apaga nada (só cria cópias e renomeia).
#
#   bash 09_marco_zero.sh
#
# Cria /root/estado-lancamento/AAAA-MM-DD_HHMMSS/ com banco.sql.gz, arquivos.tar.gz e sistema.tar.gz — o ponto para onde voltar se,
# antes do primeiro cliente, algo der errado:
#     echo RESTAURAR | painel-restaurar /root/estado-lancamento/<pasta>/banco.sql.gz /root/estado-lancamento/<pasta>/arquivos.tar.gz
# E renomeia /root/estado-zero (de 13/10: tem o domínio, o SMTP, a senha e o esquema do banco ANTIGOS) para estado-zero-OBSOLETO-<data>,
# para ninguém restaurá-lo por engano.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
iniciar_log marco_zero

[[ -f "$CRON_ATIVO" ]] || die "Os crons estão pausados (ou ausentes). Rode 07_retomar.sh antes do marco zero."
DEST="/root/estado-lancamento/$(date +%F_%H%M%S)"
umask 077
mkdir -p "$DEST"; chmod 700 /root/estado-lancamento "$DEST"

titulo "1. Banco"
mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$BANCO_NOME" | gzip -9 > "$DEST/banco.sql.gz"
gzip -t "$DEST/banco.sql.gz"; ok "banco.sql.gz ($(du -h "$DEST/banco.sql.gz" | cut -f1))"

titulo "2. Arquivos e sistema"
tar czf "$DEST/arquivos.tar.gz" -C "$SITE_DIR" --exclude='storage/cache' config.php uploads certificados storage || [[ -s "$DEST/arquivos.tar.gz" ]]
gzip -t "$DEST/arquivos.tar.gz"; ok "arquivos.tar.gz ($(du -h "$DEST/arquivos.tar.gz" | cut -f1))"
tar czf "$DEST/sistema.tar.gz" -C / etc/painel etc/cron.d/painel $( [[ -f /etc/cron.d/painel-backup ]] && echo etc/cron.d/painel-backup ) 2>/dev/null || true
[[ -s "$DEST/sistema.tar.gz" ]] && ok "sistema.tar.gz" || aviso "sistema.tar.gz não gerado"
cat > "$DEST/COMO-VOLTAR.txt" <<EOF
Estado limpo de lançamento, guardado em $(date '+%F %T').
Para voltar a este ponto (SUBSTITUI o banco e os arquivos atuais):
  echo RESTAURAR | painel-restaurar $DEST/banco.sql.gz $DEST/arquivos.tar.gz
Os crons são pausados e religados pelo próprio painel-restaurar.
EOF

titulo "3. Aposentar o estado-zero antigo"
if [[ -d /root/estado-zero ]]; then
  NOVO="/root/estado-zero-OBSOLETO-$(date +%F)"
  mv /root/estado-zero "$NOVO"; ok "/root/estado-zero renomeado para $NOVO (não restaure: volta domínio, SMTP, senha e esquema antigos)"
else ok "não há /root/estado-zero"; fi
echo
ok "marco zero guardado em $DEST"
