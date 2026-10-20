#!/usr/bin/env bash
# =====================================================================================================================
# 01_backup_antes.sh — backup de segurança ANTES de apagar. Só cria arquivos em /root/resetar-backups/<data>/ (não apaga nada).
#
#   bash 01_backup_antes.sh
#
# Guarda (tudo fora da rotação de 10 dias do painel-backup, para não sumir sozinho):
#   • banco-completo.sql.gz  — o banco inteiro, com os dados de teste (só se BACKUP_COMPLETO=sim; pode ter alguns GB)
#   • config-e-admins.sql.gz — só o que importa guardar: admins, gateways, configurações, comunidade, campanhas de ranking (pequeno)
#   • arquivos.tar.gz        — config.php, storage, certificados e uploads (se uploads < 1 GB; senão só marca_*)
#   • sistema.tar.gz         — /etc/painel e os crons
#   • logs-php/              — cópia dos logs do PHP (o passo 04 zera esses logs)
# E grava o caminho em /root/resetar-backups/ULTIMO, que o desfazer.sh usa.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
carregar_config
iniciar_log backup

DEST="$PASTA_BACKUPS/$(date +%F_%H%M%S)"
umask 077
mkdir -p "$DEST/logs-php"; chmod 700 "$PASTA_BACKUPS" "$DEST"

# espaço livre: o banco comprimido costuma ficar em ~1/4 do tamanho no disco; exige o dobro disso livre
USO_MB="$(sql "SELECT ROUND(SUM(data_length+index_length)/1024/1024) FROM information_schema.tables WHERE table_schema='${BANCO_NOME}'")"
LIVRE_MB="$(df -Pm "$PASTA_BACKUPS" | awk 'NR==2{print $4}')"
log "banco: ${USO_MB} MB | livre no disco: ${LIVRE_MB} MB"
if [[ "$BACKUP_COMPLETO" == "sim" ]] && (( LIVRE_MB < USO_MB / 2 + 1024 )); then
  die "Pouco espaço livre para o backup completo (${LIVRE_MB} MB). Libere espaço ou use BACKUP_COMPLETO=nao (o backup de config e admins continua sendo feito)."
fi

titulo "1/5 configuração e administradores (pequeno, sempre feito)"
{
  mysqldump --single-transaction --default-character-set=utf8mb4 --skip-add-drop-table --no-create-info --complete-insert \
    --where="perfil='admin'" "$BANCO_NOME" usuarios
  mysqldump --single-transaction --default-character-set=utf8mb4 --skip-add-drop-table --no-create-info --complete-insert \
    "$BANCO_NOME" gateways configuracoes comunidade_links campanhas_ranking campanhas_ranking_premios
} | gzip -9 > "$DEST/config-e-admins.sql.gz"
gzip -t "$DEST/config-e-admins.sql.gz"; ok "config-e-admins.sql.gz ($(du -h "$DEST/config-e-admins.sql.gz" | cut -f1))"

titulo "2/5 banco completo"
if [[ "$BACKUP_COMPLETO" == "sim" ]]; then
  mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$BANCO_NOME" | gzip -9 > "$DEST/banco-completo.sql.gz"
  gzip -t "$DEST/banco-completo.sql.gz"
  [[ "$(stat -c %s "$DEST/banco-completo.sql.gz")" -gt 2000 ]] || die "dump do banco suspeito (muito pequeno)"
  ok "banco-completo.sql.gz ($(du -h "$DEST/banco-completo.sql.gz" | cut -f1))"
else aviso "BACKUP_COMPLETO=nao — o banco inteiro NÃO foi guardado (só config e admins); desfazer.sh não terá como devolver os dados de teste"; fi

titulo "3/5 arquivos do site"
UP_MB="$(du -sm "$SITE_DIR/uploads" 2>/dev/null | cut -f1 || echo 0)"
if (( ${UP_MB:-0} < 1024 )); then
  tar czf "$DEST/arquivos.tar.gz" -C "$SITE_DIR" --exclude='storage/cache' config.php uploads certificados storage || [[ -s "$DEST/arquivos.tar.gz" ]]   # tar sai 1 se um arquivo mudou durante a leitura; o gzip -t abaixo garante a integridade
else
  aviso "uploads tem ${UP_MB} MB — guardando só os arquivos da marca (uploads/marca_*)"
  ( cd "$SITE_DIR" && tar czf "$DEST/arquivos.tar.gz" --exclude='storage/cache' config.php certificados storage $(ls uploads/marca_* 2>/dev/null) )
fi
gzip -t "$DEST/arquivos.tar.gz"; ok "arquivos.tar.gz ($(du -h "$DEST/arquivos.tar.gz" | cut -f1))"

titulo "4/5 sistema (/etc/painel e crons)"
tar czf "$DEST/sistema.tar.gz" -C / etc/painel $( [[ -f "$CRON_ATIVO" ]] && echo "etc/cron.d/painel" ) $( [[ -f /etc/cron.d/painel-backup ]] && echo "etc/cron.d/painel-backup" ) 2>/dev/null || true
[[ -s "$DEST/sistema.tar.gz" ]] && ok "sistema.tar.gz" || aviso "não consegui guardar /etc/painel (sem ele, o aviso de login SSH e o backup externo precisam ser refeitos à mão numa VPS nova)"

titulo "5/5 logs do PHP (serão zerados no passo 04)"
for f in /var/log/php/painel-erros.log /var/log/php/painel-cli-erros.log "$SITE_DIR"/logs/*.log; do [[ -f "$f" ]] && cp -p "$f" "$DEST/logs-php/" 2>/dev/null || true; done
ok "logs copiados ($(ls "$DEST/logs-php" | wc -l) arquivos)"

printf 'DEST=%q\nBANCO=%q\nARQUIVOS=%q\nCONFIG=%q\nSISTEMA=%q\n' "$DEST" \
  "$( [[ -f "$DEST/banco-completo.sql.gz" ]] && echo "$DEST/banco-completo.sql.gz" || echo "")" \
  "$DEST/arquivos.tar.gz" "$DEST/config-e-admins.sql.gz" "$DEST/sistema.tar.gz" > "$PASTA_BACKUPS/ULTIMO"
chmod 600 "$PASTA_BACKUPS/ULTIMO"
echo
ok "backup pronto em $DEST"
log "para voltar atrás: bash $AQUI/desfazer.sh   (usa $PASTA_BACKUPS/ULTIMO)"
