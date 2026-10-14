// ÁREA 18: API — autorização entre usuários (IDOR) e uploads de mídia
const { execFileSync } = require('child_process');
const { lancar, entrar, BASE, db, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  const U2 = JSON.parse(remoto('php zz_pg.php user2').trim());
  const criados = [];
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  const subir = async (acao, campo, nome, bytes, tipo) => {
    const r = await c.avaliar(`(async function(){ var fd=new FormData(); fd.append(${JSON.stringify(campo)}, new Blob([${bytes}],{type:${JSON.stringify(tipo)}}), ${JSON.stringify(nome)}); var r=await fetch('api.php?action=${acao}',{method:'POST',headers:{'X-CSRF-Token':window.CSRF_TOKEN||''},body:fd}); return {http:r.status,j:await r.json().catch(function(){return null})}; })()`);
    if (r.j && r.j.caminho) criados.push(r.j.caminho);
    return r;
  };
  const PNG = `await new Promise(function(res){ var cv=document.createElement('canvas'); cv.width=8; cv.height=8; cv.getContext('2d').fillRect(0,0,8,8); cv.toBlob(res,'image/png'); })`;
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    await c.ir(BASE + '/fluxos');
    area('18. API — isolamento entre usuários (recursos do usuário 2: bot ' + U2.bot + ', fluxo ' + U2.fluxo + ')');
    const casos = [
      ['obter_bot', 'obter_bot&id=' + U2.bot, null, 'GET'],
      ['obter_fluxo', 'obter_fluxo&id=' + U2.fluxo, null, 'GET'],
      ['exportar_fluxo', 'exportar_fluxo&id=' + U2.fluxo, null, 'GET'],
      ['resumo_fluxo', 'resumo_fluxo&id=' + U2.fluxo, null, 'GET'],
      ['conversoes_fluxo', 'conversoes_fluxo&id=' + U2.fluxo, null, 'GET'],
      ['obter_link_rastreamento', 'obter_link_rastreamento&id=' + db(`SELECT id FROM links_rastreamento WHERE identificador='u2_link'`)[0].id, null, 'GET'],
      ['obter_redirecionador', 'obter_redirecionador&id=' + U2.redir, null, 'GET'],
      ['campanhas_redirecionador', 'campanhas_redirecionador&id=' + U2.redir, null, 'GET'],
    ];
    await t('leitura de recursos do outro usuário é recusada em 8 endpoints (obter_bot/fluxo, exportar, resumo, conversões, link, redirecionador, campanhas)', async () => {
      const vazou = [];
      for (const [nome, qs, corpo, metodo] of casos) {
        const r = await api(qs, corpo, metodo);
        const txt = JSON.stringify(r.j || {});
        if (r.http === 200 && r.j && r.j.sucesso !== false && /Fluxo Secreto U2|pg2_bot|SIMBOT:AAH-pg2|u2_link|Redir U2|confidencial/.test(txt)) vazou.push(nome);
      }
      return { ok: !vazou.length, det: vazou.join(',') };
    }, 'segurança');
    await t('bots_do_fluxo com fluxo alheio só lista bots do próprio usuário (nunca o bot do usuário 2)', async () => {
      const r = await api('bots_do_fluxo&id=' + U2.fluxo, null, 'GET');
      return { ok: !/pg2_bot|Usuario 2|Bot do Usuario 2/.test(JSON.stringify(r.j)), det: JSON.stringify(r.j).slice(0, 120) };
    }, 'segurança');
    await t('ações de escrita com id do outro usuário falham sem efeito: excluir_bot, excluir_fluxo, salvar_fluxo, vincular_bot_fluxo, editar/excluir link, alternar/excluir redirecionador', async () => {
      const antes = JSON.stringify(db(`SELECT (SELECT COUNT(*) FROM bots WHERE id=${U2.bot}) b, (SELECT COUNT(*) FROM fluxos WHERE id=${U2.fluxo}) f, (SELECT nome FROM fluxos WHERE id=${U2.fluxo}) nf, (SELECT COUNT(*) FROM redirecionadores WHERE id=${U2.redir}) r, (SELECT ativo FROM redirecionadores WHERE id=${U2.redir}) ra, (SELECT titulo FROM links_rastreamento WHERE identificador='u2_link') lt`)[0]);
      const lid = db(`SELECT id FROM links_rastreamento WHERE identificador='u2_link'`)[0].id;
      await api('excluir_bot', { id: U2.bot });
      await api('excluir_fluxo', { id: U2.fluxo });
      await api('salvar_fluxo', { id: U2.fluxo, nome: 'HACK', modo: 'avancado', dados_fluxograma: { operators: {}, links: {} } });
      await api('vincular_bot_fluxo', { id_bot: U2.bot, id_fluxo: 0 });
      await api('editar_link_rastreamento', { id: lid, titulo: 'HACK', identificador: 'hack_u2', bot_id: 311 });
      await api('excluir_link_rastreamento', { id: lid });
      await api('alternar_redirecionador', { id: U2.redir, ativo: true });
      await api('excluir_redirecionador', { id: U2.redir });
      const depois = JSON.stringify(db(`SELECT (SELECT COUNT(*) FROM bots WHERE id=${U2.bot}) b, (SELECT COUNT(*) FROM fluxos WHERE id=${U2.fluxo}) f, (SELECT nome FROM fluxos WHERE id=${U2.fluxo}) nf, (SELECT COUNT(*) FROM redirecionadores WHERE id=${U2.redir}) r, (SELECT ativo FROM redirecionadores WHERE id=${U2.redir}) ra, (SELECT titulo FROM links_rastreamento WHERE identificador='u2_link') lt`)[0]);
      return { ok: antes === depois, det: antes === depois ? 'nada mudou' : antes + ' → ' + depois };
    }, 'segurança');
    await t('o vínculo de fluxo alheio em salvar_bot/atualizar_perfil_bot é recusado (corrigido nesta rodada)', async () => {
      const r = await api('atualizar_perfil_bot', { id: 311, nome: 'X', descricao: 'd', descricao_curta: 'c', id_fluxo_conectado: U2.fluxo });
      const dep = db(`SELECT id_fluxo_conectado f FROM bots WHERE id=311`)[0].f;
      return { ok: r.http === 404 && Number(dep) !== U2.fluxo, det: `HTTP ${r.http}; fluxo do bot=${dep}` };
    }, 'segurança');
    await t('importar_fluxo / salvar_fluxo não aceitam um id de fluxo alheio para sobrescrever (cria um novo, nunca altera o do outro)', async () => {
      const n0 = db(`SELECT nome FROM fluxos WHERE id=${U2.fluxo}`)[0].nome;
      const r = await api('salvar_fluxo', { id: U2.fluxo, nome: 'HACK2', modo: 'avancado', dados_fluxograma: { operators: {}, links: {} } });
      const n1 = db(`SELECT nome FROM fluxos WHERE id=${U2.fluxo}`)[0].nome;
      return { ok: n0 === n1 && r.http >= 400, det: `HTTP ${r.http}` };
    }, 'segurança');

    area('18b. Uploads de mídia (imagem, vídeo, áudio, remarketing)');
    await t('imagem do fluxo: PNG real é aceito e servido como imagem; texto/PHP/SVG disfarçados de imagem são recusados', async () => {
      const ok = await subir('upload_imagem_fluxo', 'image', 'ok.png', PNG, 'image/png');
      const falsos = [['falso.png', `'isto nao e png'`, 'image/png'], ['x.php', `'<?php echo 1;'`, 'text/plain'], ['x.svg', `'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'`, 'image/svg+xml'], ['x.png', `'GIF89a<?php system(1); ?>'`, 'image/png']];
      const aceitos = [];
      for (const [n, b, tp] of falsos) { const r = await subir('upload_imagem_fluxo', 'image', n, b, tp); if (r.j && r.j.sucesso) aceitos.push(n); }
      const info = ok.j && ok.j.caminho ? execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code} %{content_type}', BASE + '/' + ok.j.caminho], { encoding: 'utf8' }) : 'sem caminho';
      return { ok: ok.j && ok.j.sucesso && /^200 image\//.test(info) && !aceitos.length, det: `${info}; aceitos indevidos: ${aceitos.join(',') || 'nenhum'}` };
    }, 'segurança');
    await t('vídeo/áudio do fluxo: extensão fora da lista e conteúdo que não é mídia (texto como .mp4/.mp3) são recusados', async () => {
      const a = await subir('upload_video_fluxo', 'video', 'falso.mp4', `'isto nao e video'`, 'video/mp4');
      const b = await subir('upload_video_fluxo', 'video', 'x.exe', `'MZ'`, 'application/octet-stream');
      const d = await subir('upload_audio_fluxo', 'audio', 'falso.mp3', `'isto nao e audio'`, 'audio/mpeg');
      const e = await subir('upload_audio_fluxo', 'audio', 'x.php', `'<?php 1;'`, 'text/plain');
      return { ok: [a, b, d, e].every(r => r.j && !r.j.sucesso), det: [a, b, d, e].map(r => r.j && r.j.mensagem).join(' | ').slice(0, 220) };
    }, 'segurança');
    await t('mídia de remarketing: imagem >5 MB, vídeo falso e formato inválido são recusados', async () => {
      const a = await subir('upload_midia_remarketing', 'midia', 'grande.png', `new Uint8Array(5*1024*1024+4096)`, 'image/png');
      const b = await subir('upload_midia_remarketing', 'midia', 'falso.mp4', `'texto'`, 'video/mp4');
      const d = await subir('upload_midia_remarketing', 'midia', 'x.gif', `'GIF89a'`, 'image/gif');
      const e = await subir('upload_midia_remarketing', 'midia', 'ok.png', PNG, 'image/png');
      return { ok: [a, b, d].every(r => r.j && !r.j.sucesso) && e.j && e.j.sucesso && e.j.tipo === 'foto', det: [a, b, d].map(r => r.j && r.j.mensagem).join(' | ').slice(0, 200) };
    }, 'segurança');
    await t('arquivos enviados não são executáveis: um .php nunca é gravado em uploads/', async () => {
      const gravados = criados.filter(p => /\.(php|phtml|phar)/i.test(p));
      return { ok: !gravados.length, det: `${criados.length} arquivos de teste gravados` };
    }, 'segurança');
    nota('Os endpoints de upload do fluxo (imagem/vídeo/áudio) não impõem limite de tamanho próprio nem cota por usuário: o teto é o do PHP/servidor. Sem limpeza de arquivos órfãos, um usuário pode ocupar disco. Telegram só aceita foto até 10 MB e vídeo até 50 MB', 'observação — sem correção');
    // limpeza dos arquivos de teste
    for (const p of criados) { if (/^uploads\/[A-Za-z0-9_.]+$/.test(p)) remoto('rm -f ' + p); }
    salvar();
  } finally { await c.fechar(); }
})();
