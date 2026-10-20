#!/usr/bin/env bash
# =====================================================================================================================
# 06_senha_admin.sh — troca a senha de um administrador por uma FORTE. INTERATIVO: precisa de terminal.
#
#   bash 06_senha_admin.sh
#
# A senha é digitada sem aparecer na tela, duas vezes; nunca vai para a linha de comando, para o log, para variável de ambiente nem
# para arquivo — só o hash (password_hash do PHP, o mesmo formato do cadastro) é gravado em usuarios.senha.
# Por que existe: na rodada de testes de 15/10 a senha do admin da VPS foi trocada por uma trivial de teste (o hash da senha real do dono
# ficou guardado em /var/backups/painel/deploy-antes/admin-hash-antes-15-10.txt). Antes de lançar, isso PRECISA ser trocado.
# Não exige a frase do reset (troca a senha, não apaga nada), mas só funciona com terminal de verdade.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
[[ -t 0 ]] || die "Este passo precisa de terminal: a senha é digitada na hora (nunca passada na linha de comando)."
[[ -f "$CONFIG_ENV" ]] && { . "$CONFIG_ENV"; }
mkdir -p "$PASTA_LOGS"; chmod 700 "$PASTA_LOGS"

EMAIL="${ADMIN_SENHA_EMAIL:-}"
[[ -n "$EMAIL" ]] || read -r -p "E-mail do administrador que vai trocar a senha: " EMAIL
EMAIL="${EMAIL// /}"; EMAIL="${EMAIL,,}"
[[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die "E-mail inválido."
[[ "$(sql "SELECT COUNT(*) FROM usuarios WHERE LOWER(email)='${EMAIL}' AND perfil='admin'")" == "1" ]] || die "Não existe administrador com o e-mail $EMAIL."

echo "Regras: no mínimo 12 caracteres, com letra maiúscula, minúscula, número e símbolo. Não use a senha de teste."
read -r -s -p "Nova senha: " S1; echo
read -r -s -p "Repita a nova senha: " S2; echo
[[ "$S1" == "$S2" ]] || die "As senhas não conferem."
(( ${#S1} >= 12 )) || die "Muito curta (mínimo 12 caracteres)."
[[ "$S1" =~ [A-Z] && "$S1" =~ [a-z] && "$S1" =~ [0-9] && "$S1" =~ [^A-Za-z0-9] ]] || die "Falta maiúscula, minúscula, número ou símbolo."
case "${S1,,}" in *123456*|*senha*|*password*|*teste*|*admin*|*coyote*) die "Senha previsível (contém sequência/palavra óbvia)." ;; esac

HASH="$(printf '%s' "$S1" | php -r '$s = stream_get_contents(STDIN); echo password_hash($s, PASSWORD_DEFAULT);')"
[[ "$HASH" == '$2y$'* || "$HASH" == '$argon'* ]] || die "Não consegui gerar o hash da senha."
sql "UPDATE usuarios SET senha='${HASH}' WHERE LOWER(email)='${EMAIL}' AND perfil='admin'"
sql "DELETE FROM tentativas_login" 2>/dev/null || true

# confere que o que ficou no banco confere com o que foi digitado
DBH="$(sql "SELECT senha FROM usuarios WHERE LOWER(email)='${EMAIL}' AND perfil='admin'")"
printf '%s\n%s' "$S1" "$DBH" | php -r '$p = explode("\n", stream_get_contents(STDIN), 2); exit(password_verify($p[0], $p[1]) ? 0 : 1);' \
  || die "A senha gravada não confere com a digitada. Tente de novo."
unset S1 S2 HASH DBH
printf '%s %s\n' "$(date '+%F %T')" "$EMAIL" > "$PASTA_LOGS/senha-admin.ok"
ok "senha de $EMAIL trocada e conferida. Entre no painel com ela para testar (e guarde num gerenciador de senhas)."
log "(nenhuma senha foi gravada em log ou arquivo; só o hash está no banco)"
