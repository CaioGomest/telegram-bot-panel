// Admin → Campanhas de ranking: ARQUIVAR (nunca apaga). Duas fases, porque o cron de ranking fecha o placar de campanha encerrada.
//   PHASE=1  confere abas, recusa arquivar a campanha em andamento, cria "TESTE arquivar" (período passado) e tenta arquivar antes do placar fechar.
//   PHASE=2  (depois que a campanha aparece como "Fechada em ...") arquiva, confere a aba Arquivadas, bloqueio de edição, desarquivar e arquivar de novo.
// Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. PHASE=1|2 [ID_TESTE=<id>] node 18_admin_ranking_arquivar.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const TITULO = 'TESTE arquivar (pode ignorar)';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
const avisos = (c) => c.avaliar(`[...document.querySelectorAll('main .aviso')].map(function(e){return e.innerText.trim()}).join(' || ')`);
// clica no botão (por title) da linha cuja célula contém o texto
const clicarNaLinha = (c, texto, tituloBotao) => c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf(${JSON.stringify(texto)})>=0});if(!tr) return 'sem linha';var b=[...tr.querySelectorAll('button, a')].find(function(x){return (x.title||x.getAttribute('aria-label')||'')===${JSON.stringify(tituloBotao)}});if(!b) return 'sem botão';b.click();return 'ok'})()`);
const idDaLinha = (c, texto) => c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf(${JSON.stringify(texto)})>=0});if(!tr) return '';var a=tr.querySelector('a[href*="ranking_classificacao?id="]');return a?a.getAttribute('href').split('id=')[1]:''})()`);
const postar = (c, campos) => c.avaliar(`(async function(){
  var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)});
  var fd=new FormData(); fd.append(tk.name, tk.value); var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
  var r=await fetch('/admin/ranking',{method:'POST',body:fd,credentials:'include'});
  var t=await r.text(); return JSON.stringify({status:r.status,url:r.url.replace(location.origin,''),aviso:(t.match(/class="aviso[^>]*>([^<]*)</)||[])[1]||''}) })()`).then(JSON.parse);

