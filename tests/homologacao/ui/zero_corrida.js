// Editor Visual: o que a pessoa digita ENQUANTO uma mídia grande ainda está subindo não pode se perder (rede lenta, vídeo de verdade).
const fs = require('fs');
const path = require('path');
const { iniciar } = require('./zero_lib.js');
const q = (s) => 'jQuery(' + JSON.stringify(s) + ')';
(async () => {
    const t = await iniciar('zero_corrida', { largura: 1900, altura: 1100 });
    const { c } = t;
    try {
        // vídeo MP4 "válido" (só a caixa ftyp, que é o que o servidor confere) de 400 KB, gerado na hora: com a rede limitada a ~25 KB/s o envio leva ~16 s
        const grande = path.join(t.SAIDA, 'video_grande.mp4');
        fs.writeFileSync(grande, Buffer.concat([Buffer.from([0, 0, 0, 0x18]), Buffer.from('ftypisom'), Buffer.from([0, 0, 2, 0]), Buffer.from('isomiso2'), Buffer.alloc(400 * 1024)]));
        t.ok('login do usuário de teste', await t.entrar(), {});
        await c.ir(t.BASE + '/fluxo');
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length===13 && jQuery('#area-trabalho-fluxograma .flowchart-operator').length===1`, 25000); await t.esp(1200);
        await t.js(`window.__op = function(id){ return jQuery('#area-trabalho-fluxograma .flowchart-operator').filter(function(){ return jQuery(this).data('operator_id') === id; }); };
          window.__solta = function(tipo, x, y){ var dt = new DataTransfer(); dt.setData('node-type', tipo); jQuery('.conteiner-fluxo').get(0).dispatchEvent(new DragEvent('drop', {dataTransfer: dt, bubbles: true, cancelable: true, clientX: x, clientY: y})); }; 1`);
        const props = async (id) => JSON.parse(await t.js(`JSON.stringify(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators[${JSON.stringify(id)}].properties)`));
        const novoId = async (tipo, x, y) => { const a = JSON.parse(await t.js(`JSON.stringify(Object.keys(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators))`)); await t.js(`__solta(${JSON.stringify(tipo)}, ${x}, ${y}); 1`); await t.esp(300); return JSON.parse(await t.js(`JSON.stringify(Object.keys(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators))`)).find(k => !a.includes(k)); };
        const m1 = await novoId('message', 300, 650), vid = await novoId('video', 700, 450), aud = await novoId('audio', 1100, 450), m2 = await novoId('message', 1500, 650);
        await t.esp(2500);                       // deixa o salvamento automático dos blocos recém-soltos passar
        // rede lenta: ~25 KB/s de subida => os 400 KB levam ~16 s
        await c.enviar('Network.emulateNetworkConditions', { offline: false, latency: 100, downloadThroughput: 500000, uploadThroughput: 25000 });
        const t0 = Date.now();
        await t.subir(`__op(${JSON.stringify(vid)}).find('.campo-video-arquivo')`, grande);
        await t.esp(1500);
        const emAndamento = !(await t.js(`!!(${JSON.stringify(await props(vid))} && 0)`));
        // enquanto o vídeo sobe: digita nos outros dois blocos
        await t.digitar(`__op(${JSON.stringify(m1)}).find('.campo-conteudo')`, 'Texto digitado durante o upload do vídeo');
        await t.digitar(`__op(${JSON.stringify(m2)}).find('.campo-conteudo')`, 'Outro texto, também durante o upload');
        const antesDoFim = Date.now() - t0;
        t.ok('o upload ainda está em andamento quando a digitação termina (rede limitada: ~' + Math.round(antesDoFim / 1000) + ' s)', !(await props(vid)).video_path, { video_path: (await props(vid)).video_path, ms: antesDoFim });
        const terminou = await c.esperar(`!!jQuery('#area-trabalho-fluxograma').flowchart('getData').operators[${JSON.stringify(vid)}].properties.video_path`, 60000);
        t.ok('o vídeo termina de subir e fica anexado ao bloco', terminou, {});
        const p1 = await props(m1), p2 = await props(m2);
        t.ok('texto digitado no bloco 1 durante o upload CONTINUA lá depois que o vídeo anexa', p1.conteudo === 'Texto digitado durante o upload do vídeo', { conteudo: p1.conteudo });
        t.ok('texto digitado no bloco 2 durante o upload CONTINUA lá depois que o vídeo anexa', p2.conteudo === 'Outro texto, também durante o upload', { conteudo: p2.conteudo });
        t.ok('e o que está na tela também (campo do bloco 1)', (await t.js(`__op(${JSON.stringify(m1)}).find('.campo-conteudo').val()`)) === 'Texto digitado durante o upload do vídeo', {});
        await c.enviar('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        // limpeza: apaga o fluxo criado
        const id = await t.js(`jQuery('#id-fluxo').val()`);
        if (id) { await t.js(`window.confirm = function(){ return true; }; jQuery('#btn-excluir-fluxo').click(); 1`); await c.esperar(`location.pathname.indexOf('fluxos')>=0`, 10000); }
    } catch (e) { console.log('ERRO', e.message); t.ok('execução sem exceção', false, { erro: e.message }); }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
