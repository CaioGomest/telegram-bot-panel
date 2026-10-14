#!/usr/bin/env bash
# =====================================================================================================================
# verificar.sh — confere se a instalação do painel na VPS está de pé e segura. Pode rodar quantas vezes quiser (só lê).
#
#   sudo bash deploy/vps/verificar.sh --dominio painel.seudominio.com.br [--site-dir /var/www/painel] [--sem-ssl]
#
# Sai com código 0 se tudo passou (avisos não contam) e com o número de falhas se algo falhou.
# =====================================================================================================================
set -uo pipefail

DOMINIO=""; SITE_DIR="/var/www/painel"; SEM_SSL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --site-dir) SITE_DIR="${2:-}"; shift 2 ;;
    --sem-ssl) SEM_SSL=1; shift ;;
    -h|--ajuda|--help) sed -n '2,9p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opção desconhecida: $1" >&2; exit 2 ;;
  esac
done
[[ -n "$DOMINIO" ]] || { echo "Informe --dominio." >&2; exit 2; }

PASSOU=0; FALHOU=0; AVISOS=0
passa()  { printf '  \033[1;32m[ ok ]\033[0m %s\n' "$*"; PASSOU=$((PASSOU+1)); }
falha()  { printf '  \033[1;31m[FALHA]\033[0m %s\n' "$*"; FALHOU=$((FALHOU+1)); }
avisa()  { printf '  \033[1;33m[ !!  ]\033[0m %s\n' "$*"; AVISOS=$((AVISOS+1)); }
titulo() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

if (( SEM_SSL )); then BASE="http://127.0.0.1"; CURL_H=(-H "Host: $DOMINIO"); else BASE="https://$DOMINIO"; CURL_H=(); fi
codigo() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${CURL_H[@]}" "$@" 2>/dev/null || echo 000; }
corpo()  { curl -sS --max-time 20 "${CURL_H[@]}" "$@" 2>/dev/null || true; }

titulo "Serviços"
for s in apache2 mariadb cron fail2ban; do
  systemctl is-active --quiet "$s" && passa "$s ativo" || falha "$s NÃO está ativo"
done
FPM="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)"
if [[ -n "$FPM" ]] && systemctl is-active --quiet "$FPM"; then passa "$FPM ativo"; else falha "PHP-FPM não está ativo"; fi
if grep -q 'Status: active' < <(ufw status 2>/dev/null); then passa "firewall (ufw) ativo"; else falha "firewall (ufw) inativo"; fi

titulo "Site e HTTPS"
C="$(codigo "$BASE/login")"; [[ "$C" == "200" ]] && passa "/login responde 200" || falha "/login respondeu $C (esperado 200)"
if (( ! SEM_SSL )); then
  R="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 20 "http://$DOMINIO/login" 2>/dev/null || echo '000 -')"
  [[ "$R" == 30[12]\ https://* ]] && passa "http → https redireciona" || falha "http não redireciona para https (veio: $R)"
  FIM="$(echo | openssl s_client -servername "$DOMINIO" -connect "$DOMINIO:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
  if [[ -n "$FIM" ]]; then
    DIAS=$(( ( $(date -d "$FIM" +%s) - $(date +%s) ) / 86400 ))
    (( DIAS > 14 )) && passa "certificado válido por mais ${DIAS} dias" || falha "certificado vence em ${DIAS} dias (renovação automática não está funcionando?)"
  else falha "não consegui ler o certificado"; fi
  systemctl is-active --quiet certbot.timer && passa "renovação automática (certbot.timer) ativa" || avisa "certbot.timer inativo"
else
  avisa "modo --sem-ssl: HTTPS não verificado"
fi
CAB="$(curl -sSI --max-time 20 "${CURL_H[@]}" "$BASE/login" 2>/dev/null || true)"
grep -qi '^x-frame-options: *sameorigin' <<<"$CAB" && passa "X-Frame-Options" || falha "falta o cabeçalho X-Frame-Options (o .htaccess não está valendo? AllowOverride All)"
grep -qi '^x-content-type-options: *nosniff' <<<"$CAB" && passa "X-Content-Type-Options" || falha "falta X-Content-Type-Options"
if (( ! SEM_SSL )); then grep -qi '^strict-transport-security' <<<"$CAB" && passa "HSTS" || falha "falta HSTS"; fi
grep -qi '^x-powered-by' <<<"$CAB" && falha "X-Powered-By aparece (vaza a versão do PHP)" || passa "sem X-Powered-By"
grep -qiE '^server: *Apache/[0-9]' <<<"$CAB" && falha "o cabeçalho Server mostra a versão do Apache (ServerTokens Prod)" || passa "versão do Apache oculta"

titulo "Arquivos que NÃO podem sair pela web (esperado 403/404)"
for p in /config /config.php /conexao /funcoes/gateways /funcoes/operacao /parciais/lista_atividades /.git/config /logs/ /storage/ /uploads/ /certificados/ /anotacoes/PENDENCIAS.md /deploy/schema.sql /tests/ /seeds/popular_banco /CLAUDE.md /schema.sql /admin/atualiza_banco /cron/cron_verificar_pix; do
  C="$(codigo "$BASE$p")"
  case "$C" in 403|404|301|302|401) passa "$p → $C" ;; *) falha "$p respondeu $C (devia estar bloqueado/protegido)" ;; esac
