(function () {
    'use strict';

    // Feature isolada do dashboard (navbar de stories) -- vanilla JS de propósito, sem
    // depender de jQuery (que o index.php não carrega) e sem tocar em nada relacionado a
    // venda/pagamento. Ver funcoes/stories.php e anotacoes/pendente/plano-recursos-sharkbot.md.

    const URL_API = 'api.php';
    const DURACAO_FOTO_MS = 5000;
    const DURACAO_MAX_VIDEO_MS = 30000;
    const LIMITE_FOTO_BYTES = 5 * 1024 * 1024;
    const LIMITE_VIDEO_BYTES = 20 * 1024 * 1024;
    const DURACAO_MAX_VIDEO_SEGUNDOS = 30;

    const elBarra = document.getElementById('barra-stories');
    if (!elBarra) return; // parciais/barra_stories.php não incluído nesta página.

    const elBtnCriar = document.getElementById('story-btn-criar');
    const elInputArquivo = document.getElementById('story-input-arquivo');
    const elModalUpload = document.getElementById('modal-story-upload');
    const elFecharUpload = document.getElementById('story-fechar-upload');
    const elDropzone = document.getElementById('story-dropzone');
    const elPreview = document.getElementById('story-preview');
    const elUploadErro = document.getElementById('story-upload-erro');
    const elUploadAcoes = document.getElementById('story-upload-acoes');
    const elCancelarPreview = document.getElementById('story-cancelar-preview');
    const elPublicar = document.getElementById('story-publicar');

    const elVisualizador = document.getElementById('visualizador-story');
    const elVisNome = document.getElementById('visualizador-story-nome');
    const elVisTempo = document.getElementById('visualizador-story-tempo');
    const elVisMidia = document.getElementById('visualizador-story-midia');
    const elVisProgresso = document.getElementById('visualizador-story-progresso');
    const elVisFechar = document.getElementById('visualizador-story-fechar');
    const elVisExcluir = document.getElementById('visualizador-story-excluir');
    const usuarioLogadoId = Number(elBarra.dataset.usuarioId || 0);
    const elVisAnterior = document.getElementById('visualizador-story-anterior');
    const elVisProximo = document.getElementById('visualizador-story-proximo');

    let arquivoSelecionado = null;
    let grupos = [];
    let grupoAtualIndex = -1;
    let storyAtualIndex = 0;
    let timerAvanco = null;

    function escaparHtml(valor) {
        return String(valor || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function iniciaisNome(nome) {
        const partes = String(nome || '?').trim().split(/\s+/);
        const primeira = partes[0] ? partes[0][0] : '?';
        const segunda = partes.length > 1 ? partes[partes.length - 1][0] : '';
        return (primeira + segunda).toUpperCase();
    }

    function cabecalhosFetch(extra) {
        const headers = Object.assign({}, extra || {});
        if (window.CSRF_TOKEN) headers['X-CSRF-Token'] = window.CSRF_TOKEN;
        return headers;
    }

    function carregarStories() {
        fetch(URL_API + '?action=listar_stories', { headers: cabecalhosFetch() })
            .then((res) => res.json())
            .then((res) => {
                grupos = (res && res.sucesso && Array.isArray(res.grupos)) ? res.grupos : [];
                renderizarBarra();
            })
            .catch(() => { grupos = []; });
    }

    function renderizarBarra() {
        // O botão "Criar" é o único item fixo do HTML original -- remove só os avatares
        // renderizados dinamicamente antes de redesenhar, pra não duplicar em cada polling futuro.
        elBarra.querySelectorAll('.story-item-usuario').forEach((el) => el.remove());

        grupos.forEach((grupo, index) => {
            const semVista = grupo.tem_nao_vista ? 'story-anel-nao-vista' : 'story-anel-vista';
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'story-item story-item-usuario';
            item.dataset.grupoIndex = String(index);
            const foto = String(grupo.foto || '').replace(/[^a-zA-Z0-9._-]/g, '');
            const avatar = foto
                ? '<img class="story-avatar-foto" src="uploads/perfis/' + escaparHtml(foto) + '" alt="">'
                : '<span class="story-avatar-iniciais">' + escaparHtml(iniciaisNome(grupo.nome)) + '</span>';
            item.innerHTML =
                '<span class="story-anel ' + semVista + '">' + avatar + '</span>' +
                '<span class="story-nome">' + escaparHtml(grupo.nome || 'Usuário') + '</span>';
            item.addEventListener('click', () => abrirVisualizador(index));
            elBarra.appendChild(item);
        });
    }

    // ===== Upload =====

    function abrirModalUpload() {
        resetarModalUpload();
        elModalUpload.classList.add('aberto');
    }

    function fecharModalUpload() {
        elModalUpload.classList.remove('aberto');
        resetarModalUpload();
    }

    function resetarModalUpload() {
        arquivoSelecionado = null;
        elInputArquivo.value = '';
        elDropzone.style.display = '';
        elPreview.style.display = 'none';
        elPreview.innerHTML = '';
        elUploadErro.style.display = 'none';
        elUploadErro.textContent = '';
        elUploadAcoes.style.display = 'none';
        elPublicar.disabled = false;
        elPublicar.textContent = 'Publicar';
    }

    function mostrarErroUpload(mensagem) {
        elUploadErro.textContent = mensagem;
        elUploadErro.style.display = '';
    }

    function extensaoDoArquivo(nome) {
        const partes = String(nome || '').split('.');
        return partes.length > 1 ? partes.pop().toLowerCase() : '';
    }

    function selecionarArquivo(arquivo) {
        elUploadErro.style.display = 'none';
        if (!arquivo) return;

        const extensao = extensaoDoArquivo(arquivo.name);
        const ehFoto = ['jpg', 'jpeg', 'png', 'webp'].includes(extensao);
        const ehVideo = ['mp4', 'webm'].includes(extensao);

        if (!ehFoto && !ehVideo) {
            mostrarErroUpload('Formato não suportado. Use jpg, png, webp (foto) ou mp4, webm (vídeo).');
            return;
        }
        if (ehFoto && arquivo.size > LIMITE_FOTO_BYTES) {
            mostrarErroUpload('Foto acima do limite de 5MB.');
            return;
        }
        if (ehVideo && arquivo.size > LIMITE_VIDEO_BYTES) {
            mostrarErroUpload('Vídeo acima do limite de 20MB.');
            return;
        }

        const urlPreview = URL.createObjectURL(arquivo);

        if (ehVideo) {
            // Duração validada no navegador -- não há ffmpeg confirmado no servidor pra
            // checar isso sem custo de CPU, então essa é a única barreira contra vídeo longo.
            const videoTeste = document.createElement('video');
            videoTeste.preload = 'metadata';
            videoTeste.onloadedmetadata = function () {
                URL.revokeObjectURL(videoTeste.src);
                if (videoTeste.duration > DURACAO_MAX_VIDEO_SEGUNDOS + 0.5) {
                    mostrarErroUpload('Vídeo acima do limite de ' + DURACAO_MAX_VIDEO_SEGUNDOS + ' segundos.');
                    return;
                }
                confirmarSelecao(arquivo, urlPreview, 'video');
            };
            videoTeste.onerror = function () {
                mostrarErroUpload('Não foi possível ler este vídeo.');
            };
            videoTeste.src = urlPreview;
        } else {
            confirmarSelecao(arquivo, urlPreview, 'foto');
        }
    }

    function confirmarSelecao(arquivo, urlPreview, tipo) {
        arquivoSelecionado = arquivo;
        elDropzone.style.display = 'none';
        elPreview.style.display = '';
        elPreview.innerHTML = tipo === 'video'
            ? '<video src="' + urlPreview + '" controls muted playsinline></video>'
            : '<img src="' + urlPreview + '" alt="Pré-visualização">';
        elUploadAcoes.style.display = '';
    }

    function publicarStory() {
        if (!arquivoSelecionado) return;

        elPublicar.disabled = true;
        elPublicar.textContent = 'Publicando...';

        const formData = new FormData();
        formData.append('midia', arquivoSelecionado);

        fetch(URL_API + '?action=criar_story', {
            method: 'POST',
            headers: cabecalhosFetch(),
            body: formData,
        })
            .then((res) => res.json())
            .then((res) => {
                if (!res || !res.sucesso) {
                    mostrarErroUpload((res && res.mensagem) || 'Erro ao publicar o story.');
                    elPublicar.disabled = false;
                    elPublicar.textContent = 'Publicar';
                    return;
                }
                fecharModalUpload();
                carregarStories();
            })
            .catch(() => {
                mostrarErroUpload('Erro ao publicar o story. Tente novamente.');
                elPublicar.disabled = false;
                elPublicar.textContent = 'Publicar';
            });
    }

    // ===== Visualizador em tela cheia =====

    function abrirVisualizador(indexGrupo) {
        grupoAtualIndex = indexGrupo;
        storyAtualIndex = 0;
        elVisualizador.classList.add('aberto');
        document.body.style.overflow = 'hidden';
        montarSegmentosProgresso();
        exibirStoryAtual();
    }

    function fecharVisualizador() {
        clearTimeout(timerAvanco);
        elVisualizador.classList.remove('aberto');
        elVisMidia.innerHTML = '';
        document.body.style.overflow = '';
        grupoAtualIndex = -1;
        carregarStories();
    }

    function montarSegmentosProgresso() {
        const grupo = grupos[grupoAtualIndex];
        elVisProgresso.innerHTML = '';
        if (!grupo) return;
        grupo.stories.forEach(() => {
            const segmento = document.createElement('span');
            segmento.className = 'visualizador-story-segmento';
            segmento.innerHTML = '<span class="visualizador-story-segmento-preenchido"></span>';
            elVisProgresso.appendChild(segmento);
        });
    }

    // 'criado_em' vem do banco como "AAAA-MM-DD HH:MM:SS" em horário de Brasília (conexao.php
    // fixa SET time_zone = '-03:00'), sem informação de fuso -- se só trocasse o espaço por
    // "T" e deixasse o navegador interpretar, cada visitante fora de -03:00 veria um horário
    // relativo errado. Fixando o offset aqui, o cálculo fica correto em qualquer fuso.
    function tempoRelativoStory(criadoEm) {
        if (!criadoEm) return '';
        const data = new Date(String(criadoEm).replace(' ', 'T') + '-03:00');
        if (isNaN(data.getTime())) return '';

        const diffMs = Date.now() - data.getTime();
        const diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1) return 'agora';
        if (diffMin < 60) return diffMin + 'min';
        const diffH = Math.floor(diffMin / 60);
        if (diffH < 24) return diffH + 'h';
        return Math.floor(diffH / 24) + 'd';
    }

    function exibirStoryAtual() {
        clearTimeout(timerAvanco);
        const grupo = grupos[grupoAtualIndex];
        if (!grupo || !grupo.stories[storyAtualIndex]) {
            fecharVisualizador();
            return;
        }

        const story = grupo.stories[storyAtualIndex];
        elVisNome.textContent = grupo.nome || 'Usuário';
        if (elVisTempo) elVisTempo.textContent = tempoRelativoStory(story.criado_em);
        if (elVisExcluir) elVisExcluir.hidden = Number(grupo.id_usuario) !== usuarioLogadoId;
        elVisMidia.innerHTML = '';

        const segmentos = elVisProgresso.querySelectorAll('.visualizador-story-segmento');
        segmentos.forEach((segmento, index) => {
            const preenchido = segmento.querySelector('.visualizador-story-segmento-preenchido');
            preenchido.style.transition = 'none';
            preenchido.style.width = index < storyAtualIndex ? '100%' : '0%';
        });

        const caminhoArquivo = 'uploads/stories/' + String(story.arquivo || '').replace(/^.*[\\/]/, '');
        let duracaoMs = DURACAO_FOTO_MS;

        if (story.tipo_midia === 'video') {
            const video = document.createElement('video');
            video.src = caminhoArquivo;
            video.autoplay = true;
            video.playsInline = true;
            video.muted = false;
            video.controls = false;
            elVisMidia.appendChild(video);
            video.addEventListener('loadedmetadata', () => {
                duracaoMs = Math.min((video.duration || DURACAO_MAX_VIDEO_MS / 1000) * 1000, DURACAO_MAX_VIDEO_MS);
                iniciarProgresso(duracaoMs);
            }, { once: true });
            video.addEventListener('ended', avancarStory, { once: true });
        } else {
            const img = document.createElement('img');
            img.src = caminhoArquivo;
            img.alt = 'Story';
            elVisMidia.appendChild(img);
            iniciarProgresso(duracaoMs);
        }

        marcarComoVista(story.id);
    }

    function iniciarProgresso(duracaoMs) {
        const segmentoAtual = elVisProgresso.querySelectorAll('.visualizador-story-segmento')[storyAtualIndex];
        if (segmentoAtual) {
            const preenchido = segmentoAtual.querySelector('.visualizador-story-segmento-preenchido');
            requestAnimationFrame(() => {
                preenchido.style.transition = 'width ' + duracaoMs + 'ms linear';
                preenchido.style.width = '100%';
            });
        }
        timerAvanco = setTimeout(avancarStory, duracaoMs);
    }

    function avancarStory() {
        const grupo = grupos[grupoAtualIndex];
        if (!grupo) return;
        if (storyAtualIndex < grupo.stories.length - 1) {
            storyAtualIndex += 1;
            exibirStoryAtual();
        } else {
            fecharVisualizador();
        }
    }

    function voltarStory() {
        if (storyAtualIndex > 0) {
            storyAtualIndex -= 1;
            exibirStoryAtual();
        }
    }

    function excluirStoryAtual() {
        const grupo = grupos[grupoAtualIndex];
        const story = grupo && grupo.stories[storyAtualIndex];
        if (!story || Number(grupo.id_usuario) !== usuarioLogadoId) return;

        clearTimeout(timerAvanco);
        if (!window.confirm('Excluir este story?')) {
            exibirStoryAtual();
            return;
        }

        elVisExcluir.disabled = true;
        fetch(URL_API + '?action=excluir_story', {
            method: 'POST',
            headers: cabecalhosFetch({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ id: story.id }),
        })
            .then((res) => res.json())
            .then((res) => {
                elVisExcluir.disabled = false;
                if (!res || !res.sucesso) {
                    window.alert((res && res.mensagem) || 'Não foi possível excluir o story.');
                    exibirStoryAtual();
                    return;
                }
                grupo.stories.splice(storyAtualIndex, 1);
                if (grupo.stories.length === 0) {
                    grupos.splice(grupoAtualIndex, 1);
                    fecharVisualizador();
                    return;
                }
                if (storyAtualIndex >= grupo.stories.length) {
                    storyAtualIndex = grupo.stories.length - 1;
                }
                montarSegmentosProgresso();
                exibirStoryAtual();
            })
            .catch(() => {
                elVisExcluir.disabled = false;
                window.alert('Não foi possível excluir o story.');
                exibirStoryAtual();
            });
    }

    function marcarComoVista(storyId) {
        // Fire-and-forget de propósito: não bloqueia a troca de mídia nem trata falha na UI.
        fetch(URL_API + '?action=marcar_story_vista', {
            method: 'POST',
            headers: cabecalhosFetch({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ story_id: storyId }),
        }).catch(() => {});
    }

    // ===== Eventos =====

    elBtnCriar.addEventListener('click', () => {
        abrirModalUpload();
    });

    elDropzone.addEventListener('click', () => elInputArquivo.click());

    elInputArquivo.addEventListener('change', (evento) => {
        const arquivo = evento.target.files && evento.target.files[0];
        selecionarArquivo(arquivo);
    });

    elFecharUpload.addEventListener('click', fecharModalUpload);
    elCancelarPreview.addEventListener('click', resetarModalUpload);
    elPublicar.addEventListener('click', publicarStory);

    elModalUpload.addEventListener('click', (evento) => {
        if (evento.target === elModalUpload) fecharModalUpload();
    });

    elVisFechar.addEventListener('click', fecharVisualizador);
    if (elVisExcluir) {
        elVisExcluir.addEventListener('click', (evento) => {
            evento.stopPropagation();
            excluirStoryAtual();
        });
    }
    elVisProximo.addEventListener('click', avancarStory);
    elVisAnterior.addEventListener('click', voltarStory);

    elVisualizador.addEventListener('click', (evento) => {
        if (evento.target === elVisualizador || evento.target.id === 'visualizador-story-midia') {
            fecharVisualizador();
        }
    });

    document.addEventListener('keydown', (evento) => {
        if (!elVisualizador.classList.contains('aberto')) return;
        if (evento.key === 'Escape') fecharVisualizador();
        if (evento.key === 'ArrowRight') avancarStory();
        if (evento.key === 'ArrowLeft') voltarStory();
    });

    carregarStories();
})();
