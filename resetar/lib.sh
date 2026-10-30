#!/usr/bin/env bash
# =====================================================================================================================
# lib.sh — funções comuns dos scripts de reset. NÃO é para rodar sozinho: os outros scripts fazem `source` dele.
#
# Regras que valem para TODOS os scripts desta pasta:
#   • nada aqui roda por acaso: quem apaga dado exige a frase  RESETAR TUDO  (digitada, ou passada em --frase);
#   • tudo é registrado em /root/resetar-logs/ ;
#   • o banco é acessado como root pelo socket (igual ao deploy/vps/restaurar.sh) e o nome vem de /etc/painel/painel.conf.
# =====================================================================================================================
set -Eeuo pipefail

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_ENV="${CONFIG_ENV:-$AQUI/config.env}"
CONF_SISTEMA=/etc/painel/painel.conf
PASTA_LOGS=/root/resetar-logs
PASTA_BACKUPS=/root/resetar-backups
CRON_ATIVO=/etc/cron.d/painel
CRON_PAUSADO=/root/painel-cron.pausado     # mesmo nome que o deploy/vps/restaurar.sh usa
FRASE_RESET="RESETAR TUDO"
FRASE_INFORMADA=""
SIMULAR=0

if [[ -t 1 ]]; then C_OK=$'\e[32m'; C_AV=$'\e[33m'; C_ER=$'\e[31m'; C_FIM=$'\e[0m'; else C_OK=""; C_AV=""; C_ER=""; C_FIM=""; fi
log()   { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
ok()    { printf '%s  ✔ %s%s\n' "$C_OK" "$*" "$C_FIM"; }
aviso() { printf '%s  ⚠ %s%s\n' "$C_AV" "$*" "$C_FIM"; }
falha() { printf '%s  ✘ %s%s\n' "$C_ER" "$*" "$C_FIM" >&2; }
die()   { falha "$*"; exit 1; }
titulo(){ printf '\n== %s ==\n' "$*"; }

precisa_root() { (( EUID == 0 )) || die "Rode como root (sudo -i)."; }

# Lê as opções comuns: --frase "RESETAR TUDO"  e  --simular (só mostra o que faria, sem mudar nada).
ler_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --frase)   FRASE_INFORMADA="${2:-}"; shift 2 ;;
      --simular) SIMULAR=1; shift ;;
      *) ARGS_RESTANTES+=("$1"); shift ;;
    esac
  done
}
ARGS_RESTANTES=()

# Carrega /etc/painel/painel.conf (SITE_DIR, BANCO_NOME) e confere os valores.
carregar_sistema() {
  # shellcheck disable=SC1090
  [[ -f "$CONF_SISTEMA" ]] && . "$CONF_SISTEMA"
  SITE_DIR="${SITE_DIR:-/var/www/painel}"
  BANCO_NOME="${BANCO_NOME:-painel}"
  [[ "$BANCO_NOME" =~ ^[A-Za-z0-9_]{1,40}$ ]] || die "Nome de banco inválido em $CONF_SISTEMA: $BANCO_NOME"
  [[ -d "$SITE_DIR" && -f "$SITE_DIR/config.php" ]] || die "Não achei o site em $SITE_DIR (sem config.php)."
  mysql -e "SELECT 1" "$BANCO_NOME" >/dev/null 2>&1 || die "Não consegui falar com o banco '$BANCO_NOME' como root."
}

# Executa SQL no banco do painel. sql "SELECT ..." imprime sem cabeçalho; sql_in lê o SQL da entrada padrão.
sql()    { mysql --batch --skip-column-names "$BANCO_NOME" -e "$1"; }
sql_in() { mysql --batch --skip-column-names "$BANCO_NOME"; }

