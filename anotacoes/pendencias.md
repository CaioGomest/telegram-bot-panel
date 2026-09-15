# Pendências / lembretes

Notas e coisas pra fazer depois. Não é código, não afeta a aplicação.

## Débitos técnicos identificados na limpeza

- (preencher conforme formos limpando os arquivos)

## Pra etapa de layout (futuro, com Claude Code)

- Refazer layout/UI do painel (fora do escopo da limpeza atual)

## Dúvidas pra confirmar com o Caio

- (preencher)

## Renomeações feitas (nomes de página mais claros)

- `debug_cron.php` → `debug_ultima_venda.php`
- `debug_fix_db.php` → `debug_colunas_vendas.php`
- `temp_check_db.php` → `debug_colunas_grupos.php`
- `teste_infopago_painel.php` → `teste_gateway_infopago.php`
- `teste_infopago_recorrente_painel.php` → `teste_gateway_infopago_recorrente.php`

Não renomeados de propósito: `cron_*.php` e `webhook*.php` (nomes usados fora do repo — crontab da Hostinger e URLs de webhook no Telegram/InfoPago).
