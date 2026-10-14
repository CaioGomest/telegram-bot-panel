#!/usr/bin/env bash
# =====================================================================================================================
# restaurar.sh (instalado como /usr/local/sbin/painel-restaurar) — volta o painel para um backup.
#
#   sudo painel-restaurar /var/backups/painel/diario/banco_2026-10-17_0315.sql.gz [/var/backups/painel/diario/arquivos_2026-10-17_0315.tar.gz]
#
# O que faz: (1) tira um backup do estado ATUAL (para poder desfazer), (2) pausa os crons, (3) apaga e recria o banco,
# (4) importa o dump, (5) restaura os arquivos (se informados), (6) religa os crons. SUBSTITUI os dados atuais.
# =====================================================================================================================
set -Eeuo pipefail
trap 'echo "[ERRO] restauração falhou na linha $LINENO: $BASH_COMMAND — os crons podem estar pausados (veja /root/painel-cron.pausado)" >&2' ERR

(( EUID == 0 )) || { echo "Rode como root." >&2; exit 1; }
BANCO_ARQ="${1:-}"; FILES_ARQ="${2:-}"
[[ -f "$BANCO_ARQ" ]] || { echo "Uso: painel-restaurar ARQUIVO_DO_BANCO.sql.gz [ARQUIVOS.tar.gz]" >&2; exit 1; }
gzip -t "$BANCO_ARQ"
[[ -z "$FILES_ARQ" ]] || [[ -f "$FILES_ARQ" ]] || { echo "Arquivo de arquivos não encontrado: $FILES_ARQ" >&2; exit 1; }
# Trabalha numa CÓPIA dos arquivos escolhidos: o backup de segurança do passo 1 cai na mesma pasta e (se o nome coincidisse) poderia sobrescrever o que você quer restaurar.
COPIA="$(mktemp -d /root/painel-restaurar.XXXXXX)"
trap 'rm -rf "$COPIA"' EXIT
cp "$BANCO_ARQ" "$COPIA/banco.sql.gz"; BANCO_ARQ_ORIG="$BANCO_ARQ"; BANCO_ARQ="$COPIA/banco.sql.gz"
if [[ -n "$FILES_ARQ" ]]; then cp "$FILES_ARQ" "$COPIA/arquivos.tar.gz"; FILES_ARQ_ORIG="$FILES_ARQ"; FILES_ARQ="$COPIA/arquivos.tar.gz"; fi

CONF=/etc/painel/painel.conf
# shellcheck disable=SC1090
[[ -f "$CONF" ]] && . "$CONF"
SITE_DIR="${SITE_DIR:-/var/www/painel}"
BANCO_NOME="${BANCO_NOME:-painel}"

echo "Isto vai SUBSTITUIR o banco '$BANCO_NOME'${FILES_ARQ:+ e os arquivos do painel} pelo conteúdo do backup:"
echo "  banco ..... $BANCO_ARQ_ORIG"
[[ -n "$FILES_ARQ" ]] && echo "  arquivos .. $FILES_ARQ_ORIG"
read -r -p 'Digite RESTAURAR para continuar: ' R
[[ "$R" == "RESTAURAR" ]] || { echo "Cancelado."; exit 1; }

echo "1/6 backup do estado atual (para desfazer)…"
/usr/local/sbin/painel-backup || echo "(aviso) não consegui fazer o backup do estado atual"

echo "2/6 pausando os crons…"
[[ -f /etc/cron.d/painel ]] && mv /etc/cron.d/painel /root/painel-cron.pausado
sleep 3

echo "3/6 recriando o banco…"
mysql -e "DROP DATABASE IF EXISTS \`${BANCO_NOME}\`; CREATE DATABASE \`${BANCO_NOME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "4/6 importando o dump (pode demorar)…"
gunzip -c "$BANCO_ARQ" | mysql "$BANCO_NOME"

if [[ -n "$FILES_ARQ" ]]; then
  echo "5/6 restaurando arquivos…"
  tar xzf "$FILES_ARQ" -C "$SITE_DIR"
  chown root:www-data "$SITE_DIR/config.php"; chmod 640 "$SITE_DIR/config.php"
  for d in uploads certificados storage; do [[ -d "$SITE_DIR/$d" ]] && chown -R www-data:www-data "$SITE_DIR/$d"; done
else
  echo "5/6 (arquivos não informados — mantidos como estão)"
fi

echo "6/6 religando os crons e conferindo…"
[[ -f /root/painel-cron.pausado ]] && mv /root/painel-cron.pausado /etc/cron.d/painel
systemctl reload apache2 || true
TAB="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${BANCO_NOME}'")"
USU="$(mysql -N -e "SELECT COUNT(*) FROM \`${BANCO_NOME}\`.usuarios" 2>/dev/null || echo '?')"
echo "Pronto: ${TAB} tabelas, ${USU} usuários. Abra o painel e confira; rode 'bash deploy/vps/verificar.sh --dominio ...' se quiser."