# Carrega e VALIDA config.env (decisões do dono). Nada tem padrão escondido: o dono precisa escolher.
carregar_config() {
  [[ -f "$CONFIG_ENV" ]] || die "Falta $CONFIG_ENV — copie config.exemplo.env para config.env e preencha (veja PASSO-A-PASSO.md, fase 0)."
  # shellcheck disable=SC1090
  . "$CONFIG_ENV"
  local re_email='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
  [[ -n "${DOMINIO_ESPERADO:-}" && "$DOMINIO_ESPERADO" =~ ^[A-Za-z0-9.-]+$ ]] || die "DOMINIO_ESPERADO vazio ou inválido em config.env."
  [[ -n "${MANTER_ADMINS:-}" ]] || die "MANTER_ADMINS vazio em config.env (e-mails dos administradores que ficam)."
  local e; IFS=',' read -ra _adm <<< "$MANTER_ADMINS"
  for e in "${_adm[@]}"; do e="${e// /}"; [[ "$e" =~ $re_email ]] || die "E-mail de admin inválido em MANTER_ADMINS: '$e'"; done
  [[ "${RANKING:-}" =~ ^(manter|apagar)$ ]] || die "RANKING precisa ser 'manter' ou 'apagar' em config.env."
  [[ "${COMUNIDADE:-}" =~ ^(manter|apagar|ids:[0-9]+(,[0-9]+)*)$ ]] || die "COMUNIDADE precisa ser 'manter', 'apagar' ou 'ids:1,2' em config.env."
  [[ "${GATEWAY_OMEGA:-}" =~ ^(ativar|manter)$ ]] || die "GATEWAY_OMEGA precisa ser 'ativar' ou 'manter' em config.env."
  [[ "${SPLIT_APLICAR:-}" =~ ^(sim|nao)$ ]] || die "SPLIT_APLICAR precisa ser 'sim' ou 'nao' em config.env."
  if [[ "$SPLIT_APLICAR" == "sim" ]]; then
    [[ "${SPLIT_TIPO:-}" =~ ^(fixo|percentual)$ ]] || die "SPLIT_TIPO precisa ser 'fixo' ou 'percentual'."
    [[ "${SPLIT_VALOR:-}" =~ ^[0-9]+([.][0-9]{1,2})?$ ]] || die "SPLIT_VALOR precisa ser número com ponto (ex.: 1.40)."
    awk -v v="$SPLIT_VALOR" -v t="$SPLIT_TIPO" 'BEGIN{ if (v+0 <= 0) exit 1; if (t=="percentual" && v+0 > 100) exit 1; if (t=="fixo" && v+0 > 999.99) exit 1; exit 0 }' \
      || die "SPLIT_VALOR fora do limite (fixo: 0,01–999,99; percentual: 0,01–100)."
    [[ "${SPLIT_ID_RECEBEDOR:-}" =~ ^[A-Za-z0-9._@+-]{3,255}$ ]] || die "SPLIT_ID_RECEBEDOR vazio ou com caracteres inválidos (3–255; letras, números e . _ @ + -)."
  fi
  [[ "${BACKUP_COMPLETO:-}" =~ ^(sim|nao)$ ]] || die "BACKUP_COMPLETO precisa ser 'sim' ou 'nao' em config.env."
  [[ "${LIMPAR_SESSOES:-}" =~ ^(sim|nao)$ ]] || die "LIMPAR_SESSOES precisa ser 'sim' ou 'nao' em config.env."
  [[ -z "${ADMIN_SENHA_EMAIL:-}" || "$ADMIN_SENHA_EMAIL" =~ $re_email ]] || die "ADMIN_SENHA_EMAIL inválido em config.env."
}

# Lista de e-mails dos admins que ficam, já em minúsculas e entre aspas para o SQL (a validação acima impede aspas dentro do e-mail).
admins_para_sql() {
  local e out=""; IFS=',' read -ra _adm <<< "$MANTER_ADMINS"
  for e in "${_adm[@]}"; do e="${e// /}"; e="${e,,}"; out+="'${e}',"; done
  printf '%s' "${out%,}"
}

# Exige a frase de confirmação. Aceita --frase "RESETAR TUDO" ou digitada num terminal. Sem nenhum dos dois, não faz nada.
exigir_frase() {
  local frase="${1:-$FRASE_RESET}" digitada=""
  if [[ -n "$FRASE_INFORMADA" ]]; then
    [[ "$FRASE_INFORMADA" == "$frase" ]] || die "A frase informada em --frase não confere. Nada foi feito."
    return 0
  fi
  [[ -t 0 ]] || die "Sem terminal e sem --frase: por segurança nada foi feito. Para apagar, informe --frase \"$frase\"."
  read -r -p "Para continuar digite exatamente  $frase  : " digitada
  [[ "$digitada" == "$frase" ]] || die "Frase diferente — cancelado. Nada foi feito."
}

