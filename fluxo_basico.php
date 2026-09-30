<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fluxo Guiado - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Fluxo Guiado</h1>
                <p>Preencha as seções — sem montar fluxograma.</p>
            </div>
            <div class="acoes-cabecalho">
                <span class="basico-status" id="status-salvo">Tudo salvo</span>
                <label class="basico-interruptor" title="Desligado: o bot responde que o atendimento está indisponível">
                    <input type="checkbox" id="fluxo-ativo" checked> <span id="fluxo-ativo-rotulo">Ativo</span>
                </label>
                <a class="botao" href="fluxos">Voltar</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <input type="hidden" id="id-fluxo">

        <div class="basico-layout">
            <nav class="basico-nav" id="basico-nav">
                <div class="basico-nav-grupo">Estrutura</div>
                <button type="button" class="basico-nav-item ativo" data-secao="geral">Geral</button>
                <button type="button" class="basico-nav-item" data-secao="bots">Bots</button>
                <button type="button" class="basico-nav-item" data-secao="boasvindas">Boas-vindas</button>
                <button type="button" class="basico-nav-item" data-secao="planos">Planos</button>
                <div class="basico-nav-grupo">Conversão</div>
                <button type="button" class="basico-nav-item" data-secao="upsell">Upsell</button>
                <button type="button" class="basico-nav-item" data-secao="downsell">Downsell</button>
                <button type="button" class="basico-nav-item" data-secao="orderbump">Order Bump</button>
                <div class="basico-nav-grupo">Operação</div>
                <button type="button" class="basico-nav-item" data-secao="pagamentos">Pagamentos</button>
                <button type="button" class="basico-nav-item" data-secao="suporte">Suporte</button>
            </nav>

            <div class="basico-conteudo" id="basico-conteudo">
                <section class="basico-secao ativa" data-secao="geral">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Geral</h2></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label for="nome-fluxo">Nome do fluxo</label>
                                <input type="text" id="nome-fluxo" placeholder="Ex.: Funil VIP">
                            </div>
                            <div class="campo">
                                <label for="descricao-fluxo">Descrição</label>
                                <input type="text" id="descricao-fluxo" placeholder="Uso interno do fluxo">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="bots">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Resumo</h2></div>
                        <div class="basico-resumo">
                            <div class="basico-resumo-item"><span>Leads</span><strong id="resumo-leads">0</strong></div>
                            <div class="basico-resumo-item"><span>VIPs (pagantes)</span><strong id="resumo-vips">0</strong></div>
                            <div class="basico-resumo-item"><span>Receita</span><strong id="resumo-receita">R$ 0,00</strong></div>
                        </div>
                    </div>
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Bots vinculados</h2></div>
                        <p class="texto-ajuda" id="bots-aviso-salvar">Salve o fluxo primeiro para poder vincular bots.</p>
                        <div id="lista-bots-vinculados"></div>
                        <div class="basico-vincular" id="area-vincular" hidden>
                            <select id="select-bot-vincular"></select>
                            <button type="button" class="botao botao-claro" id="btn-vincular-bot">Vincular bot</button>
                        </div>
                        <p class="texto-ajuda" style="margin-top:10px;">Vincular um bot a este fluxo tira ele do fluxo em que estava.</p>
                    </div>
                </section>

                <section class="basico-secao" data-secao="boasvindas">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Boas-vindas</h2></div>
                        <p class="texto-ajuda">Mensagem enviada assim que alguém dá /start no bot.</p>
                        <div class="campo">
                            <label for="bv-mensagem">Mensagem inicial</label>
                            <textarea id="bv-mensagem" rows="3" placeholder="Olá! Bem-vindo(a)..."></textarea>
                        </div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label>Mídia (opcional)</label>
                                <div class="area-previa-basico" id="bv-midia-previa" title="Clique para adicionar/trocar">Sem mídia</div>
                                <input type="file" id="bv-midia-arquivo" accept="image/*,video/*" style="display:none">
                            </div>
                            <div class="campo">
                                <label for="bv-cta">Texto do botão (CTA)</label>
                                <input type="text" id="bv-cta" placeholder="Ver Planos" value="Ver Planos">
                            </div>
                            <div class="campo">
                                <label for="bv-cta-cor">Cor do botão</label>
                                <select id="bv-cta-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="planos">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Planos</h2></div>
                        <p class="texto-ajuda">O cliente escolhe um destes planos pra pagar. Pelo menos 1 é obrigatório pra vender.</p>
                        <div id="lista-planos"></div>
                        <button type="button" class="botao botao-claro" id="btn-adicionar-plano">+ Adicionar plano</button>
                    </div>
                </section>

                <section class="basico-secao" data-secao="upsell">
                    <div class="painel" data-oferta="upsell">
                        <div class="painel-cabecalho"><h2>Upsell</h2></div>
                        <p class="texto-ajuda">Depois que o cliente escolhe um plano, oferece um plano melhor antes de gerar o Pix.</p>
                        <div class="linha-flex"><input type="checkbox" class="of-ativo"> <label style="margin:0">Ativar upsell</label></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Plano oferecido</label><select class="of-plano"></select></div>
                            <div class="campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Que tal levar o plano completo com desconto?"></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="downsell">
                    <div class="painel" data-oferta="downsell">
                        <div class="painel-cabecalho"><h2>Downsell</h2></div>
                        <p class="texto-ajuda">Se o cliente recusar o upsell (ou não houver upsell), oferece uma opção mais barata.</p>
                        <div class="linha-flex"><input type="checkbox" class="of-ativo"> <label style="margin:0">Ativar downsell</label></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Plano oferecido</label><select class="of-plano"></select></div>
                            <div class="campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Espera! Tenho uma condição especial pra você."></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="orderbump">
                    <div class="painel" data-oferta="order_bump">
                        <div class="painel-cabecalho"><h2>Order Bump</h2></div>
                        <p class="texto-ajuda">Último passo antes do Pix: oferece um extra por um valor adicional, somado ao total.</p>
                        <div class="linha-flex"><input type="checkbox" class="of-ativo"> <label style="margin:0">Ativar order bump</label></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Nome do extra</label><input type="text" class="of-nome" placeholder="Ex.: Pack bônus"></div>
                            <div class="campo"><label>Valor extra (R$)</label><input type="number" min="0" step="0.01" class="of-valor-extra" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Quer adicionar o bônus por só mais R$ 9,90?"></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="pagamentos">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Pagamentos</h2></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label for="pg-msg-instrucoes">Mensagem no Pix gerado</label>
                                <textarea id="pg-msg-instrucoes" rows="2">Copie o código Pix abaixo e pague no app do seu banco.</textarea>
                            </div>
                            <div class="campo">
                                <label for="pg-msg-confirmado">Mensagem no pagamento aprovado</label>
                                <textarea id="pg-msg-confirmado" rows="2">Pagamento confirmado! Seu acesso foi liberado.</textarea>
                            </div>
                        </div>
                        <div class="linha-flex">
                            <input type="checkbox" id="pg-mostrar-copiar" checked> <label style="margin:0">Botão Copiar código</label>
                        </div>
                        <div class="linha-flex">
                            <input type="checkbox" id="pg-mostrar-confirmar" checked> <label style="margin:0">Botão "Já fiz o pagamento"</label>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="suporte">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Suporte</h2></div>
                        <p class="texto-ajuda">Se preencher, a lista de planos ganha um botão "Falar com o suporte" que abre esse contato no Telegram.</p>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label for="suporte-usuario">Usuário do Telegram</label>
                                <input type="text" id="suporte-usuario" placeholder="@seususuario" maxlength="60">
                            </div>
                            <div class="campo">
                                <label for="suporte-cor">Cor do botão</label>
                                <select id="suporte-cor">
                                    <option value="">Padrão do Telegram</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="acoes-cabecalho" style="justify-content:flex-end; margin-bottom:24px;">
                    <button class="botao" id="btn-excluir-fluxo-basico" style="display:none;">Excluir</button>
                    <button class="botao botao-primario" id="btn-salvar-fluxo-basico">Salvar fluxo</button>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/edicao_fluxo_basico.js?v=<?php echo @filemtime(__DIR__ . '/assets/edicao_fluxo_basico.js'); ?>"></script>
</body>
</html>
