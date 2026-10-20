// Admin → Usuários (16/10): sem as abas de perfil e sem as listas "E-mail" e "Bots"; com busca + ordem + botão "Filtrar" (e "Limpar" só com filtro).
// Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 23_admin_usuarios_filtro.js   (capturas em estado/usuarios_<largura>.png)
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 220) : '')); };
const resumo = (c) => c.avaliar(`(document.querySelector('.resumo-lista')||{innerText:''}).innerText.trim()`);
const totalDe = (t) => (t.match(/de ([\d.]+) usuário/) || [])[1] || '';
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 900, deviceScaleFactor: 1, mobile: false });
    chk('login do admin', /admin/.test(await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA)));

    // ---- estrutura da barra
    await c.ir(BASE + '/admin/usuarios'); await esp(1500);
    const e = JSON.parse(await c.avaliar(`JSON.stringify((function(){var f=document.getElementById('form-filtros-usuarios');
      var r=function(x){return Math.round(x.getBoundingClientRect().height)};
      return {abas:f.querySelectorAll('.abas-status, .aba-status').length, selEmail:!!f.querySelector('[name=email]'), selBots:!!f.querySelector('[name=bots]'), hidPerfil:!!f.querySelector('[name=perfil]'),
        busca:!!f.querySelector('input[name=busca]'), ordem:!!f.querySelector('select[name=ordem]'), filtrar:[...f.querySelectorAll('button')].some(function(b){return b.innerText.trim()==='Filtrar'}),
        limpar:!!f.querySelector('a.botao'), ordemOnchange:f.querySelector('select[name=ordem]').getAttribute('onchange'),
        alturas:[r(f.querySelector('.campo-busca')), r(f.querySelector('select[name=ordem]')), r([...f.querySelectorAll('button')].find(function(b){return b.innerText.trim()==='Filtrar'}))]}})())`));
    chk('abas Todos/Usuários/Admins removidas', e.abas === 0);
    chk('listas "E-mail" e "Bots" removidas (e o campo oculto de perfil)', !e.selEmail && !e.selBots && !e.hidPerfil);
    chk('busca, ordem e botão "Filtrar" presentes', e.busca && e.ordem && e.filtrar);
    chk('sem filtro ativo: "Limpar" não aparece', e.limpar === false);
    chk('a lista de ordem não envia sozinha (o botão Filtrar é quem aplica)', !e.ordemOnchange, String(e.ordemOnchange));
    chk('busca, ordem e Filtrar com a mesma altura', Math.max(...e.alturas) - Math.min(...e.alturas) <= 2, e.alturas.join('/'));
    const totalSemFiltro = totalDe(await resumo(c));
    await c.captura(path.join(ESTADO, 'usuarios_1500.png'));

    // ---- a ordem não envia sozinha
    await c.avaliar(`(function(){var s=document.querySelector('select[name=ordem]');s.value='nome';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(1800);
    chk('trocar a ordem NÃO recarrega a página sozinha', !/ordem=/.test(await c.avaliar('location.search')), await c.avaliar('location.search'));

    // ---- botão Filtrar com ordem
    await c.avaliar(`[...document.querySelectorAll('#form-filtros-usuarios button')].find(function(b){return b.innerText.trim()==='Filtrar'}).click(); 1`); await esp(3000);
    chk('Filtrar aplica a ordem (ordem=nome na URL) e mostra "Limpar"', /ordem=nome/.test(await c.avaliar('location.search')) && (await c.avaliar(`!!document.querySelector('#form-filtros-usuarios a.botao')`)) === true);
    chk('o valor escolhido continua selecionado depois de filtrar', (await c.avaliar(`document.querySelector('select[name=ordem]').value`)) === 'nome');
    // ---- Filtrar com busca
    await c.ir(BASE + '/admin/usuarios'); await esp(1000);
    await c.avaliar(`(function(){var f=document.getElementById('form-filtros-usuarios');f.querySelector('input[name=busca]').value='${DONO_USER}+vps1510';[...f.querySelectorAll('button')].find(function(b){return b.innerText.trim()==='Filtrar'}).click();return 1})()`); await esp(3000);
    const rb = await resumo(c);
    chk('buscar por e-mail com o botão Filtrar: só a conta de teste e "(de N no total)"', /de 1 usuário/.test(rb) && new RegExp('de ' + totalSemFiltro.replace(/\./g, '\\.') + ' no total').test(rb), rb);
    chk('com busca ativa o "Limpar" aparece e volta à lista inteira', (await c.avaliar(`!!document.querySelector('#form-filtros-usuarios a.botao')`)) === true);
    await c.avaliar(`document.querySelector('#form-filtros-usuarios a.botao').click(); 1`); await esp(2500);
    chk('Limpar volta ao total sem filtro', totalDe(await resumo(c)) === totalSemFiltro, totalDe(await resumo(c)) + ' vs ' + totalSemFiltro);
    // ---- Enter no campo de busca também filtra
    await c.avaliar(`(function(){var f=document.getElementById('form-filtros-usuarios');f.querySelector('input[name=busca]').value='admintest-inexistente';f.requestSubmit();return 1})()`); await esp(3000);
    chk('busca sem resultado: mensagem "Nenhum usuário encontrado com esses filtros."', /Nenhum usuário encontrado com esses filtros/.test(await c.avaliar('document.body.innerText')));

    // ---- parâmetros antigos são ignorados
    await c.ir(BASE + '/admin/usuarios?perfil=admin&email=confirmado&bots=com'); await esp(1800);
    const ig = await resumo(c);
    chk('?perfil=admin&email=…&bots=… são ignorados (não filtram nem quebram a página)', totalDe(ig) === totalSemFiltro && (await c.avaliar(`!!document.querySelector('#form-filtros-usuarios a.botao')`)) === false && !/(Warning|Fatal|Notice)/.test(await c.avaliar('document.body.innerText')), ig);

    // ---- paginação mantém a busca
    await c.ir(BASE + '/admin/usuarios?busca=estresse'); await esp(2500);
    const pag = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('a')].map(function(a){return a.getAttribute('href')||''}).filter(function(h){return /pagina=2/.test(h)}))`));
    chk('a paginação mantém a busca (link da página 2 leva busca=estresse)', pag.length > 0 && pag.every(h => /busca=estresse/.test(h)), pag.slice(0, 2).join(' | '));
    const id1 = await c.avaliar(`document.querySelector('tbody tr td').innerText.trim()`);
    await c.ir(BASE + '/admin/usuarios' + pag[0].replace(/^.*?\?/, '?').replace(/&amp;/g, '&')); await esp(2500);
    chk('a página 2 da busca mostra outros usuários e continua filtrada', (await c.avaliar(`document.querySelector('tbody tr td').innerText.trim()`)) !== id1 && (await c.avaliar(`document.querySelector('input[name=busca]').value`)) === 'estresse');

    // ---- XSS/SQL na busca e celular
    await c.ir(BASE + '/admin/usuarios?busca=%3Cscript%3Ealert(1)%3C%2Fscript%3E'); await esp(1500);
    chk('busca com <script> não vira HTML cru', (await c.avaliar(`document.body.innerHTML.indexOf('<script>alert(1)')>=0`)) === false);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 2, mobile: true });
    await c.ir(BASE + '/admin/usuarios?busca=teste'); await esp(1800);
    chk('celular: sem rolagem horizontal da página e com Filtrar + Limpar visíveis', (await c.avaliar(`document.documentElement.scrollWidth<=window.innerWidth+2`)) === true && (await c.avaliar(`[...document.querySelectorAll('#form-filtros-usuarios .botao')].every(function(b){return b.offsetParent!==null})`)) === true);
    await c.captura(path.join(ESTADO, 'usuarios_390.png'));
  } catch (err) { console.log('ERRO', err.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
