# Teste página por página (Chrome real + banco do servidor de homologação)

Bateria usada em 07/10/2026 (relatório em `anotacoes/TESTES.md`). Cada `pg_aN.js` testa uma área do sistema
com o Chrome (DevTools Protocol, sem dependências além do Node 22+) e confere o resultado **no banco** (`db()`), não só na tela.

## Como rodar
1. Suba `servidor/zz_*.php` para a pasta do painel no servidor (são temporários — apague depois) e rode
   `php zz_pg.php seed` (cria usuário `pgteste-user`, admin `pgteste-admin`, bots, fluxos, leads, vendas...) e `php zz_pg.php user2`.
2. No PowerShell/Git Bash, com a senha SSH só em variável de ambiente (nunca em arquivo):
   `PGPW=... OUT=./pg IDS='{"bot1":..}' node pg_a5.js` (cada script grava `pg/res_pg_aN.json`).
3. `node gera_relatorio.js` monta as tabelas do relatório. `node pg_crawl.js user|admin [largura]` passa por todas as páginas.
4. Limpeza: `php zz_pg.php del` apaga os usuários `pgteste-*` e tudo deles; depois `rm zz_*.php`.

Cuidados: `anon_scan.js` faz GET anônimo em endpoints de cron (**eles executam**, 5 das 9 não exigem chave); o webhook de teste usa um receptor
próprio (`zz_recv.php`) no mesmo servidor, nunca um serviço de terceiros; as configurações globais do admin são restauradas no fim do teste.

## Scripts novos (07–08/10/2026)
- `pg_a17.js` — Remarketing: cancelar campanha pendente, aba Falha, botão do estado vazio, data mínima.
- `pg_a18.js` — Remarketing ponta a ponta: agenda pela tela → cron → banco (Telegram não simulado: envios contam como falha).
- `pg_a19.js` — Editor do Fluxo Guiado: caixa "Enviar também o QR Code (foto)" (usa a conta `cliente5@gmial.com` do teste de estresse; `FLUXO=<id>`).
- Os scripts antigos têm ids fixos (bot 311/312, usuário 49): troque pelos do seed ao rodar.