done
# o instalador não pode estar aberto para qualquer um depois de instalado
INST_HTML="$(corpo "$BASE/instalacao")"
if [[ "$INST_HTML" == *'name="banco_host"'* ]]; then falha "o formulário do instalador está ABERTO ($BASE/instalacao)"; else passa "instalador fechado (pede login de admin)"; fi
# um cron não pode rodar pela web sem chave
C="$(codigo "$BASE/cron/cron_ranking")"; [[ "$C" == "403" || "$C" == "404" ]] && passa "cron pela web recusado ($C)" || falha "/cron/cron_ranking pela web respondeu $C (tem que recusar sem a chave)"
# a API de recuperação de senha do login tem que continuar acessível (é a única exceção em funcoes/)
C="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${CURL_H[@]}" -X POST -H 'Content-Type: application/json' -d '{"email":"nao-existe@exemplo.invalid"}' "$BASE/funcoes/usuario?api=usuario&acao=enviar_codigo_senha_login" 2>/dev/null || echo 000)"
[[ "$C" == "200" ]] && passa "API de recuperação de senha do login responde" || avisa "API de recuperação de senha respondeu $C"
C="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${CURL_H[@]}" -X POST -H 'Content-Type: application/json' -d '{}' "$BASE/webhook_omegapayments.php" 2>/dev/null || echo 000)"
[[ "$C" == "200" ]] && passa "webhook da OmegaPayments responde 200 a um aviso vazio (ignora)" || falha "webhook_omegapayments.php respondeu $C a um aviso vazio"

titulo "PHP"
if [[ -f "$SITE_DIR/config.php" ]]; then
  php -r 'exit(version_compare(PHP_VERSION,"8.1.0",">=")?0:1);' && passa "PHP $(php -r 'echo PHP_VERSION;')" || falha "PHP abaixo de 8.1"
  PHP_MODULOS="$(php -m 2>/dev/null)"; PHP_INFO="$(php -i 2>/dev/null)"
  for e in pdo_mysql curl mbstring openssl fileinfo json iconv ctype zlib; do grep -qix "$e" <<<"$PHP_MODULOS" && passa "extensão $e" || falha "falta a extensão PHP $e"; done
  grep -q 'date.timezone => America/Sao_Paulo' <<<"$PHP_INFO" && passa "fuso do PHP: America/Sao_Paulo" || falha "date.timezone do PHP não é America/Sao_Paulo"
  FPMV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  grep -q 'disable_functions' "/etc/php/$FPMV/fpm/conf.d/99-painel.ini" 2>/dev/null && passa "funções perigosas desligadas no FPM" || avisa "99-painel.ini do FPM sem disable_functions"
  [[ -d /var/lib/php/painel-sessoes ]] && passa "pasta de sessões própria" || falha "falta /var/lib/php/painel-sessoes"
