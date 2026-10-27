// Editor Guiado (20/10): (1) mídia das Boas-vindas com feedback visível (vazio → anexada com miniatura/nome/Trocar/Tirar), (2) mídia também no Upsell, Downsell e Order Bump,
// (3) "Esconder o botão de recusar" no Upsell, Downsell SEM botões Aceitar/Recusar (um botão por plano marcado), Boas-vindas com o botão CTA opcional e o ESPAÇAMENTO entre os campos (rótulos não colados no campo de cima), (4) tudo persiste ao salvar e reabrir, (5) caminho de mídia adulterado por POST é zerado pelo servidor.
// Cria 1 fluxo de teste e APAGA no fim (as ~6 miniaturas/vídeos pequenos enviados ficam em uploads/ do servidor: não há como apagá-los pela tela).
// Não usa inspecionar() (zera c.eventos e o auto-aceitador de diálogos trava). Uso: node 32_basico_midia_ofertas.js   (capturas em estado/midia_*.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const MIDIA = path.join(PROJ, 'tests/homologacao/ui/midia');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };
(async () => {
  const c = await lancar();
  let id_fluxo = '';
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    const qtd = async () => { await c.ir(BASE + '/fluxos'); await esp(1000); return Number(((await c.avaliar(`document.querySelector('main').innerText`)).match(/Novo Fluxo \((\d+)\/50\)/) || [])[1]); };
    const antes = await qtd();
    await c.ir(BASE + '/fluxo_basico'); await esp(2500);

    const js = (expr) => c.avaliar(expr);
    const secao = async (s) => { await js(`document.querySelector('.basico-nav-item[data-secao="${s}"]').click(); 1`); await esp(450); };
    const subir = async (seletor, arquivo) => {
      const marca = 'm' + Math.floor(Math.random() * 1e9);
      const r = await js(`(function(){var el=document.querySelector(${JSON.stringify(seletor)});if(!el)return 'sem elemento';el.setAttribute('data-zt','${marca}');return 'ok'})()`);
      if (r !== 'ok') throw new Error('subir: ' + r + ' em ' + seletor);
      const doc = await c.enviar('DOM.getDocument', { depth: 0 });
      const q = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
      await c.enviar('DOM.setFileInputFiles', { nodeId: q.result.nodeId, files: [path.join(MIDIA, arquivo)] });
    };
    // a caixa de mídia: estado, tipo, texto, miniatura (imagem carregou?) e botões
    const caixa = async (sel) => JSON.parse(await js(`(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return JSON.stringify({});var img=e.querySelector('.midia-basico-mini img');
      var cs=getComputedStyle(e);return JSON.stringify({estado:e.getAttribute('data-estado'),tipo:e.getAttribute('data-tipo'),texto:e.innerText.replace(/\\s+/g,' ').trim(),
      img:!!img,imgOk:!!img&&img.complete&&img.naturalWidth>0,trocar:!!e.querySelector('.midia-trocar'),tirar:!!e.querySelector('.midia-tirar'),escolher:!!e.querySelector('.midia-escolher'),borda:cs.borderTopColor,estilo:cs.borderTopStyle})})()`));
    const esperaEstado = async (sel, fim) => {
      for (let i = 0; i < 80; i++) { const x = await caixa(sel); if (x.estado && x.estado !== 'enviando' && (!fim || x.estado === fim)) break; await esp(150); }
      for (let i = 0; i < 30; i++) { const x = await caixa(sel); if (!x.img || x.imgOk) return; await esp(150); }   // miniatura: dá tempo de a imagem carregar
    };
    const toast = () => js(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`);
    const visivel = (sel) => js(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return !!e && e.offsetParent!==null && !e.hidden})()`);

    // ---------- 1) Boas-vindas
    console.log('--- 1) Boas-vindas: a caixa de mídia mostra o que aconteceu');
    await secao('boasvindas');
    const BV = '#bv-midia-previa';
    let x = await caixa(BV);
    chk('vazio: convite "Adicionar foto ou vídeo" (botão), sem miniatura', x.estado === 'vazio' && x.tipo === 'none' && x.escolher && !x.img && /Adicionar foto ou vídeo/.test(x.texto), JSON.stringify(x));
    const borda_vazia = x.borda;
    await subir('#bv-midia-arquivo', 'foto1.png'); await esperaEstado(BV, 'anexada');
    x = await caixa(BV);
    chk('foto anexada: estado "anexada", tipo image, texto "Foto anexada" e nome do arquivo', x.estado === 'anexada' && x.tipo === 'image' && /Foto anexada/.test(x.texto) && /flow_image_.*\.png/.test(x.texto), x.texto);
    chk('mostra a MINIATURA da foto (a imagem carregou de verdade)', x.img && x.imgOk);
    chk('tem os botões "Trocar" e "Tirar a mídia" e some o convite', x.trocar && x.tirar && !x.escolher);
    chk('a caixa muda de aspecto (borda sólida e outra cor)', x.estilo === 'solid' && x.borda !== borda_vazia, 'vazia=' + borda_vazia + ' anexada=' + x.borda + ' (' + x.estilo + ')');
    chk('o aviso diz para salvar e o topo mostra "Alterações não salvas"', /Salvar alterações/.test(await toast()) && /Alterações não salvas/.test(await js(`document.getElementById('status-salvo').innerText`)), await toast());
    await c.captura(path.join(ESTADO, 'midia_boasvindas.png'));
    const caminho_foto1 = (await caixa(BV)).texto;

    await subir('#bv-midia-arquivo', 'falso.mp4'); await esp(2500);
    x = await caixa(BV);
    chk('arquivo falso (.mp4 que é texto) é recusado e a foto anterior continua lá', x.estado === 'anexada' && x.tipo === 'image' && x.texto === caminho_foto1, x.texto + ' | toast=' + await toast());
    await subir('#bv-midia-arquivo', 'video1.mp4'); await esperaEstado(BV, 'anexada'); await esp(300);
    x = await caixa(BV);
    chk('trocar por vídeo: "Vídeo anexado", tipo video, ícone no lugar da miniatura', x.estado === 'anexada' && x.tipo === 'video' && /Vídeo anexado/.test(x.texto) && !x.img && /flow_video_|\.mp4/.test(x.texto), x.texto);
    await subir('#bv-midia-arquivo', 'foto2.jpg'); await esperaEstado(BV, 'anexada'); await esp(300);
    x = await caixa(BV);
    chk('trocar de novo por foto: volta a "Foto anexada" com miniatura', x.tipo === 'image' && /Foto anexada/.test(x.texto) && x.img && x.imgOk, x.texto);
    const nome_bv = x.texto;
    await js(`document.querySelector('${BV} .midia-tirar').click(); 1`); await esp(400);
    x = await caixa(BV);
    chk('"Tirar a mídia" volta ao estado vazio e avisa', x.estado === 'vazio' && x.tipo === 'none' && /Mídia tirada/.test(await toast()), x.estado + ' | ' + await toast());
    await subir('#bv-midia-arquivo', 'foto2.jpg'); await esperaEstado(BV, 'anexada');
    chk('escolher a MESMA foto de novo funciona (o campo de arquivo é limpo a cada escolha)', (await caixa(BV)).estado === 'anexada');

    // ---------- 2) Ofertas
    console.log('\n--- 2) Upsell, Downsell e Order Bump: agora aceitam foto/vídeo');
    await secao('planos');
    await js(`document.getElementById('btn-adicionar-plano').click(); 1`); await esp(500);
    await js(`(function(){var p=document.querySelector('#lista-planos .campo-plano-nome');p.value='Plano do teste';p.dispatchEvent(new Event('input',{bubbles:true}));var v=document.querySelector('#lista-planos .campo-plano-valor');v.value='5';v.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
    await js(`document.getElementById('btn-adicionar-plano').click(); 1`); await esp(500);
    await js(`(function(){var n=document.querySelectorAll('#lista-planos .campo-plano-nome');var p=n[n.length-1];p.value='Plano dois';p.dispatchEvent(new Event('input',{bubbles:true}));var v=document.querySelectorAll('#lista-planos .campo-plano-valor');var q=v[v.length-1];q.value='8';q.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
    const ofertas = [['upsell', 'upsell', 'foto1.png', 'image'], ['downsell', 'downsell', 'video1.mp4', 'video'], ['order_bump', 'orderbump', 'foto2.jpg', 'image']];
    for (const [tipo, secao_nome, arquivo, tipo_esperado] of ofertas) {
      await secao(secao_nome);
      const P = `.painel[data-oferta="${tipo}"]`;
      await js(`(function(){var p=document.querySelector('${P} .of-ativo');p.checked=true;p.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(300);
      if (tipo === 'upsell') await js(`(function(){var s=document.querySelector('${P} .of-plano');s.value=s.options[2].value;s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);   // [0] "Escolha um plano", [1] "Criar um plano só para o Upsell", [2] o 1º plano
      else if (tipo === 'downsell') await js(`(function(){var c=document.querySelector('${P} .of-planos-lista input[type=checkbox]');c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
      else await js(`(function(){var v=document.querySelector('${P} .of-valor-extra');v.value='5';v.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
      await js(`(function(){var m=document.querySelector('${P} .of-mensagem');m.value='Oferta ${tipo} de teste';m.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
      let o = await caixa(P + ' .of-midia');
      chk(tipo + ': campo "Mídia (opcional)" existe, vazio, visível, com convite', o.estado === 'vazio' && o.escolher && await visivel(P + ' .of-midia') && /Adicionar foto ou vídeo/.test(o.texto) && /Mídia \(opcional\)/i.test(await js(`document.querySelector('${P} .of-midia').closest('.campo').innerText`)), JSON.stringify(o));
      await subir(P + ' .of-midia-arquivo', arquivo); await esperaEstado(P + ' .of-midia', 'anexada'); await esp(300);
      o = await caixa(P + ' .of-midia');
      chk(tipo + ': ' + (tipo_esperado === 'image' ? 'foto' : 'vídeo') + ' anexado(a) — estado, tipo, nome e botões Trocar/Tirar' + (tipo_esperado === 'image' ? ', com miniatura' : ''),
        o.estado === 'anexada' && o.tipo === tipo_esperado && o.trocar && o.tirar && (tipo_esperado === 'image' ? o.img && o.imgOk && /Foto anexada/.test(o.texto) : /Vídeo anexado/.test(o.texto)), o.texto);
      chk(tipo + ': o aviso diz que a mídia foi anexada à oferta', /Mídia anexada à oferta/.test(await toast()), await toast());
      if (tipo === 'upsell') await c.captura(path.join(ESTADO, 'midia_upsell.png'));
    }

    // ---------- 3) Esconder o Recusar (Upsell)
    console.log('\n--- 3) Upsell: "Esconder o botão de recusar"');
    await secao('upsell');
    const UP = '.painel[data-oferta="upsell"]';
    chk('só o Upsell tem a opção (Downsell e Order Bump não)', (await js(`document.querySelectorAll('.of-esconder-recusar').length`)) === 1 && (await js(`document.querySelectorAll('${UP} .of-esconder-recusar').length`)) === 1);
    chk('desligada por padrão: campos do Recusar visíveis e sem a dica', (await visivel(UP + ' .of-recusar')) && (await visivel(UP + ' .of-recusar-cor')) && !(await visivel(UP + ' .of-esconder-recusar-dica')));
    await js(`document.querySelector('${UP} .of-esconder-recusar').click(); 1`); await esp(300);
    chk('ligando: os campos do Recusar somem (texto e cor) e aparece a explicação', !(await visivel(UP + ' .of-recusar')) && !(await visivel(UP + ' .of-recusar-cor')) && /Só o botão aceitar aparece/.test(await js(`document.querySelector('${UP} .of-esconder-recusar-dica').innerText`)) && (await visivel(UP + ' .of-esconder-recusar-dica')));
    chk('os campos do Aceitar continuam visíveis', (await visivel(UP + ' .of-aceitar')) && (await visivel(UP + ' .of-aceitar-cor')));
    await c.captura(path.join(ESTADO, 'midia_upsell_sem_recusar.png'));
    await js(`document.querySelector('${UP} .of-esconder-recusar').click(); 1`); await esp(250);
    chk('desligando: os campos do Recusar voltam', (await visivel(UP + ' .of-recusar')) && !(await visivel(UP + ' .of-esconder-recusar-dica')));
    await js(`document.querySelector('${UP} .of-esconder-recusar').click(); 1`); await esp(250);   // deixa ligado para salvar

    // ---------- 3b) Downsell sem Aceitar/Recusar
    console.log('\n--- 3b) Downsell: sem Aceitar/Recusar, só os planos que você marcar');
    await secao('downsell');
    const DN = '.painel[data-oferta="downsell"]';
    chk('o Downsell NÃO tem mais campos de botão aceitar/recusar nem a lista suspensa de um plano só', (await js(`document.querySelectorAll('${DN} .of-aceitar, ${DN} .of-recusar, ${DN} .of-aceitar-cor, ${DN} .of-recusar-cor, ${DN} .of-plano').length`)) === 0);
    chk('o Upsell e o Order Bump continuam com os botões aceitar/recusar', (await js(`document.querySelectorAll('.painel[data-oferta="upsell"] .of-aceitar, .painel[data-oferta="upsell"] .of-recusar, .painel[data-oferta="order_bump"] .of-aceitar, .painel[data-oferta="order_bump"] .of-recusar').length`)) === 4);
    const caixas = JSON.parse(await js(`JSON.stringify([...document.querySelectorAll('${DN} .of-planos-lista input[type=checkbox]')].map(function(c){return {marcado:c.checked,rotulo:c.closest('label').innerText.trim()}}))`));
    chk('lista de planos: 1 caixa por plano do fluxo ("Plano do teste — R$ 5,00" e "Plano dois — R$ 8,00"), só o 1º marcado', caixas.length === 2 && caixas[0].marcado === true && caixas[1].marcado === false && /Plano do teste — R\$ 5,00/i.test(caixas[0].rotulo) && /Plano dois — R\$ 8,00/i.test(caixas[1].rotulo), JSON.stringify(caixas));
    chk('a explicação diz que só os marcados aparecem (e que não há aceitar/recusar)', /Só os planos marcados aparecem/.test(await js(`document.querySelector('${DN}').innerText`)) && /Não tem botão de aceitar nem de recusar/.test(await js(`document.querySelector('${DN}').innerText`)));
    await c.captura(path.join(ESTADO, 'midia_downsell.png'));
    await js(`document.querySelector('${DN} .of-planos-lista input[type=checkbox]').click(); 1`); await esp(300);
    chk('marcar/desmarcar um plano deixa o fluxo como "Alterações não salvas"', /Alterações não salvas/.test(await js(`document.getElementById('status-salvo').innerText`)));
    await js(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(1200);
    chk('downsell ligado SEM nenhum plano marcado: o salvar é recusado com aviso claro', /Marque pelo menos 1 plano oferecido no Downsell/.test(await toast()), await toast());
    await js(`document.querySelectorAll('${DN} .of-planos-lista input[type=checkbox]')[0].click(); 1`); await esp(300);   // volta a marcar só o 1º

    // ---------- 3c) Boas-vindas: botão CTA opcional
    console.log('\n--- 3c) Boas-vindas: "Mostrar botão (CTA) na mensagem"');
    await secao('boasvindas');
    const vis = async (sel) => !!(await visivel(sel));
    chk('por padrão o botão CTA está LIGADO: texto e cor do botão visíveis e sem a explicação', (await js(`document.getElementById('bv-mostrar-cta').checked`)) === true && await vis('#bv-cta') && await vis('#bv-cta-cor') && !(await vis('#bv-cta-dica')));
    await js(`document.getElementById('bv-mostrar-cta').click(); 1`); await esp(300);
    chk('desligando: texto e cor do botão somem e aparece "Sem o botão, a lista de planos é enviada logo depois da mensagem"', !(await vis('#bv-cta')) && !(await vis('#bv-cta-cor')) && (await vis('#bv-cta-dica')) && /a lista de planos é enviada logo depois da mensagem/.test(await js(`document.getElementById('bv-cta-dica').innerText`)));
    chk('a mídia continua visível com o botão desligado', await vis('#bv-midia-previa'));
    await c.captura(path.join(ESTADO, 'midia_boasvindas_sem_cta.png'));
    chk('desligar o botão deixa o fluxo como "Alterações não salvas"', /Alterações não salvas/.test(await js(`document.getElementById('status-salvo').innerText`)));

    // ---------- 3d) Espaçamento: nenhum rótulo colado no campo de cima
    console.log('\n--- 3d) Espaçamento entre os campos (rótulo não pode ficar colado no campo de cima)');
    const folgas = (alvoSel) => js(`(function(){
      var raiz = document.querySelector(${JSON.stringify(alvoSel)});
      var itens = [].filter.call(raiz.children, function(e){ return e.offsetParent !== null && (e.classList.contains('campo') || e.classList.contains('grade') || e.classList.contains('basico-interruptor')) && e.getBoundingClientRect().height > 0; });
      var piores = [];
      for (var i = 1; i < itens.length; i++) {
        var a = itens[i-1].getBoundingClientRect(), b = itens[i].getBoundingClientRect();
        piores.push(Math.round((b.top - a.bottom) * 10) / 10);
      }
      return JSON.stringify({ n: itens.length, folgas: piores, menor: piores.length ? Math.min.apply(null, piores) : 99 });
    })()`).then(JSON.parse);
    for (const [largura, mobile] of [[1360, false], [390, true]]) {
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: 900, deviceScaleFactor: 1, mobile });
      await esp(300);
      for (const [secao_nome, alvo] of [['boasvindas', '[data-secao="boasvindas"] .painel'], ['upsell', '.painel[data-oferta="upsell"] .of-detalhes'], ['downsell', '.painel[data-oferta="downsell"] .of-detalhes'], ['orderbump', '.painel[data-oferta="order_bump"] .of-detalhes']]) {
        await secao(secao_nome);
        if (secao_nome === 'boasvindas') { await js(`document.getElementById('bv-mostrar-cta').click(); 1`); await esp(250); }   // mede com o botão CTA LIGADO (interruptor + grade logo abaixo)
        const r = await folgas(alvo);
        if (secao_nome === 'boasvindas') { await js(`document.getElementById('bv-mostrar-cta').click(); 1`); await esp(250); }
        chk(`${largura}px · ${secao_nome}: entre um bloco e o seguinte há pelo menos 10 px (menor folga ${r.menor}px em ${r.n} blocos)`, r.n >= 2 && r.menor >= 10, JSON.stringify(r.folgas));
      }
    }
    // alinhamento dentro das grades: campos da MESMA linha começam na mesma altura (o respiro entre blocos não pode empurrar o 2º campo)
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await esp(300);
    for (const [secao_nome, alvo] of [['boasvindas', '[data-secao="boasvindas"] .painel'], ['upsell', '.painel[data-oferta="upsell"] .of-detalhes'], ['downsell', '.painel[data-oferta="downsell"] .of-detalhes'], ['orderbump', '.painel[data-oferta="order_bump"] .of-detalhes']]) {
      await secao(secao_nome);
      if (secao_nome === 'boasvindas') { await js(`document.getElementById('bv-mostrar-cta').click(); 1`); await esp(250); }
      const r = JSON.parse(await js(`(function(){
        var desalinhados = [], pares = 0;
        [].forEach.call(document.querySelector(${JSON.stringify(alvo)}).querySelectorAll('.grade'), function (g) {
          var cel = [].filter.call(g.children, function (e) { return e.offsetParent !== null && e.getBoundingClientRect().height > 0; });
          for (var i = 0; i < cel.length; i++) for (var j = i + 1; j < cel.length; j++) {
            var a = cel[i].getBoundingClientRect(), b = cel[j].getBoundingClientRect();
            var mesmaLinha = a.top < b.bottom - 1 && b.top < a.bottom - 1 && a.left !== b.left;
            if (mesmaLinha) { pares++; if (Math.abs(a.top - b.top) > 1) desalinhados.push(Math.round(a.top) + 'x' + Math.round(b.top)); }
          }
        });
        return JSON.stringify({ pares: pares, desalinhados: desalinhados });
      })()`));
      if (secao_nome === 'boasvindas') { await js(`document.getElementById('bv-mostrar-cta').click(); 1`); await esp(250); }
      chk(`1360px · ${secao_nome}: os campos lado a lado na mesma linha começam na mesma altura (${r.pares} pares conferidos)`, (r.pares >= 1 || secao_nome === 'downsell') && r.desalinhados.length === 0, JSON.stringify(r.desalinhados));
    }
    await secao('upsell'); await esp(300);
    await c.captura(path.join(ESTADO, 'midia_upsell_espacamento.png'));
    await secao('boasvindas');

    // ---------- 4) Salvar e reabrir
    console.log('\n--- 4) Salvar e reabrir: nada se perde');
    await js(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(3500);
    id_fluxo = await js(`document.getElementById('id-fluxo').value`);
    chk('salvou o fluxo (o servidor devolveu um id)', !!id_fluxo, 'id=' + id_fluxo + ' toast=' + await toast());
    const lerServidor = () => js(`fetch('api.php?action=obter_fluxo&id=${Number(id_fluxo) || 0}',{credentials:'include'}).then(function(r){return r.json()}).then(function(j){var d=(j.fluxo&&j.fluxo.dados_fluxograma)||{};var o=d.ofertas||{};var f=function(k){return {tipo:(o[k]||{}).midia_tipo,path:(o[k]||{}).midia_path}};return JSON.stringify({bv:{tipo:(d.boas_vindas||{}).midia_tipo,path:(d.boas_vindas||{}).midia_path},up:f('upsell'),dn:f('downsell'),bp:f('order_bump'),esc:(o.upsell||{}).esconder_recusar,escDn:(o.downsell||{}).esconder_recusar,escBp:(o.order_bump||{}).esconder_recusar,dnPlanos:(o.downsell||{}).planos_oferecidos,dnDest:(o.downsell||{}).id_plano_destino,dnAceitar:(o.downsell||{}).texto_aceitar,dnRecusar:(o.downsell||{}).texto_recusar,planos:(d.planos||[]).map(function(p){return p.id}),cta:(d.boas_vindas||{}).mostrar_cta})})`).then(JSON.parse);
    let srv = await lerServidor();
    chk('servidor guardou a mídia das Boas-vindas (foto em uploads/)', srv.bv.tipo === 'image' && /^uploads\/flow_image_[^/]+$/.test(srv.bv.path), JSON.stringify(srv.bv));
    chk('servidor guardou a mídia do Upsell (foto), do Downsell (vídeo) e do Order Bump (foto)', srv.up.tipo === 'image' && /^uploads\//.test(srv.up.path) && srv.dn.tipo === 'video' && /^uploads\//.test(srv.dn.path) && srv.bp.tipo === 'image' && /^uploads\//.test(srv.bp.path), JSON.stringify([srv.up, srv.dn, srv.bp]));
    chk('servidor guardou "esconder recusar" só no Upsell', srv.esc === true && !srv.escDn && !srv.escBp, JSON.stringify([srv.esc, srv.escDn, srv.escBp]));
    chk('servidor guardou o Downsell novo: planos_oferecidos = só o 1º plano, id_plano_destino = o mesmo, e SEM textos de aceitar/recusar', Array.isArray(srv.dnPlanos) && srv.dnPlanos.length === 1 && srv.dnPlanos[0] === srv.planos[0] && srv.dnDest === srv.planos[0] && !srv.dnAceitar && !srv.dnRecusar, JSON.stringify([srv.dnPlanos, srv.dnDest, srv.dnAceitar, srv.dnRecusar, srv.planos]));
    chk('servidor guardou o botão CTA das Boas-vindas como DESLIGADO (mostrar_cta = false)', srv.cta === false, JSON.stringify(srv.cta));
    const guardado = JSON.stringify(srv);
    await c.ir(BASE + '/fluxo_basico?id=' + id_fluxo); await esp(3000);
    x = await caixa(BV);
    await secao('boasvindas');
    chk('reabrindo: o botão CTA continua desligado e os campos dele escondidos', (await js(`document.getElementById('bv-mostrar-cta').checked`)) === false && !(await visivel('#bv-cta')) && (await visivel('#bv-cta-dica')));
    chk('reabrindo: Boas-vindas mostra a foto com miniatura carregada', x.estado === 'anexada' && x.tipo === 'image' && x.img && x.imgOk, x.texto);
    await secao('upsell');
    let u = await caixa(UP + ' .of-midia');
    chk('reabrindo: Upsell mostra a foto, e o "esconder recusar" continua ligado com os campos do Recusar escondidos', u.estado === 'anexada' && u.imgOk && (await js(`document.querySelector('${UP} .of-esconder-recusar').checked`)) && !(await visivel(UP + ' .of-recusar')), u.texto);
    await secao('downsell');
    const marcadosAoReabrir = JSON.parse(await js(`JSON.stringify([...document.querySelectorAll('.painel[data-oferta="downsell"] .of-planos-lista input[type=checkbox]')].map(function(c){return c.checked}))`));
    chk('reabrindo: o Downsell mostra o plano marcado (1º) e o outro desmarcado', JSON.stringify(marcadosAoReabrir) === '[true,false]', JSON.stringify(marcadosAoReabrir));
    let d = await caixa('.painel[data-oferta="downsell"] .of-midia');
    chk('reabrindo: Downsell mostra o vídeo', d.estado === 'anexada' && d.tipo === 'video' && /Vídeo anexado/.test(d.texto), d.texto);
    await secao('orderbump');
    let b = await caixa('.painel[data-oferta="order_bump"] .of-midia');
    chk('reabrindo: Order Bump mostra a foto', b.estado === 'anexada' && b.tipo === 'image' && b.imgOk, b.texto);
    chk('status do topo "Tudo salvo" depois de reabrir', /Tudo salvo/.test(await js(`document.getElementById('status-salvo').innerText`)));

    // ---------- 5) Tirar a mídia de uma oferta e salvar
    console.log('\n--- 5) Tirar a mídia de uma oferta e salvar');
    await js(`document.querySelector('.painel[data-oferta="order_bump"] .of-midia .midia-tirar').click(); 1`); await esp(400);
    b = await caixa('.painel[data-oferta="order_bump"] .of-midia');
    chk('Order Bump: "Tirar a mídia" volta ao vazio, o aviso aparece e o topo vira "Alterações não salvas"', b.estado === 'vazio' && /Mídia tirada da oferta/.test(await toast()) && /Alterações não salvas/.test(await js(`document.getElementById('status-salvo').innerText`)), b.estado);
    await js(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(3000);
    srv = await lerServidor();
    chk('depois de salvar: o Order Bump ficou sem mídia no servidor e as outras continuam', (!srv.bp.path || srv.bp.path === '') && srv.bp.tipo === 'none' && srv.up.path === JSON.parse(guardado).up.path && srv.dn.path === JSON.parse(guardado).dn.path && srv.bv.path === JSON.parse(guardado).bv.path, JSON.stringify(srv));

    // ---------- 5b) Fluxo no formato ANTIGO de downsell
    console.log('\n--- 5b) Fluxo antigo (Downsell com 1 plano + Aceitar/Recusar) abre com o plano marcado');
    const r5b = await js(`(async function(){
      var j = await fetch('api.php?action=obter_fluxo&id=${Number(id_fluxo)}',{credentials:'include'}).then(function(r){return r.json()});
      var d = j.fluxo.dados_fluxograma;
      delete d.boas_vindas.mostrar_cta;
      d.ofertas.downsell = {ativo:true,id_plano_destino:d.planos[1].id,desconto_percentual:15,mensagem:'antigo',texto_aceitar:'Quero!',texto_recusar:'Nao',cor_aceitar:'',cor_recusar:''};
      var resp = await fetch('api.php?action=salvar_fluxo',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},body:JSON.stringify({id:${Number(id_fluxo)},nome:j.fluxo.nome,descricao:j.fluxo.descricao||'',modo:'basico',dados_fluxograma:d})}).then(function(r){return r.json()});
      return JSON.stringify({ok:resp.sucesso});
    })()`);
    await c.ir(BASE + '/fluxo_basico?id=' + id_fluxo); await esp(3000);
    await secao('boasvindas');
    chk('fluxo antigo (sem o campo mostrar_cta): as Boas-vindas abrem com o botão CTA LIGADO', (await js(`document.getElementById('bv-mostrar-cta').checked`)) === true && (await visivel('#bv-cta')), '');
    await secao('downsell');
    const antigo = JSON.parse(await js(`JSON.stringify({marcados:[...document.querySelectorAll('.painel[data-oferta="downsell"] .of-planos-lista input[type=checkbox]')].map(function(c){return c.checked}),desc:document.querySelector('.painel[data-oferta="downsell"] .of-desconto').value,msg:document.querySelector('.painel[data-oferta="downsell"] .of-mensagem').value})`));
    chk('downsell antigo (plano 2 + 15% + mensagem): abre com o "Plano dois" marcado, desconto 15 e a mensagem', JSON.parse(r5b).ok === true && JSON.stringify(antigo.marcados) === '[false,true]' && antigo.desc === '15' && antigo.msg === 'antigo', r5b + ' ' + JSON.stringify(antigo));
    await js(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(1500);
    await js(`document.querySelector('.basico-nav-item[data-secao="downsell"]').click(); 1`);
    srv = await lerServidor();
    chk('salvar esse fluxo antigo no editor novo converte para o formato novo (planos_oferecidos = [plano 2], sem textos de aceitar/recusar)', Array.isArray(srv.dnPlanos) && srv.dnPlanos.length === 1 && srv.dnPlanos[0] === srv.planos[1] && !srv.dnAceitar, JSON.stringify([srv.dnPlanos, srv.dnAceitar]));

    // ---------- 6) Caminho adulterado por POST direto
    console.log('\n--- 6) Servidor: caminho de mídia adulterado é zerado');
    const r6 = await js(`(async function(){
      var j = await fetch('api.php?action=obter_fluxo&id=${Number(id_fluxo)}',{credentials:'include'}).then(function(r){return r.json()});
      var d = j.fluxo.dados_fluxograma;
      d.ofertas.upsell.midia_tipo='image';   d.ofertas.upsell.midia_path='../config.php';
      d.ofertas.downsell.midia_tipo='image'; d.ofertas.downsell.midia_path='config.php';
      d.ofertas.order_bump.midia_tipo='image'; d.ofertas.order_bump.midia_path='uploads/../config.php';
      var resp = await fetch('api.php?action=salvar_fluxo',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},body:JSON.stringify({id:${Number(id_fluxo)},nome:j.fluxo.nome,descricao:j.fluxo.descricao||'',modo:'basico',dados_fluxograma:d})}).then(function(r){return r.json()});
      return JSON.stringify({ok:resp.sucesso});
    })()`);
    srv = await lerServidor();
    chk('POST com "../config.php", "config.php" e "uploads/../config.php" nas 3 ofertas: o servidor aceita o fluxo mas zera os 3 caminhos', JSON.parse(r6).ok === true && !srv.up.path && !srv.dn.path && !srv.bp.path, r6 + ' ' + JSON.stringify([srv.up, srv.dn, srv.bp]));

    // ---------- limpeza
    console.log('\n--- limpeza');
    await c.ir(BASE + '/fluxos'); await esp(900);
    await js(`jQuery.ajax({url:'api.php?action=excluir_fluxo',method:'POST',contentType:'application/json',data:JSON.stringify({id:${Number(id_fluxo)}})}); 1`); await esp(900);
    chk('a conta ficou com o mesmo número de fluxos', (await qtd()) === antes);
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
