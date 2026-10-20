// Teste 7: segurança do webhook do Telegram na VPS (sem o segredo do Telegram nada pode ser aceito). Payloads inofensivos (sem message).
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const H = JSON.parse(fs.readFileSync(path.join(ESTADO, 'cred_homolog.json'), 'utf8'));
const TOKEN = H.bots[0].token;
const BASE = 'https://coyotebot.com.br';
const mask = (s) => String(s || '').split(TOKEN).join('<TOKEN>');
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + mask(x) : '')); };
async function post(url, corpo, headers = {}, metodo = 'POST') {
  const r = await fetch(url, { method: metodo, headers: { 'Content-Type': 'application/json', ...headers }, body: metodo === 'GET' ? undefined : corpo, redirect: 'manual' });
  const t = await r.text();
  return { status: r.status, corpo: t.slice(0, 120).replace(/\s+/g, ' ') };
}
(async () => {
  const url = BASE + '/webhook.php?token=' + encodeURIComponent(TOKEN);
  const upd = JSON.stringify({ update_id: 1 });
  let r = await post(url, upd);
  chk('token certo, SEM segredo do Telegram: recusado', r.status === 401 || r.status === 403, r.status + ' ' + r.corpo);
  r = await post(url, upd, { 'X-Telegram-Bot-Api-Secret-Token': 'segredo-errado' });
  chk('segredo errado: recusado', r.status === 401 || r.status === 403, r.status + ' ' + r.corpo);
  r = await post(BASE + '/webhook.php?token=' + encodeURIComponent('111111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'), upd, { 'X-Telegram-Bot-Api-Secret-Token': 'x' });
  chk('token desconhecido: recusado ou ignorado sem erro de servidor', r.status < 500, r.status + ' ' + r.corpo);
  r = await post(url, 'isto nao e json');
  chk('corpo inválido: sem erro 500', r.status < 500, r.status + ' ' + r.corpo);
  r = await post(BASE + '/webhook.php', upd);
  chk('sem token: sem erro 500', r.status < 500, r.status + ' ' + r.corpo);
  r = await post(url, null, {}, 'GET');
  chk('GET no webhook: sem erro 500 e sem vazar nada', r.status < 500 && !/PHP|Warning|Stack/.test(r.corpo), r.status + ' ' + r.corpo);
  // webhook da gateway
  r = await post(BASE + '/webhook_omegapayments.php', JSON.stringify({ event: 'x' }));
  chk('webhook_omegapayments sem credencial: sem erro 500', r.status < 500, r.status + ' ' + r.corpo);
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