else
  falha "config.php não existe em $SITE_DIR (o instalador não rodou?)"
fi

titulo "Banco de dados"
if [[ -f "$SITE_DIR/config.php" ]]; then
  SAIDA="$(SITE_DIR="$SITE_DIR" php -r '
    chdir(getenv("SITE_DIR")); require "config.php";
    try {
      $p = new PDO("mysql:host=".BANCO_HOST.";dbname=".BANCO_NOME.";charset=utf8mb4", BANCO_USUARIO, BANCO_SENHA, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
      $q = fn($s) => $p->query($s)->fetchColumn();
      echo "tabelas=", $q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"), "\n";
      echo "sql_mode=", $q("SELECT @@sql_mode"), "\n";
      echo "isolamento=", $p->query("SHOW VARIABLES WHERE Variable_name IN (\"tx_isolation\", \"transaction_isolation\")")->fetch(PDO::FETCH_NUM)[1], "\n";
      echo "wait_timeout=", $q("SELECT @@wait_timeout"), "\n";
      echo "fuso=", $q("SELECT @@global.time_zone"), "\n";
      echo "admins=", $q("SELECT COUNT(*) FROM usuarios WHERE perfil = \"admin\""), "\n";
      echo "admin_padrao=", $q("SELECT COUNT(*) FROM usuarios WHERE email = \"admin@exemplo.com\""), "\n";
      $fracas = 0; foreach ($p->query("SELECT senha FROM usuarios ORDER BY (perfil = \"admin\") DESC, id LIMIT 30") as $u) { if (password_verify("123456", $u["senha"]) || password_verify("admin123", $u["senha"])) $fracas++; }   /* admins primeiro e no máximo 30: cada password_verify custa ~200 ms (bcrypt custo 12) — com milhares de usuários a checagem inteira levaria dezenas de minutos */
      echo "senhas_padrao=", $fracas, "\n";
      echo "url_base=", $q("SELECT valor FROM configuracoes WHERE chave = \"url_base\""), "\n";
      echo "gateway_omega=", $q("SELECT COUNT(*) FROM gateways WHERE nome = \"omegapayments\""), "\n";
      echo "leads_id_fluxo=", $q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \"leads\" AND column_name = \"id_fluxo\""), "\n";
    } catch (Throwable $e) { echo "ERRO=", $e->getMessage(), "\n"; }
  ' 2>&1)"
  v() { grep -m1 "^$1=" <<<"$SAIDA" | cut -d= -f2-; }
  if grep -q '^ERRO=' <<<"$SAIDA"; then falha "não conectei ao banco: $(v ERRO)"; else
    [[ "$(v tabelas)" -ge 39 ]] 2>/dev/null && passa "$(v tabelas) tabelas (esperado 39 ou mais)" || falha "só $(v tabelas) tabelas (esperado 39)"
    [[ "$(v leads_id_fluxo)" == "1" ]] && passa "leads.id_fluxo existe (sem ela o painel não grava leads)" || falha "falta a coluna leads.id_fluxo — rode o Atualizar Banco"
    [[ "$(v gateway_omega)" == "1" ]] && passa "gateway OmegaPayments cadastrado" || falha "falta a linha da OmegaPayments em gateways"
    [[ "$(v sql_mode)" != *STRICT* ]] && passa "sql_mode sem modo estrito ($(v sql_mode))" || falha "sql_mode ESTRITO ($(v sql_mode)): texto longo derruba o cadastro de leads"
    [[ "$(v isolamento)" == "READ-COMMITTED" ]] && passa "isolamento READ-COMMITTED" || falha "isolamento é $(v isolamento) (testado em READ-COMMITTED)"
    (( $(v wait_timeout) >= 300 )) && passa "wait_timeout $(v wait_timeout) s" || falha "wait_timeout $(v wait_timeout) s (muito baixo: derruba conexões no meio de uma venda)"
    [[ "$(v fuso)" == "-03:00" ]] && passa "fuso do banco -03:00" || avisa "fuso global do banco é '$(v fuso)' (o painel ajusta por conexão, mas convém -03:00)"
    [[ "$(v admins)" -ge 1 ]] && passa "existe administrador" || falha "nenhum administrador"
    [[ "$(v admin_padrao)" == "0" ]] && passa "admin padrão (admin@exemplo.com) não existe mais" || falha "o admin padrão admin@exemplo.com AINDA EXISTE"
    [[ "$(v senhas_padrao)" == "0" ]] && passa "nenhum usuário com senha 123456/admin123" || falha "$(v senhas_padrao) usuário(s) com senha padrão"
    [[ "$(v url_base)" == "https://$DOMINIO" || ( $SEM_SSL == 1 && -n "$(v url_base)" ) ]] && passa "URL do painel gravada ($(v url_base))" || falha "'URL do painel' (Admin → Configurações) é '$(v url_base)', devia ser https://$DOMINIO (e-mails e avisos de pagamento usam)"
  fi
