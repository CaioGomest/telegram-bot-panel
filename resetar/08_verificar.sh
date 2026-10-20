#!/usr/bin/env bash
# =====================================================================================================================
# 08_verificar.sh — confere se a VPS ficou PRONTA PARA LANÇAR. SOMENTE LEITURA (não cria usuário, não envia e-mail, não muda nada).
#
#   bash 08_verificar.sh [--dominio coyotebot.com.br]
#
# Termina com um veredito: PRONTO PARA LANÇAR (nenhuma falha) ou NÃO PRONTO (lista o que falta). Avisos (⚠) não impedem, mas leia.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
DOMINIO=""
[[ "${1:-}" == "--dominio" ]] && DOMINIO="${2:-}"
[[ -f "$CONFIG_ENV" ]] && { . "$CONFIG_ENV"; DOMINIO="${DOMINIO:-${DOMINIO_ESPERADO:-}}"; }
[[ -n "$DOMINIO" ]] || die "Informe o domínio: bash 08_verificar.sh --dominio SEU.DOMINIO (ou preencha DOMINIO_ESPERADO no config.env)."
iniciar_log verificar

FALHAS=0; AVISOS=0
bom()   { ok "$*"; }
ruim()  { falha "$*"; FALHAS=$((FALHAS+1)); }
atenc() { aviso "$*"; AVISOS=$((AVISOS+1)); }
espera() { local n="$1" real="$2" desc="$3"; [[ "$real" == "$n" ]] && bom "$desc" || ruim "$desc — esperado $n, veio $real"; }

