// Tela do chat dos leads (assets/chat_leads.js + assets/css/chat_leads.css) num Chrome de verdade.
// SEM servidor, banco nem login: monta uma página mínima com o markup do botão de leads.php, o CSS e o JS verdadeiros, e um fetch simulado que se comporta
// como ajax/chat_mensagens.php e ajax/chat_enviar.php (paginação por id, tempo real, envio, erro de lead bloqueado).
//   node tests/chat/ui.js        (sai com código 1 se algo falhar; capturas em %TEMP%/chat_ui/*.png)
const fs = require('fs');
const os = require('os');
const { lancar } = require('../homologacao/ui/cdp.js');

const RAIZ = require('path').resolve(__dirname, '../..').replace(/\\/g, '/');
const SAIDA = (os.tmpdir() + '/chat_ui').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });

const LEADS = [
    { bot: 1, tg: '900001', nome: 'Bruno', bot_user: 'Claude_Gomes_sharkbot', inicio: '2026-10-04' },
    { bot: 2, tg: '900001', nome: 'Bruno', bot_user: 'gomes_newshark_bot', inicio: '2026-03-12' },
    { bot: 1, tg: '900403', nome: 'Suporte', bot_user: 'BrunadoHot_bot', inicio: '2026-03-11' },
    { bot: 3, tg: '900004', nome: 'Gomes', bot_user: 'BrunadoHot_bot', inicio: '2026-03-10' },
    { bot: 3, tg: '900005', nome: 'Bruno', bot_user: 'BrunadoHot_bot', inicio: '2026-03-10' },
];

const linhas = LEADS.map((l) => `<tr><td>${l.nome}</td><td class="num">
  <button type="button" class="a-btn chat-abrir" data-bot-id="${l.bot}" data-tg="${l.tg}" data-nome="${l.nome}" data-bot="${l.bot_user}" data-inicio="${l.inicio}" aria-label="Abrir chat com ${l.nome}">
    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg><span class="t">Abrir chat</span>
  </button></td></tr>`).join('\n');

// o "servidor": banco em memória e fetch simulado
const SERVIDOR = `
function z(n) { return (n < 10 ? '0' : '') + n; }
function fmt(d) { return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + ' ' + z(d.getHours()) + ':' + z(d.getMinutes()) + ':' + z(d.getSeconds()); }
var agora = Date.now(), seq = 0, BANCO = {};
function add(chave, direcao, tipo, texto, minAtras, origem) {
    (BANCO[chave] = BANCO[chave] || []).push({ id: ++seq, direcao: direcao, origem: origem || 0, tipo: tipo, texto: texto, criado_em: fmt(new Date(agora - minAtras * 60000)) });
}
for (var i = 0; i < 130; i++) { add('1:900001', i % 2 === 0 ? 0 : 1, 'texto', 'mensagem ' + (i + 1), (130 - i) * 35); }
add('1:900001', 0, 'foto', 'meu comprovante', 9);
add('1:900001', 0, 'botao', 'Quero o plano VIP', 8);
add('1:900001', 1, 'texto', 'Linha um\\nlinha dois\\n' + 'palavra '.repeat(60), 7, 2);
add('1:900001', 0, 'texto', '<img src=x onerror="window.__xss=1"> <b>negrito?</b>', 6);
add('1:900001', 1, 'video', 'Assista', 5);
add('1:900403', 0, 'texto', 'oi, tem alguém aí?', 20);
add('3:900004', 1, 'texto', 'Olá Gomes', 90);
window.__chamadas = [];
window.__injetar = function (chave, texto) { add(chave, 0, 'texto', texto, 0); };
window.fetch = function (url, opcoes) {
    opcoes = opcoes || {};
    var u = new URL(url, location.href), q = u.searchParams, arquivo = u.pathname.split('/').pop(), resposta, http = 200;
    window.__chamadas.push({ metodo: opcoes.method || 'GET', arquivo: arquivo, busca: u.search, cabecalhos: opcoes.headers || {}, corpo: opcoes.body || null });
    if (arquivo === 'chat_mensagens.php') {
        var lista = BANCO[q.get('bot_id') + ':' + q.get('id_telegram')] || [], antes = Number(q.get('antes') || 0), depois = Number(q.get('depois') || 0), saida, tem = false;
        if (depois) { saida = lista.filter(function (m) { return m.id > depois; }); }
        else {
            var base = antes ? lista.filter(function (m) { return m.id < antes; }) : lista;
            tem = base.length > 40; saida = base.slice(-40);
        }
        resposta = { sucesso: true, mensagens: saida, tem_mais: tem };
    } else if (arquivo === 'chat_enviar.php') {
        var d = JSON.parse(opcoes.body);
        if (d.id_telegram === '900403') { http = 422; resposta = { sucesso: false, mensagem: 'Este lead bloqueou o bot, então a mensagem não pode ser entregue.' }; }
        else { add(d.bot_id + ':' + d.id_telegram, 1, 'texto', d.texto, 0, 1); resposta = { sucesso: true }; }
    } else { http = 404; resposta = { sucesso: false }; }
    return new Promise(function (r) { setTimeout(function () { r({ status: http, json: function () { return Promise.resolve(resposta); } }); }, 40); });
};
window.CSRF_TOKEN = 'tok-ui';
`;