(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
    const fase = process.env.PHASE || '1';
    const dest = await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    chk('login do admin', /admin/.test(dest), dest);

    if (fase === '1') {
      await c.ir(BASE + '/admin/ranking'); await esp(1200);
      let t = await main(c);
      chk('abas "Em uso" e "Arquivadas" aparecem com contagem', /Em uso \(\d+\)/.test(t) && /Arquivadas \(\d+\)/.test(t), (t.match(/Em uso \(\d+\)[^|]*|Arquivadas \(\d+\)/g) || []).join(' / '));
      // 1) campanha em andamento não arquiva
      const r1 = await clicarNaLinha(c, 'Campanha de Outubro', 'Arquivar');
      chk('botão Arquivar existe na campanha em andamento', r1 === 'ok', r1);
      await esp(2500);
      const av = await avisos(c); t = await main(c);
      chk('arquivar a campanha EM ANDAMENTO é recusado, com aviso na lista (não abre o formulário)', /est[áa] valendo/i.test(av) && /Campanha de Outubro/.test(t), av.slice(0, 160));
      const modalAberto = await c.avaliar(`document.getElementById('modal-form').classList.contains('aberto')`);
      chk('a recusa não abre o modal de "Nova campanha"', modalAberto === false);
      // 2) cria a campanha de teste (período já encerrado)
      await c.ir(BASE + '/admin/ranking'); await esp(800);
      await c.avaliar(`(function(){var f=document.querySelector('#modal-form form');var s=function(n,v){f.querySelector('[name="'+n+'"]').value=v};
        s('slug','teste-arquivar-1015');s('titulo',${JSON.stringify(TITULO)});s('subtitulo','campanha criada pelo teste de arquivar');s('data_inicio','2026-09-01T00:00');s('data_fim','2026-09-10T23:59');
        s('premio_titulo[1]','1º lugar de teste');s('premio_descricao[1]','premio fictício');f.requestSubmit();return 1})()`);
      await esp(3000);
      await c.ir(BASE + '/admin/ranking'); await esp(1000);
      t = await main(c);
      chk('campanha de teste criada (período já encerrado) e listada em "Em uso"', t.indexOf(TITULO) >= 0, t.slice(t.indexOf('TÍTULO'), t.indexOf('TÍTULO') + 300));
      const id = await idDaLinha(c, TITULO);
      console.log('   ID da campanha de teste:', id);
      fs.writeFileSync(path.join(ESTADO, 'id_campanha_teste.txt'), String(id));
      // 3) antes do cron fechar o placar, não arquiva
      await clicarNaLinha(c, TITULO, 'Arquivar'); await esp(2500);
      const av2 = await avisos(c);
      const sit = await main(c);
      chk('encerrada mas com placar ainda por fechar: arquivar recusado', /placar final|valendo/i.test(av2) || /Fechada em/.test(sit), av2.slice(0, 140) + ' | ' + (sit.match(/Fechada em[^|]*|Aguardando o cron/) || [''])[0]);
      await c.captura(path.join(ESTADO, 'ranking_antes_de_arquivar.png'));
    } else {
      const id = process.env.ID_TESTE || fs.readFileSync(path.join(ESTADO, 'id_campanha_teste.txt'), 'utf8').trim();
      await c.ir(BASE + '/admin/ranking'); await esp(1200);
      let t = await main(c);
      const antesArq = Number((t.match(/Arquivadas \((\d+)\)/) || [])[1] || 0);
      chk('placar da campanha de teste já foi fechado pelo cron', /Fechada em/.test(t.slice(t.indexOf(TITULO))), (t.slice(t.indexOf(TITULO)).match(/Fechada em[^|]*|Aguardando o cron/) || [''])[0]);
      // arquivar
      const rr = await clicarNaLinha(c, TITULO, 'Arquivar'); await esp(2800);
      t = await main(c); const av = await avisos(c);
      chk('arquivar campanha encerrada com placar fechado: aviso de sucesso', /arquivada/i.test(av) && /guardado/i.test(av), av.slice(0, 160));
      chk('ela sai da lista "Em uso"', t.indexOf(TITULO) < 0 || /Arquivadas/.test(t) && t.indexOf(TITULO) < 0, 'contagem Em uso/Arquivadas: ' + (t.match(/Em uso \(\d+\)|Arquivadas \(\d+\)/g) || []).join(' / '));
      chk('a de Outubro (em andamento) continua na lista', /Campanha de Outubro/.test(t));
      // aba Arquivadas
      await c.ir(BASE + '/admin/ranking?arquivadas=1'); await esp(1200);
      t = await main(c);
      chk('aba Arquivadas mostra a campanha com data de arquivamento e placar fechado', t.indexOf(TITULO) >= 0 && /Arquivada em/.test(t) && /placar fechado em/.test(t), t.slice(t.indexOf(TITULO), t.indexOf(TITULO) + 200));
      chk('contagem da aba Arquivadas subiu em 1', Number((t.match(/Arquivadas \((\d+)\)/) || [])[1]) === antesArq + 1, 'antes ' + antesArq + ' → ' + (t.match(/Arquivadas \((\d+)\)/) || [])[1]);
      const botoes = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('tbody tr')].filter(function(x){return x.innerText.indexOf(${JSON.stringify(TITULO)})>=0}).map(function(tr){return [...tr.querySelectorAll('button, a.btn-icon')].map(function(b){return b.title||b.getAttribute('aria-label')})})[0])`));
      chk('na aba Arquivadas há "Desarquivar" e classificação, mas NÃO Editar/ligar/Arquivar', botoes.includes('Desarquivar') && botoes.some(b => /classifica/i.test(b)) && !botoes.includes('Editar') && !botoes.includes('Arquivar'), JSON.stringify(botoes));
      // registro continua acessível
      // não usar inspecionar() aqui: ele zera c.eventos e o auto-aceitador de diálogos perde a conta (o próximo confirm() trava o script)
      await c.ir(BASE + '/admin/ranking_classificacao?id=' + id); await esp(1500);
      const rc = { status: await c.avaliar(`fetch('/admin/ranking_classificacao?id=${id}',{credentials:'include'}).then(function(r){return r.status})`), h1: '' };
      const tc = await main(c);
      chk('classificação/ganhadores da arquivada continuam abrindo (registro guardado)', rc.status === 200 && tc.toLowerCase().indexOf(TITULO.toLowerCase()) >= 0, (rc.h1 || '') + ' | ' + tc.slice(0, 160));
      // bloqueios
      await c.ir(BASE + '/admin/ranking?editar=' + id); await esp(1200);
      const urlEd = await c.avaliar('location.pathname + location.search');
      const avEd = await avisos(c);
      chk('abrir "editar" de campanha arquivada redireciona para Arquivadas com aviso', /arquivadas=1/.test(urlEd) && /desarquive/i.test(avEd), urlEd + ' | ' + avEd.slice(0, 100));
      await c.ir(BASE + '/admin/ranking?arquivadas=1'); await esp(800);
      const pEd = await postar(c, { acao: 'salvar_campanha', id, slug: 'teste-arquivar-1015', titulo: 'Hackeado', data_inicio: '2026-09-01T00:00', data_fim: '2026-09-10T23:59' });
      const pTog = await postar(c, { acao: 'alternar_ativa', id });
      await c.ir(BASE + '/admin/ranking?arquivadas=1'); await esp(800);
      t = await main(c);
      chk('POST direto de edição em arquivada: recusado, título não mudou', /Campanha arquivada não pode ser editada/.test(pEd.aviso) && t.indexOf(TITULO) >= 0 && t.indexOf('Hackeado') < 0, pEd.aviso.slice(0, 100));
      chk('POST direto de liga/desliga em arquivada não altera nada (continua só "Arquivada")', /Arquivada/.test(t.slice(t.indexOf(TITULO), t.indexOf(TITULO) + 80)) || t.indexOf(TITULO) >= 0, 'status ' + pTog.status);
      // arquivar de novo (já arquivada): sem erro
      const pArq = await postar(c, { acao: 'arquivar_campanha', id });
      chk('arquivar uma já arquivada: sem erro', pArq.status === 200 && !/n[ãa]o encontrada/.test(pArq.aviso), pArq.url + ' ' + pArq.aviso.slice(0, 80));
      const pInexistente = await postar(c, { acao: 'arquivar_campanha', id: '99999999' });
      chk('arquivar id inexistente: "Campanha não encontrada."', /n[ãa]o encontrada/i.test(pInexistente.aviso), pInexistente.aviso);
      // desarquivar e arquivar de novo
      await c.ir(BASE + '/admin/ranking?arquivadas=1'); await esp(800);
      await clicarNaLinha(c, TITULO, 'Desarquivar'); await esp(2800);
      const avD = await avisos(c); t = await main(c);
      chk('desarquivar: volta para "Em uso" com aviso', /desarquivada/i.test(avD) && t.indexOf(TITULO) >= 0, avD.slice(0, 120));
      await clicarNaLinha(c, TITULO, 'Arquivar'); await esp(2800);
      await c.ir(BASE + '/admin/ranking?arquivadas=1'); await esp(800);
      t = await main(c);
      chk('arquivada de novo (fica guardada como registro de teste)', t.indexOf(TITULO) >= 0 && /Arquivada em/.test(t));
      await c.captura(path.join(ESTADO, 'ranking_arquivadas.png'));

      // usuário comum não consegue arquivar
      await c.enviar('Network.clearBrowserCookies');
      await entrar(c, U.email, U.senha);
      await c.ir(BASE + '/configuracao_usuario'); await esp(700);
      const pu = await c.avaliar(`(async function(){var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)});var fd=new FormData();fd.append(tk.name,tk.value);fd.append('acao','desarquivar_campanha');fd.append('id',${JSON.stringify(id)});var r=await fetch('/admin/ranking',{method:'POST',body:fd,credentials:'include'});return JSON.stringify({url:r.url.replace(location.origin,'')})})()`).then(JSON.parse);
      chk('usuário comum não consegue desarquivar (volta para /index, sem aviso)', pu.url === '/index', pu.url);
      // a tela de Ranking do usuário segue mostrando a campanha em andamento
      const ru = await inspecionar(c, '/ranking', { pausa: 800 });
      const tu = linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
      chk('tela de Ranking do usuário mostra só a de Outubro (a arquivada não aparece)', /CAMPANHA DE OUTUBRO/i.test(tu) && tu.indexOf('TESTE arquivar') < 0 && resumo(ru).length === 0, tu.slice(0, 120));
    }
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO fase ${process.env.PHASE || '1'}: ${ok} ok, ${falha} falhas`);
})();
