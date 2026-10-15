#!/usr/bin/env bash
# =====================================================================================================================
# instalar.sh — instala o painel numa VPS Ubuntu LIMPA (22.04, 24.04 ou 26.04), de ponta a ponta.
#
#   Apache 2.4 (event) + PHP-FPM + MariaDB, HTTPS (Let's Encrypt), cron do painel, backup diário, firewall, fail2ban e a
#   auditoria dos logins SSH da VPS (quem entrou, de que IP e de onde — comando: painel-ssh-acessos).
#
# USO (como root, na VPS, com o código do painel já copiado para ela — veja deploy/PLANO-VPS.md):
#   sudo bash deploy/vps/instalar.sh --dominio painel.seudominio.com.br --email-ssl voce@seudominio.com.br
#
# Pode rodar de novo (idempotente): reaproveita a senha do banco, não recria o admin nem sobrescreve o config.php.
# Antes de rodar de verdade, veja o que ele faria:  --dry-run
# =====================================================================================================================
set -Eeuo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------- parâmetros ----------------------------------------
DOMINIO=""
EMAIL_SSL=""
ADMIN_EMAIL=""
NOME_SISTEMA="Painel"
ORIGEM="$(cd "$DIR_SCRIPT/../.." && pwd)"
SITE_DIR="/var/www/painel"
BANCO_NOME="painel"
BANCO_USUARIO="painel"
SEM_SSL=0
SEM_EMAIL_SSL=0
COM_WWW=0
ENDURECER_SSH=0
SWAP_GB=""
CONTROLE_ID=""
CONTROLE_CHAVE=""
FORCAR=0
DRY_RUN=0
SIM=0

CRED_ARQ="/root/painel-credenciais.txt"
LOG_INSTALACAO="/var/log/painel-instalacao.log"

uso() { sed -n '2,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; cat <<'AJUDA'

Opções:
  --dominio D          (obrigatório) domínio do painel, ex.: painel.seudominio.com.br  (o registro A já deve apontar para esta VPS)
  --email-ssl E        e-mail para o Let's Encrypt (obrigatório, a não ser com --sem-ssl ou --sem-email-ssl)
  --sem-email-ssl      pede o certificado SEM e-mail (você não recebe os avisos de vencimento do Let's Encrypt; a renovação segue automática). Exige --admin-email.
  --admin-email E      e-mail do administrador do painel (padrão: o de --email-ssl)
  --nome-sistema N     nome da marca no painel (padrão: Painel; dá para trocar depois em Admin → Configurações)
  --origem DIR         pasta com o código do painel (padrão: a raiz do repositório onde este script está)
  --site-dir DIR       onde instalar (padrão: /var/www/painel)
  --banco-nome N       nome do banco (padrão: painel)
  --banco-usuario U    usuário do banco (padrão: painel)
  --com-www            inclui www.DOMINIO no certificado
  --sem-ssl            não pede certificado agora (DNS ainda não propagou); rode de novo sem esta opção depois
  --endurecer-ssh      desliga o login por SENHA no SSH (só use se a VPS já tem chave SSH configurada!)
  --swap-gb N          cria N GB de swap (padrão: 2 GB quando a VPS tem até 4 GB de RAM; 0 = não criar)
  --controle-id ID                 suspensão remota: identificador desta instalação (saída de deploy/controle/gerar-chaves.php)
  --controle-chave-publica CHAVE   suspensão remota: chave PÚBLICA (base64, uma linha) — vai para o config.php; a privada fica com você
  --forcar             ignora as verificações de sistema/RAM/DNS (não recomendado)
  --dry-run            só mostra o que seria feito, sem mexer em nada
  --sim                não pergunta nada (confirma sozinho)
  -h, --ajuda          esta mensagem
AJUDA
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --email-ssl) EMAIL_SSL="${2:-}"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="${2:-}"; shift 2 ;;
    --nome-sistema) NOME_SISTEMA="${2:-}"; shift 2 ;;
    --origem) ORIGEM="${2:-}"; shift 2 ;;
    --site-dir) SITE_DIR="${2:-}"; shift 2 ;;
    --banco-nome) BANCO_NOME="${2:-}"; shift 2 ;;
    --banco-usuario) BANCO_USUARIO="${2:-}"; shift 2 ;;
    --com-www) COM_WWW=1; shift ;;
    --sem-ssl) SEM_SSL=1; shift ;;
    --sem-email-ssl) SEM_EMAIL_SSL=1; shift ;;
    --endurecer-ssh) ENDURECER_SSH=1; shift ;;
    --swap-gb) SWAP_GB="${2:-}"; shift 2 ;;
    --controle-id) CONTROLE_ID="${2:-}"; shift 2 ;;
    --controle-chave-publica) CONTROLE_CHAVE="${2:-}"; shift 2 ;;
    --forcar) FORCAR=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    --sim) SIM=1; shift ;;
    -h|--ajuda|--help) uso; exit 0 ;;
    *) echo "Opção desconhecida: $1" >&2; uso; exit 2 ;;
  esac
done

# ---------------------------------------------------------------- utilidades ----------------------------------------
etapa() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()    { printf '\033[1;32m  [ok]\033[0m %s\n' "$*"; }
aviso() { printf '\033[1;33m  [!!]\033[0m %s\n' "$*"; }
falha() { printf '\033[1;31m[ERRO]\033[0m %s\n' "$*" >&2; exit 1; }
trap 'printf "\n\033[1;31m[ERRO]\033[0m falhou na linha %s: %s\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

# executa (ou só mostra, no --dry-run)
exe() { if (( DRY_RUN )); then printf '   [dry-run] %s\n' "$*"; else "$@"; fi; }

# grava o que vier no stdin em um arquivo (ou só mostra o nome, no --dry-run). Uso: escrever CAMINHO MODO <<EOF ... EOF
escrever() {
  local alvo="$1" modo="${2:-644}"
  if (( DRY_RUN )); then
    printf '   [dry-run] gravaria %s (modo %s)\n' "$alvo" "$modo"
    # PAINEL_RENDER_DIR=/pasta: no --dry-run, grava uma CÓPIA dos arquivos de configuração gerados dentro dessa pasta, para revisão
    if [[ -n "${PAINEL_RENDER_DIR:-}" ]]; then mkdir -p "$PAINEL_RENDER_DIR$(dirname "$alvo")"; cat > "$PAINEL_RENDER_DIR$alvo"; else cat >/dev/null; fi
    return 0
  fi
  mkdir -p "$(dirname "$alvo")"
  local novo; novo="$(mktemp)"
  cat > "$novo"
  # Rodar o instalador de novo reescreve estes arquivos (Apache, PHP-FPM, MariaDB, cron, /etc/painel). Se o conteúdo vai mudar, a
  # versão que estava lá é guardada antes: um ajuste manual nunca se perde em silêncio. (O config.php do painel NÃO passa por aqui.)
  if [[ -f "$alvo" ]] && ! cmp -s "$novo" "$alvo"; then
    local guarda="/var/backups/painel/config-antes/${INICIO_EXEC}$(dirname "$alvo")"
    mkdir -p "$guarda"; chmod 700 /var/backups/painel /var/backups/painel/config-antes
    cp -p "$alvo" "$guarda/"
    printf '   (a versão anterior de %s foi guardada em %s)\n' "$alvo" "$guarda"
  fi
  cat "$novo" > "$alvo"; rm -f "$novo"
  chmod "$modo" "$alvo"
}

