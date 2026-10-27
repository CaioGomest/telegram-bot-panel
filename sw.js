/* Service worker dos avisos push (Pix gerado / Pix pago). Plano: deploy/PLANO-PUSH-NOTIFICACOES.md (seção 9).
 * - Sem cache offline e sem "fetch": nunca serve página velha.
 * - "push": SEMPRE mostra uma notificação (o iOS revoga a inscrição se chegar push que não mostra nada); conteúdo ilegível vira texto genérico.
 * - "notificationclick": foca uma aba do painel ou abre a página do aviso.
 * - "pushsubscriptionchange": o navegador renovou a chave/endereço; inscreve de novo e avisa o servidor.
 * O arquivo é servido com Cache-Control: no-cache (.htaccess). O ícone e o "badge" vêm da logo do sistema (uploads/pwa/), com ?v=<versão> no aviso. */

var BASE = self.registration.scope;   // termina em "/": a pasta onde o painel está

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
    var d = {};
    try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
    var v = d.v ? '?v=' + encodeURIComponent(d.v) : '';
    var opcoes = {
        body: d.b || 'Abra o painel para ver.',
        icon: BASE + 'uploads/pwa/icone-192.png' + v,
        badge: BASE + 'uploads/pwa/badge-96.png' + v,
        tag: d.g || 'painel',
        renotify: d.k === 'rg' || d.k === 'rp',
        timestamp: d.ts || Date.now(),
        data: { u: d.u || '/vendas' }
    };
    event.waitUntil(self.registration.showNotification(d.t || 'Nova atividade no painel', opcoes));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var caminho = (event.notification.data && event.notification.data.u) || '/vendas';
    var alvo = new URL(String(caminho).replace(/^\//, ''), BASE).href;
    if (alvo.indexOf(BASE) !== 0) { alvo = BASE + 'vendas'; }   // o aviso só leva a páginas do próprio painel
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (abas) {
        for (var i = 0; i < abas.length; i++) {
            if (abas[i].url.indexOf(BASE) === 0 && 'focus' in abas[i]) {
                if ('navigate' in abas[i]) { abas[i].navigate(alvo); }
                return abas[i].focus();
            }
        }
        return self.clients.openWindow(alvo);
    }));
});

self.addEventListener('pushsubscriptionchange', function (event) {
    event.waitUntil((async function () {
        try {
            var r = await fetch(BASE + 'ajax/push_chave.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (!j || !j.ativo || !j.chave_publica) { return; }
            var chave = Uint8Array.from(atob(j.chave_publica.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((j.chave_publica.length + 3) % 4)), function (c) { return c.charCodeAt(0); });
            var sub = event.newSubscription || await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chave });
            await fetch(BASE + 'ajax/push_inscrever.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': j.csrf || '' },
                body: JSON.stringify({ subscription: sub.toJSON(), renovacao: true })
            });
        } catch (e) { /* sem sessão: push.js reenvia na próxima vez que o painel abrir */ }
    })());
});