# Começa a gravar o log da execução (tela + arquivo) em /root/resetar-logs/NOME_AAAA-MM-DD_HHMMSS.log
iniciar_log() {
  mkdir -p "$PASTA_LOGS"; chmod 700 "$PASTA_LOGS"
  LOG_ARQ="$PASTA_LOGS/${1:-reset}_$(date +%F_%H%M%S).log"
  exec > >(tee -a "$LOG_ARQ") 2>&1
  log "log em $LOG_ARQ"
}

# Tabelas do banco (nomes), uma por linha.
tabelas_do_banco() { sql "SELECT table_name FROM information_schema.tables WHERE table_schema='${BANCO_NOME}' AND table_type='BASE TABLE' ORDER BY 1"; }

# Classificação das tabelas (schema de 15/10/2026, 39 tabelas). Se aparecer tabela fora destas listas, os scripts PARAM e pedem que alguém classifique.
TABELAS_MANTER=(usuarios gateways configuracoes comunidade_links campanhas_ranking campanhas_ranking_premios)
# usuarios_splits e vendas_splits (split do InfoPago) foram removidas pelo Atualizar Banco em 25/10/2026; ficam nesta lista só para o reset não parar num banco que ainda não migrou (se não existirem, ele apenas avisa).
TABELAS_APAGAR=(fluxos bots leads leads_starts vendas atividades bot_grupos membros_grupos avisos_assinatura_enviados fluxo_continuacoes
  fluxo_ofertas_aceitas chat_mensagens mensagens_agendadas previas_vistas downsell_agenda order_bump_agenda usuarios_gateways usuarios_splits vendas_splits remarketing_campanhas remarketing_envios remarketing_vendas
  usuarios_traqueamento links_rastreamento stories stories_visualizacoes webhooks webhooks_envios tokens_email fila_emails email_envios
  limites_taxa tentativas_login ranking_cache metricas_horarias_admin metricas_horarias_usuario redirecionadores redirecionador_destinos
  redirecionador_cliques_dia)

em_lista() { local x="$1"; shift; local i; for i in "$@"; do [[ "$i" == "$x" ]] && return 0; done; return 1; }

# Confere que TODA tabela do banco está classificada (manter ou apagar). Devolve 0 se estiver tudo classificado.
conferir_tabelas_classificadas() {
  local t sem=()
  while read -r t; do
    [[ -z "$t" ]] && continue
    em_lista "$t" "${TABELAS_MANTER[@]}" || em_lista "$t" "${TABELAS_APAGAR[@]}" || sem+=("$t")
  done < <(tabelas_do_banco)
  if (( ${#sem[@]} )); then
    falha "Tabela(s) do banco que o reset NÃO conhece: ${sem[*]}"
    falha "Decida se é para manter ou apagar e inclua em TABELAS_MANTER/TABELAS_APAGAR no lib.sh. Nada foi alterado."
    return 1
  fi
  local falt=() todas; todas="$(tabelas_do_banco)"   # em variável: `cmd | grep -q` com pipefail pode dar falso negativo (SIGPIPE)
  for t in "${TABELAS_APAGAR[@]}" "${TABELAS_MANTER[@]}"; do grep -qx "$t" <<< "$todas" || falt+=("$t"); done
  if (( ${#falt[@]} )); then aviso "Tabelas esperadas que não existem no banco (ok se o painel é de outra versão): ${falt[*]}"; fi
  return 0
}

# True se algo está escutando na porta TCP $1 (sem `ss | grep -q`, que dá falso resultado com pipefail).
porta_em_uso() { local o; o="$(ss -ltn 2>/dev/null || true)"; grep -q ":$1 " <<< "$o"; }

# Número de processos de cron do painel ainda rodando.
crons_rodando() { pgrep -fc 'cron/cron_[a-z_]*\.php' || true; }

# Versão do PHP-FPM instalada (para recarregar o serviço certo).
servico_fpm() { systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1; }
