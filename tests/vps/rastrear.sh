#!/usr/bin/env bash
# =====================================================================================================================
# rastrear.sh — roda NA VPS: entra no painel pela web (HTTPS de verdade, passando pelo Apache e pelo PHP-FPM) como administrador
# e como vendedor e abre todas as telas, conferindo código HTTP e o log de erros do PHP. Serve para pegar incompatibilidade com
# a versão do PHP/Apache da VPS (o painel foi testado em PHP 8.1; a VPS pode vir com 8.5).
#
#   sudo bash tests/vps/rastrear.sh --dominio painel.seudominio.com.br
#
# Cria um usuário vendedor temporário (rastreio-vps@example.invalid) e o apaga no fim. Não altera mais nada.
# =====================================================================================================================
set -uo pipefail
DOMINIO=""; SITE_DIR="/var/www/painel"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --site-dir) SITE_DIR="${2:-}"; shift 2 ;;
    *) echo "Opção desconhecida: $1" >&2; exit 2 ;;
  esac
done
[[ -n "$DOMINIO" ]] || { echo "Informe --dominio." >&2; exit 2; }
(( EUID == 0 )) || { echo "Rode como root." >&2; exit 1; }

BASE="https://$DOMINIO"
JAR_A="$(mktemp)"; JAR_V="$(mktemp)"
LOG_PHP=/var/log/php/painel-erros.log
EMAIL_V="rastreio-vps@example.invalid"; SENHA_V="Rastreio$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-12)9"
PASSOU=0; FALHOU=0
limpar() { rm -f "$JAR_A" "$JAR_V"; runuser -u www-data -- env SITE_DIR="$SITE_DIR" php -r 'chdir(getenv("SITE_DIR")); require "conexao.php"; $pdo->prepare("DELETE FROM usuarios WHERE email = ?")->execute(["rastreio-vps@example.invalid"]);' 2>/dev/null; }
trap limpar EXIT

# usuário vendedor temporário (pelas funções do próprio painel)
runuser -u www-data -- env SITE_DIR="$SITE_DIR" EMAIL_V="$EMAIL_V" SENHA_V="$SENHA_V" php -r '
  chdir(getenv("SITE_DIR")); require "conexao.php"; require "funcoes/usuario.php";
  $pdo->prepare("DELETE FROM usuarios WHERE email = ?")->execute([getenv("EMAIL_V")]);
  $r = criarUsuario("Rastreio VPS", getenv("EMAIL_V"), getenv("SENHA_V"));
  if (empty($r["sucesso"])) { fwrite(STDERR, "criarUsuario falhou: " . json_encode($r) . "\n"); exit(1); }
  $pdo->prepare("UPDATE usuarios SET email_verificado_em = NOW() WHERE email = ?")->execute([getenv("EMAIL_V")]);
' || { echo "Não consegui criar o usuário de teste."; exit 1; }

login() {   # login JAR EMAIL SENHA -> imprime o código HTTP do POST
  local jar="$1" email="$2" senha="$3" token
  token="$(curl -fsS -c "$jar" "$BASE/login" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')"
  [[ -n "$token" ]] || { echo "sem-token"; return; }
  curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" --data-urlencode "csrf_token=$token" --data-urlencode "acao=login" \
       --data-urlencode "email=$email" --data-urlencode "senha=$senha" "$BASE/login"
}
confere() {   # confere ROTULO JAR CAMINHO [esperado...]
  local rotulo="$1" jar="$2" caminho="$3"; shift 3
  local esperado=("${@:-200}") cod corpo
  corpo="$(mktemp)"
  cod="$(curl -sS -o "$corpo" -w '%{http_code}' --max-time 60 -b "$jar" -c "$jar" "$BASE$caminho" 2>/dev/null || echo 000)"
  local ok=0; for e in "${esperado[@]}"; do [[ "$cod" == "$e" ]] && ok=1; done
  if (( ok )) && ! grep -qiE 'Fatal error|Parse error|Uncaught|Stack trace' "$corpo"; then PASSOU=$((PASSOU+1)); else FALHOU=$((FALHOU+1)); printf '  [FALHA] %-8s %s → %s (esperado %s)\n' "$rotulo" "$caminho" "$cod" "${esperado[*]}"; fi
  rm -f "$corpo"
}

ERROS_ANTES=$(wc -l < "$LOG_PHP" 2>/dev/null || echo 0)

echo "== login"
ADMIN_EMAIL="$(grep -m1 '^ADMIN_EMAIL=' /root/painel-credenciais.txt | cut -d= -f2-)"; ADMIN_SENHA="$(grep -m1 '^ADMIN_SENHA=' /root/painel-credenciais.txt | cut -d= -f2-)"
C="$(login "$JAR_A" "$ADMIN_EMAIL" "$ADMIN_SENHA")"; [[ "$C" == "302" ]] && echo "  admin entrou (302)" || { echo "  [FALHA] login do admin → $C"; FALHOU=$((FALHOU+1)); }
C="$(login "$JAR_V" "$EMAIL_V" "$SENHA_V")"; [[ "$C" == "302" ]] && echo "  vendedor entrou (302)" || { echo "  [FALHA] login do vendedor → $C"; FALHOU=$((FALHOU+1)); }
C="$(login "$(mktemp)" "$EMAIL_V" "senha-errada-xyz")"; [[ "$C" == "200" ]] && echo "  senha errada recusada (fica em /login, 200)" || echo "  [!!] senha errada → $C"

echo "== telas do vendedor"
for p in /index /bots /fluxos /leads /vendas /remarketing /redirecionamento /traqueamento /links_rastreamento /webhooks /ranking /comunidade /gateways /configuracao_usuario /termos "/vendas?status=pago&periodo=30" "/leads?busca=a" "/index?periodo=30"; do confere vendedor "$JAR_V" "$p"; done
confere vendedor "$JAR_V" /ajax/notificacoes.php
echo "== telas do administrador"
for p in /admin/index /admin/usuarios /admin/transacoes /admin/logs /admin/configuracoes /admin/ranking /admin/comunidade /admin/checklist_testes /admin/testes_desempenho /admin/consultar_venda /index /bots /gateways /configuracao_usuario; do confere admin "$JAR_A" "$p" 200 302; done
echo "== o vendedor NÃO pode abrir telas de administrador (esperado redirecionamento/negado)"
for p in /admin/index /admin/usuarios /admin/configuracoes /admin/transacoes; do confere vendedor "$JAR_V" "$p" 302 403 404; done
echo "== sem login"
for p in /bots /vendas /admin/index /api.php; do confere anonimo "$(mktemp)" "$p" 302 401 403; done
echo "== públicas"
for p in /login /cadastro /termos /404 "/l/inexistente"; do confere publica "$(mktemp)" "$p" 200 302 404; done

ERROS_DEPOIS=$(wc -l < "$LOG_PHP" 2>/dev/null || echo 0)
NOVOS=$(( ERROS_DEPOIS - ERROS_ANTES ))
echo
echo "== log de erros do PHP durante o rastreio: $NOVOS linha(s) nova(s)"
if (( NOVOS > 0 )); then tail -n "$NOVOS" "$LOG_PHP" | cut -c1-260 | head -25; FALHOU=$((FALHOU+1)); fi
echo
echo "RESUMO: $PASSOU telas ok · $FALHOU falha(s)"
exit "$FALHOU"
