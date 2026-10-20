const fs=require('fs'),path=require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const H=JSON.parse(fs.readFileSync(path.join(ESTADO,'cred_homolog.json'),'utf8'));
const tok=H.bots[0].token;
const mask=(s)=>String(s||'').split(tok).join('<TOKEN>').split(encodeURIComponent(tok)).join('<TOKEN>');
(async()=>{
  const me=await (await fetch(`https://api.telegram.org/bot${tok}/getMe`)).json();
  console.log('getMe:',JSON.stringify({ok:me.ok,username:me.result?.username,id:me.result?.id,first_name:me.result?.first_name}));
  const w=await (await fetch(`https://api.telegram.org/bot${tok}/getWebhookInfo`)).json();
  const r=w.result||{};
  console.log('webhook:',JSON.stringify({url:mask(r.url),pending:r.pending_update_count,ultimo_erro:r.last_error_message,max_conn:r.max_connections,ips:r.ip_address,permitidos:r.allowed_updates}));
})();
