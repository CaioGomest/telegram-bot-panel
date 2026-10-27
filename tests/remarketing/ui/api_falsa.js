// API simulada de remarketing.php e do envio de mídia. Tudo responde na hora; as chamadas ficam em window.__chamadas e o que seria salvo, em window.__salvo.
// O teste define window.__dados_pre (antes da página abrir) para trocar os dados; sem ele valem os padrões abaixo.
window.__chamadas = [];
window.__salvo = null;
window.__acoes = [];
(function ($) {
    const padrao = {
        minimo_pix: 1.5,
        fluxos: [
            { id: 1, nome: 'claude gomes', modo: 'basico', bots: [{ id: 11, nome: 'Bot A' }], grupos: [{ id: '-1001', titulo: 'grupo 1' }, { id: '-1002', titulo: 'grupo 2' }],
              planos: [{ id: 'pl1', nome: 'Plano Mensal', valor: 29.9, acesso: 'mensal', dias: 30, entrega_tipo: 'grupo', id_grupo: '-1001', link_entrega: '' },
                       { id: 'pl2', nome: 'Plano Anual', valor: 199, acesso: 'anual', dias: 365, entrega_tipo: 'link', id_grupo: '', link_entrega: 'https://exemplo.com/anual' }] },
            { id: 2, nome: 'teste fluxo', modo: 'basico', bots: [], grupos: [], planos: [] },
            { id: 3, nome: 'Funil visual', modo: 'avancado', bots: [{ id: 12, nome: 'Bot B' }, { id: 13, nome: 'Bot C' }], grupos: [{ id: '-2001', titulo: 'VIP visual' }], planos: [] }
        ],
        // alcance devolvido por "contar": base + 10 * posição do segmento (o teste confere que mudou ao trocar de segmento)
        alcance_base: 100,
        campanhas: [],
        resumo: { total: 0, ativas: 0, enviadas: 0, receita: 0 },
        edicao: null,
        erro_salvar: '',
        erro_acao: ''
    };
    function dados() {
        window.__dados = window.__dados || $.extend(true, {}, padrao, window.__dados_pre || {});
        return window.__dados;
    }
    const SEGS = ['todos', 'nao_comprou', 'comprou', 'pix_gerado', 'expirados', 'membros_ativos'];

    function juntar(url, extra) {
        const p = {};
        const q = String(url).split('?')[1] || '';
        q.split('&').forEach(function (par) {
            if (!par) return;
            const kv = par.split('=');
            p[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
        });
        return $.extend(p, extra && typeof extra === 'object' ? extra : {});
    }

    function responder(metodo, url, corpo) {
        const D = dados();
        const p = juntar(url, metodo === 'GET' ? corpo : null);
        window.__chamadas.push({ metodo: metodo, url: String(url), p: metodo === 'GET' ? p : corpo });
        if (String(url).indexOf('upload_midia_remarketing') !== -1) {
            const arq = corpo && corpo.get ? corpo.get('midia') : null;
            const video = arq && /^video\//.test(arq.type);
            window.__uploads = (window.__uploads || 0) + 1;
            return { status: 200, corpo: { sucesso: true, caminho: 'uploads/remarketing_' + (video ? 'video' : 'foto') + '_' + window.__uploads + (video ? '.mp4' : '.jpg'), tipo: video ? 'video' : 'foto' } };
        }
        if (metodo === 'GET') {
            switch (p.action) {
                case 'listar': return { status: 200, corpo: { sucesso: true, resumo: D.resumo, campanhas: D.campanhas } };
                case 'contexto': return { status: 200, corpo: { sucesso: true, fluxos: D.fluxos, minimo_pix: D.minimo_pix } };
                case 'obter': return D.edicao ? { status: 200, corpo: { sucesso: true, campanha: D.edicao } } : { status: 404, corpo: { sucesso: false, mensagem: 'Campanha não encontrada.' } };
                case 'contar': {
                    const f = D.fluxos.find(function (x) { return String(x.id) === String(p.id_fluxo); });
                    return { status: 200, corpo: { sucesso: true, bots: f ? f.bots.length : 0, total: D.alcance_base + 10 * Math.max(0, SEGS.indexOf(p.segmento)) } };
                }
            }
            return { status: 400, corpo: { sucesso: false, mensagem: 'Ação inválida.' } };
        }
        let dados_post = {};
        try { dados_post = JSON.parse(corpo); } catch (e) { /* corpo não é JSON */ }
        window.__acoes.push(dados_post);
        if (dados_post.acao === 'salvar') {
            if (D.erro_salvar) return { status: 422, corpo: { sucesso: false, mensagem: D.erro_salvar } };
            window.__salvo = dados_post;
            return { status: 200, corpo: { sucesso: true, mensagem: dados_post.id ? 'Alterações salvas.' : 'Rascunho criado. Nada foi enviado: inicie a campanha na lista quando quiser.', id: dados_post.id || 99 } };
        }
        if (D.erro_acao) return { status: 422, corpo: { sucesso: false, mensagem: D.erro_acao } };
        const recorrente = dados_post.acao === 'agendar' && dados_post.frequencia && dados_post.frequencia.tipo !== 'uma_vez';
        const mensagens = { iniciar: 'Campanha iniciada. O envio começa no próximo minuto.', agendar: recorrente ? 'Recorrência ativada.' : 'Campanha agendada.', parar: 'Agendamento cancelado.' };
        return { status: 200, corpo: { sucesso: true, mensagem: mensagens[dados_post.acao] || 'Campanha excluída.' } };
    }
    function promessa(r) {
        const d = $.Deferred();
        setTimeout(function () {
            r.status < 400 ? d.resolve(r.corpo, 'success', { status: r.status }) : d.reject({ status: r.status, responseJSON: r.corpo }, 'error', '');
        }, 5);
        return d.promise();
    }
    $.getJSON = function (url, extra) { return promessa(responder('GET', url, extra)); };
    $.ajax = function (o) { return promessa(responder(String(o.method || o.type || 'GET').toUpperCase(), o.url, o.data)); };
})(jQuery);
