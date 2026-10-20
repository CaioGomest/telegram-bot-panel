// 3) Segundo usuário (B): aviso de e-mail não confirmado e isolamento entre contas (A não é acessível por B).
const fs = require('fs'), path = require('path');
const L = require('./lib.js');
const { ok, BASE, dorme } = L;
const E = L.env();

(async () => {
  // caixa descartável do usuário B
  const suf = Date.now().toString().slice(-7);
  const emailB = `vpsteste-b${suf}@maxxspace.com`, mailPw = 'MailTm#B' + suf + 'z';
  const cr = await fetch('https://api.mail.tm/accounts', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ address: emailB, password: mailPw }) });
  ok('caixa descartável do usuário B criada', cr.status === 201, cr.status);
  const senhaB = 'UsuarioB#' + suf + 'Xx';
  const c = await L.lancar();
  L.autoDialogo(c, true);
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  let fluxoA = null;
  try {
    // A (conta criada por 01_cadastro_email.js) cria um fluxo que servirá de alvo
    await L.entrar(c, E.EMAIL, E.SENHA_PAINEL);
    const fa = await api('salvar_fluxo', { nome: 'Fluxo do A (alvo do teste de isolamento)', modo: 'avancado', dados_fluxograma: { operators: { start: { top: 40, left: 20, properties: { type: 'start', title: 'Início', inputs: {}, outputs: { output_1: { label: 'Próximo' } } } } }, links: [] } });
    fluxoA = fa.j && fa.j.fluxo ? fa.j.fluxo.id : null;
    ok('A criou um fluxo-alvo', !!fluxoA, fa);
    await c.ir(BASE + '/logout'); await c.esperarCarga();
    await c.ir(BASE + '/cadastro');
    await c.avaliar(`document.getElementById('nome').value='Usuario B Teste'; document.getElementById('email').value=${JSON.stringify(emailB)}; document.getElementById('senha').value=${JSON.stringify(senhaB)}; document.getElementById('confirma_senha').value=${JSON.stringify(senhaB)}; document.querySelector('form[method=POST]').submit(); 1`);
    await dorme(2000); await c.esperarCarga();
    ok('usuário B cadastrado', /login\?sucesso=cadastro/.test(await c.avaliar('location.pathname+location.search')), await c.avaliar('location.pathname+location.search'));
    const d = await L.entrar(c, emailB, senhaB);
    ok('usuário B entra no painel', !/login/.test(d), d);
    await c.ir(BASE + '/');
    const html = await c.avaliar('document.documentElement.outerHTML');
    ok('aviso "E-mail não confirmado" + botão Reenviar presentes (conta ainda não confirmada)', /E-mail não confirmado/.test(html) && /data-reenviar-verificacao/.test(html), null);
    // reenviar
    await c.avaliar(`var b=document.querySelector('[data-reenviar-verificacao]'); if(b) b.click(); 1`); await dorme(2500);
    const aviso = await c.avaliar(`(document.querySelector('[data-aviso-email]')||{}).innerText||''`);
    console.log('  texto do aviso após Reenviar:', JSON.stringify(aviso));
    // isolamento
    const um = { id: fluxoA };
    const e1 = await api('exportar_fluxo&id=' + um.id, null, 'GET');
    ok('B não exporta o fluxo de A (404)', e1.http === 404, e1);
    const e2 = await api('obter_fluxo&id=' + um.id, null, 'GET');
    ok('B não lê o fluxo de A (obter_fluxo → 404/422)', e2.http === 404 || e2.http === 422 || !(e2.j && e2.j.sucesso), e2);
    const e3 = await api('excluir_fluxo', { id: um.id });
    ok('B não exclui o fluxo de A', !(e3.j && e3.j.sucesso), e3);
    const e4 = await api('salvar_fluxo', { id: um.id, nome: 'HACK', modo: 'basico', dados_fluxograma: { planos: [] } });
    ok('B não sobrescreve o fluxo de A (salvar_fluxo com id de A)', !(e4.j && e4.j.sucesso && Number(e4.j.fluxo && e4.j.fluxo.id) === Number(um.id)), e4);
    await c.ir(BASE + '/fluxo?id=' + um.id); await dorme(1200);
    const txt = await c.avaliar('document.body.innerText');
    ok('abrir o editor do fluxo de A como B não mostra o conteúdo de A', !/alvo do teste de isolamento/.test(txt), txt.slice(0, 120));
    await c.ir(BASE + '/fluxos'); await dorme(1000);
    ok('lista de fluxos de B está vazia (0/50)', /Novo Fluxo \(0\/50\)/.test(await c.avaliar('document.querySelector(".conteudo-principal").innerText')), null);
    await c.ir(BASE + '/webhooks');
    ok('B não vê o webhook de A', !/CRM/.test(await c.avaliar('document.querySelector(".conteudo-principal").innerText')), null);
    await c.ir(BASE + '/configuracao_usuario');
    ok('B vê o próprio nome/e-mail (não os de A)', (await c.avaliar(`document.getElementById('email').value`)) === emailB, null);
    // B não vê o apelido/dados de A no ranking? (apenas abre)
    // sai e volta como A: fluxos de A seguem intactos
    await c.ir(BASE + '/logout'); await c.esperarCarga();
    await L.entrar(c, E.EMAIL, E.SENHA_PAINEL);
    await c.ir(BASE + '/fluxos'); await dorme(1200);
    const nomes = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
    ok('o fluxo de A continua intacto, com o nome original, depois das tentativas de B', /alvo do teste de isolamento/.test(nomes), nomes.slice(0, 120));
    if (fluxoA) await api('excluir_fluxo', { id: fluxoA });
    ok('nenhum nome de fluxo de A virou "HACK"', !/HACK/.test(await c.avaliar('document.querySelector(".conteudo-principal").innerText')), null);
    fs.appendFileSync(path.join(L.SP, 'usuarios_teste.txt'), `B: ${emailB} (senha ${senhaB})\n`);
  } catch (e) { console.log('ERRO', e.stack || e.message); ok('exceção no roteiro', false, e.message); }
  c.pararDialogo && c.pararDialogo();
  await c.fechar();
  L.salvarResultados('08_isolamento');
})();
