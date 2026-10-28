<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();

// Assistente de campanha em tela cheia (sem a barra lateral), como o editor de fluxos. ?id=N edita um rascunho (ou campanha ainda não enviada).
$id_edicao = max(0, (int) ($_GET['id'] ?? 0));

$ic = static function (string $caminho, string $classe = 'ic'): string {
    return '<svg class="' . $classe . '" viewBox="0 0 24 24" aria-hidden="true">' . $caminho . '</svg>';
};
$I = [
    'megafone'  => '<path d="M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6"></path>',
    'pessoas'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>',
    'mensagem'  => '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
    'cifrao'    => '<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
    'prancheta' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path><rect x="9" y="3" width="6" height="4" rx="1"></rect><path d="M9 14l2 2 4-4"></path>',
    'voltar'    => '<path d="M15 18l-6-6 6-6"></path>',
    'proximo'   => '<path d="M9 18l6-6-6-6"></path>',
    'fechar'    => '<path d="M18 6L6 18M6 6l12 12"></path>',
    'cadeado'   => '<rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>',
    'check'     => '<path d="M20 6L9 17l-5-5"></path>',
    'lapis'     => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path>',
    'alerta'    => '<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path>',
    'mais'      => '<path d="M12 5v14M5 12h14"></path>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $id_edicao > 0 ? 'Editar campanha' : 'Nova campanha'; ?> - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
    <link rel="stylesheet" href="assets/css/remarketing.css?v=<?php echo @filemtime(__DIR__.'/assets/css/remarketing.css'); ?>">
</head>
<body>
<div class="rmw" id="rmw" data-id="<?php echo $id_edicao; ?>">
    <header class="rmw-topo">
        <a class="rmw-ib" href="remarketing" id="rmw-voltar" aria-label="Voltar para as campanhas" title="Voltar"><?php echo $ic($I['voltar']); ?></a>
        <div class="rmw-tit">
            <span class="rmw-eyebrow" id="rmw-eyebrow">Remarketing · 1/5</span>
            <h1 id="rmw-titulo"><?php echo $id_edicao > 0 ? 'Editar campanha' : 'Nova campanha de remarketing'; ?></h1>
            <p>Configure a campanha com segurança, etapa por etapa.</p>
        </div>
        <div class="rmw-topo-dir">
            <button type="button" class="rmw-ib" id="rmw-tema" onclick="alternarTema()" aria-label="Alternar tema" title="Alternar tema"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg></button>
            <a class="rmw-ib" href="remarketing" id="rmw-fechar" aria-label="Fechar o assistente" title="Fechar"><?php echo $ic($I['fechar']); ?></a>
        </div>
        <div class="rmw-topo-barra" id="rmw-topo-barra"></div>
    </header>

    <div class="rmw-corpo">
        <aside class="rmw-mapa" aria-label="Mapa da campanha">
            <span class="rmw-mapa-tit">Mapa da campanha</span>
            <p class="rmw-mapa-sub">Complete as etapas para preparar sua nova campanha.</p>
            <ol class="rmw-passos" id="rmw-passos">
                <li><button type="button" class="rmw-passo" data-passo="1">
                    <span class="rmw-passo-ic"><?php echo $ic($I['megafone']); ?></span><span class="rmw-passo-nome">Campanha e fluxo</span><span class="rmw-passo-seta"><?php echo $ic($I['proximo']); ?></span>
                    <span class="rmw-passo-desc">Dê um nome claro e escolha em qual fluxo a campanha será executada.</span>
                    <span class="rmw-passo-dicas"><span>Use um nome fácil de reconhecer</span><span>Escolha o fluxo que contém os bots da campanha</span></span>
                    <span class="rmw-passo-resumo"></span>
                </button></li>
                <li><button type="button" class="rmw-passo" data-passo="2">
                    <span class="rmw-passo-ic"><?php echo $ic($I['pessoas']); ?></span><span class="rmw-passo-nome">Público</span><span class="rmw-passo-seta"><?php echo $ic($I['proximo']); ?></span>
                    <span class="rmw-passo-desc">Defina quem deve receber a campanha e confira o alcance estimado.</span>
                    <span class="rmw-passo-dicas"><span>Selecione o segmento de leads</span><span>Confira o alcance antes de continuar</span></span>
                    <span class="rmw-passo-resumo"></span>
                </button></li>
                <li><button type="button" class="rmw-passo" data-passo="3">
                    <span class="rmw-passo-ic"><?php echo $ic($I['mensagem']); ?></span><span class="rmw-passo-nome">Mensagem</span><span class="rmw-passo-seta"><?php echo $ic($I['proximo']); ?></span>
                    <span class="rmw-passo-desc">Monte o conteúdo, a mídia e as variantes da comunicação.</span>
                    <span class="rmw-passo-dicas"><span>Escreva a mensagem principal</span><span>Adicione mídia ou variantes se precisar</span></span>
                    <span class="rmw-passo-resumo"></span>
                </button></li>
                <li><button type="button" class="rmw-passo" data-passo="4">
                    <span class="rmw-passo-ic"><?php echo $ic($I['cifrao']); ?></span><span class="rmw-passo-nome">Oferta e entrega</span><span class="rmw-passo-seta"><?php echo $ic($I['proximo']); ?></span>
                    <span class="rmw-passo-desc">Configure preços, destino da entrega, order bump e redirecionamento.</span>
                    <span class="rmw-passo-dicas"><span>Planos e botão redirect são opcionais</span><span>Revise o destino de cada entrega</span></span>
                    <span class="rmw-passo-resumo"></span>
                </button></li>
                <li><button type="button" class="rmw-passo" data-passo="5">
                    <span class="rmw-passo-ic"><?php echo $ic($I['prancheta']); ?></span><span class="rmw-passo-nome">Revisar e salvar</span><span class="rmw-passo-seta"><?php echo $ic($I['proximo']); ?></span>
                    <span class="rmw-passo-desc">Confira a campanha completa antes de criar ou salvar as alterações.</span>
                    <span class="rmw-passo-dicas"><span>Confira público, mensagem e valores</span><span>A campanha é criada como rascunho</span></span>
                    <span class="rmw-passo-resumo"></span>
                </button></li>
            </ol>
            <div class="rmw-progresso">
                <div class="rmw-progresso-l"><span>Progresso</span><b id="rmw-pct">20%</b></div>
                <div class="a-bar"><i id="rmw-barra" style="width:20%"></i></div>
            </div>
        </aside>

        <main class="rmw-conteudo" id="rmw-conteudo">
            <div class="rmw-carregando" id="rmw-carregando">Carregando...</div>

            <!-- 1. Campanha e fluxo -->
            <section class="rmw-etapa" data-etapa="1" hidden>
                <h2>Fluxo da campanha</h2>
                <p class="rmw-sub">Defina o nome e selecione o fluxo.</p>
                <div class="rmw-bloco">
                    <label class="a-fld"><span>Nome da campanha <b class="rmw-obrig">*</b></span>
                        <input type="text" class="a-in" id="rm-nome" maxlength="50" placeholder="Ex.: Recuperação de carrinho — outubro" autocomplete="off">
                    </label>
                    <div class="rmw-contador" id="rm-nome-cont">0/50 caracteres</div>
                </div>
                <div class="rmw-bloco">
                    <h3>Selecionar fluxo <b class="rmw-obrig">*</b></h3>
                    <p class="rmw-sub">Selecione o fluxo cujos bots vão enviar a campanha.</p>
                    <div class="rmw-fluxos" id="rm-fluxos"></div>
                    <div class="rmw-aviso" id="rm-fluxos-aviso" hidden><?php echo $ic($I['alerta']); ?><span></span></div>
                </div>
            </section>

            <!-- 2. Público -->
            <section class="rmw-etapa" data-etapa="2" hidden>
                <h2>Público-alvo</h2>
                <p class="rmw-sub">Selecione quem vai receber a campanha.</p>
                <div class="rmw-bloco">
                    <div class="rmw-alcance">
                        <span class="rmw-alcance-ic"><?php echo $ic($I['pessoas']); ?></span>
                        <div class="rmw-alcance-v"><small>Alcance estimado</small><b id="rm-alcance">—</b></div>
                        <div class="rmw-alcance-fluxo"><small>Fluxo</small><b id="rm-alcance-fluxo">—</b></div>
                    </div>
                </div>
                <div class="rmw-bloco">
                    <h3>Segmentação <b class="rmw-obrig">*</b></h3>
                    <p class="rmw-sub">Período — data de entrada do lead no bot.</p>
                    <div class="rmw-pills" id="rm-periodos" role="group" aria-label="Período de entrada do lead">
                        <button type="button" class="rmw-pill" data-periodo="total">Total</button>
                        <button type="button" class="rmw-pill" data-periodo="hoje">Hoje</button>
                        <button type="button" class="rmw-pill" data-periodo="ontem">Ontem</button>
                        <button type="button" class="rmw-pill" data-periodo="7d">7 dias</button>
                        <button type="button" class="rmw-pill" data-periodo="30d">30 dias</button>
                        <button type="button" class="rmw-pill" data-periodo="custom">Escolher</button>
                    </div>
                    <div class="rmw-datas" id="rm-datas" hidden>
                        <label class="a-fld"><span>De</span><input type="date" class="a-in" id="rm-de"></label>
                        <label class="a-fld"><span>Até</span><input type="date" class="a-in" id="rm-ate"></label>
                    </div>
                </div>
                <div class="rmw-bloco">
                    <div class="rmw-segs" id="rm-segs" role="group" aria-label="Quem recebe">
                        <button type="button" class="rmw-seg" data-seg="todos"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><?php echo $I['pessoas']; ?></svg><span><b>Todos os leads</b><small>Todos os contatos</small></span></button>
                        <button type="button" class="rmw-seg" data-seg="nao_comprou"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg><span><b>Não compraram</b><small>Nunca fizeram compra</small></span></button>
                        <button type="button" class="rmw-seg" data-seg="comprou"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M8 12l3 3 5-6"></path></svg><span><b>Já compraram</b><small>Pagaram ao menos uma vez</small></span></button>
                        <button type="button" class="rmw-seg" data-seg="pix_gerado"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M20 20h.01"></path></svg><span><b>Pix gerado</b><small>Gerou mas não pagou</small></span></button>
                        <button type="button" class="rmw-seg" data-seg="expirados"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18M9.5 14.5l5 5M14.5 14.5l-5 5"></path></svg><span><b>Expirados</b><small>VIP que não renovou</small></span></button>
                        <button type="button" class="rmw-seg" data-seg="membros_ativos"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M19 8v6M22 11h-6"></path></svg><span><b>Membros ativos</b><small>VIP com acesso ativo</small></span></button>
                    </div>
                </div>
            </section>

            <!-- 3. Mensagem -->
            <section class="rmw-etapa" data-etapa="3" hidden>
                <div class="rmw-msg-topo">
                    <div><h2>Conteúdo da campanha</h2><p class="rmw-sub" style="margin-bottom:0">Defina a mídia e a mensagem do disparo.</p></div>
                    <button type="button" class="a-btn" id="rm-add-variante"><?php echo $ic($I['mais']); ?> Adicionar variante (A/B)</button>
                </div>
                <div class="rmw-abas" id="rm-abas" hidden></div>
                <p class="rmw-sub" id="rm-ab-dica" hidden>Cada pessoa recebe uma das variantes, em rodízio. Depois do envio, compare as vendas de cada uma nas estatísticas.</p>
                <div class="rmw-bloco">
                    <h3><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg> Mídia</h3>
                    <p class="rmw-sub">Adicione uma imagem ou um vídeo (opcional). Foto até 5MB (jpg/png) ou vídeo até 20MB (mp4/mov/mkv/webm).</p>
                    <div class="rmw-midia">
                        <label class="rmw-midia-tile" id="rm-midia-tile" tabindex="0">
                            <input type="file" id="rm-midia-arq" accept="image/jpeg,image/png,video/mp4,video/quicktime,video/x-matroska,video/webm" hidden>
                            <span class="rmw-midia-rotulo"><?php echo $ic($I['mais']); ?><br>Adicionar mídia</span>
                        </label>
                        <div class="rmw-midia-acoes"><button type="button" class="a-btn" id="rm-midia-tirar" hidden>Remover mídia</button></div>
                    </div>
                    <div class="rmw-erro" id="rm-midia-erro" hidden></div>
                </div>
                <div class="rmw-bloco">
                    <h3><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><?php echo $I['mensagem']; ?></svg> Mensagem do disparo <b class="rmw-obrig">*</b></h3>
                    <p class="rmw-sub">Suporta variáveis: {nome}, {saudacao} e {usuario}.</p>
                    <textarea class="rmw-texto" id="rm-texto" placeholder="Escreva a mensagem que cada lead vai receber..."></textarea>
                    <div class="rmw-contador"><span id="rm-texto-cont">0</span>/<span id="rm-texto-max">4096</span> caracteres</div>
                    <div class="rmw-vars">Variáveis disponíveis:
                        <button type="button" class="rmw-var" data-var="{nome}">{nome}</button>
                        <button type="button" class="rmw-var" data-var="{saudacao}">{saudacao}</button>
                        <button type="button" class="rmw-var" data-var="{usuario}">{usuario}</button>
                    </div>
                </div>
            </section>

            <!-- 4. Oferta e entrega -->
            <section class="rmw-etapa" data-etapa="4" hidden>
                <h2>Planos &amp; ofertas</h2>
                <p class="rmw-sub">Configure os planos de pagamento e as ofertas adicionais. Tudo aqui é opcional: sem oferta, a campanha envia só a mensagem.</p>
                <div class="rmw-bloco">
                    <div class="rmw-planos-topo">
                        <h3><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5zM3 8v8l9 5 9-5V8"></path></svg> Planos de pagamento</h3>
                        <div class="rmw-planos-btns">
                            <button type="button" class="a-btn" id="rm-plano-fluxo" hidden>Usar plano do fluxo</button>
                            <button type="button" class="a-btn pri" id="rm-plano-novo"><?php echo $ic($I['mais']); ?> Criar novo plano</button>
                            <div class="rmw-menu" id="rm-menu-planos" hidden></div>
                        </div>
                    </div>
                    <div class="rmw-planos" id="rm-planos"></div>
                </div>
                <div class="rmw-bloco rmw-toggle" id="rm-bloco-redirect">
                    <div class="rmw-toggle-topo">
                        <span class="rmw-toggle-ic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"></path></svg></span>
                        <span class="rmw-toggle-tit"><b>Botão redirect</b><small>Leva para o canal de prévias, sem pagamento.</small></span>
                        <button type="button" class="rmw-chave" id="rm-redirect-chave" role="switch" aria-checked="false" aria-label="Ativar botão redirect"></button>
                    </div>
                    <div class="rmw-toggle-corpo" id="rm-redirect-corpo" hidden>
                        <div class="rmw-grade2">
                            <label class="a-fld"><span>Texto do botão</span><input type="text" class="a-in" id="rm-redirect-texto" maxlength="30" value="Ver Prévias"></label>
                            <label class="a-fld"><span>Endereço do redirect <b class="rmw-obrig">*</b></span><input type="url" class="a-in" id="rm-redirect-url" placeholder="https://t.me/+abc123..." autocomplete="off"></label>
                        </div>
                        <small class="a-mut">O botão aparece junto dos planos e leva o lead para o endereço, sem precisar pagar.</small>
                    </div>
                </div>
                <div class="rmw-bloco rmw-toggle" id="rm-bloco-bump">
                    <div class="rmw-toggle-topo">
                        <span class="rmw-toggle-ic"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg></span>
                        <span class="rmw-toggle-tit"><b>Order bump da campanha</b><small>Oferta extra, oferecida antes do Pix. Substitui o order bump padrão do fluxo.</small></span>
                        <button type="button" class="rmw-chave" id="rm-bump-chave" role="switch" aria-checked="false" aria-label="Ativar order bump"></button>
                    </div>
                    <div class="rmw-toggle-corpo" id="rm-bump-corpo" hidden>
                        <div class="rmw-grade2">
                            <label class="a-fld"><span>Nome do order bump <b class="rmw-obrig">*</b></span><input type="text" class="a-in" id="rm-bump-nome" maxlength="60" autocomplete="off"></label>
                            <label class="a-fld"><span>Preço (R$) <b class="rmw-obrig">*</b></span><input type="number" class="a-in" id="rm-bump-valor" min="0" step="0.01" inputmode="decimal"></label>
                        </div>
                        <label class="a-fld"><span>Descrição</span><textarea class="rmw-texto" id="rm-bump-desc" style="min-height:90px" maxlength="500"></textarea></label>
                        <div>
                            <span class="a-lab">Mídia (opcional)</span>
                            <div class="rmw-midia" style="margin-top:8px">
                                <label class="rmw-midia-tile" id="rm-bump-tile" tabindex="0">
                                    <input type="file" id="rm-bump-arq" accept="image/jpeg,image/png,video/mp4,video/quicktime,video/x-matroska,video/webm" hidden>
                                    <span class="rmw-midia-rotulo"><?php echo $ic($I['mais']); ?><br>Adicionar</span>
                                </label>
                                <div class="rmw-midia-acoes"><button type="button" class="a-btn" id="rm-bump-tirar" hidden>Remover mídia</button></div>
                            </div>
                            <div class="rmw-erro" id="rm-bump-erro" hidden></div>
                        </div>
                        <small class="a-mut">O lead escolhe um plano, vê esta oferta e decide se quer o extra somado ao valor do plano.</small>
                    </div>
                </div>
            </section>

            <!-- 5. Revisar e salvar -->
            <section class="rmw-etapa" data-etapa="5" hidden>
                <h2>Revise a campanha</h2>
                <p class="rmw-sub" id="rm-rev-sub">Confira as informações antes de criar o rascunho.</p>
                <div class="rmw-rev" id="rm-rev"></div>
                <div class="rmw-prox"><?php echo $ic($I['alerta']); ?><div><b>Próximo passo</b><span id="rm-prox-texto"></span></div></div>
                <div class="rmw-erro" id="rm-salvar-erro" hidden></div>
            </section>
        </main>
    </div>

    <footer class="rmw-rodape">
        <button type="button" class="a-btn" id="rmw-anterior"><?php echo $ic($I['voltar']); ?> <span id="rmw-anterior-rot">Cancelar</span></button>
        <span class="rmw-rodape-dica" id="rmw-dica"></span>
        <button type="button" class="a-btn pri rmw-fim" id="rmw-proximo"><span id="rmw-proximo-rot">Escolher público</span> <?php echo $ic($I['proximo']); ?></button>
    </footer>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script>window.CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/remarketing_nova.js?v=<?php echo @filemtime(__DIR__ . '/assets/remarketing_nova.js'); ?>"></script>
</body>
</html>
