// Admin → Transações: o filtro no padrão da página Vendas (rótulo em cima, grade, busca em linha própria). Captura em 3 larguras e confere alinhamento/rolagem.
// Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 22_admin_transacoes_filtro.js   (imagens em estado/transacoes_<largura>.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };
(async () => {
  const c = await lancar();
  try {
    await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    for (const [larg, alt, mobile] of [[1855, 700, false], [1000, 900, false], [390, 900, true]]) {
      await c.enviar('Emulation.setDeviceMetricsOverride', mobile ? { width: larg, height: alt, deviceScaleFactor: 2, mobile: true } : { width: larg, height: alt, deviceScaleFactor: 1, mobile: false });
      await c.ir(BASE + '/admin/transacoes?split_status=pago'); await esp(1200);
      const m = JSON.parse(await c.avaliar(`JSON.stringify((function(){
        var f=document.querySelector('.filtros-transacoes-form'); if(!f) return {semForm:true};
        var r=function(e){var b=e.getBoundingClientRect();return {x:Math.round(b.left),y:Math.round(b.top),w:Math.round(b.width),h:Math.round(b.height)}};
        var ctr=[...f.querySelectorAll('select,input[type=date],input[type=text]')].map(function(e){var b=r(e);return {n:e.name,y:b.y,h:b.h,w:b.w,x:b.x}});
        var busca=f.querySelector('[name=busca]'), bt=f.querySelector('.filtro-acoes .botao-primario');
        return {controles:ctr, busca:r(busca), botao:r(bt), rolagemX:document.documentElement.scrollWidth>window.innerWidth+2, radiusBusca:getComputedStyle(busca).borderRadius, radiusSelect:getComputedStyle(f.querySelector('select')).borderRadius, rotulos:[...f.querySelectorAll('.filtro-campo>span')].map(function(s){return s.textContent.trim()}), limpar:!!f.querySelector('.filtro-acoes a.botao')};
      })())`));
      if (m.semForm) { chk('largura ' + larg + ': formulário novo presente', false); continue; }
      const hs = m.controles.map(x => x.h);
      chk('largura ' + larg + ': rótulos dos 6 campos', m.rotulos.join(',') === 'Usuário,Status,Split,De,Até,Buscar', m.rotulos.join(','));
      chk('largura ' + larg + ': selects, datas e busca com a MESMA altura', Math.max(...hs) - Math.min(...hs) <= 2, 'alturas ' + hs.join('/'));
      chk('largura ' + larg + ': busca com o mesmo arredondamento dos selects', m.radiusBusca === m.radiusSelect, m.radiusBusca + ' vs ' + m.radiusSelect);
      chk('largura ' + larg + ': sem rolagem horizontal', m.rolagemX === false);
      chk('largura ' + larg + ': botão "Limpar" aparece (há filtro ativo)', m.limpar === true);
      if (larg >= 1181) {
        const linha1 = m.controles.filter(x => x.n !== 'busca');
        chk('largura ' + larg + ': 1ª linha com os 5 filtros alinhados', new Set(linha1.map(x => x.y)).size === 1 && linha1.length === 5, 'y=' + linha1.map(x => x.y).join(','));
        chk('largura ' + larg + ': busca na 2ª linha, ao lado do botão Filtrar', m.busca.y > linha1[0].y && Math.abs((m.botao.y + m.botao.h / 2) - (m.busca.y + m.busca.h / 2)) <= 6, 'busca y=' + m.busca.y + ' botão y=' + m.botao.y);
      }
      await c.captura(path.join(ESTADO, 'transacoes_' + larg + '.png'));
    }
    // sem filtro: "Limpar" some (padrão da página Vendas)
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1855, height: 700, deviceScaleFactor: 1, mobile: false });
    await c.ir(BASE + '/admin/transacoes'); await esp(1000);
    chk('sem filtro ativo: "Limpar" não aparece', (await c.avaliar(`!!document.querySelector('.filtros-transacoes-form .filtro-acoes a.botao')`)) === false);
    // o filtro continua funcionando
    await c.avaliar(`(function(){var f=document.querySelector('.filtros-transacoes-form');f.elements['busca'].value='SIMTX1';f.submit();return 1})()`); await esp(4500);
    chk('buscar por TXID pelo campo novo funciona (mantém o valor e mostra "Limpar")', (await c.avaliar(`location.search`)).indexOf('busca=SIMTX1') >= 0 && (await c.avaliar(`document.querySelector('[name=busca]').value`)) === 'SIMTX1' && (await c.avaliar(`!!document.querySelector('.filtro-acoes a.botao')`)) === true);
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
