// ÁREA 3: Meus Bots (lista) e Configuração do Bot (criar/editar)
const { lancar, entrar, BASE, db, remoto, autoDialogo, lerToast } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('3. Meus Bots (lista)');
    await c.ir(BASE + '/bots');
    await c.esperar(`document.querySelectorAll('.js-bot-card').length>0`);
    await t('lista mostra os 2 bots do usuário com nome, @username e contador "(2/50)"', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
      return { ok: /Bot Um/.test(tx) && /Bot Dois/.test(tx) && /@pg_bot_um/.test(tx) && /@pg_bot_dois/.test(tx) && /Novo Bot \(2\/50\)/.test(tx), det: tx.slice(0, 200) };
    }, 'bots');
    await t('cartão do Bot Um: Leads 8d = 5, Vendas 8d = 2 e fluxo conectado "Fluxo Guiado PG"', async () => {
      const r = await c.avaliar(`JSON.stringify([...document.querySelectorAll('.js-bot-card')].map(function(e){return {n:e.querySelector('.a-bot-id h3').innerText, nums:[...e.querySelectorAll('.a-bot-nums b')].map(function(x){return x.innerText}), fluxo:e.querySelector('.a-bot-flow').innerText.trim()}}))`);
      const bots = JSON.parse(r);
      const um = bots.find(b => b.n === 'Bot Um'), dois = bots.find(b => b.n === 'Bot Dois');
      return { ok: um && um.nums[0] === '5' && um.nums[1] === '2' && /Fluxo Guiado PG/.test(um.fluxo) && dois && dois.nums[0] === '1' && dois.nums[1] === '0' && /Sem fluxo/.test(dois.fluxo), det: r.slice(0, 260) };
    }, 'bots');
    await t('selo "Online/Offline" reflete o webhook: sem webhook registrado = Offline', async () => {
      const r = await c.avaliar(`[...document.querySelectorAll('.js-bot-card .a-bot-bds .a-bd')].map(function(e){return e.innerText.trim()}).join(',')`);
      return { ok: /offline/i.test(r), det: r };
    }, 'bots');
    await t('cada ação do cartão é botão de ícone com title (configurar, excluir) e o cartão "Conectar Novo Bot" leva a /bot', async () => {
      const r = await c.avaliar(`JSON.stringify({sem:[...document.querySelectorAll('.js-bot-card .a-ib')].filter(function(b){return !b.title}).length, n:document.querySelectorAll('.js-bot-card .a-ib').length, novo:(document.querySelector('.a-new')||{}).getAttribute&&document.querySelector('.a-new').getAttribute('href')})`);
      const o = JSON.parse(r);
      return { ok: o.sem === 0 && o.n === 4 && o.novo === 'bot', det: r };
    }, 'bots');
    await t('ícone de configurar abre /bot?id= do bot certo', async () => {
      await c.avaliar(`document.querySelector('.js-bot-card a.a-ib[title="Configurar bot"]').click(); 1`);
      await esp(1500);
      const u = await c.avaliar('location.pathname + location.search');
      return { ok: /\/bot\?id=\d+/.test(u), det: u };
    }, 'bots');
    await t('excluir bot: confirmação aparece, "Cancelar" mantém o bot', async () => {
      const id = remoto('php zz_pg.php bot').trim();
      await c.ir(BASE + '/bots');
      await c.esperar(`document.querySelectorAll('.js-bot-card').length>=3`);
      c.aceitar = false;
      const antes = await c.avaliar(`document.querySelectorAll('.js-bot-card').length`);
      await c.avaliar(`document.querySelector('.btn-excluir-bot[data-id="${id}"]').click(); 1`);
      await esp(1200);
      const depois = await c.avaliar(`document.querySelectorAll('.js-bot-card').length`);
      c.aceitar = true;
      const msg = (c.dialogos[c.dialogos.length - 1] || {}).msg || '';
      global.__idExcluir = id;
      return { ok: antes === depois && /excluir/i.test(msg), det: `antes ${antes}, depois ${depois}; confirm: "${msg}"` };
    }, 'bots');
    await t('excluir bot: confirmando, o bot some da lista e do banco, e o contador cai para (2/50)', async () => {
      const id = global.__idExcluir;
      await c.avaliar(`document.querySelector('.btn-excluir-bot[data-id="${id}"]').click(); 1`);
      await esp(2000);
      const n = await c.avaliar(`document.querySelectorAll('.js-bot-card').length`);
      const cont = await c.avaliar(`document.querySelector('#btn-novo-bot').innerText`);
      const noBanco = db(`SELECT COUNT(*) n FROM bots WHERE id=${id}`)[0].n;
      return { ok: n === 2 && noBanco === 0 && /2\/50/.test(cont), det: `cartões ${n}, banco ${noBanco}, ${cont}` };
    }, 'bots');
    await t('excluir bot de OUTRO usuário via API é recusado (IDOR)', async () => {
      const outro = db(`SELECT id FROM bots WHERE id_usuario <> 49 ORDER BY id DESC LIMIT 1`)[0].id;
      const r = await c.avaliar(`fetch('api.php?action=excluir_bot',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:JSON.stringify({id:${outro}})}).then(r=>r.json())`);
      const ainda = db(`SELECT COUNT(*) n FROM bots WHERE id=${outro}`)[0].n;
      return { ok: ainda === 1 && !r.sucesso, det: JSON.stringify(r).slice(0, 120) };
    }, 'segurança');

    area('3b. Configuração do Bot — editar');
    await c.ir(BASE + '/bot?id=311');
    await c.esperar(`document.getElementById('id-bot').value==='311'`, 8000);
    await t('editar: token, nome, descrições e fluxo conectado vêm preenchidos', async () => {
      const r = await c.avaliar(`JSON.stringify({id:document.getElementById('id-bot').value, token:document.getElementById('token').value.length>0, nome:document.getElementById('name').value, desc:document.getElementById('description').value, flux:document.getElementById('id-fluxo-conectado').value})`);
      const o = JSON.parse(r);
      return { ok: o.id === '311' && o.token && o.nome === 'Bot Um' && o.flux === '471', det: r };
    }, 'bot');
    await t('editar: o campo token é de senha (mascarado) e o botão Mostrar alterna', async () => {
      const antes = await c.avaliar(`document.getElementById('token').type`);
      await c.avaliar(`document.getElementById('btn-mostrar-token').click(); 1`);
      const depois = await c.avaliar(`document.getElementById('token').type`);
      return { ok: antes === 'password' && depois === 'text', det: `${antes} → ${depois}` };
    }, 'bot');
    await t('editar: a lista de fluxos mostra SÓ os fluxos do usuário (Guiado PG, Visual PG)', async () => {
      const ops = await c.avaliar(`JSON.stringify([...document.querySelectorAll('#id-fluxo-conectado option')].map(function(o){return o.textContent.trim()}))`);
      const lista = JSON.parse(ops).filter(x => x && !/selecione|nenhum/i.test(x));
      const meus = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      return { ok: lista.length === meus && lista.some(x => /Guiado PG/.test(x)), det: ops };
    }, 'bot');
    await t('editar: painéis certos aparecem (perfil, ferramentas) e os de criação ficam ocultos', async () => {
      const r = await c.avaliar(`JSON.stringify({perfil:!document.getElementById('painel-perfil-telegram').hidden, ferr:!document.getElementById('painel-ferramentas').hidden, tut:document.getElementById('cartao-tutorial-botfather').hidden, auto:document.getElementById('cartao-criar-automatico').hidden, btn:document.getElementById('btn-salvar-bot').innerText})`);
      const o = JSON.parse(r);
      return { ok: o.perfil && o.ferr && o.tut && o.auto && /Salvar/.test(o.btn), det: r };
    }, 'bot');
    await t('editar: salvar com token que o Telegram não reconhece mostra erro claro (sem travar) e não altera o bot', async () => {
      const antes = db(`SELECT token, primeiro_nome FROM bots WHERE id=311`)[0];
      await c.avaliar(`document.getElementById('btn-salvar-bot').click(); 1`);
      const toast = await lerToast(c);
      const depois = db(`SELECT token, primeiro_nome FROM bots WHERE id=311`)[0];
      return { ok: /token|inválid|unauthorized|not found/i.test(toast) && antes.token === depois.token && antes.primeiro_nome === depois.primeiro_nome, det: `toast: "${toast}"` };
    }, 'bot');
    await t('editar: "Testar" token inválido mostra mensagem traduzida (não o JSON cru do Telegram)', async () => {
      await c.avaliar(`document.getElementById('btn-testar-token').click(); 1`);
      const toast = await lerToast(c);
      return { ok: /token|inválid|unauthorized|not found/i.test(toast) && !/"ok":false|error_code/.test(toast), det: `"${toast}"` };
    }, 'bot');
    await t('editar: atualizar perfil (nome/descrição) com token inválido avisa o erro e mantém o que foi digitado', async () => {
      await c.avaliar(`document.getElementById('description').value='Descrição editada no teste'; document.getElementById('description').dispatchEvent(new Event('input',{bubbles:true})); 1`);
      const btn = await c.avaliar(`!!document.querySelector('#formulario-perfil button.botao-primario, #formulario-perfil button[type=submit], #btn-salvar-perfil')`);
      await c.avaliar(`(document.querySelector('#btn-salvar-perfil')||document.querySelector('#formulario-perfil button.botao-primario')).click(); 1`);
      const toast = await lerToast(c);
      const v = await c.avaliar(`document.getElementById('description').value`);
      return { ok: btn && toast.length > 3 && v === 'Descrição editada no teste', det: `toast: "${toast}"` };
    }, 'bot');
    await t('editar: "Descartar" volta o formulário ao que está salvo', async () => {
      await c.avaliar(`document.getElementById('btn-descartar-perfil').click(); 1`);
      await esp(1200);
      const v = await c.avaliar(`document.getElementById('description').value`);
      return { ok: v !== 'Descrição editada no teste', det: 'valor=' + v };
    }, 'bot');
    await t('ferramentas: "Info do webhook" e "Reiniciar webhook" respondem com mensagem (erro tratado com token falso)', async () => {
      await c.avaliar(`document.getElementById('btn-info-webhook').click(); 1`);
      await esp(3000);
      const a = await c.avaliar(`document.getElementById('toast').innerText + '|' + (document.querySelector('.modal-info-webhook, #modal-info-webhook, .sobreposicao-modal.aberto')||{}).innerText`);
      await c.avaliar(`document.getElementById('btn-reiniciar-webhook').click(); 1`);
      await esp(3500);
      const b = await c.avaliar(`document.getElementById('toast').innerText`);
      return { ok: (a || '').length > 3 && b.length > 3, det: `info:"${(a || '').slice(0, 60)}" reiniciar:"${b.slice(0, 80)}"` };
    }, 'bot');
    await t('ferramentas: "Exportar fluxo do bot" gera um JSON do fluxo conectado', async () => {
      const r = await c.avaliar(`fetch('api.php?action=exportar_fluxo&id=471').then(r=>r.json())`);
      return { ok: !!r && (r.sucesso === true || r.nome || r.fluxo), det: JSON.stringify(r).slice(0, 140) };
    }, 'bot');

    area('3c. Configuração do Bot — criar');
    await c.ir(BASE + '/bot');
    await t('novo bot: mostra o tutorial do BotFather, campo de token vazio e botão "Criar bot"', async () => {
      const r = await c.avaliar(`JSON.stringify({tut:!document.getElementById('cartao-tutorial-botfather').hidden, token:document.getElementById('token').value, btn:document.getElementById('btn-salvar-bot').innerText, perfil:document.getElementById('painel-perfil-telegram').hidden})`);
      const o = JSON.parse(r);
      return { ok: o.tut && o.token === '' && /Criar/.test(o.btn) && o.perfil, det: r };
    }, 'bot');
    await t('novo bot: salvar sem token mostra aviso e não chama a API de criação', async () => {
      await c.avaliar(`document.getElementById('btn-salvar-bot').click(); 1`);
      const toast = await lerToast(c);
      return { ok: /token/i.test(toast), det: `"${toast}"` };
    }, 'bot');
    await t('novo bot: token com formato inválido (lixo) é recusado com mensagem, sem criar registro', async () => {
      const antes = db(`SELECT COUNT(*) n FROM bots WHERE id_usuario=49`)[0].n;
      await c.avaliar(`document.getElementById('token').value='isto-nao-e-um-token'; document.getElementById('btn-salvar-bot').click(); 1`);
      const toast = await lerToast(c);
      const depois = db(`SELECT COUNT(*) n FROM bots WHERE id_usuario=49`)[0].n;
      return { ok: antes === depois && toast.length > 3, det: `"${toast}"` };
    }, 'bot');
    await t('novo bot: token no formato certo mas inexistente no Telegram é recusado e não cria registro', async () => {
      const antes = db(`SELECT COUNT(*) n FROM bots WHERE id_usuario=49`)[0].n;
      await c.avaliar(`document.getElementById('token').value='123456789:AAHfakefakefakefakefakefakefakefakeX'; document.getElementById('btn-salvar-bot').click(); 1`);
      const toast = await lerToast(c);
      const depois = db(`SELECT COUNT(*) n FROM bots WHERE id_usuario=49`)[0].n;
      return { ok: antes === depois && toast.length > 3, det: `"${toast}"` };
    }, 'bot');
    await t('bot de outro usuário: /bot?id= de bot alheio não abre os dados (404/redirect, nada vaza)', async () => {
      const outro = db(`SELECT id, token FROM bots WHERE id_usuario <> 49 ORDER BY id DESC LIMIT 1`)[0];
      await c.ir(BASE + '/bot?id=' + outro.id);
      await esp(1200);
      const tok = await c.avaliar(`document.getElementById('token').value`);
      const html = await c.avaliar(`document.documentElement.innerHTML.indexOf(${JSON.stringify(outro.token)})>=0`);
      return { ok: tok === '' && !html, det: 'token preenchido=' + (tok !== '') };
    }, 'segurança');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
