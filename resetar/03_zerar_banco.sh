#!/usr/bin/env bash
# =====================================================================================================================
# 03_zerar_banco.sh — ZERA OS DADOS do banco do painel. Exige a frase RESETAR TUDO. É o passo que apaga de verdade.
#
#   bash 03_zerar_banco.sh --frase "RESETAR TUDO"       (ou digite a frase quando pedir)
#   bash 03_zerar_banco.sh --simular                    (mostra o SQL e as contagens; não muda nada)
#
# APAGA (TRUNCATE — também zera os contadores de id): usuários que não são admin e tudo que é de usuário (bots, fluxos, leads, vendas,
#   splits por venda, remarketing, webhooks, links, stories, redirecionadores, notificações, fila de e-mails, tentativas de login,
#   limites de taxa, cache do ranking e métricas). Lista completa: TABELAS_APAGAR em lib.sh.
# MANTÉM: os admins de MANTER_ADMINS, `gateways` (inclui o split da plataforma), `configuracoes` (marca, SMTP, domínio, Google…),
#   `comunidade_links` e `campanhas_ranking*` (conforme COMUNIDADE e RANKING do config.env).
#
# Recusa rodar se: houver tabela que o reset não conhece; os crons não estiverem pausados; não houver backup de hoje (01); algum admin
# de MANTER_ADMINS não existir.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
carregar_config
iniciar_log zerar_banco

titulo "1. Conferências de segurança"
conferir_tabelas_classificadas || exit 1
ok "todas as tabelas do banco estão classificadas"

if (( SIMULAR )); then
  aviso "[simulação] pulando as exigências de crons pausados e de backup recente"
else
  [[ -f "$CRON_PAUSADO" && ! -f "$CRON_ATIVO" ]] || die "Os crons não estão pausados. Rode antes:  bash 02_pausar.sh"
  (( $(crons_rodando) == 0 )) || die "Há rotinas de cron rodando agora. Espere terminarem (ou rode 02_pausar.sh)."
  [[ -f "$PASTA_BACKUPS/ULTIMO" ]] || die "Não há backup de segurança. Rode antes:  bash 01_backup_antes.sh"
  # shellcheck disable=SC1090
  . "$PASTA_BACKUPS/ULTIMO"
  IDADE=$(( $(date +%s) - $(stat -c %Y "$PASTA_BACKUPS/ULTIMO") ))
  (( IDADE < 21600 )) || die "O último backup tem mais de 6 horas ($(( IDADE / 3600 )) h). Rode de novo:  bash 01_backup_antes.sh"
  [[ -s "$CONFIG" ]] || die "O backup de config e admins não existe ($CONFIG)."
  ok "backup recente: $DEST"
fi

LISTA="$(admins_para_sql)"
IFS=',' read -ra ADM <<< "$MANTER_ADMINS"
for e in "${ADM[@]}"; do
  e="${e// /}"; e="${e,,}"
  n="$(sql "SELECT COUNT(*) FROM usuarios WHERE LOWER(email)='${e}' AND perfil='admin'")"
  [[ "$n" == "1" ]] || die "O admin '$e' (de MANTER_ADMINS) não existe como administrador. Confira com 00_conferir.sh."
done
ok "admins que ficam: ${MANTER_ADMINS}"

titulo "2. O que vai acontecer"
TOTAL_USU="$(sql "SELECT COUNT(*) FROM usuarios")"
APAGA_USU="$(sql "SELECT COUNT(*) FROM usuarios WHERE LOWER(email) NOT IN (${LISTA})")"
echo "  usuários: $TOTAL_USU hoje → ficam $(( TOTAL_USU - APAGA_USU )), apagados $APAGA_USU"
sql "SELECT CONCAT('  ⚠ ADMIN QUE SERÁ APAGADO: #', id, ' ', email) FROM usuarios WHERE perfil='admin' AND LOWER(email) NOT IN (${LISTA})"
echo "  ranking: $RANKING | comunidade: $COMUNIDADE | gateway Omega: mantido como está (o passo 05 ajusta)"
echo "  tabelas zeradas: ${#TABELAS_APAGAR[@]} (${TABELAS_APAGAR[*]})"

SQLTMP="$(mktemp /root/resetar-sql.XXXXXX)"; trap 'rm -f "$SQLTMP"' EXIT
{
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for t in "${TABELAS_APAGAR[@]}"; do echo "TRUNCATE TABLE \`${t}\`;"; done
  echo "DELETE FROM usuarios WHERE LOWER(email) NOT IN (${LISTA});"
  if [[ "$RANKING" == "apagar" ]]; then echo "TRUNCATE TABLE campanhas_ranking_premios;"; echo "TRUNCATE TABLE campanhas_ranking;"; fi
  case "$COMUNIDADE" in
    apagar) echo "TRUNCATE TABLE comunidade_links;" ;;
    ids:*)  echo "DELETE FROM comunidade_links WHERE id IN (${COMUNIDADE#ids:});" ;;
  esac
  echo "ALTER TABLE usuarios AUTO_INCREMENT = 1;"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} > "$SQLTMP"

if (( SIMULAR )); then
  titulo "[simulação] SQL que seria executado"; cat "$SQLTMP"
  echo; log "simulação terminada — nada foi alterado."; exit 0
fi

titulo "3. Confirmação"
echo "  ISTO APAGA OS DADOS ACIMA DE FORMA DEFINITIVA (o desfazer.sh volta ao backup de $DEST)."
exigir_frase

titulo "4. Zerando"
INI="$(date +%s)"
sql_in < "$SQLTMP"
ok "SQL executado em $(( $(date +%s) - INI )) s"

titulo "5. Conferência do resultado"
FALHAS=0
for t in "${TABELAS_APAGAR[@]}"; do
  n="$(sql "SELECT COUNT(*) FROM \`${t}\`" 2>/dev/null || echo '?')"
  [[ "$n" == "0" ]] || { falha "tabela $t ficou com $n linha(s)"; FALHAS=$((FALHAS+1)); }
done
RESTAM="$(sql "SELECT COUNT(*) FROM usuarios")"
[[ "$RESTAM" == "${#ADM[@]}" ]] || { falha "usuarios tem $RESTAM linha(s); esperado ${#ADM[@]}"; FALHAS=$((FALHAS+1)); }
(( FALHAS == 0 )) || die "O reset terminou com $FALHAS problema(s) acima. Para voltar: bash desfazer.sh"
ok "tabelas de dados zeradas; ficam só os ${#ADM[@]} admin(s): ${MANTER_ADMINS}"
sql "SELECT CONCAT('  banco agora: ', ROUND(SUM(data_length+index_length)/1024/1024), ' MB') FROM information_schema.tables WHERE table_schema='${BANCO_NOME}'"
sql "SELECT CONCAT('  gateway ', nome, ': ativo=', ativo, ', split ', tipo_split, ' ', taxa_split) FROM gateways"
echo
log "banco zerado. Próximo: 04_limpar_arquivos.sh"