const PAGINA = `<!DOCTYPE html>
<html lang="pt-BR" class="layout-a" data-theme="dark">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="file:///${RAIZ}/">
<link rel="stylesheet" href="assets/css/painel.css"><link rel="stylesheet" href="assets/css/layout_a.css"><link rel="stylesheet" href="assets/css/chat_leads.css">
</head>
<body style="background:#0b0c10;color:#eee">
<main style="margin:24px"><h1>Leads</h1><table class="a-t"><tbody>${linhas}</tbody></table><div style="height:1500px"></div></main>
<script>${SERVIDOR}</script>
<script src="assets/chat_leads.js"></script>
</body></html>`;

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + JSON.stringify(e))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    fs.writeFileSync(SAIDA + '/index.html', PAGINA);
    const c = await lancar();
    const av = (expr) => c.avaliar(expr);
    const J = (expr) => av(`JSON.stringify(${expr})`).then(JSON.parse);
    const aberto = `!!document.querySelector('.chat-fundo:not([hidden])')`;
    const abrirPagina = async (w, h) => {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
        await c.ir('file:///' + SAIDA + '/index.html');
        await esp(300);
    };
    try {
        // ================================================================ desktop
        await abrirPagina(1280, 720);
        chk('antes do clique o modal nem existe no DOM (montagem preguiçosa: página leve)', await av(`!document.querySelector('.chat-fundo')`));
        chk('a página não fez nenhuma consulta ao carregar', (await av(`window.__chamadas.length`)) === 0);
        chk('há um botão "Abrir chat" por lead', (await av(`document.querySelectorAll('.chat-abrir').length`)) === LEADS.length);

        await av(`document.querySelector('.chat-abrir').focus(); document.querySelector('.chat-abrir').click()`);
        await esp(400);
        chk('o clique abre o modal', await av(aberto));
        chk('a lista lateral mostra os 5 leads da página, com a contagem "5" (sem consulta para listar)', (await av(`document.querySelectorAll('.chat-item').length`)) === 5 && (await av(`document.querySelector('.chat-contagem').textContent`)) === '5');
        chk('o lead clicado vem marcado, e o cabeçalho mostra nome, ID e o bot', (await av(`document.querySelector('.chat-item.ativo b').textContent`)) === 'Bruno' && (await av(`document.querySelector('.chat-quem span').textContent`)) === 'ID: 900001 · via @Claude_Gomes_sharkbot');
        chk('o cabeçalho mostra a posição "1/5"', (await av(`document.querySelector('.chat-posicao').textContent`)) === '1/5');
        const chamadas1 = await J(`window.__chamadas`);
        chk('abrir o chat fez UMA consulta (as últimas 40), só da conversa aberta', chamadas1.length === 1 && /bot_id=1&id_telegram=900001/.test(chamadas1[0].busca) && !/antes|depois/.test(chamadas1[0].busca), chamadas1);

        const nb = await av(`document.querySelectorAll('.chat-bolha').length`);
        chk('desenhou só as 40 últimas mensagens (não as 135 da conversa)', nb === 40, nb);
        chk('a conversa abre rolada até a mensagem mais nova', await av(`(function(){var a=document.querySelector('.chat-mensagens');return a.scrollHeight-a.scrollTop-a.clientHeight<4;})()`));
        chk('aparecem separadores de dia ("Hoje" e "Ontem")', await av(`(function(){var t=[].map.call(document.querySelectorAll('.chat-dia'),function(n){return n.textContent;});return t.indexOf('Hoje')!==-1&&t.indexOf('Ontem')!==-1;})()`), await J(`[].map.call(document.querySelectorAll('.chat-dia'),function(n){return n.textContent;})`));
        chk('mensagens do lead à esquerda e do bot à direita, com horário e visto nas enviadas', await av(`document.querySelectorAll('.chat-linha.recebida').length>0 && document.querySelectorAll('.chat-linha.enviada .chat-ok').length>0 && document.querySelector('.chat-meta time').textContent.length===5`));
        chk('tipos: foto, botão clicado, vídeo e a marca "Remarketing" aparecem', await av(`(function(){var t=document.querySelector('.chat-mensagens').textContent;return /📷 Foto/.test(t)&&/🔘 Quero o plano VIP/.test(t)&&/🎬 Vídeo/.test(t)&&/Remarketing/.test(t);})()`));
        chk('SEGURANÇA: HTML do lead vira texto (nenhuma <img> criada, script não rodou, tag aparece literal)', await av(`document.querySelectorAll('.chat-mensagens img').length===0 && !window.__xss && /<b>negrito\\?<\\/b>/.test(document.querySelector('.chat-mensagens').textContent)`));
        chk('quebras de linha e texto longo se mantêm dentro da bolha (sem estourar a largura)', await av(`(function(){var a=document.querySelector('.chat-mensagens'),b=[].filter.call(document.querySelectorAll('.chat-bolha'),function(x){return /Linha um/.test(x.textContent);})[0];return !!b && b.getBoundingClientRect().right<=a.getBoundingClientRect().right+1 && a.scrollWidth<=a.clientWidth+1;})()`));
        await c.captura(SAIDA + '/desktop_1280.png');

        // dentro da janela
        chk('o modal cabe inteiro na janela (1280x720)', await av(`(function(){var r=document.querySelector('.chat-modal').getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight&&document.documentElement.scrollWidth<=innerWidth;})()`));
        chk('a caixa de mensagem fica visível no rodapé, com o botão enviar desabilitado enquanto vazia', await av(`(function(){var f=document.querySelector('.chat-envio').getBoundingClientRect(),m=document.querySelector('.chat-modal').getBoundingClientRect();return f.bottom<=m.bottom+1&&f.height>40&&document.querySelector('.chat-enviar').disabled;})()`));

        chk('desktop: o botão "voltar" (só do celular) e o X da lista ficam escondidos', await av(`getComputedStyle(document.querySelector('.chat-voltar')).display==='none' && getComputedStyle(document.querySelector('.chat-lista-topo .chat-fechar')).display==='none'`));
        chk('a caixa de mensagem tem altura de uma linha (as regras gerais de textarea do painel não a deixam alta nem redimensionável)', await av(`(function(){var c=document.querySelector('.chat-campo'),s=getComputedStyle(c);return c.offsetHeight<=60 && s.resize==='none' && parseFloat(s.minHeight)<=44;})()`), await av(`(function(){var c=document.querySelector('.chat-campo'),s=getComputedStyle(c);return [c.offsetHeight,s.resize,s.minHeight,s.fontSize];})()`));
        chk('os botões de ícone usam a cor discreta do chat (a regra geral de button não os pinta de branco)', await av(`getComputedStyle(document.querySelector('.chat-anterior')).color`) !== await av(`getComputedStyle(document.querySelector('.chat-quem b')).color`));

        // rolar para cima carrega a página anterior sem pular
        await av(`document.querySelector('.chat-mensagens').scrollTop = 0; 1`);
        await esp(600);
        const nb2 = await av(`document.querySelectorAll('.chat-bolha').length`);
        const chamadas2 = await J(`window.__chamadas.filter(function(c){return /antes=/.test(c.busca);})`);
        chk('rolar até o topo pede a página anterior (antes=<primeiro id>) e passa a mostrar 80 mensagens', chamadas2.length === 1 && nb2 === 80, [chamadas2.length, nb2]);
        chk('o ponto de leitura é preservado (não volta ao topo nem ao fim)', await av(`(function(){var a=document.querySelector('.chat-mensagens');return a.scrollTop>200 && a.scrollHeight-a.scrollTop-a.clientHeight>200;})()`));
        await av(`(function(){var a=document.querySelector('.chat-mensagens');a.scrollTop=a.scrollHeight;})(); 1`);

        // enviar
        await av(`(function(){var t=document.querySelector('.chat-campo');t.value='Olá! tudo bem?';t.dispatchEvent(new Event('input'));})(); 1`);
        chk('digitar habilita o botão enviar', await av(`!document.querySelector('.chat-enviar').disabled`));
        await av(`document.querySelector('.chat-campo').dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',shiftKey:true,bubbles:true,cancelable:true})); 1`);
        await esp(150);
        chk('Shift+Enter não envia', (await av(`window.__chamadas.filter(function(c){return c.arquivo==='chat_enviar.php';}).length`)) === 0);
        await av(`document.querySelector('.chat-campo').dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true})); 1`);
        await esp(600);
        const env = await J(`window.__chamadas.filter(function(c){return c.arquivo==='chat_enviar.php';})`);
        chk('Enter envia: POST com o token CSRF no cabeçalho e o corpo certo', env.length === 1 && env[0].metodo === 'POST' && env[0].cabecalhos['X-CSRF-Token'] === 'tok-ui' && JSON.parse(env[0].corpo).texto === 'Olá! tudo bem?' && JSON.parse(env[0].corpo).id_telegram === '900001', env);
        chk('a mensagem enviada aparece no fim da conversa, a caixa limpa e a tela rolada até ela', await av(`(function(){var a=document.querySelector('.chat-mensagens'),b=document.querySelectorAll('.chat-linha.enviada .chat-texto');return b[b.length-1].textContent==='Olá! tudo bem?' && document.querySelector('.chat-campo').value==='' && a.scrollHeight-a.scrollTop-a.clientHeight<4;})()`));

        // tempo real
        const antes_poll = await av(`window.__chamadas.length`);
        await av(`window.__injetar('1:900001', 'resposta do lead agora'); 1`);
        await esp(3600);
        chk('tempo real: a mensagem nova chega sozinha em até ~3 s, sem recarregar', await av(`/resposta do lead agora/.test(document.querySelector('.chat-mensagens').textContent)`));
        const polls = await J(`window.__chamadas.slice(${antes_poll}).filter(function(c){return /depois=/.test(c.busca);})`);
        chk('o tempo real usa a consulta leve (?depois=<último id>), nunca recarrega a conversa inteira', polls.length >= 1 && polls.every(p => /depois=\d+/.test(p.busca)), polls);

        // nova mensagem com o leitor rolado para cima: não arrasta a tela
        await av(`document.querySelector('.chat-mensagens').scrollTop = 0; 1`);
        await esp(500);
        await av(`window.__injetar('1:900001', 'mensagem enquanto lê o histórico'); 1`);
        await esp(3600);
        // (rolar até o topo também carrega a página anterior, então o scrollTop sobe: o que importa é a distância até o FIM)
        chk('lendo o histórico (rolado para cima): a tela NÃO é arrastada ao fim e aparece o aviso "Novas mensagens"', await av(`(function(){var a=document.querySelector('.chat-mensagens');return a.scrollHeight-a.scrollTop-a.clientHeight>300 && !document.querySelector('.chat-novas').hidden;})()`), await av(`(function(){var a=document.querySelector('.chat-mensagens');return [a.scrollHeight-a.scrollTop-a.clientHeight, document.querySelector('.chat-novas').hidden];})()`));
        await av(`document.querySelector('.chat-novas').click(); 1`);
        chk('clicar no aviso desce até a mensagem nova', await av(`(function(){var a=document.querySelector('.chat-mensagens');return a.scrollHeight-a.scrollTop-a.clientHeight<4 && document.querySelector('.chat-novas').hidden;})()`));

        // trocar de conversa
        await av(`document.querySelector('.chat-proxima').click(); 1`);
        await esp(500);
        chk('próxima conversa (2/5): mostra o estado vazio "Nenhuma mensagem — O histórico aparecerá aqui"', (await av(`document.querySelector('.chat-posicao').textContent`)) === '2/5' && await av(`/Nenhuma mensagem/.test(document.querySelector('.chat-mensagens').textContent) && /O histórico aparecerá aqui/.test(document.querySelector('.chat-mensagens').textContent)`));
        await c.captura(SAIDA + '/desktop_vazio.png');
        await av(`document.querySelector('.chat-anterior').click(); 1`);
        const antes_volta = await av(`window.__chamadas.length`);
        await esp(500);
        chk('voltar à conversa já aberta é instantâneo (cache em memória): mantém as 82+ mensagens sem recarregar tudo', await av(`document.querySelectorAll('.chat-bolha').length`) >= 80 && (await J(`window.__chamadas.slice(${antes_volta}).filter(function(c){return !/depois=/.test(c.busca);})`)).length === 0);

        // busca
        await av(`(function(){var i=document.querySelector('.chat-busca-campo');i.value='zzzz';i.dispatchEvent(new Event('input'));})(); 1`);
        chk('busca sem resultado mostra "Nenhuma conversa encontrada"', await av(`/Nenhuma conversa encontrada/.test(document.querySelector('.chat-itens').textContent)`));
        await av(`(function(){var i=document.querySelector('.chat-busca-campo');i.value='gomes';i.dispatchEvent(new Event('input'));})(); 1`);
        chk('a busca filtra por nome, ID ou bot (aqui "gomes": 3 leads — Gomes e os dois bots com "gomes")', (await av(`document.querySelectorAll('.chat-item').length`)) === 3, await av(`document.querySelectorAll('.chat-item').length`));
        await av(`(function(){var i=document.querySelector('.chat-busca-campo');i.value='';i.dispatchEvent(new Event('input'));})(); 1`);

        // erro de envio (lead que bloqueou o bot)
        await av(`document.querySelectorAll('.chat-item')[2].click(); 1`);
        await esp(500);
        await av(`(function(){var t=document.querySelector('.chat-campo');t.value='você está aí?';t.dispatchEvent(new Event('input'));})(); 1`);
        await av(`document.querySelector('.chat-enviar').click(); 1`);
        await esp(600);
        chk('lead que bloqueou o bot: aparece o erro amigável e o texto digitado é mantido', await av(`!document.querySelector('.chat-erro').hidden && /bloqueou o bot/.test(document.querySelector('.chat-erro').textContent) && document.querySelector('.chat-campo').value==='você está aí?'`));
        await c.captura(SAIDA + '/desktop_erro.png');

        // fechar
        await av(`document.querySelector('.chat-campo').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true})); 1`);
        await esp(200);
        chk('Esc fecha o chat', !(await av(aberto)));
        chk('ao fechar, o foco volta ao botão que abriu', await av(`document.activeElement===document.querySelector('.chat-abrir')`));
        const apos_fechar = await av(`window.__chamadas.length`);
        await esp(3800);
        chk('fechado = zero consulta: depois de fechar, nada mais é pedido ao servidor', (await av(`window.__chamadas.length`)) === apos_fechar, [apos_fechar, await av(`window.__chamadas.length`)]);

        // reabrir mostra a conversa guardada (cache) e só pergunta o que chegou
        await av(`document.querySelector('.chat-abrir').click(); 1`);
        await esp(500);
        chk('reabrir mostra a lista de novo e pede só as mensagens novas (?depois=)', (await av(aberto)) && (await J(`window.__chamadas.slice(${apos_fechar})`)).every(x => /depois=/.test(x.busca)));
        await av(`document.querySelector('.chat-fundo').dispatchEvent(new MouseEvent('mousedown',{bubbles:true})); 1`);
        await esp(150);
        chk('clicar fora do cartão também fecha', !(await av(aberto)));

        // tema claro
        await av(`document.documentElement.setAttribute('data-theme','light'); document.querySelectorAll('.chat-abrir')[0].click(); 1`);
        await esp(500);
        await c.captura(SAIDA + '/desktop_claro.png');
        chk('tema claro: fundo do cartão e dos balões usam os tokens do tema (não ficam escuros)', await av(`(function(){var m=getComputedStyle(document.querySelector('.chat-modal')).backgroundColor;return /^rgb\\((2[0-9]{2}|255)/.test(m);})()`), await av(`getComputedStyle(document.querySelector('.chat-modal')).backgroundColor`));
        await av(`document.documentElement.setAttribute('data-theme','dark'); 1`);
        // cor do sistema escolhida pelo usuário
        await av(`document.documentElement.style.setProperty('--or','#3b82f6'); document.documentElement.style.setProperty('--orsoft','rgba(59,130,246,.14)'); 1`);
        await esp(100);
        await c.captura(SAIDA + '/desktop_cor_azul.png');
        await av(`(function(){var t=document.querySelector('.chat-campo');t.value='x';t.dispatchEvent(new Event('input'));})(); 1`); // habilita o botão enviar (desabilitado ele é cinza de propósito)
        chk('a cor do sistema escolhida pelo usuário vale no chat: botão enviar e conversa selecionada usam --or', (await av(`getComputedStyle(document.querySelector('.chat-enviar')).backgroundColor`)) === 'rgb(59, 130, 246)' && (await av(`getComputedStyle(document.querySelector('.chat-item.ativo')).backgroundColor`)).indexOf('59, 130, 246') !== -1, [await av(`getComputedStyle(document.querySelector('.chat-enviar')).backgroundColor`), await av(`getComputedStyle(document.querySelector('.chat-item.ativo')).backgroundColor`)]);
        await av(`document.documentElement.style.removeProperty('--or'); document.documentElement.style.removeProperty('--orsoft'); document.querySelector('.chat-fechar').click(); 1`);

        // ================================================================ janelas diferentes
        for (const [w, h, nome] of [[1920, 1080, 'tela cheia 1920'], [1024, 600, 'janela baixa 1024x600'], [2560, 1300, 'ultrawide 2560']]) {
            await abrirPagina(w, h);
            await av(`document.querySelector('.chat-abrir').click(); 1`);
            await esp(500);
            chk(nome + ': modal inteiro dentro da janela, sem rolagem lateral e com a caixa de mensagem visível', await av(`(function(){var r=document.querySelector('.chat-modal').getBoundingClientRect(),f=document.querySelector('.chat-envio').getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight&&f.bottom<=innerHeight&&document.documentElement.scrollWidth<=innerWidth;})()`));
        }
        await c.captura(SAIDA + '/ultrawide.png');

        // ================================================================ celular
        await abrirPagina(390, 800);
        await av(`document.querySelectorAll('.chat-abrir')[0].click(); 1`);
        await esp(500);
        chk('celular: abre direto na conversa, ocupando a tela toda, sem rolagem lateral', await av(`(function(){var m=document.querySelector('.chat-modal'),r=m.getBoundingClientRect();return m.classList.contains('chat-mostra-conversa')&&r.width>=innerWidth-1&&r.height>=innerHeight-1&&document.documentElement.scrollWidth<=innerWidth&&getComputedStyle(document.querySelector('.chat-lista')).display==='none';})()`));
        chk('celular: o botão voltar aparece e a navegação 1/5 some (a lista faz esse papel)', await av(`getComputedStyle(document.querySelector('.chat-voltar')).display!=='none' && getComputedStyle(document.querySelector('.chat-nav')).display==='none'`));
        chk('celular: a caixa de mensagem usa fonte de 16 px (o iPhone não dá zoom ao focar)', (await av(`parseFloat(getComputedStyle(document.querySelector('.chat-campo')).fontSize)`)) >= 16);
        await c.captura(SAIDA + '/celular_conversa.png');
        await av(`document.querySelector('.chat-voltar').click(); 1`);
        chk('celular: voltar mostra a lista de chats (5 itens) e esconde a conversa', await av(`document.querySelectorAll('.chat-item').length===5 && getComputedStyle(document.querySelector('.chat-conversa')).display==='none' && getComputedStyle(document.querySelector('.chat-lista')).display!=='none'`));
        await c.captura(SAIDA + '/celular_lista.png');
        await av(`document.querySelectorAll('.chat-item')[3].click(); 1`);
        await esp(500);
        chk('celular: tocar num item abre aquela conversa', (await av(`document.querySelector('.chat-quem b').textContent`)) === 'Gomes' && await av(`document.querySelector('.chat-modal').classList.contains('chat-mostra-conversa')`));

        chk('sem erro de JavaScript em todo o teste', c.errosJs().length === 0, c.errosJs());
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)\nCapturas em ' + SAIDA);
    process.exit(falhas ? 1 : 0);
})();
