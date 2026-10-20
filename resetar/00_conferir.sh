#!/usr/bin/env bash
# =====================================================================================================================
# 00_conferir.sh — SOMENTE LEITURA. Mostra o estado da VPS antes do reset: o que existe, o que o reset vai apagar e o que vai manter.
# Não altera banco, arquivos, crons nem serviços. Pode ser rodado a qualquer momento (como root, na VPS).
#
#   bash 00_conferir.sh
#
# Nunca imprime senha, token, chave Pix nem valor de configuração: só nomes, contagens e tamanhos.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
iniciar_log conferir

titulo "1. Onde estamos"
log "site: $SITE_DIR | banco: $BANCO_NOME | $(hostname) | $(date '+%F %T')"
df -h / | tail -1 | awk '{printf "  disco: %s usados de %s (%s)\n",$3,$2,$5}'
sql "SELECT CONCAT('  banco: ', ROUND(SUM(data_length+index_length)/1024/1024), ' MB em ', COUNT(*), ' tabelas') FROM information_schema.tables WHERE table_schema='${BANCO_NOME}'"

titulo "2. Tabelas — todas classificadas?"
if conferir_tabelas_classificadas; then ok "todas as tabelas do banco estão classificadas (manter ou apagar)"; else aviso "há tabela desconhecida: o reset (03) vai se recusar a rodar até classificar"; fi

titulo "3. O que será APAGADO (linhas aproximadas)"
printf '  %-34s %s\n' TABELA LINHAS
for t in "${TABELAS_APAGAR[@]}"; do
  n="$(sql "SELECT table_rows FROM information_schema.tables WHERE table_schema='${BANCO_NOME}' AND table_name='${t}'" 2>/dev/null || true)"
  [[ -n "$n" ]] && printf '  %-34s ~%s\n' "$t" "$n"
done

titulo "4. Usuários"
sql "SELECT CONCAT('  total: ', COUNT(*), ' | admins: ', SUM(perfil='admin'), ' | @estresse.invalid: ', SUM(email LIKE '%@estresse.invalid'), ' | @example.invalid: ', SUM(email LIKE '%@example.invalid'), ' | outros: ', SUM(perfil<>'admin' AND email NOT LIKE '%@estresse.invalid' AND email NOT LIKE '%@example.invalid')) FROM usuarios"
echo "  administradores (ficam só os listados em MANTER_ADMINS; o resto é apagado):"
sql "SELECT CONCAT('    #', id, '  ', email, '  (criado em ', criado_em, ')') FROM usuarios WHERE perfil='admin' ORDER BY id"
echo "  contas que NÃO são do teste de carga (confira se alguma é real antes de zerar):"
sql "SELECT CONCAT('    #', id, '  ', perfil, '  ', email, '  ', nome) FROM usuarios WHERE perfil<>'admin' AND email NOT LIKE '%@estresse.invalid' ORDER BY id LIMIT 60"

titulo "5. O que será MANTIDO"
echo "  gateways (sem mostrar chave):"
sql "SELECT CONCAT('    ', nome, ' | ativo=', ativo, ' | split ', tipo_split, ' ', taxa_split, ' | id do recebedor: ', IF(chave_pix_split IS NULL OR chave_pix_split='', 'VAZIO', CONCAT(CHAR_LENGTH(chave_pix_split), ' caracteres'))) FROM gateways"
echo "  configurações gravadas (só os nomes; ✔ = preenchida, ✘ = vazia):"
sql "SELECT CONCAT('    ', IF(valor IS NULL OR valor='', '✘ ', '✔ '), chave) FROM configuracoes ORDER BY chave"
echo "  comunidade (links) — decida em COMUNIDADE (manter | apagar | ids:...):"
sql "SELECT CONCAT('    id ', id, ' | ', titulo, ' | ', url, ' | ativo=', ativo) FROM comunidade_links ORDER BY id"
echo "  campanhas de ranking — decida em RANKING (manter | apagar):"
sql "SELECT CONCAT('    id ', id, ' | ', nome) FROM campanhas_ranking ORDER BY id" 2>/dev/null || aviso "não consegui listar campanhas_ranking"

titulo "6. config.php (só os NOMES das constantes — nunca os valores)"
grep -oE "define\('[A-Z_]+'" "$SITE_DIR/config.php" | sed -E "s/define\('([A-Z_]+)'/    \1/" | sort -u
grep -q "CHAVE_CRIPTOGRAFIA_GATEWAYS" "$SITE_DIR/config.php" && ok "CHAVE_CRIPTOGRAFIA_GATEWAYS existe (NÃO trocar: as senhas guardadas em configuracoes dependem dela)" || aviso "CHAVE_CRIPTOGRAFIA_GATEWAYS não encontrada no config.php"

titulo "7. Arquivos"
for d in uploads storage logs certificados; do printf '  %-14s %s\n' "$d" "$(du -sh "$SITE_DIR/$d" 2>/dev/null | cut -f1) em $(find "$SITE_DIR/$d" -type f 2>/dev/null | wc -l) arquivos"; done
for f in storage/controle_estado.json storage/controle_chave.json; do [[ -f "$SITE_DIR/$f" ]] && ok "$f existe (suspensão remota — o reset NÃO mexe)" || aviso "$f não existe"; done
ls "$SITE_DIR"/uploads/marca_logo* >/dev/null 2>&1 && ok "logo da marca presente (o reset NÃO apaga uploads/marca_*)" || aviso "sem uploads/marca_logo* (marca ainda não definida?)"
for f in /var/log/php/painel-erros.log /var/log/php/painel-cli-erros.log; do [[ -f "$f" ]] && printf '  %-42s %s\n' "$f" "$(du -h "$f" | cut -f1)"; done

titulo "8. Rotinas e serviços"
[[ -f "$CRON_ATIVO" ]] && ok "crons ativos em $CRON_ATIVO ($(grep -cE '^[^#].*php' "$CRON_ATIVO") linhas de tarefa)" || { [[ -f "$CRON_PAUSADO" ]] && aviso "crons PAUSADOS em $CRON_PAUSADO" || aviso "nenhum arquivo de cron encontrado"; }
echo "  processos de cron rodando agora: $(crons_rodando)"
for s in apache2 mariadb "$(servico_fpm)"; do [[ -n "$s" ]] && printf '  %-22s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || true)"; done

titulo "9. Sobras do teste de carga na VPS"
for p in /root/estresse /root/res /var/www/painel-estresse; do [[ -e "$p" ]] && aviso "existe $p (o passo 02 tenta desmontar)" || ok "não existe $p"; done
ls /etc/php/*/fpm/pool.d/estresse.conf >/dev/null 2>&1 && aviso "pool PHP-FPM 'estresse' ainda existe" || ok "sem pool 'estresse'"
if porta_em_uso 8080; then aviso "algo escutando na porta 8080 (site do teste de carga?)"; else ok "porta 8080 livre"; fi
[[ -d /root/estado-zero ]] && aviso "/root/estado-zero existe — é de 13/10 e NÃO deve ser restaurado (voltaria domínio, SMTP, senha e colunas antigas). O passo 09 o renomeia."

titulo "10. Decisões do dono (config.env)"
if [[ -f "$CONFIG_ENV" ]]; then
  if ( carregar_config ) >/dev/null 2>&1; then ok "config.env presente e válido"; else aviso "config.env presente, mas inválido — rode:  bash -c '. lib.sh; carregar_config'  para ver o motivo"; fi
else aviso "config.env ainda não existe (copie config.exemplo.env e preencha)"; fi
echo
log "conferência terminada — nada foi alterado."
