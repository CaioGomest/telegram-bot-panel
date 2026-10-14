function alternarTema() {
    var atual = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    var novo = atual === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', novo);
    try { localStorage.setItem('tema', novo); } catch (e) {}
}

/*
 * Seleção de arquivo: o seletor do sistema deixa escolher "Todos os arquivos", então o atributo accept sozinho não barra
 * nada. Aqui, em toda página que carrega este arquivo, um arquivo fora do que o campo aceita (extensão ou tipo) é recusado na
 * hora: o campo é limpo, aparece o motivo ao lado e os scripts da página não recebem o arquivo. Os tipos genéricos
 * (image/*, video/*, audio/*) só conferem o tipo do navegador; tipos e extensões explícitos conferem também a extensão.
 */
(function () {
    var EXTENSOES = {
        'image/jpeg': ['jpg', 'jpeg'], 'image/png': ['png'], 'image/webp': ['webp'], 'image/gif': ['gif'],
        'video/mp4': ['mp4'], 'video/quicktime': ['mov'], 'video/x-matroska': ['mkv'], 'video/webm': ['webm'],
        'audio/mpeg': ['mp3'], 'audio/ogg': ['ogg'], 'audio/mp4': ['m4a'], 'application/json': ['json']
    };
    function extensaoDe(nome) {
        var i = String(nome).lastIndexOf('.');
        return i < 0 ? '' : String(nome).slice(i + 1).toLowerCase();
    }
    function aceito(arquivo, accept) {
        var ext = extensaoDe(arquivo.name), tipo = (arquivo.type || '').toLowerCase();
        return accept.split(',').some(function (t) {
            t = t.trim().toLowerCase();
            if (!t) return false;
            if (t.charAt(0) === '.') return ext === t.slice(1);
            if (t.slice(-2) === '/*') return tipo === '' || tipo.indexOf(t.slice(0, -1)) === 0;
            return (tipo === '' || tipo === t) && (!EXTENSOES[t] || EXTENSOES[t].indexOf(ext) !== -1);
        });
    }
    function descricao(accept) {
        var ext = [];
        accept.split(',').forEach(function (t) {
            t = t.trim().toLowerCase();
            if (t.charAt(0) === '.') ext.push(t.slice(1));
            else if (EXTENSOES[t]) ext = ext.concat(EXTENSOES[t]);
            else if (t.slice(-2) === '/*') ext.push(t.slice(0, -2) === 'image' ? 'imagem' : t.slice(0, -2) === 'video' ? 'vídeo' : 'áudio');
        });
        return ext.filter(function (e, i) { return ext.indexOf(e) === i; }).join(', ');
    }
    document.addEventListener('change', function (e) {
        var campo = e.target;
        if (!campo || campo.tagName !== 'INPUT' || campo.type !== 'file' || !campo.accept) return;
        if (!(campo.files && campo.files.length)) return; // campo vazio (inclusive o evento disparado abaixo): mantém o aviso já mostrado
        var aviso = campo.parentNode && campo.parentNode.querySelector('.aviso-arquivo-invalido');
        if (aviso) aviso.remove();
        var invalido = Array.prototype.filter.call(campo.files || [], function (a) { return !aceito(a, campo.accept); });
        if (!invalido.length) return;
        e.stopImmediatePropagation();
        campo.value = '';
        var msg = document.createElement('small');
        msg.className = 'aviso-arquivo-invalido';
        msg.style.cssText = 'display:block;margin-top:6px;color:#e5484d;';
        msg.textContent = 'Arquivo "' + invalido[0].name + '" não é permitido aqui. Formatos aceitos: ' + descricao(campo.accept) + '.';
        if (campo.type === 'file' && campo.style.display !== 'none' && !campo.hidden) campo.insertAdjacentElement('afterend', msg);
        else alert(msg.textContent);
        // avisa quem escuta o campo que ele voltou a ficar vazio (ex.: limite de caracteres do remarketing)
        campo.dispatchEvent(new Event('change', { bubbles: true }));
    }, true);
})();
