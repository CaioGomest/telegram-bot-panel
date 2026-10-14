#!/bin/bash
# Funções auxiliares da rodada de testes (rodam no servidor, na cópia /tmp/pt/app).
export TMPDIR=/tmp/pt/tmp
mkdir -p "$TMPDIR"
PHP="php -d auto_prepend_file=/tmp/pt/sim.php -d display_errors=1 -d error_reporting=32767 -d log_errors=1 -d error_log=/tmp/pt/php_error.log"
IDS=/tmp/pt/ids.json
tokenbot() { php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["token"];'; }
TOKEN=$(tokenbot 2>/dev/null)
CHAT=777000111
G=""

secao() { G="$1"; echo; echo "=== $1"; }
mark() { touch /tmp/pt/sim.log; wc -l < /tmp/pt/sim.log > /tmp/pt/marca; }
chk() { $PHP /tmp/pt/chk.php "$1" "$2" "$G"; }
sql() { $PHP /tmp/pt/db.php "$1"; }
reset() { $PHP /tmp/pt/reset.php >/dev/null; mark; }
setflow() { $PHP /tmp/pt/setflow.php "$@" >/dev/null; }
hook() { $PHP /tmp/pt/hook.php "$TOKEN" "$1" "${2:-}" >/dev/null 2>>/tmp/pt/php_stderr.log; }
# msg TEXTO [nome] [username] [chat]
msg() { hook "$($PHP /tmp/pt/upd.php msg "${4:-$CHAT}" "$1" "${2:-Maria}" "${3:-}")"; }
# cb DATA [message_id] [chat]
cb() { hook "$($PHP /tmp/pt/upd.php cb "${3:-$CHAT}" "$1" "${2:-555}")"; }
cron() { $PHP /tmp/pt/cron.php "$1" >/dev/null 2>>/tmp/pt/php_stderr.log; }
owh() { $PHP /tmp/pt/owh.php "$1" >/dev/null 2>>/tmp/pt/php_stderr.log; }
pagar() { echo "$1" >> /tmp/pt/pagos.txt; }
ultimotx() { php -r 'require "/tmp/pt/common.php"; echo q1("SELECT transacao_id FROM vendas WHERE bot_id = ? ORDER BY id DESC LIMIT 1", [ids()["bot"]]);'; }
falha() { echo "$1" > /tmp/pt/falhas.json; }
semfalha() { rm -f /tmp/pt/falhas.json; }
# espera sair da janela em que a cron REAL do servidor roda (segundo 58-04) pra ela não "roubar" o teste
janela() { local s=$((10#$(date +%S))); if [ $s -lt 6 ]; then sleep $((6 - s)); elif [ $s -gt 38 ]; then sleep $((66 - s)); fi; }
# api METODO ACAO 'json' ['qs'] [uid]  -> resposta em /tmp/pt/resp.json (usada por chk via resp()/http())
api() { $PHP /tmp/pt/apirun.php "$@" >/dev/null 2>>/tmp/pt/php_stderr.log; }
fluxoid() { php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["fluxo"] ?? 0;'; }
payloadfluxo() { php /tmp/pt/mkpayload.php "$@"; }
