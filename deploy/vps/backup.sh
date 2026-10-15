#!/usr/bin/env bash
# =====================================================================================================================
# backup.sh (instalado como /usr/local/sbin/painel-backup) — backup do banco e dos arquivos do painel.
#
#   • banco:    mysqldump consistente (--single-transaction, não trava o painel), comprimido e testado (gzip -t)
#   • arquivos: config.php (tem a chave que descriptografa as credenciais das gateways!), uploads, certificados, storage
#   • sistema:  /etc/painel (aviso de login SSH por Telegram, destino do backup fora da VPS, retenção)
#   • guarda 10 diários + 6 mensais (ajustável em /etc/painel/painel.conf)
#   • cópia FORA da VPS (recomendado): se existir /etc/painel/offsite.conf com RCLONE_DESTINO="remoto:pasta", envia com o rclone
#
# Rodar à mão:  sudo painel-backup          (o cron roda todo dia às 03:15)
# Restaurar:    sudo painel-restaurar ARQUIVO_DO_BANCO.sql.gz [ARQUIVOS.tar.gz]
# =====================================================================================================================
set -Eeuo pipefail
trap 'echo "[$(date "+%F %T")] ERRO no backup (linha $LINENO): $BASH_COMMAND" >&2' ERR

CONF=/etc/painel/painel.conf
# shellcheck disable=SC1090
[[ -f "$CONF" ]] && . "$CONF"
SITE_DIR="${SITE_DIR:-/var/www/painel}"
BANCO_NOME="${BANCO_NOME:-painel}"
DESTINO_BACKUP="${DESTINO_BACKUP:-/var/backups/painel}"
DIAS_DIARIOS="${DIAS_DIARIOS:-10}"
MESES_MENSAIS="${MESES_MENSAIS:-6}"

umask 077
mkdir -p "$DESTINO_BACKUP/diario" "$DESTINO_BACKUP/mensal"
chmod 700 "$DESTINO_BACKUP"

AGORA="$(date +%F_%H%M%S)"   # com segundos: dois backups no mesmo minuto (ex.: o de segurança do restaurar.sh) não podem se sobrescrever
[[ -e "$DESTINO_BACKUP/diario/banco_${AGORA}.sql.gz" ]] && AGORA="${AGORA}-$$"   # nunca sobrescreve um backup que já existe
BANCO_ARQ="$DESTINO_BACKUP/diario/banco_${AGORA}.sql.gz"
FILES_ARQ="$DESTINO_BACKUP/diario/arquivos_${AGORA}.tar.gz"

# espaço: não começa se o disco estiver quase cheio (um backup cortado no meio é pior que nenhum)
USO="$(df --output=pcent "$DESTINO_BACKUP" | tail -1 | tr -dc '0-9')"
if (( USO >= 92 )); then echo "[$(date '+%F %T')] disco em ${USO}% — backup cancelado" >&2; exit 2; fi

echo "[$(date '+%F %T')] backup iniciado"
mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$BANCO_NOME" \
  | gzip -9 > "${BANCO_ARQ}.tmp"
gzip -t "${BANCO_ARQ}.tmp"
[[ "$(stat -c %s "${BANCO_ARQ}.tmp")" -gt 2000 ]] || { echo "dump do banco suspeito (muito pequeno)" >&2; rm -f "${BANCO_ARQ}.tmp"; exit 3; }
mv "${BANCO_ARQ}.tmp" "$BANCO_ARQ"

tar czf "${FILES_ARQ}.tmp" -C "$SITE_DIR" --exclude='storage/cache' config.php uploads certificados storage 2>/dev/null || true
if [[ -s "${FILES_ARQ}.tmp" ]]; then mv "${FILES_ARQ}.tmp" "$FILES_ARQ"; else rm -f "${FILES_ARQ}.tmp"; fi

# Configuração do SISTEMA que não fica na pasta do site: /etc/painel (aviso por Telegram dos logins SSH, destino do backup fora da VPS,
# retenção). Pequena, mas perdê-la numa VPS nova é refazer à mão. Nunca atrapalha o backup principal: se falhar, só não gera este arquivo.
# Restaurar (à mão, o painel-restaurar não mexe nisto):  tar xzf sistema_AAAA-MM-DD_HHMMSS.tar.gz -C /
SIS_ARQ="$DESTINO_BACKUP/diario/sistema_${AGORA}.tar.gz"
if [[ -d /etc/painel ]]; then
  tar czf "${SIS_ARQ}.tmp" -C / etc/painel 2>/dev/null && [[ -s "${SIS_ARQ}.tmp" ]] && mv "${SIS_ARQ}.tmp" "$SIS_ARQ" || rm -f "${SIS_ARQ}.tmp"
fi
echo "[$(date '+%F %T')] banco: $(du -h "$BANCO_ARQ" | cut -f1) | arquivos: $( [[ -f "$FILES_ARQ" ]] && du -h "$FILES_ARQ" | cut -f1 || echo '-' ) | sistema: $( [[ -f "$SIS_ARQ" ]] && du -h "$SIS_ARQ" | cut -f1 || echo '-' )"

# retenção: diários
find "$DESTINO_BACKUP/diario" -type f -mtime +"$DIAS_DIARIOS" -delete
# um backup por mês (dia 1) guardado por mais tempo
MES="$(date +%Y-%m)"
if [[ "$(date +%d)" == "01" ]] && ! ls "$DESTINO_BACKUP/mensal"/banco_"$MES"* >/dev/null 2>&1; then
  cp "$BANCO_ARQ" "$DESTINO_BACKUP/mensal/banco_${MES}.sql.gz"
  [[ -f "$FILES_ARQ" ]] && cp "$FILES_ARQ" "$DESTINO_BACKUP/mensal/arquivos_${MES}.tar.gz"
  [[ -f "$SIS_ARQ" ]] && cp "$SIS_ARQ" "$DESTINO_BACKUP/mensal/sistema_${MES}.tar.gz"
fi
find "$DESTINO_BACKUP/mensal" -type f -mtime +$(( MESES_MENSAIS * 31 )) -delete

# cópia fora da VPS (opcional)
if [[ -f /etc/painel/offsite.conf ]]; then
  # shellcheck disable=SC1091
  . /etc/painel/offsite.conf
  if [[ -n "${RCLONE_DESTINO:-}" ]] && command -v rclone >/dev/null 2>&1; then
    rclone copy "$DESTINO_BACKUP/diario" "$RCLONE_DESTINO/diario" --max-age 2d --quiet && echo "[$(date '+%F %T')] enviado para $RCLONE_DESTINO"
  fi
fi
echo "[$(date '+%F %T')] backup concluído"
