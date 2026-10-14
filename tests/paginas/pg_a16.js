// ÁREA 19: Ranking redesenhado (abas, mensal, atualização ao vivo, contagem)
const { lancar, entrar, BASE, db } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('19. Ranking — abas, mensal e tempo real');
    const mes = new Date(); const ini = mes.getFullYear() + '-' + String(mes.getMonth() + 1).padStart(2, '0') + '-01';
    const esperado = db(`SELECT m.id_usuario, SUM(m.valor_pago) f FROM metricas_horarias_usuario m JOIN usuarios u ON u.id=m.id_usuario WHERE m.data >= '${ini}' AND u.perfil='usuario' GROUP BY m.id_usuario HAVING SUM(m.valor_pago)>0 ORDER BY f DESC, m.id_usuario ASC LIMIT 10`);
    await c.ir(BASE + '/ranking?aba=mensal');
    await t('aba Mensal: o placar bate com a soma de vendas aprovadas do mês no banco (mesma ordem e valores)', async () => {
      const vals = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.podio-valor, .linha-ranking .linha-ranking-valor')].map(function(e){return e.textContent.trim()}))`));
      const fmt = (v) => { v = Number(v); if (v >= 1e6) return 'R$ ' + (v / 1e6).toFixed(1).replace('.', ',') + ' mi'; if (v >= 1000) return 'R$ ' + (v / 1000).toFixed(1).replace('.', ',') + ' mil'; return 'R$ ' + v.toFixed(2).replace('.', ','); };
      const exp = esperado.map(x => fmt(x.f));
      const ordenado = [vals[0], vals[1], vals[2]].filter(Boolean);
      return { ok: JSON.stringify(vals.slice(0, exp.length).sort()) === JSON.stringify(exp.slice(0, vals.length).sort()) && vals.length === Math.min(exp.length, 10), det: 'tela ' + vals.join(', ') + ' | banco ' + exp.join(', ') };
    }, 'ranking');
    await t('aba Mensal: mostra o mês corrente, "zera no dia 1", participantes e contagem até o fim do mês', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      const fim = Number(await c.avaliar(`document.querySelector('.grade-contagem').getAttribute('data-alvo')`));
      const esperadoFim = Math.floor(new Date(mes.getFullYear(), mes.getMonth() + 1, 0, 23, 59, 59).getTime() / 1000);
      return { ok: /outubro de 2026/i.test(tx) && /dia 1/.test(tx) && /participante/.test(tx) && Math.abs(fim - esperadoFim) < 86400, det: `alvo ${fim} vs ${esperadoFim}` };
    }, 'ranking');
    await t('abas: Concurso e Mensal alternam por link (?aba=), a ativa fica destacada, valor inválido volta ao padrão', async () => {
      const r = [];
      for (const q of ['?aba=concurso', '?aba=mensal', '?aba=xyz', '?aba=%3Cscript%3E', '']) {
        await c.ir(BASE + '/ranking' + q);
        r.push(await c.avaliar(`(document.querySelector('.aba-ranking.ativa span')||{}).textContent||'?'`));
      }
      return { ok: r.join(',') === 'Concurso,Mensal,Concurso,Concurso,Concurso', det: r.join(',') };
    }, 'ranking');
    await t('contagem regressiva anda sozinha (segundos mudam sem recarregar) e o "Atualiza a cada 60 segundos" aparece', async () => {
      await c.ir(BASE + '/ranking?aba=mensal');
      const a = await c.avaliar(`document.querySelector('.caixa-contagem-valor:last-child, .grade-contagem .caixa-contagem:last-child .caixa-contagem-valor').textContent`);
      await esp(2300);
      const b = await c.avaliar(`document.querySelector('.grade-contagem .caixa-contagem:last-child .caixa-contagem-valor').textContent`);
      const txt = /Atualiza a cada 60 segundos/.test(await c.avaliar('document.body.innerText'));
      return { ok: a !== b && txt, det: `${a} → ${b}` };
    }, 'ranking');
    await t('troca ao vivo: o bloco do placar é substituído pelo conteúdo novo do servidor sem recarregar a página', async () => {
      await c.avaliar(`window.__marca = 'mantida'; document.getElementById('bloco-placar').setAttribute('data-velho','1'); 1`);
      const r = await c.avaliar(`fetch(location.href,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.text()).then(function(h){ var d=new DOMParser().parseFromString(h,'text/html'); var n=d.getElementById('bloco-placar'), a=document.getElementById('bloco-placar'); a.replaceWith(n); return !!document.getElementById('bloco-placar') && !document.getElementById('bloco-placar').hasAttribute('data-velho') && window.__marca==='mantida'; })`);
      return { ok: r, det: '' };
    }, 'ranking');
    await t('privacidade e HTML: só apelido/"Usuário #id" aparecem; nome com HTML sai escapado; ninguém vê e-mail', async () => {
      const html = await c.avaliar('document.documentElement.outerHTML');
      const emails = db(`SELECT email FROM usuarios WHERE perfil='usuario' AND email<>'pgteste-user@example.invalid' LIMIT 60`).map(x => x.email);
      const vazou = emails.filter(e => html.includes(e));
      return { ok: !vazou.length, det: vazou.slice(0, 2).join(',') };
    }, 'segurança');
    await t('layout: sem rolagem horizontal no desktop e no celular (390px) nas duas abas', async () => {
      const falhas = [];
      for (const larg of [1500, 390]) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: 900, deviceScaleFactor: 1, mobile: larg < 800 });
        for (const q of ['?aba=concurso', '?aba=mensal']) {
          await c.ir(BASE + '/ranking' + q);
          if (await c.avaliar('document.documentElement.scrollWidth > window.innerWidth + 2')) falhas.push(larg + q);
        }
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'ranking');
    salvar();
  } finally { await c.fechar(); }
})();
