$(function() {
  const $modal = $('#modal-nova-campanha');
  const $form = $('#form-campanha');
  const $feedback = $('#modal-feedback');
  const $btnOpen = $('#btn-nova-campanha');
  const $btnClose = $('#btn-fechar-modal, #btn-cancelar-campanha');

  function openModal() {
    $feedback.hide().text('');
    $form.get(0).reset();
    $modal.css('display', 'flex');
  }
  function closeModal() {
    $modal.hide();
  }

  $btnOpen.on('click', function() { openModal(); });
  $btnClose.on('click', function() { closeModal(); });
  $modal.on('click', function(e) {
    if (e.target === this) { closeModal(); }
  });
  $(document).on('keydown', function(e) {
    if ($modal.is(':visible') && e.key === 'Escape') closeModal();
  });

  $form.on('submit', function(e) {
    e.preventDefault();
    syncAgendamento();
    const dados = $form.serialize();
    const $submit = $form.find('button[type=submit]');
    $submit.prop('disabled', true).text('Enviando...');
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
      $submit.prop('disabled', false).text('Salvar/Enviar');
    });
  });

  function atualizarContagem() {
    const botId = $('#modal-bot_id').val();
    const audiencia = $('#modal-audiencia').val();
    const $cont = $('#contador-destinatarios');
    if (!botId || !audiencia) { $cont.hide(); return; }
    $cont.text('Estimando destinatários...').show();
    $.ajax({
      url: 'remarketing.php',
      method: 'GET',
      data: { action: 'contar_destinatarios', bot_id: botId, audiencia },
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
  $('#modal-mensagem').on('input', function() {
    atualizarContadorMensagem();
  });
  // Inicializa quando abre
  $btnOpen.on('click', atualizarContadorMensagem);
});
