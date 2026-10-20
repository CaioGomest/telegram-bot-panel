#!/usr/bin/env bash
# =====================================================================================================================
# desfazer.sh — VOLTA ao backup feito pelo 01_backup_antes.sh (o estado de ANTES do reset, com os dados de teste).
# Exige a frase  DESFAZER RESET.
#
#   bash desfazer.sh --frase "DESFAZER RESET"          (ou digite a frase quando pedir)
#
# Usa o painel-restaurar do painel (deploy/vps/restaurar.sh): ele ainda tira um backup do estado atual antes de substituir, pausa os
# crons, recria o banco, importa o dump, restaura config.php/uploads/certificados/storage e religa os crons.
# Só funciona se o backup completo existe (BACKUP_COMPLETO=sim). Sem ele, o que há é config-e-admins.sql.gz (só parte da configuração).
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
iniciar_log desfazer

[[ -f "$PASTA_BACKUPS/ULTIMO" ]] || die "Não achei $PASTA_BACKUPS/ULTIMO — não há backup do 01_backup_antes.sh para voltar."
# shellcheck disable=SC1090
. "$PASTA_BACKUPS/ULTIMO"
command -v painel-restaurar >/dev/null 2>&1 || die "painel-restaurar não está instalado (/usr/local/sbin). Veja deploy/vps/restaurar.sh."
if [[ -z "${BANCO:-}" || ! -s "${BANCO:-/nao}" ]]; then
  falha "O backup completo do banco NÃO existe (BACKUP_COMPLETO=nao na hora do backup)."
  echo "  O que existe em $DEST:"; ls -lh "$DEST" | sed 's/^/    /'
  die "Sem o dump completo não dá para devolver os dados. Os admins/configurações estão em $CONFIG (INSERTs; não restaure por cima de linhas existentes sem conferir)."
fi
[[ -s "${ARQUIVOS:-/nao}" ]] || die "O backup de arquivos não existe (${ARQUIVOS:-})."

titulo "O que vai acontecer"
echo "  banco ..... $BANCO ($(du -h "$BANCO" | cut -f1))"
echo "  arquivos .. $ARQUIVOS"
echo "  O banco e os arquivos ATUAIS serão substituídos por esse backup (volta tudo, inclusive os dados de teste)."
echo "  Antes, o painel-restaurar guarda um backup do estado atual."
exigir_frase "DESFAZER RESET"

printf 'RESTAURAR\n' | painel-restaurar "$BANCO" "$ARQUIVOS"
echo
ok "restauração concluída. Confira o painel e rode:  bash 00_conferir.sh"