titulo "1. Dados zerados"
for t in bots leads leads_starts vendas fluxos atividades remarketing_campanhas webhooks redirecionadores links_rastreamento fila_emails email_envios ranking_cache usuarios_gateways; do
  n="$(sql "SELECT COUNT(*) FROM \`${t}\`" 2>/dev/null || echo '?')"; espera 0 "$n" "$t vazia"
done
NADM="$(sql "SELECT COUNT(*) FROM usuarios WHERE perfil='admin'")"; NTOT="$(sql "SELECT COUNT(*) FROM usuarios")"
espera "$NADM" "$NTOT" "só administradores em usuarios ($NADM)"
[[ "$NADM" -ge 1 ]] && bom "há ao menos 1 administrador" || ruim "nenhum administrador!"
sql "SELECT CONCAT('    admin: #', id, ' ', email) FROM usuarios WHERE perfil='admin'"
N="$(sql "SELECT COUNT(*) FROM usuarios WHERE email LIKE '%@estresse.invalid' OR email LIKE '%@example.invalid' OR email LIKE '%maxxspace.com' OR email LIKE '%+vps%'")"
espera 0 "$N" "nenhuma conta com e-mail de teste (estresse/example.invalid/maxxspace/+vps)"
sql "SELECT CONCAT('  banco: ', ROUND(SUM(data_length+index_length)/1024/1024), ' MB') FROM information_schema.tables WHERE table_schema='${BANCO_NOME}'"

titulo "2. Configuração preservada"
for k in nome_sistema url_base; do
  [[ -n "$(sql "SELECT valor FROM configuracoes WHERE chave='${k}'" 2>/dev/null)" ]] && bom "configuracoes.$k preenchida" || atenc "configuracoes.$k vazia (defina em Admin → Configurações)"
done
for k in smtp_host smtp_usuario smtp_senha email_remetente; do   # sem SMTP não sai e-mail de cadastro, confirmação nem recuperação de senha
  [[ -n "$(sql "SELECT valor FROM configuracoes WHERE chave='${k}'" 2>/dev/null)" ]] && bom "e-mail: $k preenchido" || ruim "e-mail: $k VAZIO (Admin → Configurações → E-mail) — cadastro e recuperação de senha não enviam e-mail"
done
for k in google_client_id google_client_secret; do
  [[ -n "$(sql "SELECT valor FROM configuracoes WHERE chave='${k}'" 2>/dev/null)" ]] && bom "login com Google: $k preenchido" || atenc "login com Google: $k vazio (o botão 'Continuar com Google' fica escondido)"
done
URL="$(sql "SELECT valor FROM configuracoes WHERE chave='url_base'" 2>/dev/null || true)"
[[ "$URL" == "https://${DOMINIO}"* ]] && bom "url_base aponta para https://$DOMINIO" || ruim "url_base é '$URL' (esperado https://$DOMINIO)"
GW="$(sql "SELECT CONCAT(ativo, '|', tipo_split, '|', taxa_split, '|', IF(chave_pix_split IS NULL OR chave_pix_split='', 0, 1)) FROM gateways WHERE nome='omegapayments'")"
IFS='|' read -r G_ATIVO G_TIPO G_TAXA G_RECEB <<< "$GW"
[[ "$G_ATIVO" == "1" ]] && bom "OmegaPayments liberada para os usuários" || ruim "OmegaPayments DESLIGADA (gateways.ativo=0)"
if [[ "$G_RECEB" == "1" ]] && awk -v v="$G_TAXA" 'BEGIN{exit !(v+0>0)}'; then bom "split da plataforma configurado: $G_TIPO $G_TAXA, com recebedor"
else ruim "split NÃO configurado (taxa $G_TAXA, recebedor $( [[ "$G_RECEB" == 1 ]] && echo ok || echo vazio )) — a plataforma não ganharia nada por venda"; fi
for f in storage/controle_estado.json storage/controle_chave.json; do [[ -f "$SITE_DIR/$f" ]] && bom "$f presente (suspensão remota)" || atenc "$f ausente"; done
ls "$SITE_DIR"/uploads/marca_logo* >/dev/null 2>&1 && bom "logo da marca presente" || atenc "sem logo em uploads/marca_logo*"
grep -q "CHAVE_CRIPTOGRAFIA_GATEWAYS" "$SITE_DIR/config.php" && bom "chave de criptografia no config.php" || ruim "CHAVE_CRIPTOGRAFIA_GATEWAYS ausente do config.php"
CON="$(sql "SELECT COUNT(*) FROM comunidade_links WHERE url LIKE '%srv2059665.hstgr.cloud%'")"
[[ "$CON" == "0" ]] && bom "nenhum card da Comunidade aponta para o endereço antigo (srv2059665.hstgr.cloud)" || ruim "$CON card(s) da Comunidade ainda apontam para srv2059665.hstgr.cloud"

titulo "3. Segurança e acesso (o que dá para ver daqui)"
if [[ -f "$PASTA_LOGS/senha-admin.ok" ]]; then bom "senha de admin trocada pelo 06 ($(cat "$PASTA_LOGS/senha-admin.ok"))"; else ruim "a senha do admin NÃO foi trocada pelo 06_senha_admin.sh (na VPS ela está trivial desde a rodada de testes)"; fi
grep -qiE '^\s*PasswordAuthentication\s+no' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null && bom "SSH sem senha (só chave)" || atenc "SSH ainda aceita senha (instale a chave do dono e rode instalar.sh --endurecer-ssh)"
[[ -f /etc/painel/offsite.conf ]] && bom "backup fora da VPS configurado (offsite.conf)" || atenc "backup fora da VPS NÃO configurado (rclone)"
[[ -f /etc/cron.d/painel-backup ]] && bom "backup diário agendado" || atenc "backup diário (painel-backup) não está agendado"

titulo "4. Serviços e rotinas"
for s in apache2 mariadb cron "$(servico_fpm)"; do [[ -n "$s" ]] && { [[ "$(systemctl is-active "$s" 2>/dev/null)" == "active" ]] && bom "$s ativo" || ruim "$s NÃO está ativo"; }; done
[[ -f "$CRON_ATIVO" && ! -f "$CRON_PAUSADO" ]] && bom "crons do painel ativos" || ruim "crons pausados ou ausentes — rode 07_retomar.sh"
[[ -e /root/estresse || -e /var/www/painel-estresse ]] && ruim "ainda há sobras do teste de carga (/root/estresse ou /var/www/painel-estresse)" || bom "sem sobras do teste de carga"
if porta_em_uso 8080; then ruim "algo escutando na 8080 (site do teste de carga)"; else bom "porta 8080 livre"; fi
[[ -d /root/estado-zero ]] && atenc "/root/estado-zero ainda existe com esse nome (é de 13/10 — o 09_marco_zero.sh o renomeia)"

titulo "5. Pelo ar (HTTPS de verdade)"
code() { curl -s -o /dev/null -m 25 -w '%{http_code}' "$@" 2>/dev/null || echo 000; }
c="$(code "https://$DOMINIO/login")";            [[ "$c" == "200" ]] && bom "/login → 200" || ruim "/login → $c"
c="$(code "https://$DOMINIO/cadastro")";         [[ "$c" == "200" ]] && bom "/cadastro → 200" || ruim "/cadastro → $c"
c="$(code "https://$DOMINIO/bots")";             [[ "$c" == "302" ]] && bom "/bots sem login → 302 (redireciona)" || ruim "/bots sem login → $c"
c="$(code "https://$DOMINIO/admin/dashboard")";  [[ "$c" == "302" ]] && bom "/admin/dashboard sem login → 302" || ruim "/admin/dashboard sem login → $c"
for p in config.php conexao.php deploy/schema.sql resetar/lib.sh .git/config; do
  c="$(code "https://$DOMINIO/$p")"; [[ "$c" == "403" || "$c" == "404" ]] && bom "/$p → $c (bloqueado)" || ruim "/$p → $c (DEVERIA estar bloqueado)"
done
c="$(code -X POST "https://$DOMINIO/webhook")";  [[ "$c" =~ ^(200|400|401|403|404|405)$ ]] && bom "/webhook responde ($c)" || atenc "/webhook → $c"
EXP="$(echo | openssl s_client -connect "${DOMINIO}:443" -servername "$DOMINIO" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
[[ -n "$EXP" ]] && { DIAS=$(( ( $(date -d "$EXP" +%s) - $(date +%s) ) / 86400 )); (( DIAS > 14 )) && bom "certificado TLS vale mais $DIAS dias" || ruim "certificado TLS vence em $DIAS dias"; } || atenc "não consegui ler o certificado TLS"

titulo "6. Erros recentes do PHP"
if [[ -f /var/log/php/painel-erros.log ]]; then
  N="$(grep -ciE 'fatal|parse error|uncaught' /var/log/php/painel-erros.log || true)"
  [[ "$N" == "0" ]] && bom "painel-erros.log sem erros graves" || ruim "painel-erros.log tem $N erro(s) grave(s) — veja: tail -50 /var/log/php/painel-erros.log"
fi

echo
if (( FALHAS == 0 )); then
  ok "PRONTO PARA LANÇAR — nenhuma falha ($AVISOS aviso(s))."
  log "Próximo: 09_marco_zero.sh (guarda o estado limpo) e a venda de aceite/primeiro cadastro real, conforme PASSO-A-PASSO.md"
else
  falha "NÃO PRONTO — $FALHAS falha(s) e $AVISOS aviso(s). Corrija o que está em ✘ e rode de novo."
  exit 1
fi
