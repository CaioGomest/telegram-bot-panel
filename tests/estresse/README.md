# Teste de estresse (07/10/2026) — relatório em `anotacoes/TESTES.md`

- `servidor/zz_estresse.php` — cria/apaga a massa de dados: `php zz_estresse.php seed 300` (usuários `clienteN@gmial.com`, senha `12345678`, bots, fluxos, leads, vendas, notificações) · `del` apaga tudo · `contar`.
  Só cria vendas pagas/expiradas/canceladas e campanhas concluídas (a cron real de Pix/remarketing não pode agir sobre elas).
- `servidor/zz_carga.php` — gerador de carga que roda **dentro** do servidor (curl_multi, sessões reais): `php zz_carga.php fase=mistura|paginas|login|publico|admin|soak usuarios=300 degraus=10,20,40 duracao=20 pensa=0 offset=1`.
- `servidor/zz_grande.php` — vendedor grande (+200 mil leads, +30 mil vendas no cliente1).
- `carga.js` — o mesmo gerador, de fora (Node). **Cuidado:** acima de ~60 req/s de um mesmo IP o firewall da hospedagem bloqueia o IP por dezenas de minutos.
- Use os `zz_*.php` só por SSH e apague do servidor depois (ficam acessíveis pela web).
