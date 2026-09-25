<?php
// Feature isolada: se algo aqui quebrar, é só um <div> vazio no topo do dashboard --
// não toca em fluxo de venda/lead/pagamento. Ver funcoes/stories.php e
// anotacoes/pendente/plano-recursos-sharkbot.md.
?>
<div class="barra-stories-painel">
    <div class="barra-stories" id="barra-stories" data-usuario-id="<?php echo (int) ($_SESSION['usuario_id'] ?? 0); ?>">
        <button type="button" class="story-item story-item-criar" id="story-btn-criar">
            <span class="story-anel story-anel-criar"><span class="story-icone-mais">+</span></span>
            <span class="story-nome">Criar</span>
        </button>
    </div>
</div>

<input type="file" id="story-input-arquivo" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" hidden>

<div class="sobreposicao-modal" id="modal-story-upload">
    <div class="modal-gateway" style="max-width:380px;">
        <div class="cabecalho-modal">
            <div class="titulo-modal">Novo story</div>
            <button type="button" class="fechar-modal" id="story-fechar-upload">&times;</button>
        </div>
        <div class="corpo-modal">
            <div class="story-dropzone" id="story-dropzone">
                <span>Clique para selecionar</span>
                <small>Foto (máx. 5MB) ou vídeo (máx. 20MB · 30s)</small>
            </div>
            <div class="story-preview" id="story-preview" style="display:none;"></div>
            <p class="story-erro" id="story-upload-erro" style="display:none;"></p>
            <div class="linha-acoes" id="story-upload-acoes" style="display:none;margin-top:16px;">
                <button type="button" class="botao" id="story-cancelar-preview">Cancelar</button>
                <button type="button" class="botao botao-primario" id="story-publicar">Publicar</button>
            </div>
        </div>
    </div>
</div>

<div class="visualizador-story" id="visualizador-story">
    <div class="visualizador-story-conteudo">
        <div class="visualizador-story-progresso" id="visualizador-story-progresso"></div>
        <div class="visualizador-story-cabecalho">
            <div class="visualizador-story-info">
                <span class="visualizador-story-nome" id="visualizador-story-nome"></span>
                <span class="visualizador-story-tempo" id="visualizador-story-tempo"></span>
            </div>
            <div class="visualizador-story-acoes">
                <button type="button" class="visualizador-story-excluir" id="visualizador-story-excluir" hidden>Excluir</button>
                <button type="button" class="visualizador-story-fechar" id="visualizador-story-fechar">&times;</button>
            </div>
        </div>
        <div class="visualizador-story-midia" id="visualizador-story-midia"></div>
        <button type="button" class="visualizador-story-nav visualizador-story-anterior" id="visualizador-story-anterior" aria-label="Anterior">&#8249;</button>
        <button type="button" class="visualizador-story-nav visualizador-story-proximo" id="visualizador-story-proximo" aria-label="Próximo">&#8250;</button>
    </div>
</div>
