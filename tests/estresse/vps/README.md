# Teste de carga pesado na VPS (13/10/2026) — relatório em `anotacoes/TESTES.md`

Mede o limite do sistema NA VPS (Hostinger KVM 2: 2 vCPU AMD EPYC, 8 GB, NVMe; Ubuntu 26.04, PHP 8.5, MariaDB 11.8): quantos vendedores, quantas telas por segundo,
quantos clientes entrando pelo bot do Telegram e quantas vendas por dia. Resultados brutos em `resultados/` (só no local; ignorado pelo git).

## Como foi montado
- **Cópia de teste** do painel em `/var/www/painel-estresse` (mesmo banco `painel`), com **Telegram e OmegaPayments simulados COM latência** (`sim_rapido.php`):
  a espera pelo Telegram (padrão 150 ms) e pela gateway (700 ms no Pix) é o que prende os processos do PHP-FPM e decide quantos webhooks por segundo cabem.
  Site de teste só em `127.0.0.1:8080` (não aparece na internet) e pool PHP-FPM `estresse` (igual ao `painel`). `preparar.sh` monta tudo; `desmontar.sh` desmonta.
- **Dados** (`semear.php`): usuários `TAG-N@estresse.invalid`, bots `SIMBOT:TAG-UID-B`, vendas `ETAG-n`; senha `Estresse#2026`. Só vendas pagas/expiradas/canceladas e assinantes com validade só em 2027+.
- **Geradores:** `funil.php` (clientes do Telegram: /start → planos → Pix → pagamento; laço fechado ou aberto; confere no banco que nada se perdeu nem duplicou),
  `paginas.lua` (wrk: vendedores navegando; 1/3 das requisições é a consulta de notificações que o navegador repete a cada 30 s), `login_carga.php`, `pagamentos.php`, `duplicados.php`.
- **Monitor** (`monitor.php`): CPU, memória, disco, processos do PHP-FPM e métricas do MariaDB a cada 2 s; `rodada.sh` junta tudo por rodada.
- Fases: `fase_paginas.sh`, `fase_funil.sh`, `fase_tuning_fpm.sh`, `fase_pagamentos.sh`, `fase_admin_crons.sh`, `fase_s2.sh`, `fase_s2b.sh`, `fase_soak.sh`, `fase_combinado.sh`.
- Experimentos de ganho **só na cópia de teste**: `patch_ranking_cache.php` (cache do ranking). `setpool.sh` muda o tamanho do pool COM validação (nunca derruba o PHP-FPM).

## ⚠ Cuidados que custaram caro
1. **Pause as crons reais (`/etc/cron.d/painel`) antes de gerar Pix de teste.** A cron real de Pix consulta a OmegaPayments de verdade para vendas "gerado" — com credencial falsa
   foram 75 chamadas indevidas (73 × HTTP 401, 2 × 429) na primeira rodada. Depois de cada rodada: `UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'`.
2. **Valide a configuração do PHP-FPM antes de recarregar** (`setpool.sh`): `pm.start_servers` fora de min/max spare derrubou o serviço (e o painel) por ~1 min.
3. As métricas do dashboard vêm da cron `metricas_admin` (5 min): meça só **depois** de ela rodar, senão as telas parecem rápidas à toa.
4. wrk com pausa ("humanos") exige `KeepAliveTimeout` alto no site de teste (o padrão de 3 s do painel fecha a conexão e o wrk ignora a pausa).

## Zerar (SÓ quando o dono mandar)
```bash
bash /root/estresse/desmontar.sh                      # tira a cópia, o pool e o site de teste
# voltar o banco e os arquivos ao estado de logo depois da instalação (guardado em /root/estado-zero):
echo RESTAURAR | painel-restaurar /root/estado-zero/banco.sql.gz /root/estado-zero/arquivos.tar.gz
# ou apagar só os dados de teste, mantendo o que o dono criou depois:  php /root/estresse/semear.php apagar --tag=e1  (e e2, mega, s0...)
rm -rf /root/res /root/estresse                       # resultados e ferramentas na VPS
```
