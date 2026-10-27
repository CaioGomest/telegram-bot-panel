// API simulada: o editor chama $.getJSON / $.ajax em api.php; aqui tudo responde na hora e o que seria salvo fica em window.__salvo.
window.__salvo = null;
window.__fluxo = window.__fluxo_pre || null;   // o que "obter_fluxo" devolve (o teste define antes de abrir, via __fluxo_pre)
window.__uploads = 0;
(function ($) {
    function resposta(url, dados) {
        const acao = (String(url).match(/action=([a-z_]+)/) || [])[1];
        switch (acao) {
            case 'limites_pagamento': return { sucesso: true, minimo: 1.5 };
            case 'listar_grupos_usuario': return { sucesso: true, grupos: window.__grupos || [] };
            case 'listar_bots': return { sucesso: true, bots: [] };
            case 'obter_fluxo': return { sucesso: true, fluxo: window.__fluxo };
            case 'resumo_fluxo': return { sucesso: true, resumo: { leads: 0, vips: 0, receita: 0 } };
            case 'conversoes_fluxo': return { sucesso: true, conversoes: { gerados: 0, leads: 0 } };
            case 'bots_do_fluxo': return { sucesso: true, vinculados: [], disponiveis: [] };
            case 'salvar_fluxo': window.__salvo = JSON.parse(dados); return { sucesso: true, fluxo: { id: 99 }, mensagem: 'Fluxo salvo com sucesso.' };
            case 'upload_imagem_fluxo': case 'upload_video_fluxo': case 'upload_audio_fluxo': {
                // window.__falhar_arquivo = nome de um arquivo que o servidor "recusa"; window.__arquivos_enviados guarda os nomes, na ordem em que chegaram
                const f = dados && dados.get && (dados.get('image') || dados.get('video') || dados.get('audio'));
                window.__arquivos_enviados = (window.__arquivos_enviados || []).concat([f ? f.name : '?']);
                if (f && window.__falhar_arquivo && f.name === window.__falhar_arquivo) return { sucesso: false, mensagem: 'Formato não aceito.' };
                window.__uploads++;
                return acao === 'upload_imagem_fluxo' ? { sucesso: true, caminho: 'uploads/foto' + window.__uploads + '.jpg' }
                    : acao === 'upload_video_fluxo' ? { sucesso: true, caminho: 'uploads/video' + window.__uploads + '.mp4' }
                        : { sucesso: true, caminho: 'uploads/audio' + window.__uploads + '.mp3' };
            }
            default: return { sucesso: true };
        }
    }
    $.getJSON = function (url) { return $.Deferred().resolve(resposta(url)).promise(); };
    $.ajax = function (o) {
        const r = resposta(o.url, o.data);
        if (window.__atraso_upload && /action=upload_/.test(String(o.url))) {   // envio "lento": deixa o teste ver o andamento ("Enviando mídia 2 de 3…")
            const d = $.Deferred();
            setTimeout(function () { d.resolve(r); }, window.__atraso_upload);
            return d.promise();
        }
        return r && r.__http ? $.Deferred().reject({ responseJSON: r, status: r.__http }).promise() : $.Deferred().resolve(r).promise();
    };
})(jQuery);
