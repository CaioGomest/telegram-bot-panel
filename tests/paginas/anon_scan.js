// Varredura anônima (sem login) de endpoints PHP: o que responde 200 com conteúdo? Só GET. Pula seeds/ e atualiza_banco.
const { execFileSync } = require('child_process');
const BASE = 'https://telegram.stackcode.com.br';
const alvos = [
  'ajax/adicionar_usuario', 'ajax/deletar_usuario', 'ajax/detalhes_usuario', 'ajax/editar_usuario', 'ajax/marcar_notificacao_lida', 'ajax/notificacoes',
  'parciais/barra_stories', 'parciais/lista_atividades', 'parciais/lista_logs', 'parciais/sino_notificacoes',
  'cron/cron_aviso_vencimento', 'cron/cron_limpar_remarketing_envios', 'cron/cron_limpar_stories', 'cron/cron_metricas_admin', 'cron/cron_ranking', 'cron/cron_remarketing', 'cron/cron_renovacao', 'cron/cron_verificar_acessos', 'cron/cron_verificar_pix',
  'funcoes/usuario', 'funcoes/gateways', 'funcoes/criptografia', 'funcoes/email', 'funcoes/webhooks', 'conexao', 'config', 'tema_inline', 'barra_lateral',
  'instalacao', 'webhook', 'webhook_omegapayments', 'api', 'google_callback', 'google_login', 'verificar_email', 'l', 'comunidade',
  'tests/homologacao/sim.php', 'tests/homologacao/patch.php', 'anotacoes/capacidade.md', 'uploads/', 'storage/', 'logs/', 'assets/', 'admin/', 'funcoes/', 'cron/', 'seeds/'
];
const out = [];
for (const a of alvos) {
  let r;
  try { r = execFileSync('curl', ['-s', '-m', '20', '-o', 'resp.tmp', '-w', '%{http_code}|%{redirect_url}|%{size_download}', `${BASE}/${a}`], { encoding: 'utf8' }); } catch (e) { r = 'erro'; }
  const [codigo, red, tam] = r.split('|');
  let corpo = '';
  try { corpo = require('fs').readFileSync('resp.tmp', 'utf8').slice(0, 90).replace(/\s+/g, ' '); } catch (e) {}
  const suspeito = codigo === '200' && Number(tam) > 0;
  out.push({ a, codigo, red: (red || '').replace(BASE, ''), tam, corpo, suspeito });
  console.log((suspeito ? '??? ' : '    ') + a.padEnd(38) + codigo + ' ' + (red || '').replace(BASE, '').padEnd(22) + String(tam).padStart(7) + '  ' + corpo.slice(0, 70));
}
require('fs').writeFileSync('anon_scan.json', JSON.stringify(out, null, 1));
