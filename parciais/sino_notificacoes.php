<?php
declare(strict_types=1);

$caminho_base = $caminho_base ?? '';
?>
<div id="sino-notificacoes-raiz" class="sino-notificacoes-wrap" hidden
     data-url-lista="<?php echo htmlspecialchars($caminho_base . 'ajax/notificacoes.php'); ?>"
     data-url-ler="<?php echo htmlspecialchars($caminho_base . 'ajax/marcar_notificacao_lida.php'); ?>">
    <button type="button" class="sino-notificacoes" aria-label="Notificações" aria-expanded="false" aria-haspopup="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <span class="sino-badge" hidden>0</span>
    </button>
    <div class="sino-painel" hidden>
        <div class="sino-painel-cabecalho">
            <h3>Notificações</h3>
            <span class="sino-ao-vivo"><span class="ponto-vivo"></span> AO VIVO</span>
        </div>
        <div class="sino-lista"></div>
        <button type="button" class="sino-marcar-todas">Marcar todas como lidas</button>
    </div>
</div>
