/* Avisos push no navegador (Pix gerado / Pix pago). Plano: deploy/PLANO-PUSH-NOTIFICACOES.md (seção 9).
 * Carregado em todas as páginas do painel QUANDO o interruptor geral está ligado (barra_lateral.php) e sempre em Minha Conta (cartão #push-cartao).
 *   - cartão em Minha Conta: estados (sem suporte, iPhone fora da Tela de Início, desligado pelo admin, permissão negada, ativar, ativo) e lista de aparelhos;
 *   - a permissão e a inscrição só acontecem DENTRO DO CLIQUE no botão (nunca ao carregar a página);
 *   - convite discreto no sino; sincronização diária da inscrição; sair da conta cancela a inscrição deste aparelho. */
(function () {
    'use strict';
    if (window.__pushCarregado) { return; }
    window.__pushCarregado = true;

    var script = document.currentScript;
    var BASE = script && script.src ? script.src.replace(/assets\/js\/push\.js.*$/, '') : '/';
    var cfg = window.PUSH_CONFIG || {};
    var suporta = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var ehIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var instalado = navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var CHAVE_LOCAL = 'push_inscrito';
    var CHAVE_SYNC = 'push_sync_em';
    var CHAVE_CONVITE = 'push_convite_ate';

    function lerLocal(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
    function gravarLocal(k, v) { try { if (v === null) { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) { /* modo privado */ } }

    function api(caminho, metodo, corpo) {
        var opcoes = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (metodo === 'POST') {
            opcoes.headers['Content-Type'] = 'application/json';
            opcoes.headers['X-CSRF-Token'] = window.CSRF_TOKEN || '';
            opcoes.body = JSON.stringify(corpo || {});
        }
        return fetch(BASE + caminho, opcoes).then(function (r) { return r.json().catch(function () { return { sucesso: false, mensagem: 'Resposta inválida do servidor.' }; }); });
    }

    function b64uParaBytes(texto) {
        var b64 = texto.replace(/-/g, '+').replace(/_/g, '/');
        b64 += '==='.slice((b64.length + 3) % 4);
        var bin = atob(b64);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes;
    }

    function sha256Hex(texto) {
        if (!(window.crypto && window.crypto.subtle)) { return Promise.resolve(''); }
        return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(texto)).then(function (buf) {
            return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        });
    }

    /** A inscrição do navegador foi feita com a MESMA chave pública do servidor? (se o administrador regenerou as chaves, não). Sem como conferir, confia. */
    function mesmaChave(sub, chaveB64u) {
        try {
            var k = sub.options && sub.options.applicationServerKey;
            if (!k) { return true; }
            var a = new Uint8Array(k), b = b64uParaBytes(chaveB64u);
            if (a.length !== b.length) { return false; }
            for (var i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
            return true;
        } catch (e) { return true; }
    }

    function registrarSW() {
        return navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).then(function () { return navigator.serviceWorker.ready; });
    }

    function inscricaoAtual() {
        if (!suporta) { return Promise.resolve(null); }
        return navigator.serviceWorker.getRegistration(BASE).then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; }).catch(function () { return null; });
    }

    // ---------------------------------------------------------------- cartão em Minha Conta
    var cartao = null;   // definido em iniciar(): este script pode rodar antes de o cartão existir na página
    var estadoAtual = '';

    function mostrar(estado) {
        estadoAtual = estado;
        [].forEach.call(cartao.querySelectorAll('[data-push-estado]'), function (el) { el.hidden = el.getAttribute('data-push-estado') !== estado; });
        var lista = cartao.querySelector('#push-dispositivos-bloco');
        if (lista) { lista.hidden = !(estado === 'ativo' || estado === 'ativar' || estado === 'negada'); }
    }

    function erro(msg) {
        var el = cartao.querySelector('#push-erro');
        if (!el) { return; }
        el.textContent = msg || '';
        el.hidden = !msg;
    }

    function sincronizarCartao() {
        erro('');
        if (!cfg.ativo) { mostrar('admin-desligado'); return Promise.resolve(); }
        if (!suporta) { mostrar(ehIOS && !instalado ? 'ios-instalar' : 'sem-suporte'); return Promise.resolve(); }
        if (ehIOS && !instalado) { mostrar('ios-instalar'); return Promise.resolve(); }
        if (Notification.permission === 'denied') { mostrar('negada'); return listarAparelhos(null); }
        return inscricaoAtual().then(function (sub) {
            mostrar(sub ? 'ativo' : 'ativar');
            if (!sub) { gravarLocal(CHAVE_LOCAL, null); }
            return listarAparelhos(sub);
        });
    }

    function rotuloQuando(seg) {
        if (seg === null || seg === undefined) { return 'nunca'; }
        if (seg < 90) { return 'agora há pouco'; }
        if (seg < 3600) { return 'há ' + Math.round(seg / 60) + ' min'; }
        if (seg < 86400) { return 'há ' + Math.round(seg / 3600) + ' h'; }
        return 'há ' + Math.round(seg / 86400) + ' dia(s)';
    }

    var jaReconciliou = false;

    /** Cancela a inscrição deste navegador e volta o cartão para "ativar" (o vendedor ativa de novo com uma inscrição limpa). */
    function desfazerLocal(sub) {
        return sub.unsubscribe().catch(function () { return false; }).then(function () { gravarLocal(CHAVE_LOCAL, null); return sincronizarCartao(); });
    }

    /** O navegador tem uma inscrição que o servidor não lista (aparelho apagado depois de um erro do serviço de push, chaves regeneradas...). Uma tentativa por página. */
    function reconciliar(sub) {
        if (jaReconciliou) { return desfazerLocal(sub); }
        jaReconciliou = true;
        return api('ajax/push_chave.php', 'GET').then(function (k) {
            if (!k.sucesso || !k.ativo || !k.chave_publica) { return null; }
            if (!mesmaChave(sub, k.chave_publica)) { return desfazerLocal(sub); }
            return api('ajax/push_inscrever.php', 'POST', { subscription: sub.toJSON(), renovacao: true, plataforma: ehIOS ? 'ios' : '' }).then(function (r) {
                return r.sucesso ? sincronizarCartao() : desfazerLocal(sub);
            });
        });
    }

    function listarAparelhos(subAtual) {
        var ul = cartao.querySelector('#push-dispositivos');
        if (!ul) { return Promise.resolve(); }
        return Promise.all([api('ajax/push_listar.php', 'GET'), subAtual ? sha256Hex(subAtual.endpoint) : Promise.resolve('')]).then(function (r) {
            var j = r[0];
            var meuHash = r[1];
            if (j.ativo === false) { mostrar('admin-desligado'); return; }   // os avisos foram desligados enquanto a página estava aberta: some a lista e os botões
            if (subAtual && meuHash && j.sucesso && !(j.aparelhos || []).some(function (a) { return a.endpoint_hash === meuHash; })) {
                return reconciliar(subAtual);
            }
            ul.textContent = '';
            if (!j.sucesso || !j.aparelhos || !j.aparelhos.length) {
                var vazio = document.createElement('li');
                vazio.className = 'push-aviso';
                vazio.textContent = 'Nenhum aparelho ativado ainda.';
                ul.appendChild(vazio);
                return;
            }
            j.aparelhos.forEach(function (a) {
                var este = !!(meuHash && a.endpoint_hash === meuHash);
                var li = document.createElement('li');
                li.className = 'push-dispositivo' + (este ? ' este' : '');
                li.setAttribute('data-id', String(a.id));
                var topo = document.createElement('div');
                topo.className = 'push-dispositivo-topo';
                var nome = document.createElement('div');
                nome.className = 'push-dispositivo-nome';
                var t = document.createElement('span');
                t.textContent = a.apelido || 'Aparelho';
                nome.appendChild(t);
                if (este) {
                    var selo = document.createElement('span');
                    selo.className = 'push-selo-este';
                    selo.textContent = 'Este aparelho';
                    nome.appendChild(selo);
                }
                var meta = document.createElement('div');
                meta.className = 'push-dispositivo-meta';
                meta.textContent = 'Visto ' + rotuloQuando(a.visto_ha_s) + (a.envio_ha_s !== null && a.envio_ha_s !== undefined ? ' · último aviso ' + rotuloQuando(a.envio_ha_s) : '');
                topo.appendChild(nome);
                topo.appendChild(meta);
                li.appendChild(topo);

                var prefs = document.createElement('div');
                prefs.className = 'push-chaves-pref';
                [['pref_pix_gerado', 'Pix gerado'], ['pref_pix_pago', 'Pix pago']].forEach(function (p) {
                    var rotulo = document.createElement('label');
                    rotulo.className = 'push-interruptor';
                    var cx = document.createElement('input');
                    cx.type = 'checkbox';
                    cx.checked = !!a[p[0]];
                    cx.addEventListener('change', function () {
                        var corpo = { id: a.id };
                        corpo[p[0]] = cx.checked;
                        api('ajax/push_preferencias.php', 'POST', corpo).then(function (r2) {
                            if (!r2.sucesso) { cx.checked = !cx.checked; erro(r2.mensagem || 'Não foi possível salvar.'); }
                        });
                    });
                    rotulo.appendChild(cx);
                    rotulo.appendChild(document.createTextNode(p[1]));
                    prefs.appendChild(rotulo);
                });
                li.appendChild(prefs);

                var acoes = document.createElement('div');
                acoes.className = 'push-dispositivo-acoes';
                var teste = document.createElement('button');
                teste.type = 'button';
                teste.className = 'a-btn';
                teste.textContent = 'Enviar aviso de teste';
                teste.addEventListener('click', function () {
                    teste.disabled = true;
                    var antes = teste.textContent;
                    teste.textContent = 'Enviando…';
                    api('ajax/push_teste.php', 'POST', { id: a.id }).then(function (r2) {
                        if (r2.morta) {
                            // o serviço de push disse que este aparelho não existe mais (o servidor já o apagou): limpa a inscrição daqui e volta para "ativar"
                            var msg = r2.mensagem;
                            (este && subAtual ? subAtual.unsubscribe().catch(function () { return false; }) : Promise.resolve(true)).then(function () {
                                if (este) { gravarLocal(CHAVE_LOCAL, null); }
                                return sincronizarCartao();
                            }).then(function () { erro(msg); });
                            return;
                        }
                        teste.textContent = r2.sucesso ? 'Enviado ✓' : antes;
                        erro(r2.sucesso ? '' : (r2.mensagem || 'Não foi possível enviar.'));
                        setTimeout(function () { teste.disabled = false; teste.textContent = antes; if (!r2.sucesso) { sincronizarCartao(); } }, r2.sucesso ? 3000 : 1500);
                    });
                });
                var remover = document.createElement('button');
                remover.type = 'button';
                remover.className = 'a-btn';
                remover.textContent = 'Remover';
                remover.addEventListener('click', function () {
                    if (!window.confirm('Parar de receber avisos neste aparelho?')) { return; }
                    var p = este && subAtual ? subAtual.unsubscribe().catch(function () { return false; }) : Promise.resolve(true);
                    p.then(function () { return api('ajax/push_remover.php', 'POST', { id: a.id }); }).then(function () {
                        if (este) { gravarLocal(CHAVE_LOCAL, null); }
                        sincronizarCartao();
                    });
                });
                acoes.appendChild(teste);
                acoes.appendChild(remover);
                li.appendChild(acoes);
                ul.appendChild(li);
            });
        });
    }

    /** Dentro do clique: pede a permissão e inscreve este aparelho. */
    function ativar(botao) {
        erro('');
        botao.disabled = true;
        var rotulo = botao.textContent;
        botao.textContent = 'Ativando…';
        var termina = function () { botao.disabled = false; botao.textContent = rotulo; };
        Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') {
                termina();
                sincronizarCartao();
                return null;
            }
            return api('ajax/push_chave.php', 'GET').then(function (j) {
                if (!j.sucesso || !j.ativo || !j.chave_publica) { throw new Error(j.mensagem || 'Os avisos no celular ainda não estão disponíveis.'); }
                return registrarSW().then(function (reg) {
                    var inscrever = function () { return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uParaBytes(j.chave_publica) }); };
                    return reg.pushManager.getSubscription().then(function (existente) {
                        if (existente && !mesmaChave(existente, j.chave_publica)) {
                            // inscrição de antes de o administrador trocar as chaves: o navegador recusa inscrever por cima, então cancela e faz de novo
                            return existente.unsubscribe().catch(function () { return false; }).then(inscrever);
                        }
                        return existente || inscrever();
                    });
                });
            }).then(function (sub) {
                return api('ajax/push_inscrever.php', 'POST', { subscription: sub.toJSON(), plataforma: ehIOS ? 'ios' : '' }).then(function (r) {
                    if (!r.sucesso) { throw new Error(r.mensagem || 'Não foi possível registrar este aparelho.'); }
                    gravarLocal(CHAVE_LOCAL, '1');
                    gravarLocal(CHAVE_SYNC, String(Date.now()));
                });
            }).then(function () { termina(); return sincronizarCartao(); });
        }).catch(function (e) {
            termina();
            var m = e && e.message ? e.message : '';
            var texto = /push service error|registration failed/i.test(m)
                ? 'O navegador não conseguiu se conectar ao serviço de avisos. No Brave, ligue "Usar serviços do Google para mensagens push" em brave://settings/privacy e tente de novo.'
                : (m || 'Não foi possível ativar os avisos neste aparelho.');
            sincronizarCartao().then(function () { erro(texto); });   // o cartão se redesenha (e limpa o erro) antes; a mensagem entra depois
        });
    }

    function iniciarCartao() {
        mostrar('carregando');
        var bAtivar = cartao.querySelector('#push-ativar');
        if (bAtivar) { bAtivar.addEventListener('click', function () { ativar(bAtivar); }); }
        var bTestar = cartao.querySelector('#push-testar-aqui');
        if (bTestar) {
            bTestar.addEventListener('click', function () {   // aciona o botão de teste do aparelho marcado como "Este aparelho" na lista
                var b = cartao.querySelector('#push-dispositivos li.este .push-dispositivo-acoes button');
                if (b && !b.disabled) { b.click(); } else { erro('Aguarde a lista de aparelhos carregar e tente de novo.'); }
            });
        }
        var bRever = cartao.querySelector('#push-rever');
        if (bRever) { bRever.addEventListener('click', function () { sincronizarCartao(); }); }   // depois de liberar nas configurações do navegador, volta a oferecer "Ativar"
        var bDesativar = cartao.querySelector('#push-desativar');
        if (bDesativar) {
            bDesativar.addEventListener('click', function () {
                inscricaoAtual().then(function (sub) {
                    var ep = sub ? sub.endpoint : null;
                    return (sub ? sub.unsubscribe().catch(function () { return false; }) : Promise.resolve(true)).then(function () {
                        return ep ? api('ajax/push_remover.php', 'POST', { endpoint: ep }) : null;
                    });
                }).then(function () { gravarLocal(CHAVE_LOCAL, null); sincronizarCartao(); });
            });
        }
        sincronizarCartao();
    }

    // ---------------------------------------------------------------- sincronização diária (qualquer página)
    function sincronizarDiario() {
        if (!cfg.ativo || !suporta || lerLocal(CHAVE_LOCAL) !== '1' || Notification.permission !== 'granted') { return; }
        var ultimo = parseInt(lerLocal(CHAVE_SYNC) || '0', 10);
        if (Date.now() - ultimo < 24 * 3600 * 1000) { return; }
        inscricaoAtual().then(function (sub) {
            if (!sub) { gravarLocal(CHAVE_LOCAL, null); return null; }
            return api('ajax/push_inscrever.php', 'POST', { subscription: sub.toJSON(), renovacao: true, plataforma: ehIOS ? 'ios' : '' }).then(function (r) {
                if (r.sucesso) { gravarLocal(CHAVE_SYNC, String(Date.now())); }
            });
        });
    }

    // ---------------------------------------------------------------- convite no sino
    function convidarNoSino() {
        var painel = document.querySelector('#sino-notificacoes-raiz .sino-painel');
        if (!cfg.ativo || !painel || painel.querySelector('.sino-convite-push') || cartao) { return; }
        if (lerLocal(CHAVE_LOCAL) === '1' || (suporta && Notification.permission === 'denied')) { return; }
        var ate = parseInt(lerLocal(CHAVE_CONVITE) || '0', 10);
        if (ate > Date.now()) { return; }
        if (!suporta && !(ehIOS && !instalado)) { return; }
        var caixa = document.createElement('div');
        caixa.className = 'sino-convite-push';
        var texto = document.createElement('span');
        texto.textContent = ehIOS && !instalado ? 'Receba avisos de Pix no iPhone.' : 'Receba os avisos de Pix neste aparelho.';
        var link = document.createElement('a');
        link.href = BASE + 'configuracao_usuario#avisos';
        link.textContent = ehIOS && !instalado ? 'Como ativar' : 'Ativar';
        var depois = document.createElement('button');
        depois.type = 'button';
        // X no canto (sem texto): mesma ação de antes, esconder por 30 dias; o nome fica no aria-label e no title
        depois.setAttribute('aria-label', 'Agora não');
        depois.title = 'Agora não';
        depois.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6L6 18M6 6l12 12"></path></svg>';
        depois.addEventListener('click', function () {
            gravarLocal(CHAVE_CONVITE, String(Date.now() + 30 * 24 * 3600 * 1000));
            caixa.remove();
        });
        caixa.appendChild(texto);
        caixa.appendChild(link);
        caixa.appendChild(depois);
        var lista = painel.querySelector('.sino-lista');
        painel.insertBefore(caixa, lista || null);
    }

    // ---------------------------------------------------------------- sair da conta cancela a inscrição DESTE aparelho
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href$="logout"], a[href*="logout?"]') : null;
        if (!a || !suporta || lerLocal(CHAVE_LOCAL) !== '1' || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank') { return; }
        e.preventDefault();
        var destino = a.href;
        var seguir = function () { gravarLocal(CHAVE_LOCAL, null); window.location.href = destino; };
        var limite = setTimeout(seguir, 2500);   // o sair nunca fica preso se a rede falhar
        inscricaoAtual().then(function (sub) {
            if (!sub) { return null; }
            return fetch(BASE + 'ajax/push_remover.php', {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: JSON.stringify({ endpoint: sub.endpoint })
            }).then(function () { return sub.unsubscribe().catch(function () { return false; }); });
        }).catch(function () { /* segue para o sair */ }).then(function () { clearTimeout(limite); seguir(); });
    }, true);

    function iniciar() {
        cartao = document.getElementById('push-cartao');
        if (cartao) { iniciarCartao(); }
        sincronizarDiario();
        convidarNoSino();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
