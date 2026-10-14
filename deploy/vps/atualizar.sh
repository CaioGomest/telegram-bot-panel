#!/usr/bin/env bash
# =====================================================================================================================
# atualizar.sh — coloca uma NOVA VERSÃO do código do painel na VPS (sem mexer em config, uploads, logs, banco de dados).
#
#   sudo bash deploy/vps/atualizar.sh --dominio painel.seudominio.com.br [--origem /caminho/do/codigo] [--sem-banco] [--sem-backup]
#
# O que faz: backup → copia o código novo (rsync, sem apagar dados) → ajusta permissões → recarrega o PHP-FPM (limpa o opcache)
#            → roda a migração do banco (Atualizar Banco, pela linha de comando) → roda o verificar.sh.
# Se algo der errado, o backup do passo 1 está em /var/backups/painel/diario (restaurar: painel-restaurar).
# =====================================================================================================================
set -Eeuo pipefail
trap 'echo "[ERRO] falhou na linha $LINENO: $BASH_COMMAND" >&2' ERR

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORIGEM="$(cd "$DIR_SCRIPT/../.." && pwd)"
DOMINIO=""; SEM_BANCO=0; SEM_BACKUP=0; DRY_RUN=0
CONF=/etc/painel/painel.conf
# shellcheck disable=SC1090
[[ -f "$CONF" ]] && . "$CONF"
SITE_DIR="${SITE_DIR:-/var/www/painel}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --origem) ORIGEM="${2:-}"; shift 2 ;;
    --site-dir) SITE_DIR="${2:-}"; shift 2 ;;
    --sem-banco) SEM_BANCO=1; shift ;;
    --sem-backup) SEM_BACKUP=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--ajuda|--help) sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opção desconhecida: $1" >&2; exit 2 ;;
  esac
done

etapa() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
exe() { if (( DRY_RUN )); then printf '   [dry-run] %s\n' "$*"; else "$@"; fi; }

(( EUID == 0 )) || { echo "Rode como root." >&2; exit 1; }
[[ -f "$ORIGEM/webhook.php" && -f "$ORIGEM/instalacao.php" ]] || { echo "Código novo não encontrado em $ORIGEM (use --origem)." >&2; exit 1; }
[[ -f "$SITE_DIR/config.php" ]] || { echo "Não há painel instalado em $SITE_DIR (use instalar.sh)." >&2; exit 1; }
if [[ -z "$DOMINIO" ]]; then
  DOMINIO="$(php -r 'chdir($argv[1]); require "conexao.php"; require "funcoes/configuracoes.php"; echo parse_url(configSistema("url_base"), PHP_URL_HOST) ?: "";' "$SITE_DIR" 2>/dev/null || true)"
fi

etapa "1/5  Backup antes de mexer"
if (( SEM_BACKUP )); then echo "   (pulado: --sem-backup)"; else exe /usr/local/sbin/painel-backup; fi

etapa "2/5  Copiando o código novo"
EXCLUIR=(--exclude='.git/' --exclude='.gitignore' --exclude='.gitattributes' --exclude='.claude/' --exclude='tests/' --exclude='anotacoes/'
         --exclude='deploy/' --exclude='seeds/' --exclude='*.md' --exclude='telegram.zip' --exclude='test_write.txt' --exclude='error_log'
         --exclude='/config.php' --exclude='/uploads/*' --exclude='/logs/*' --exclude='/storage/*' --exclude='/certificados/*')
# --delete remove do servidor o que saiu da nova versão; o que está em --exclude (config, uploads, logs...) fica protegido
exe rsync -a --delete "${EXCLUIR[@]}" "$ORIGEM"/ "$SITE_DIR"/
for d in uploads logs storage certificados; do
  if [[ -f "$ORIGEM/$d/.htaccess" ]]; then exe install -m 0644 "$ORIGEM/$d/.htaccess" "$SITE_DIR/$d/.htaccess"; fi
done

etapa "3/5  Permissões e recarga do PHP"
if (( DRY_RUN )); then
  echo "   [dry-run] aplicaria permissões e recarregaria o PHP-FPM"
else
  chown -R root:www-data "$SITE_DIR"
  find "$SITE_DIR" -type d -exec chmod 750 {} +
  find "$SITE_DIR" -type f -exec chmod 640 {} +
  for d in uploads logs storage certificados; do
    chown -R www-data:www-data "$SITE_DIR/$d"
    find "$SITE_DIR/$d" -type d -exec chmod 750 {} +
    find "$SITE_DIR/$d" -type f -exec chmod 640 {} +
  done
  chown root:www-data "$SITE_DIR/config.php"; chmod 640 "$SITE_DIR/config.php"
  systemctl reload "$(systemctl list-units --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)"
fi

etapa "4/5  Migração do banco (Atualizar Banco)"
if (( SEM_BANCO )); then
  echo "   (pulado: --sem-banco)"
elif (( DRY_RUN )); then
  echo "   [dry-run] runuser -u www-data -- php /usr/local/lib/painel/atualizar-banco.php $SITE_DIR"
else
  # o www-data não consegue ler /root (onde o código novo costuma ficar): roda a migração a partir de uma cópia legível por ele
  install -d -m 0755 /usr/local/lib/painel
  install -m 0644 "$DIR_SCRIPT/atualizar-banco.php" /usr/local/lib/painel/atualizar-banco.php
  runuser -u www-data -- php /usr/local/lib/painel/atualizar-banco.php "$SITE_DIR" | tail -25
fi

etapa "5/5  Verificação"
if (( DRY_RUN )); then echo "   [dry-run] bash $DIR_SCRIPT/verificar.sh --dominio ${DOMINIO:-?}"
elif [[ -n "$DOMINIO" ]]; then bash "$DIR_SCRIPT/verificar.sh" --dominio "$DOMINIO" --site-dir "$SITE_DIR"
else echo "   (informe --dominio para rodar o verificar.sh)"; fi
