(function ($) {
    const url_api = 'api.php';

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), 3000);
    }

    function formatarDataFluxo(valor) {
        if (!valor) return '-';
        const data = new Date(String(valor).replace(' ', 'T'));
        if (Number.isNaN(data.getTime())) return String(valor);
        const p = function (n) { return String(n).padStart(2, '0'); };
        return p(data.getDate()) + '/' + p(data.getMonth() + 1) + '/' + data.getFullYear()
            + ' ' + p(data.getHours()) + ':' + p(data.getMinutes());
    }

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    const icons = {
        edit: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
        trash: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>'
    };

    const miniatura_fluxo = '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="var(--m)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="9" width="6.5" height="6" rx="1.5"></rect><rect x="15.5" y="3" width="7" height="6" rx="1.5"></rect><rect x="15.5" y="15" width="7" height="6" rx="1.5"></rect><path d="M8 12h3.75M11.75 6v12M11.75 6h3.75M11.75 18h3.75"></path></svg>';

    let limite_fluxos = 50;

    // Contador no botão de criar ("+ Novo Fluxo (2/50)"); no limite, criar e importar ficam desativados.
    function atualizarContadorFluxos(total) {
        const no_limite = total >= limite_fluxos;
        const aviso = 'Limite de ' + limite_fluxos + ' fluxos atingido. Exclua um para criar ou importar outro.';
        $('#btn-abrir-escolha-modo').text('+ Novo Fluxo (' + total + '/' + limite_fluxos + ')');
        $('#btn-abrir-escolha-modo, #btn-importar-fluxo-lista').prop('disabled', no_limite).attr('title', no_limite ? aviso : null);
    }

    function base64Utf8(obj) {
        const bytes = new TextEncoder().encode(JSON.stringify(obj));
        let bin = '';
        for (let i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin);
    }

    function importarFluxoDeArquivo(arquivo) {
        const leitor = new FileReader();
        leitor.onload = function (ev) {
            let json;
            try {
                json = JSON.parse(ev.target.result);
            } catch (err) {
                exibirAviso('Arquivo JSON inválido.', 'erro');
                return;
            }
            if (!json || typeof json !== 'object' || !json.dados_fluxograma) {
                exibirAviso('Esse arquivo não é um fluxo exportado.', 'erro');
                return;
            }
            $.ajax({
                url: url_api + '?action=importar_fluxo',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    nome: json.nome,
                    descricao: json.descricao,
                    link_suporte: json.link_suporte || '',
                    modo: json.modo || '',
                    dados_fluxograma_b64: base64Utf8(json.dados_fluxograma)
                })
            }).done(function (resp) {
                if (!resp.sucesso) {
                    exibirAviso(resp.mensagem || 'Erro ao importar fluxo.', 'erro');
                    return;
                }
                exibirAviso('Fluxo importado com sucesso.');
                carregarFluxos();
            }).fail(function (xhr) {
                exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Falha ao importar fluxo.', 'erro');
            });
        };
        leitor.readAsText(arquivo);
    }

    function carregarFluxos() {
        const $list = $('#lista-fluxos');
        $list.html('<div class="estado-vazio">Carregando...</div>');

        $.getJSON(url_api + '?action=listar_fluxos')
            .done(function (response) {
                if (!response.sucesso) {
                    $list.html(`<div class="estado-vazio erro">${escaparHtml(response.mensagem || 'Erro ao carregar fluxos.')}</div>`);
                    return;
                }

                const fluxos = response.fluxos || [];
                limite_fluxos = Number(response.limite_fluxos) || limite_fluxos;
                atualizarContadorFluxos(fluxos.length);
                $list.empty();

                if (!fluxos.length) {
                    $list.html('<div class="estado-vazio">Nenhum fluxo encontrado. Crie um novo!</div>');
                    return;
                }

                fluxos.forEach(function (flow) {
                    const eh_basico = flow.modo === 'basico';
                    const pagina_editar = eh_basico ? 'fluxo_basico' : 'fluxo';
                    const rotulo_modo = eh_basico ? 'Guiado' : 'Editor Visual';
                    $list.append(`
                        <div class="cartao-bot-item cartao-fluxo">
                            <div class="miniatura-fluxo">${miniatura_fluxo}</div>
                            <div class="cartao-cabecalho">
                                <h3>${escaparHtml(flow.nome || 'Sem nome')}</h3>
                                <span class="badge-modo-fluxo${eh_basico ? ' basico' : ' visual'}">${rotulo_modo}</span>
                            </div>
                            <div class="cartao-corpo">
                                <p>${escaparHtml(flow.descricao || 'Sem descrição')}</p>
                                <p class="texto-suave mono">Atualizado: ${escaparHtml(formatarDataFluxo(flow.atualizado_em))}</p>
                            </div>
                            <div class="cartao-acoes">
                                <a href="${pagina_editar}?id=${encodeURIComponent(flow.id)}" class="btn-icon editar" title="Editar fluxo" aria-label="Editar fluxo">${icons.edit}</a>
                                <button type="button" class="btn-icon excluir btn-excluir-fluxo" data-id="${escaparHtml(flow.id)}" title="Excluir">${icons.trash}</button>
                            </div>
                        </div>
                    `);
                });
            })
            .fail(function () {
                $list.html('<div class="estado-vazio erro">Não foi possível carregar os fluxos.</div>');
            });
    }

    function excluirFluxo(id) {
        if (!confirm('Tem certeza que deseja excluir este fluxo? Bots conectados a ele podem parar de funcionar.')) {
            return;
        }

        $.ajax({
            url: url_api + '?action=excluir_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (response) {
            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Erro ao excluir fluxo.', 'erro');
                return;
            }
            exibirAviso('Fluxo excluído com sucesso.');
            carregarFluxos();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir fluxo.', 'erro');
        });
    }

    $(function() {
        carregarFluxos();

        $(document).on('click', '.btn-excluir-fluxo', function() {
            const id = $(this).data('id');
            excluirFluxo(id);
        });

        $('#btn-importar-fluxo-lista').on('click', function () {
            $('#arquivo-importar-fluxo-lista').click();
        });
        $('#arquivo-importar-fluxo-lista').on('change', function (e) {
            const arquivo = e.target.files && e.target.files[0];
            if (arquivo) importarFluxoDeArquivo(arquivo);
            $(this).val('');
        });

        $('#btn-abrir-escolha-modo').on('click', function () {
            $('#modal-escolha-modo').addClass('aberto');
        });
        $('#btn-fechar-escolha-modo, #modal-escolha-modo').on('click', function (e) {
            if (e.target === this) {
                $('#modal-escolha-modo').removeClass('aberto');
            }
        });
    });

})(jQuery);