fi

titulo "Rotinas (cron) e backup"
[[ -f /etc/cron.d/painel ]] && passa "/etc/cron.d/painel presente ($(grep -c 'cron_' /etc/cron.d/painel) rotinas)" || falha "falta /etc/cron.d/painel"
[[ "$(grep -c 'cron_' /etc/cron.d/painel 2>/dev/null)" == "10" ]] || avisa "esperadas 10 rotinas em /etc/cron.d/painel"
if [[ -f "$SITE_DIR/logs/cron_pix.log" ]]; then
  IDADE=$(( $(date +%s) - $(stat -c %Y "$SITE_DIR/logs/cron_pix.log") ))
  (( IDADE < 300 )) && passa "cron_verificar_pix rodou há ${IDADE}s" || avisa "cron_verificar_pix não escreve log há ${IDADE}s (normal se não há Pix pendente; veja /var/log/syslog | grep CRON)"
else avisa "ainda não há logs/cron_pix.log (espere 1-2 minutos depois da instalação e rode de novo)"; fi
[[ -x /usr/local/sbin/painel-backup ]] && passa "painel-backup instalado" || falha "painel-backup NÃO instalado"
if ls /var/backups/painel/diario/banco_*.sql.gz >/dev/null 2>&1; then
  ULT="$(ls -t /var/backups/painel/diario/banco_*.sql.gz | head -1)"; IDADE=$(( ( $(date +%s) - $(stat -c %Y "$ULT") ) / 3600 ))
  (( IDADE < 26 )) && passa "último backup há ${IDADE}h" || falha "último backup há ${IDADE}h"
else avisa "ainda não existe backup — rode 'sudo painel-backup' uma vez agora"; fi
[[ -f /etc/painel/offsite.conf ]] && passa "backup fora da VPS configurado" || avisa "backup só dentro da VPS (se a VPS morrer, perde-se tudo): configure /etc/painel/offsite.conf (rclone)"

titulo "Auditoria de logins SSH (quem entra na VPS)"
[[ -x /usr/local/sbin/painel-ssh-acessos ]] && passa "painel-ssh-acessos instalado" || falha "painel-ssh-acessos NÃO instalado (deploy/vps/ssh-acessos.php)"
systemctl is-active --quiet painel-ssh-acessos && passa "serviço painel-ssh-acessos ativo (registrando os logins)" || falha "serviço painel-ssh-acessos parado (systemctl status painel-ssh-acessos)"
if grep -q . < <(journalctl -t sshd -t sshd-session -n 1 --no-pager -q 2>/dev/null); then passa "journal do sshd legível"; else avisa "não achei registros do sshd no journal (ainda ninguém entrou, ou o SSH loga em outro lugar)"; fi
if [[ -s /var/log/painel-ssh/acessos.jsonl ]]; then passa "$(wc -l < /var/log/painel-ssh/acessos.jsonl) evento(s) de SSH já registrados"; else avisa "ainda não há eventos em /var/log/painel-ssh/acessos.jsonl (entre por SSH uma vez e rode de novo)"; fi
/usr/local/sbin/painel-ssh-acessos localizar 8.8.8.8 >/dev/null 2>&1 && passa "consulta de localização de IP funciona" || avisa "consulta de localização de IP falhou (painel-ssh-acessos localizar 8.8.8.8 mostra o motivo); o login é registrado do mesmo jeito, sem a cidade"
grep -qE '^TELEGRAM_BOT_TOKEN=.+' /etc/painel/ssh-acessos.conf 2>/dev/null && passa "aviso de login por Telegram configurado" || avisa "sem aviso por Telegram: o registro fica só na VPS e quem tem root consegue apagá-lo (configure /etc/painel/ssh-acessos.conf)"

