// ATENÇÃO (26/10/2026): a parte de REMARKETING deste teste mira a tela ANTIGA (janela "Nova campanha", filtros por bot/audiência, ações cancelar_campanha/contar_destinatarios), que foi trocada pela tela em etapas (tarefas_revisao_final.md, item 14). Esta parte não vale mais; a cobertura nova está em tests/remarketing/ (unitario_remarketing.php, ui/teste.js e integracao/rodar.js).
// ÁREA 4e (09/10/2026): seleção de arquivo só aceita os formatos do campo (remarketing, mídia da campanha).
// Uso: PGPW=... OUT=./pg ARQ=<pasta com "1 (2).jfif", "ok.jpg" e "doc.pdf"> node pg_a21.js   (conta de estresse cliente5)
const { lancar, entrar, BASE } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const ARQ = (process.env.ARQ || '.').split('\\').join('/');
(async () => {
  const c = await lancar();
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    await c.ir(BASE + '/remarketing'); await esp(1200);
    await c.avaliar(`document.querySelector('#btn-nova-campanha, #btn-nova-campanha-empty').click()`); await esp(500);
    const escolher = async (nome) => {
      const doc = await c.enviar('DOM.getDocument', { depth: 1 });
      const q = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: '#modal-midia' });
      await c.enviar('DOM.setFileInputFiles', { files: [ARQ + '/' + nome], nodeId: q.result.nodeId });
      await esp(500);
      return JSON.parse(await c.avaliar(`JSON.stringify({n: document.getElementById('modal-midia').files.length, aviso: (document.querySelector('.aviso-arquivo-invalido')||{}).innerText||'', remover: !document.getElementById('btn-remover-midia').hidden, limite: document.getElementById('limite-mensagem').innerText})`));
    };
    area('4e. Arquivo permitido x não permitido');
    await t('.jfif (não permitido) é recusado: campo limpo, aviso com os formatos aceitos', async () => {
      const r = await escolher('1 (2).jfif');
      return { ok: r.n === 0 && /não é permitido/.test(r.aviso) && /jpg/.test(r.aviso) && !r.remover && r.limite === '4096', det: JSON.stringify(r) };
    }, 'remarketing');
    await t('.pdf (não permitido) também é recusado', async () => { const r = await escolher('doc.pdf'); return { ok: r.n === 0 && /não é permitido/.test(r.aviso), det: JSON.stringify(r) }; }, 'remarketing');
    await t('.jpg (permitido) é aceito, some o aviso, aparece "Remover arquivo" e o limite cai para 1024', async () => {
      const r = await escolher('ok.jpg');
      return { ok: r.n === 1 && r.aviso === '' && r.remover && r.limite === '1024', det: JSON.stringify(r) };
    }, 'remarketing');
    await t('"Remover arquivo" limpa o campo, esconde o botão e volta o limite para 4096', async () => {
      await c.avaliar(`document.getElementById('btn-remover-midia').click()`); await esp(400);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({n: document.getElementById('modal-midia').files.length, remover: !document.getElementById('btn-remover-midia').hidden, limite: document.getElementById('limite-mensagem').innerText})`));
      return { ok: r.n === 0 && !r.remover && r.limite === '4096', det: JSON.stringify(r) };
    }, 'remarketing');
    console.log('erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