# valor de uma variável de um arquivo de configuração já existente (ou o padrão): usado para não zerar o que o mantenedor ajustou
conf_valor() { [[ -f "$1" ]] && ( set +u; . "$1" >/dev/null 2>&1; v="${!2:-}"; echo "${v:-$3}" ) || echo "$3"; }

INICIO_EXEC="$(date +%F_%H%M%S)"   # pasta da cópia de segurança das configurações reescritas nesta execução

senha_aleatoria() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c "1-${1:-24}"; }

confirmar() {
  (( SIM || DRY_RUN )) && return 0
  local r; read -r -p "$1 [s/N] " r; [[ "$r" =~ ^[sSyY]$ ]]
}

# ---------------------------------------------------------------- 0. validações ----------------------------------------
etapa "0/12  Verificando o que foi pedido"
[[ -n "$DOMINIO" ]] || { uso; falha "Informe --dominio."; }
[[ "$DOMINIO" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]] || falha "Domínio inválido: $DOMINIO (use minúsculas, ex.: painel.seudominio.com.br)"
(( SEM_SSL || SEM_EMAIL_SSL )) || [[ -n "$EMAIL_SSL" ]] || falha "Informe --email-ssl (ou --sem-email-ssl, ou --sem-ssl para pular o certificado agora)."
[[ -z "$EMAIL_SSL" || "$EMAIL_SSL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || falha "E-mail inválido: $EMAIL_SSL"
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL_SSL}"
[[ -n "$ADMIN_EMAIL" ]] || falha "Informe --admin-email (ou --email-ssl)."
[[ "$BANCO_NOME" =~ ^[A-Za-z0-9_]{1,40}$ && "$BANCO_USUARIO" =~ ^[A-Za-z0-9_]{1,30}$ ]] || falha "Nome de banco/usuário só aceita letras, números e _."
[[ "$SITE_DIR" =~ ^/[A-Za-z0-9_./-]+$ ]] || falha "--site-dir inválido."
if [[ -n "$CONTROLE_ID$CONTROLE_CHAVE" ]]; then
  [[ "$CONTROLE_ID" =~ ^[A-Za-z0-9_-]{8,64}$ ]] || falha "--controle-id inválido (8 a 64 letras, números, - ou _)."
  [[ "$CONTROLE_CHAVE" =~ ^[A-Za-z0-9+/]+={0,2}$ && ${#CONTROLE_CHAVE} -ge 300 && ${#CONTROLE_CHAVE} -le 1500 ]] || falha "--controle-chave-publica inválida (precisa ser o base64 em UMA linha que o gerar-chaves.php imprime)."
fi
[[ -f "$ORIGEM/instalacao.php" && -f "$ORIGEM/webhook.php" && -f "$ORIGEM/conexao.php" ]] || falha "Código do painel não encontrado em $ORIGEM (faltam instalacao.php/webhook.php/conexao.php). Use --origem."
(( EUID == 0 )) || { (( DRY_RUN )) && aviso "Rodando o --dry-run sem ser root (só mostra)." || falha "Rode como root (sudo bash $0 ...)."; }

if (( ! DRY_RUN )); then
  mkdir -p "$(dirname "$LOG_INSTALACAO")"
  : >> "$LOG_INSTALACAO"; chmod 600 "$LOG_INSTALACAO"   # o resumo final imprime a senha do admin
  exec > >(tee -a "$LOG_INSTALACAO") 2>&1
  echo "--- instalação iniciada em $(date '+%F %T') ---"
fi

# shellcheck disable=SC1091
. /etc/os-release 2>/dev/null || true
if [[ "${ID:-}" != "ubuntu" || ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04|26\.04)$ ]]; then
  (( FORCAR )) && aviso "Sistema não suportado (${PRETTY_NAME:-desconhecido}); seguindo por causa do --forcar." \
               || falha "Sistema suportado: Ubuntu 22.04, 24.04 ou 26.04 limpo (achei: ${PRETTY_NAME:-desconhecido}). Reinstale a VPS com o Ubuntu 'puro' (sem painel/Docker) ou use --forcar."
fi

RAM_MB="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
CPUS="$(nproc)"
DISCO_LIVRE_GB="$(df -BG --output=avail / | tail -1 | tr -dc '0-9')"
ok "Sistema: ${PRETTY_NAME:-?} | ${CPUS} vCPU | ${RAM_MB} MB de RAM | ${DISCO_LIVRE_GB} GB livres"
(( RAM_MB >= 1800 )) || { (( FORCAR )) && aviso "Pouca RAM (${RAM_MB} MB)." || falha "RAM insuficiente (${RAM_MB} MB). Mínimo recomendado: 2 GB (VPS de 2 vCPU / 4-8 GB é o ideal)."; }
(( DISCO_LIVRE_GB >= 10 )) || { (( FORCAR )) && aviso "Pouco disco." || falha "Menos de 10 GB livres."; }

# portas 80/443 devem estar livres (ou já ser do Apache de uma execução anterior)
for p in 80 443; do
  if grep -q . < <(ss -ltnH "sport = :$p" 2>/dev/null) && ! grep -q apache2 < <(ss -ltnp "sport = :$p" 2>/dev/null); then
    (( FORCAR )) && aviso "Porta $p ocupada por outro serviço." || falha "A porta $p já está em uso por outro serviço (nginx/docker/painel de hospedagem?). Libere ou use uma VPS limpa."
  fi
done

# o domínio precisa apontar para esta VPS (senão o certificado falha)
IP_VPS="$(curl -4fsS --max-time 10 https://api.ipify.org 2>/dev/null || curl -4fsS --max-time 10 https://ifconfig.me 2>/dev/null || true)"
IP_DNS="$(getent ahostsv4 "$DOMINIO" 2>/dev/null | awk 'NR==1{print $1}' || true)"
# O /etc/hosts da própria VPS costuma apontar o nome dela (ex.: srv123.hstgr.cloud) para 127.0.1.1 e esconde o DNS público: pergunta a um DNS de verdade
if [[ -z "$IP_DNS" || "$IP_DNS" == 127.* ]]; then
  IP_DNS="$(curl -fsS --max-time 10 -H 'accept: application/dns-json' "https://cloudflare-dns.com/dns-query?name=$DOMINIO&type=A" 2>/dev/null | grep -oE '"data":"[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+"' | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' || true)"
fi
if [[ -n "$IP_VPS" && "$IP_DNS" == "$IP_VPS" ]]; then
  ok "DNS ok: $DOMINIO → $IP_DNS (esta VPS)"
else
  MSG="O domínio $DOMINIO aponta para '${IP_DNS:-nada}', mas esta VPS é '${IP_VPS:-desconhecido}'. Crie o registro A $DOMINIO → ${IP_VPS:-IP da VPS} e espere propagar."
  if (( SEM_SSL || FORCAR )); then aviso "$MSG (seguindo sem certificado/por --forcar)"; else falha "$MSG Ou rode com --sem-ssl e peça o certificado depois."; fi
fi

if [[ -z "$SWAP_GB" ]]; then (( RAM_MB <= 4096 )) && SWAP_GB=2 || SWAP_GB=0; fi

# ajustes calculados pelo tamanho da VPS
BUFFER_MB=$(( RAM_MB / 4 )); (( BUFFER_MB < 256 )) && BUFFER_MB=256; (( BUFFER_MB > 4096 )) && BUFFER_MB=4096
# Teste de carga de 13/10/2026 (VPS 2 vCPU/8 GB): o gargalo do funil de vendas era o NÚMERO de processos do PHP-FPM — cada webhook segura um processo enquanto
# espera o Telegram e a gateway (~2 s), com a CPU quase parada. Com 48 processos: ~23 clientes/s; com 150: ~65/s. Cada processo extra custa só ~3 MB (opcache compartilhado).
FPM_FILHOS=$(( RAM_MB / 50 )); (( FPM_FILHOS < 8 )) && FPM_FILHOS=8; (( FPM_FILHOS > 200 )) && FPM_FILHOS=200
# O Apache precisa aceitar bem mais requisições simultâneas que os processos do PHP (senão eles se esgotam entre si: "server reached MaxRequestWorkers"):
# 3× os processos do PHP, em múltiplos de 25 (threads por processo do Apache), mínimo 200.
APACHE_MAXREQ=$(( (FPM_FILHOS * 3 + 24) / 25 * 25 )); (( APACHE_MAXREQ < 200 )) && APACHE_MAXREQ=200
APACHE_SERVERLIMIT=$(( APACHE_MAXREQ / 25 )); (( APACHE_SERVERLIMIT < 16 )) && APACHE_SERVERLIMIT=16
# conexões do banco: 1 por processo do PHP (conexão persistente) + crons + folga
DB_CONEXOES=$(( FPM_FILHOS + 150 )); (( DB_CONEXOES < 300 )) && DB_CONEXOES=300
FPM_INI=$(( FPM_FILHOS / 4 )); (( FPM_INI < 3 )) && FPM_INI=3
FPM_MIN=$(( FPM_INI / 2 )); (( FPM_MIN < 2 )) && FPM_MIN=2
FPM_MAX=$(( FPM_INI * 2 )); (( FPM_MAX > FPM_FILHOS )) && FPM_MAX=$FPM_FILHOS

TXT_WWW=""; if (( COM_WWW )); then TXT_WWW="(+ www)"; fi
TXT_CERT="Let's Encrypt (${EMAIL_SSL:-sem e-mail})"; if (( SEM_SSL )); then TXT_CERT="NÃO agora (--sem-ssl)"; fi
TXT_SSH="inalterado"; if (( ENDURECER_SSH )); then TXT_SSH="login por SENHA será DESLIGADO"; fi
TXT_CONTROLE="NÃO configurada (sem --controle-id/--controle-chave-publica; veja deploy/PLANO-VPS.md)"; if [[ -n "$CONTROLE_ID" ]]; then TXT_CONTROLE="ativada (ID $CONTROLE_ID)"; fi
cat <<RESUMO

  Vai instalar:
    domínio .......... $DOMINIO $TXT_WWW
    admin do painel .. $ADMIN_EMAIL
    pasta do site .... $SITE_DIR      (código de: $ORIGEM)
    banco ............ $BANCO_NOME (usuário $BANCO_USUARIO, senha aleatória)
    certificado ...... $TXT_CERT
    ajustes .......... buffer do banco ${BUFFER_MB} MB | PHP-FPM até ${FPM_FILHOS} processos | Apache até ${APACHE_MAXREQ} requisições simultâneas | swap ${SWAP_GB} GB
    SSH .............. $TXT_SSH
    suspensão remota . $TXT_CONTROLE
RESUMO
confirmar "Continuar?" || falha "Cancelado."

# ---------------------------------------------------------------- 1. sistema ----------------------------------------
etapa "1/12  Sistema (fuso horário, pacotes, swap)"
exe timedatectl set-timezone America/Sao_Paulo
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
exe apt-get update -y
exe apt-get -o Dpkg::Options::="--force-confold" upgrade -y
exe apt-get -o Dpkg::Options::="--force-confold" install -y \
  apache2 mariadb-server php-fpm php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl \
  curl rsync unzip openssl ca-certificates ufw fail2ban unattended-upgrades certbot python3-certbot-apache cron logrotate dnsutils
if (( DRY_RUN )); then PHPV="8.x"; else PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"; fi
ok "PHP $PHPV instalado"
if (( ! DRY_RUN )) && php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then :; elif (( ! DRY_RUN )); then falha "PHP abaixo de 8.1."; fi

if (( SWAP_GB > 0 )) && [[ -z "$(swapon --show --noheadings 2>/dev/null)" ]]; then
  exe fallocate -l "${SWAP_GB}G" /swapfile
  exe chmod 600 /swapfile
  exe mkswap /swapfile
  exe swapon /swapfile
  (( DRY_RUN )) || grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  escrever /etc/sysctl.d/99-painel.conf 644 <<'EOF'
vm.swappiness = 10
EOF
  exe sysctl --system >/dev/null
  ok "swap de ${SWAP_GB} GB criado"
fi

# ---------------------------------------------------------------- 2. banco ----------------------------------------
etapa "2/12  MariaDB (banco, usuário e ajustes — alinhados ao ambiente que foi testado)"
# senha do banco: a que o painel JÁ USA (config.php) manda; depois a do arquivo de credenciais; só então uma nova.
# Sem isso, rodar o instalador de novo depois de apagar o painel-credenciais.txt (o que o próprio fim do script recomenda) gerava
# uma senha nova, trocava no MariaDB (ALTER USER) e o config.php, que não é reescrito, ficava com a antiga: o painel perdia o banco.
BANCO_SENHA=""
if [[ -f "$SITE_DIR/config.php" ]] && command -v php >/dev/null 2>&1; then
  BANCO_SENHA="$(php -r 'include $argv[1]; echo defined("BANCO_SENHA") ? BANCO_SENHA : "";' "$SITE_DIR/config.php" 2>/dev/null || true)"
  [[ -z "$BANCO_SENHA" ]] || ok "senha do banco: a mesma que o config.php já usa"
fi
if [[ -z "$BANCO_SENHA" && -f "$CRED_ARQ" ]]; then BANCO_SENHA="$(grep -m1 '^BANCO_SENHA=' "$CRED_ARQ" | cut -d= -f2- || true)"; fi
[[ -n "$BANCO_SENHA" ]] || BANCO_SENHA="$(senha_aleatoria 28)"

# IMPORTANTE: sql_mode e isolamento iguais aos do ambiente de homologação onde o painel foi validado. O padrão do MariaDB
# (modo ESTRITO) recusa texto maior que a coluna (ex.: nome de lead com mais de 100 caracteres) — no painel isso derrubaria
# o cadastro do lead em silêncio. A homologação roda sem modo estrito (NO_ENGINE_SUBSTITUTION) e em READ-COMMITTED.
escrever /etc/mysql/mariadb.conf.d/99-painel.cnf 644 <<EOF
[mysqld]
character-set-server           = utf8mb4
collation-server               = utf8mb4_unicode_ci
bind-address                   = 127.0.0.1
skip-name-resolve
sql_mode                       = NO_ENGINE_SUBSTITUTION
transaction-isolation          = READ-COMMITTED
default-time-zone              = '-03:00'
wait_timeout                   = 3600
interactive_timeout            = 3600
max_connections                = ${DB_CONEXOES}
max_allowed_packet             = 64M
tmp_table_size                 = 64M
max_heap_table_size            = 64M
innodb_buffer_pool_size        = ${BUFFER_MB}M
innodb_flush_method            = O_DIRECT
innodb_flush_log_at_trx_commit = 1
slow_query_log                 = 1
slow_query_log_file            = /var/log/mysql/slow.log
long_query_time                = 1
EOF
exe systemctl enable mariadb
exe systemctl restart mariadb
if (( ! DRY_RUN )); then
  mysql <<SQL
DROP USER IF EXISTS ''@'localhost';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS \`${BANCO_NOME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${BANCO_USUARIO}'@'localhost' IDENTIFIED BY '${BANCO_SENHA}';
ALTER USER '${BANCO_USUARIO}'@'localhost' IDENTIFIED BY '${BANCO_SENHA}';
GRANT ALL PRIVILEGES ON \`${BANCO_NOME}\`.* TO '${BANCO_USUARIO}'@'localhost';
FLUSH PRIVILEGES;
SQL
  mysql -e "SELECT @@sql_mode AS sql_mode, @@time_zone AS fuso, @@wait_timeout AS wait_timeout; SHOW VARIABLES WHERE Variable_name IN ('tx_isolation','transaction_isolation')" | sed 's/^/     /'
fi
ok "banco '${BANCO_NOME}' pronto"

# ---------------------------------------------------------------- 3. PHP ----------------------------------------
etapa "3/12  PHP-FPM"
escrever "/etc/php/${PHPV}/fpm/conf.d/99-painel.ini" 644 <<'EOF'
; Ajustes do painel (php-fpm). Os mesmos valores valem para a linha de comando em /etc/php/*/cli/conf.d/99-painel.ini
date.timezone = America/Sao_Paulo
memory_limit = 512M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 60
max_input_time = 60
max_input_vars = 5000
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
; mesmas funções desligadas da hospedagem de homologação (o painel não usa nenhuma delas)
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,dl,leak,virtual,apache_child_terminate,mb_send_mail,ini_alter,chgrp,symlink,link,mysql_list_dbs
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
EOF
escrever "/etc/php/${PHPV}/cli/conf.d/99-painel.ini" 644 <<'EOF'
; Os crons do painel rodam por aqui (CLI). Sem limite de tempo/memória apertado e com o mesmo fuso do site.
date.timezone = America/Sao_Paulo
memory_limit = 1024M
max_execution_time = 0
display_errors = Off
log_errors = On
error_log = /var/log/php/painel-cli-erros.log
opcache.enable_cli = 0
EOF
exe mkdir -p /var/log/php /var/lib/php/painel-sessoes
exe chown www-data:www-data /var/log/php /var/lib/php/painel-sessoes
exe chmod 750 /var/lib/php/painel-sessoes
# o cron roda como www-data e grava no mesmo arquivo de erros do CLI
exe touch /var/log/php/painel-cli-erros.log /var/log/php/painel-erros.log
exe chown www-data:www-data /var/log/php/painel-cli-erros.log /var/log/php/painel-erros.log

# Sessões: pasta própria e coleta pelo PRÓPRIO PHP, com o mesmo prazo de 30 dias que o painel usa no "lembrar-me". (No Ubuntu o
# padrão é um cron do sistema que apaga sessão com mais de 24 minutos sem uso — o painel deslogaria todo mundo toda hora.)
escrever "/etc/php/${PHPV}/fpm/pool.d/painel.conf" 644 <<EOF
[painel]
user = www-data
group = www-data
listen = /run/php/painel.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = ${FPM_FILHOS}
pm.start_servers = ${FPM_INI}
pm.min_spare_servers = ${FPM_MIN}
pm.max_spare_servers = ${FPM_MAX}
pm.max_requests = 500
request_terminate_timeout = 120s
request_slowlog_timeout = 15s
slowlog = /var/log/php/painel-lento.log
catch_workers_output = yes
security.limit_extensions = .php

php_admin_value[error_log] = /var/log/php/painel-erros.log
php_admin_flag[log_errors] = on
php_admin_value[session.save_path] = /var/lib/php/painel-sessoes
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 200
php_admin_value[session.gc_maxlifetime] = 2592000
EOF
if [[ -f "/etc/php/${PHPV}/fpm/pool.d/www.conf" ]]; then exe mv "/etc/php/${PHPV}/fpm/pool.d/www.conf" "/etc/php/${PHPV}/fpm/pool.d/www.conf.desativado"; fi
escrever /etc/logrotate.d/painel-php 644 <<'EOF'
/var/log/php/*.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}
EOF
exe systemctl enable "php${PHPV}-fpm"
exe systemctl restart "php${PHPV}-fpm"
ok "PHP-FPM com pool 'painel' (até ${FPM_FILHOS} processos)"

# ---------------------------------------------------------------- 4. Apache ----------------------------------------
etapa "4/12  Apache"
exe mkdir -p "$SITE_DIR"
exe a2dismod -q mpm_prefork mpm_worker || true
exe a2enmod -q mpm_event rewrite headers expires deflate http2 proxy proxy_fcgi setenvif ssl
# "zz-": o Apache carrega os conf em ordem alfabética e o security.conf do Ubuntu (ServerTokens OS) viria depois e venceria o nosso (ServerTokens Prod)
escrever /etc/apache2/conf-available/zz-painel-global.conf 644 <<EOF
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 120
KeepAlive On
MaxKeepAliveRequests 200
KeepAliveTimeout 3
Protocols h2 http/1.1
ProxyTimeout 120

<IfModule mpm_event_module>
    StartServers 2
    MinSpareThreads 25
    MaxSpareThreads 75
    ThreadsPerChild 25
    ServerLimit ${APACHE_SERVERLIMIT}
    MaxRequestWorkers ${APACHE_MAXREQ}
    MaxConnectionsPerChild 10000
</IfModule>

# arquivos estáticos (css/js/imagens trazem ?v= para trocar de versão): cache de 7 dias
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType image/png "access plus 7 days"
    ExpiresByType image/jpeg "access plus 7 days"
    ExpiresByType image/svg+xml "access plus 7 days"
    ExpiresByType image/webp "access plus 7 days"
</IfModule>
EOF
SERVER_ALIAS=""
(( COM_WWW )) && SERVER_ALIAS="ServerAlias www.${DOMINIO}"
escrever /etc/apache2/sites-available/painel.conf 644 <<EOF
<VirtualHost *:80>
    ServerName ${DOMINIO}
    ${SERVER_ALIAS}
    DocumentRoot ${SITE_DIR}
    DirectoryIndex index.php

    <Directory ${SITE_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # PHP pelo PHP-FPM (pool "painel")
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/painel.sock|fcgi://localhost"
    </FilesMatch>

    # controle de versão nunca sai pela web (o .htaccess também bloqueia; aqui é a segunda trava)
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    ErrorLog \${APACHE_LOG_DIR}/painel-error.log
    CustomLog \${APACHE_LOG_DIR}/painel-access.log combined
</VirtualHost>
EOF
exe a2disconf -q "php${PHPV}-fpm" || true
if [[ -f /etc/apache2/conf-available/painel-global.conf ]]; then exe a2disconf -q painel-global || true; exe rm -f /etc/apache2/conf-available/painel-global.conf; fi   # nome antigo
exe a2enconf -q zz-painel-global
exe a2dissite -q 000-default || true
exe a2ensite -q painel
exe systemctl enable apache2
if (( ! DRY_RUN )); then apache2ctl configtest; fi
exe systemctl restart apache2
ok "Apache configurado"

# ---------------------------------------------------------------- 5. código ----------------------------------------
etapa "5/12  Código do painel → ${SITE_DIR}"
exe mkdir -p "$SITE_DIR"
# NÃO vai para o servidor: testes, anotações internas, deploy, dados de demonstração, documentação, git, config e dados de runtime
EXCLUIR=(--exclude='.git/' --exclude='.gitignore' --exclude='.gitattributes' --exclude='.claude/' --exclude='tests/' --exclude='anotacoes/'
         --exclude='deploy/' --exclude='seeds/' --exclude='*.md' --exclude='telegram.zip' --exclude='test_write.txt' --exclude='error_log'
         --exclude='/config.php' --exclude='/uploads/*' --exclude='/logs/*' --exclude='/storage/*' --exclude='/certificados/*')
exe rsync -a "${EXCLUIR[@]}" "$ORIGEM"/ "$SITE_DIR"/
for d in uploads logs storage certificados; do
  exe mkdir -p "$SITE_DIR/$d"
  # o .htaccess de cada pasta de dados (bloqueio de acesso) vai junto
  if [[ -f "$ORIGEM/$d/.htaccess" ]]; then exe install -m 0644 "$ORIGEM/$d/.htaccess" "$SITE_DIR/$d/.htaccess"; fi
done
exe mkdir -p "$SITE_DIR/storage/cache"
ok "código copiado"

# permissões: o código é do root (o PHP só lê); só estas pastas são do www-data
aplicar_permissoes() {
  chown -R root:www-data "$SITE_DIR"
  find "$SITE_DIR" -type d -exec chmod 750 {} +
  find "$SITE_DIR" -type f -exec chmod 640 {} +
  for d in uploads logs storage certificados; do
    chown -R www-data:www-data "$SITE_DIR/$d"
    find "$SITE_DIR/$d" -type d -exec chmod 750 {} +
    find "$SITE_DIR/$d" -type f -exec chmod 640 {} +
  done
}
(( DRY_RUN )) && echo "   [dry-run] aplicaria permissões (código root:www-data 750/640; uploads, logs, storage, certificados do www-data)" || aplicar_permissoes

# ---------------------------------------------------------------- 6. instalador ----------------------------------------
etapa "6/12  Criando as tabelas e o config.php (rodando o próprio instalador do painel)"
ADMIN_SENHA=""
if [[ -f "$SITE_DIR/config.php" ]]; then
  ok "config.php já existe — não vou reinstalar nem recriar o admin (rode o atualizar.sh para novas versões)"
  # Trocar de domínio = rodar este script de novo com o novo --dominio (o Apache e o certificado seguem abaixo). Aqui acerta a "URL do painel".
  if (( ! DRY_RUN )); then
    URL_ATUAL="$(SITE_DIR="$SITE_DIR" runuser -p -u www-data -- php -r 'chdir(getenv("SITE_DIR")); require "conexao.php"; require "funcoes/configuracoes.php"; echo configSistema("url_base");' 2>/dev/null || true)"
    if [[ -n "$URL_ATUAL" && "$URL_ATUAL" != "https://$DOMINIO" ]]; then
      SITE_DIR="$SITE_DIR" URL_BASE="https://$DOMINIO" runuser -p -u www-data -- php -r 'chdir(getenv("SITE_DIR")); require "conexao.php"; require "funcoes/configuracoes.php"; definirConfigSistema("url_base", getenv("URL_BASE"));' \
        && ok "'URL do painel' trocada: $URL_ATUAL → https://$DOMINIO" || aviso "não consegui trocar a 'URL do painel' (Admin → Configurações)"
      N_BOTS="$(mysql -N -e "SELECT COUNT(*) FROM \`${BANCO_NOME}\`.bots WHERE url_webhook IS NOT NULL AND url_webhook <> '' AND url_webhook NOT LIKE 'https://${DOMINIO}/%'" 2>/dev/null || echo 0)"
      (( N_BOTS > 0 )) && aviso "$N_BOTS bot(s) ainda têm o webhook do Telegram no endereço ANTIGO: abra cada bot em Meus Bots e salve de novo (ou use 'reativar webhook') para ele apontar para https://$DOMINIO"
    fi
  fi
else
  if (( DRY_RUN )); then
    echo "   [dry-run] GET http://127.0.0.1/instalacao (Host: $DOMINIO) → pega o token CSRF → POST com banco/usuário/senha"
  else
    chmod 770 "$SITE_DIR"   # só durante a instalação: o instalador grava o config.php na pasta do site
    JAR="$(mktemp)"
    HTML="$(curl -fsS -c "$JAR" -H "Host: $DOMINIO" http://127.0.0.1/instalacao)" || falha "Não consegui abrir http://127.0.0.1/instalacao (o Apache/PHP-FPM responderam erro). Veja /var/log/apache2/painel-error.log e /var/log/php/painel-erros.log"
    TOKEN="$(printf '%s' "$HTML" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//' || true)"
    [[ -n "$TOKEN" ]] || falha "O instalador não devolveu o token de segurança (o painel já foi instalado, ou o PHP não está funcionando?)."
    RESP="$(curl -fsS -b "$JAR" -H "Host: $DOMINIO" http://127.0.0.1/instalacao \
      --data-urlencode "csrf_token=$TOKEN" --data-urlencode "banco_host=localhost" --data-urlencode "nome_banco=$BANCO_NOME" \
      --data-urlencode "usuario_banco=$BANCO_USUARIO" --data-urlencode "senha_banco=$BANCO_SENHA" --data-urlencode "nome_sistema=$NOME_SISTEMA")" || falha "Falha ao enviar o formulário do instalador."
    rm -f "$JAR"
    [[ "$RESP" == *'Instalação concluída com sucesso'* ]] || { printf '%s' "$RESP" | sed 's/<[^>]*>//g' | grep -i 'erro' | head -3; falha "O instalador não confirmou a instalação."; }
    [[ -f "$SITE_DIR/config.php" ]] || falha "O instalador disse que terminou, mas não gravou o config.php."
    chmod 750 "$SITE_DIR"
    chown root:www-data "$SITE_DIR/config.php"; chmod 640 "$SITE_DIR/config.php"
    ok "tabelas criadas e config.php gravado"

    # o instalador cria o admin padrão (admin@exemplo.com / 123456): troca AGORA, antes de qualquer acesso, e grava a URL pública
    ADMIN_SENHA="$(senha_aleatoria 20)"
    SITE_DIR="$SITE_DIR" ADMIN_EMAIL="$ADMIN_EMAIL" ADMIN_SENHA="$ADMIN_SENHA" URL_BASE="https://$DOMINIO" \
    runuser -p -u www-data -- php -d display_errors=1 -r '
      chdir(getenv("SITE_DIR")); require "conexao.php"; require "funcoes/configuracoes.php";
      $st = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ?, email_verificado_em = NOW() WHERE id = 1 AND perfil = \"admin\"");
      $st->execute(["Administrador", getenv("ADMIN_EMAIL"), password_hash(getenv("ADMIN_SENHA"), PASSWORD_DEFAULT)]);
      if ($st->rowCount() < 1) { fwrite(STDERR, "admin padrão não encontrado\n"); exit(1); }
      definirConfigSistema("url_base", getenv("URL_BASE"));
      echo "admin e URL pública configurados\n";
    ' || falha "Não consegui trocar a senha do admin padrão. TROQUE manualmente (admin@exemplo.com / 123456) antes de abrir o site!"
    ok "senha do admin padrão trocada por uma aleatória; 'URL do painel' gravada"
  fi
fi

# Suspensão remota (funcoes/controle.php): grava só a chave PÚBLICA no config.php (root:www-data 640 — o PHP apenas lê). A privada nunca vem para cá.
# Chave dos crons chamados por URL: o instalador do painel já grava; aqui só completa um config.php mais antigo (sem ela o cron pela web dava erro 500 em vez de 403)
if (( ! DRY_RUN )) && [[ -f "$SITE_DIR/config.php" ]] && ! grep -q "define('CHAVE_SECRETA_CRON'" "$SITE_DIR/config.php"; then
  printf "define('CHAVE_SECRETA_CRON', '%s');\n" "$(senha_aleatoria 32)" >> "$SITE_DIR/config.php"
  ok "CHAVE_SECRETA_CRON acrescentada ao config.php"
fi

if [[ -n "$CONTROLE_ID" ]]; then
  if (( DRY_RUN )); then
    echo "   [dry-run] acrescentaria CONTROLE_ID e CONTROLE_CHAVE_PUBLICA ao $SITE_DIR/config.php"
  elif [[ ! -f "$SITE_DIR/config.php" ]]; then
    aviso "config.php não existe — não consegui gravar a suspensão remota."
  elif grep -q "define('CONTROLE_ID'" "$SITE_DIR/config.php"; then
    if grep -q "define('CONTROLE_ID', '$CONTROLE_ID')" "$SITE_DIR/config.php"; then ok "suspensão remota já configurada neste config.php (mesmo ID)"
    else aviso "o config.php já tem OUTRO CONTROLE_ID — não alterei (edite à mão se for trocar a chave)."; fi
  else
    {
      printf '\n// Suspensão remota (funcoes/controle.php): só a chave PÚBLICA fica aqui; a privada fica com quem administra a VPS.\n'
      printf "define('CONTROLE_ID', '%s');\n" "$CONTROLE_ID"
      printf "define('CONTROLE_CHAVE_PUBLICA', '%s');\n" "$CONTROLE_CHAVE"
    } >> "$SITE_DIR/config.php"
    php -l "$SITE_DIR/config.php" >/dev/null || falha "config.php ficou inválido depois de gravar a suspensão remota (restaure de /var/backups ou corrija à mão)."
    ok "suspensão remota ativada (ID $CONTROLE_ID) — teste: php deploy/controle/enviar.php NOME consultar"
  fi
fi

# ---------------------------------------------------------------- 7. cron ----------------------------------------
etapa "7/12  Cron do painel (10 rotinas)"
escrever /etc/cron.d/painel 644 <<EOF
# Rotinas do painel — rodam como www-data pela linha de comando (não precisam de chave). Cada uma tem trava própria contra sobreposição.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
* * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_verificar_pix.php      >/dev/null 2>&1
* * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_verificar_acessos.php  >/dev/null 2>&1
* * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_aviso_vencimento.php   >/dev/null 2>&1
* * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_ranking.php            >/dev/null 2>&1
* * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_remarketing.php        >/dev/null 2>&1
*/5 * * * *  www-data /usr/bin/php ${SITE_DIR}/cron/cron_metricas_admin.php     >/dev/null 2>&1
0 * * * *    www-data /usr/bin/php ${SITE_DIR}/cron/cron_renovacao.php          >/dev/null 2>&1
30 3 * * *   www-data /usr/bin/php ${SITE_DIR}/cron/cron_limpar_stories.php     >/dev/null 2>&1
45 3 * * *   www-data /usr/bin/php ${SITE_DIR}/cron/cron_limpar_remarketing_envios.php >/dev/null 2>&1
15 4 * * *   www-data /usr/bin/php ${SITE_DIR}/cron/cron_rotacionar_logs.php    >/dev/null 2>&1
EOF
exe systemctl enable cron
exe systemctl restart cron
ok "10 rotinas agendadas em /etc/cron.d/painel"

# ---------------------------------------------------------------- 8. backup ----------------------------------------
etapa "8/12  Backup diário"
BACKUP_ORIGEM="$DIR_SCRIPT/backup.sh"
RESTAURAR_ORIGEM="$DIR_SCRIPT/restaurar.sh"
if [[ -f "$BACKUP_ORIGEM" && -f "$RESTAURAR_ORIGEM" ]]; then
  exe install -m 0750 "$BACKUP_ORIGEM" /usr/local/sbin/painel-backup
  exe install -m 0750 "$RESTAURAR_ORIGEM" /usr/local/sbin/painel-restaurar
  # o que o mantenedor já ajustou neste arquivo (retenção, destino) é mantido; só SITE_DIR e BANCO_NOME vêm sempre desta instalação
  DESTINO_BACKUP_CONF="$(conf_valor /etc/painel/painel.conf DESTINO_BACKUP /var/backups/painel)"
  DIAS_DIARIOS_CONF="$(conf_valor /etc/painel/painel.conf DIAS_DIARIOS 10)"
  MESES_MENSAIS_CONF="$(conf_valor /etc/painel/painel.conf MESES_MENSAIS 6)"
  escrever /etc/painel/painel.conf 600 <<EOF
SITE_DIR="${SITE_DIR}"
BANCO_NOME="${BANCO_NOME}"
DESTINO_BACKUP="${DESTINO_BACKUP_CONF}"
DIAS_DIARIOS=${DIAS_DIARIOS_CONF}
MESES_MENSAIS=${MESES_MENSAIS_CONF}
EOF
  escrever /etc/cron.d/painel-backup 644 <<'EOF'
# Backup do banco + arquivos do painel (config, uploads, certificados) todo dia às 03:15
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 3 * * * root /usr/local/sbin/painel-backup >> /var/log/painel-backup.log 2>&1
EOF
  ok "backup diário às 03:15 (10 diários + 6 mensais em /var/backups/painel)"
else
  aviso "backup.sh/restaurar.sh não encontrados ao lado deste script — pulei o backup (INSTALE antes de abrir ao público!)"
fi

# ---------------------------------------------------------------- 9. segurança ----------------------------------------
etapa "9/12  Firewall, fail2ban, auditoria de logins SSH e atualizações automáticas"
SSH_PORTA="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}' || true)"; SSH_PORTA="${SSH_PORTA:-22}"
exe ufw default deny incoming
exe ufw default allow outgoing
exe ufw allow "${SSH_PORTA}/tcp"
exe ufw allow 80/tcp
exe ufw allow 443/tcp
exe ufw --force enable
escrever /etc/fail2ban/jail.d/painel.local 644 <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
port    = ${SSH_PORTA}

[recidive]
enabled = true
EOF
exe systemctl enable fail2ban
exe systemctl restart fail2ban
escrever /etc/apt/apt.conf.d/20auto-upgrades 644 <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
ok "ufw ativo (SSH ${SSH_PORTA}, 80, 443), fail2ban e atualizações de segurança automáticas"

# Auditoria dos logins SSH (Linux): serviço que lê o journal do sshd e grava quem entrou, de que IP e de onde. Não mexe no SSH/PAM.
if [[ -f "$DIR_SCRIPT/ssh-acessos.php" ]]; then
  exe install -m 0750 "$DIR_SCRIPT/ssh-acessos.php" /usr/local/sbin/painel-ssh-acessos
  exe install -d -m 0750 -o root -g adm /var/log/painel-ssh
  exe install -d -m 0750 -o root -g root /var/lib/painel-ssh
  if [[ ! -f /etc/painel/ssh-acessos.conf ]]; then   # não sobrescreve o que o mantenedor já configurou (token do Telegram etc.)
    escrever /etc/painel/ssh-acessos.conf 600 <<'EOF'
# Auditoria dos logins SSH — veja:  painel-ssh-acessos ajuda
# Aviso por Telegram (a cópia que fica FORA da VPS; quem tem root consegue apagar o registro local):
#   1. crie um bot no @BotFather e cole o token abaixo; 2. mande /start para o bot; 3. descubra seu chat id em
#   https://api.telegram.org/bot<TOKEN>/getUpdates ; 4. systemctl restart painel-ssh-acessos ; 5. painel-ssh-acessos testar-alerta
#TELEGRAM_BOT_TOKEN=
#TELEGRAM_CHAT_ID=
# novo_ip (avisa quando um IP que nunca tinha entrado consegue entrar) | sempre (todo login) | nunca
ALERTAR=novo_ip
# IPs seus que não precisam gerar aviso (separados por espaço); continuam registrados
#IPS_CONHECIDOS=
EOF
  fi
  escrever /etc/logrotate.d/painel-ssh 644 <<'EOF'
/var/log/painel-ssh/acessos.jsonl {
    monthly
    rotate 24
    missingok
    notifempty
    compress
    delaycompress
    create 0640 root adm
}
EOF
  escrever /etc/systemd/system/painel-ssh-acessos.service 644 <<'EOF'
[Unit]
Description=Auditoria dos logins SSH da VPS (quem entrou, de que IP e de onde)
After=systemd-journald.service ssh.service
Wants=systemd-journald.service

[Service]
Type=simple
ExecStart=/usr/local/sbin/painel-ssh-acessos seguir
Restart=always
RestartSec=5
Nice=10
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
  exe systemctl daemon-reload
  exe systemctl enable painel-ssh-acessos
  exe systemctl restart painel-ssh-acessos
  ok "auditoria de logins SSH ativa (painel-ssh-acessos; registro em /var/log/painel-ssh; Telegram: /etc/painel/ssh-acessos.conf)"
else
  aviso "ssh-acessos.php não encontrado ao lado deste script — pulei a auditoria dos logins SSH."
fi

if (( ENDURECER_SSH )); then
  if [[ -s /root/.ssh/authorized_keys ]]; then
    escrever /etc/ssh/sshd_config.d/99-painel.conf 644 <<'EOF'
PasswordAuthentication no
PermitRootLogin prohibit-password
EOF
    if (( ! DRY_RUN )); then sshd -t && systemctl reload ssh 2>/dev/null || systemctl reload sshd; fi
    ok "SSH: login por senha desligado (só chave)"
  else
    aviso "Não achei chave em /root/.ssh/authorized_keys — NÃO desliguei o login por senha (você ficaria sem acesso)."
  fi
fi

# ---------------------------------------------------------------- 10. SSL ----------------------------------------
etapa "10/12  Certificado HTTPS (Let's Encrypt)"
if (( SEM_SSL )); then
  aviso "Pulei o certificado (--sem-ssl). Quando o DNS apontar, rode:  certbot --apache -d ${DOMINIO} -m SEU_EMAIL --agree-tos --redirect"
else
  # O vhost HTTPS que o certbot gerou numa execução anterior (pode ser de OUTRO domínio) é descartado: o certbot o recria agora a partir do painel.conf
  # (gerado acima, só com o domínio atual). Sobras dele fazem o redirecionamento http→https falhar depois de uma troca de domínio.
  if [[ -f /etc/apache2/sites-available/painel-le-ssl.conf ]]; then exe a2dissite -q painel-le-ssl || true; exe rm -f /etc/apache2/sites-available/painel-le-ssl.conf; fi
  CERT_DOMS=(-d "$DOMINIO"); (( COM_WWW )) && CERT_DOMS+=(-d "www.$DOMINIO")
  CERT_EMAIL=(-m "$EMAIL_SSL"); if (( SEM_EMAIL_SSL )) || [[ -z "$EMAIL_SSL" ]]; then CERT_EMAIL=(--register-unsafely-without-email); fi
  exe certbot --apache "${CERT_DOMS[@]}" "${CERT_EMAIL[@]}" --agree-tos --no-eff-email --redirect --non-interactive
  exe systemctl enable --now certbot.timer
  ok "HTTPS ativo; renovação automática pelo certbot.timer"
fi

# ---------------------------------------------------------------- 11. credenciais ----------------------------------------
etapa "11/12  Guardando as credenciais"
if (( ! DRY_RUN )); then
  {
    echo "# Credenciais do painel — gerado em $(date '+%F %T'). Guarde num cofre de senhas e APAGUE este arquivo da VPS."
    echo "URL=https://${DOMINIO}"
    echo "ADMIN_EMAIL=${ADMIN_EMAIL}"
    [[ -n "$ADMIN_SENHA" ]] && echo "ADMIN_SENHA=${ADMIN_SENHA}"
    echo "BANCO_NOME=${BANCO_NOME}"
    echo "BANCO_USUARIO=${BANCO_USUARIO}"
    echo "BANCO_SENHA=${BANCO_SENHA}"
    echo "SITE_DIR=${SITE_DIR}"
  } > "${CRED_ARQ}.novo"
  # em re-execução o admin não é recriado: mantém a senha que já estava gravada
  if [[ -z "$ADMIN_SENHA" && -f "$CRED_ARQ" ]]; then grep '^ADMIN_SENHA=' "$CRED_ARQ" >> "${CRED_ARQ}.novo" || true; fi
  mv "${CRED_ARQ}.novo" "$CRED_ARQ"; chmod 600 "$CRED_ARQ"
  ok "credenciais em ${CRED_ARQ} (somente root lê)"
fi

# ---------------------------------------------------------------- 12. verificação ----------------------------------------
etapa "12/12  Verificação final"
if [[ -f "$DIR_SCRIPT/verificar.sh" ]] && (( ! DRY_RUN )); then
  VERIF_ARGS=(--dominio "$DOMINIO" --site-dir "$SITE_DIR"); if (( SEM_SSL )); then VERIF_ARGS+=(--sem-ssl); fi
  bash "$DIR_SCRIPT/verificar.sh" "${VERIF_ARGS[@]}" || aviso "A verificação apontou itens acima — leia e corrija antes de abrir ao público."
else
  aviso "verificar.sh não encontrado ao lado deste script (ou --dry-run)."
fi

TITULO_FIM="INSTALAÇÃO CONCLUÍDA"; if (( DRY_RUN )); then TITULO_FIM="SIMULAÇÃO TERMINADA — nada foi alterado (tire o --dry-run para instalar de verdade)"; fi
cat <<FIM

=====================================================================================================================
  ${TITULO_FIM}
=====================================================================================================================
  Painel ........... https://${DOMINIO}/login
  Admin ............ ${ADMIN_EMAIL}
  Senha do admin ... $( [[ -n "$ADMIN_SENHA" ]] && echo "${ADMIN_SENHA}   (troque em Minha Conta; ela também está em ${CRED_ARQ})" || echo "(já existia — veja ${CRED_ARQ})" )

  Quem entra na VPS por SSH:  sudo painel-ssh-acessos   (aviso por Telegram: /etc/painel/ssh-acessos.conf)
  Suspensão remota ........... $( [[ -n "$CONTROLE_ID" ]] && echo "ativada — no SEU computador: php deploy/controle/enviar.php NOME consultar" || echo "NÃO configurada (rode de novo com --controle-id e --controle-chave-publica, ou veja deploy/PLANO-VPS.md)" )

  PRÓXIMOS PASSOS (veja deploy/PLANO-VPS.md, seção 6):
   1. Entre no painel com o admin acima e troque a senha.
   2. Admin → Configurações: marca, e-mail (SMTP) e login com Google.
   3. Gateways: habilite a OmegaPayments, cadastre as credenciais e o split.
   4. Webhook da OmegaPayments (cada cobrança já o informa; se o painel deles pedir, use):  https://${DOMINIO}/webhook_omegapayments.php
   5. Crie o bot em Meus Bots (o painel registra o webhook do Telegram sozinho).
   6. Faça 1 venda real de teste (R\$ 2,10) e confira o repasse do split.
   7. Apague ${CRED_ARQ} depois de guardar as senhas num cofre.
=====================================================================================================================
FIM