titulo "Suspensão remota (kill switch assinado)"
if [[ -f "$SITE_DIR/config.php" ]]; then
  if [[ "$(SITE_DIR="$SITE_DIR" php -r 'chdir(getenv("SITE_DIR")); require "config.php"; echo (defined("CONTROLE_ID") && defined("CONTROLE_CHAVE_PUBLICA")) ? "sim" : "nao";' 2>/dev/null)" == "sim" ]]; then
    passa "CONTROLE_ID e CONTROLE_CHAVE_PUBLICA gravados no config.php"
    C="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${CURL_H[@]}" -X POST -H 'Content-Type: application/json' -d '{"x":1}' "$BASE/controle" 2>/dev/null || echo 000)"
    [[ "$C" == "400" || "$C" == "403" ]] && passa "/controle recusa comando sem assinatura ($C)" || falha "/controle respondeu $C a um comando sem assinatura (esperado 400/403)"
    if grep -q '"estado":"suspenso"' "$SITE_DIR/storage/controle_estado.json" 2>/dev/null; then avisa "o sistema está SUSPENSO por comando remoto (storage/controle_estado.json) — as falhas de site acima são esperadas; reative com: php deploy/controle/enviar.php NOME reativar"; else passa "sistema não está suspenso"; fi
    perm="$(stat -c %U:%G:%a "$SITE_DIR/config.php" 2>/dev/null)"; [[ "$perm" == "root:www-data:640" ]] && passa "config.php é do root (o PHP só lê; não dá para trocar a chave pelo painel)" || avisa "config.php está $perm (o ideal é root:www-data:640)"
  else
    avisa "suspensão remota NÃO configurada (sem CONTROLE_ID/CONTROLE_CHAVE_PUBLICA): você não tem como suspender o sistema por fora. Veja deploy/PLANO-VPS.md"
  fi
  C="$(codigo "$BASE/controle")"; [[ "$C" == "405" ]] && passa "GET /controle → 405 (só aceita POST)" || avisa "GET /controle respondeu $C (esperado 405)"
fi

titulo "Sistema"
grep -q 'America/Sao_Paulo' < <(timedatectl 2>/dev/null) && passa "fuso do sistema America/Sao_Paulo" || falha "fuso do sistema não é America/Sao_Paulo"
USO="$(df --output=pcent / | tail -1 | tr -dc '0-9')"; (( USO < 85 )) && passa "disco em ${USO}%" || falha "disco em ${USO}%"
MEM="$(free | awk '/Mem:/ {printf "%d", $3/$2*100}')"; (( MEM < 90 )) && passa "memória em ${MEM}%" || avisa "memória em ${MEM}%"
fail2ban-client status sshd >/dev/null 2>&1 && passa "fail2ban protegendo o SSH" || falha "fail2ban sem a jail sshd"
[[ "$(sshd -T 2>/dev/null | awk '/^permitrootlogin /{print $2}')" == "yes" ]] && avisa "SSH permite root com senha (use chave e --endurecer-ssh)" || passa "root não entra por senha no SSH"
[[ -f /var/run/reboot-required ]] && avisa "o sistema pede reinício (kernel atualizado): reinicie numa janela calma" || passa "sem reinício pendente"

printf '\n'
echo "RESUMO: ${PASSOU} ok · ${AVISOS} aviso(s) · ${FALHOU} falha(s)"
(( FALHOU == 0 )) && echo "Tudo certo." || echo "Corrija as falhas acima antes de abrir ao público."
exit "$FALHOU"
