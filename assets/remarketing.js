$(function() {
  const $modal = $('#modal-nova-campanha');
  const $form = $('#form-campanha');
  const $feedback = $('#modal-feedback');
  const $btn_open = $('#btn-nova-campanha, #btn-nova-campanha-empty');
  const $btn_close = $('#btn-fechar-modal, #btn-cancelar-campanha');

  function openModal() {
    $feedback.hide().text('');
    $form.get(0).reset();
    $('#modal-midia-caminho, #modal-midia-tipo').val('');
    $('#btn-remover-midia').prop('hidden', true);
    $('.aviso-arquivo-invalido').remove();
    atualizarLimiteMensagem();
    $modal.css('display', 'flex');
  }
  function closeModal() {
    $modal.hide();
  }

  $btn_open.on('click', function() { openModal(); });
  $btn_close.on('click', function() { closeModal(); });
  $modal.on('click', function(e) {
    if (e.target === this) { closeModal(); }
  });
  $(document).on('keydown', function(e) {
    if ($modal.is(':visible') && e.key === 'Escape') closeModal();
  });

  function enviarCampanha($submit) {
    syncAgendamento();
    const dados = $form.serialize();
    $.ajax({
      url: 'remarketing.php',
      method: 'POST',
      data: dados,
      dataType: 'json',
      headers: { 'Accept': 'application/json' }
    }).done(function(resp) {
      const msg = (resp && resp.mensagem) ? resp.mensagem : 'Operação concluída.';
      $feedback.text(msg).show();
      if (resp && resp.sucesso) {
        setTimeout(function() {
          closeModal();
          window.location.reload();
        }, 800);
      }
    }).fail(function(xhr) {
      const msg = (xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao criar campanha.';
      $feedback.text(msg).show();
    }).always(function() {
      $submit.prop('disabled', false).text('Agendar');
    });
  }

  $form.on('submit', function(e) {
    e.preventDefault();
    const $submit = $form.find('button[type=submit]');
    const arquivo = ($('#modal-midia').get(0) || {}).files;

    $feedback.hide().text('');
    $submit.prop('disabled', true).text('Enviando...');

    if (arquivo && arquivo.length > 0) {
      const dadosArquivo = new FormData();
      dadosArquivo.append('midia', arquivo[0]);
      // Este form não usa $.ajaxSetup global de CSRF (o form principal manda o token
      // como campo escondido via serialize()) -- FormData é um POST separado, então
      // precisa levar o token explicitamente, senão cai em 403 (verificarCsrf()).
      dadosArquivo.append('csrf_token', $form.find('input[name=csrf_token]').val());
      $submit.text('Enviando mídia...');
      $.ajax({
        url: 'api.php?action=upload_midia_remarketing',
        method: 'POST',
        data: dadosArquivo,
        processData: false,
        contentType: false
      }).done(function(resp) {
        if (!resp || !resp.sucesso) {
          $feedback.text((resp && resp.mensagem) || 'Falha ao enviar o arquivo.').show();
          $submit.prop('disabled', false).text('Agendar');
          return;
        }
        $('#modal-midia-caminho').val(resp.caminho);
        $('#modal-midia-tipo').val(resp.tipo);
        $submit.text('Enviando...');
        enviarCampanha($submit);
      }).fail(function(xhr) {
        const msg = (xhr.responseJSON && xhr.responseJSON.mensagem) || 'Falha ao enviar o arquivo.';
        $feedback.text(msg).show();
        $submit.prop('disabled', false).text('Agendar');
      });
    } else {
      enviarCampanha($submit);
    }
  });

  // Cancelar uma campanha que ainda está pendente (só ela; depois que a cron começa não dá mais).
  $(document).on('click', '.btn-cancelar-campanha-lista', function() {
    const $btn = $(this);
    if (!confirm('Cancelar esta campanha? Ela não será enviada.')) return;
    $btn.prop('disabled', true);
    $.ajax({
      url: 'remarketing.php',
      method: 'POST',
      data: { acao: 'cancelar_campanha', id: $btn.data('id'), csrf_token: $form.find('input[name=csrf_token]').val() },
      dataType: 'json',
      headers: { 'Accept': 'application/json' }
    }).done(function(resp) {
      if (resp && resp.sucesso) { window.location.reload(); return; }
      alert((resp && resp.mensagem) || 'Não foi possível cancelar.');
      $btn.prop('disabled', false);
    }).fail(function() {
      alert('Não foi possível cancelar agora. Tente de novo.');
      $btn.prop('disabled', false);
    });
  });

  function atualizarContagem() {
    const bot_id = $('#modal-bot_id').val();
    const audiencia = $('#modal-audiencia').val();
    const $cont = $('#contador-destinatarios');
    if (!bot_id || !audiencia) { $cont.hide(); return; }
    $cont.text('Estimando destinatários...').show();
    $.ajax({
      url: 'remarketing.php',
      method: 'GET',
      data: { action: 'contar_destinatarios', bot_id: bot_id, audiencia },
      dataType: 'json',
      headers: { 'Accept': 'application/json' }
    }).done(function(resp) {
      if (!resp || resp.sucesso === false) {
        $cont.text(resp && resp.mensagem ? resp.mensagem : 'Não foi possível estimar.');
        return;
      }
      $cont.text('Destinatários estimados: ' + (resp.total || 0));
    }).fail(function() {
      $cont.text('Falha ao estimar destinatários.');
    });
  }
  $('#modal-bot_id, #modal-audiencia').on('change', atualizarContagem);
  $('#btn-nova-campanha, #btn-nova-campanha-empty').on('click', function() {
    setTimeout(atualizarContagem, 100);
  });

  function syncAgendamento() {
    const data = $('#modal-data').val();
    const hora = $('#modal-hora').val();
    if (data && hora) {
      $('#modal-agendado_em').val(data + ' ' + hora);
    } else {
      $('#modal-agendado_em').val('');
    }
  }
  $('#modal-data, #modal-hora').on('change', syncAgendamento);

  function atualizarContadorMensagem() {
    const len = ($('#modal-mensagem').val() || '').length;
    $('#contador-mensagem').text(len);
  }
  function atualizarLimiteMensagem() {
    const temMidia = (($('#modal-midia').get(0) || {}).files || []).length > 0;
    $('#limite-mensagem').text(temMidia ? '1024' : '4096');
  }
  $('#modal-mensagem').on('input', function() {
    atualizarContadorMensagem();
  });
  $('#modal-midia').on('change', function() {
    // Arquivo trocado/removido depois de já ter subido um -- limpa o caminho salvo,
    // senão o submit usaria uma mídia diferente da que aparece selecionada no campo.
    $('#modal-midia-caminho, #modal-midia-tipo').val('');
    $('#btn-remover-midia').prop('hidden', !(this.files && this.files.length));
    atualizarLimiteMensagem();
  });
  $('#btn-remover-midia').on('click', function() {
    $('.aviso-arquivo-invalido').remove();
    $('#modal-midia').val('').trigger('change');
  });
  // Inicializa quando abre
  $btn_open.on('click', function() {
    atualizarContadorMensagem();
    atualizarLimiteMensagem();
  });
});
