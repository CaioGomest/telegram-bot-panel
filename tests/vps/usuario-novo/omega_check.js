const fs=require('fs'),path=require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const H=JSON.parse(fs.readFileSync(path.join(ESTADO,'cred_homolog.json'),'utf8'));
const og=H.usuarios_gateways.find(g=>g.gateway_nome==='omegapayments');
(async()=>{
  const r=await fetch('https://app.omegapayments.com.br/api/v1/gateway/transactions?id=teste-validacao-credencial-vps',{headers:{'x-public-key':og.client_id,'x-secret-key':og.client_secret,'Content-Type':'application/json'}});
  const t=await r.text();
  console.log('HTTP',r.status,'|',t.slice(0,220).split(og.client_secret).join('<SECRET>'));
})();
