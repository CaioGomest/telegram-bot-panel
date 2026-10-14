<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/icones.php';
bloquearAdmin();
$icone_cadeado = '<svg class="editor-fluxo-cadeado" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
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
<div class="editor-fluxo" id="editor-fluxo">
    <header class="editor-fluxo-topo">
        <div class="editor-fluxo-topo-esq">
            <a class="editor-fluxo-voltar" href="fluxos">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Fluxos
            </a>
            <div class="editor-fluxo-titulo">
                <div class="editor-fluxo-titulo-linha">
                    <h1 id="titulo-fluxo">Novo fluxo</h1>
                    <input type="text" id="nome-fluxo" class="editor-fluxo-nome-input" placeholder="Nome do fluxo" maxlength="80" hidden>
                    <button type="button" class="editor-fluxo-icone-btn" id="btn-editar-nome" aria-label="Editar nome do fluxo">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    </button>
                </div>
                <span class="editor-fluxo-sub">Editor de fluxo</span>
            </div>
        </div>
        <div class="editor-fluxo-topo-dir">
            <span class="basico-status" id="status-salvo">Tudo salvo</span>
            <label class="basico-interruptor" title="Desligado: o bot responde que o atendimento está indisponível">
                <input type="checkbox" id="fluxo-ativo" checked> <span id="fluxo-ativo-rotulo">Ativo</span>
            </label>
            <button type="button" class="btn-icon excluir" id="btn-excluir-fluxo-basico" style="display:none;" title="Excluir fluxo" aria-label="Excluir fluxo"><?php echo iconeAcao('excluir'); ?></button>
            <button class="botao botao-primario" id="btn-salvar-fluxo-basico">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8"/></svg>
                Salvar alterações
            </button>
        </div>
    </header>

    <input type="hidden" id="id-fluxo">

    <div class="editor-fluxo-corpo">
        <button type="button" class="secao-atual-mobile" id="secao-atual" aria-haspopup="dialog" aria-controls="folha-secoes">
            <span class="secao-atual-icone" id="secao-atual-icone"></span>
            <span class="secao-atual-texto"><small>Seção atual</small><strong id="secao-atual-nome">Bots</strong></span>
            <span class="secao-atual-posicao" id="secao-atual-posicao">1 de 11</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <nav class="basico-nav" id="basico-nav">
            <div class="basico-nav-topo">
                <div><span class="basico-nav-titulo">Navegação</span><span class="basico-nav-contagem" id="nav-contagem">1 de 11 seções</span></div>
                <button type="button" class="editor-fluxo-icone-btn" id="btn-recolher-nav" aria-label="Recolher navegação">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
                </button>
            </div>
            <div class="basico-nav-grupo">Estrutura</div>
            <button type="button" class="basico-nav-item ativo" data-secao="bots" title="Bots"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/></svg><span class="basico-nav-rotulo">Bots</span></button>
            <button type="button" class="basico-nav-item" data-secao="boasvindas" title="Boas-vindas"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span class="basico-nav-rotulo">Boas-vindas</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="planos" title="Planos"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg><span class="basico-nav-rotulo">Planos</span><?php echo $icone_cadeado; ?></button>
            <div class="basico-nav-grupo">Conversão</div>
            <button type="button" class="basico-nav-item" data-secao="upsell" title="Upsell"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8M15 7h6v6"/></svg><span class="basico-nav-rotulo">Upsell</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="downsell" title="Downsell"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l6 6 4-4 8 8M15 17h6v-6"/></svg><span class="basico-nav-rotulo">Downsell</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="orderbump" title="Order Bump"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l1 13H5zM9 7a3 3 0 0 1 6 0"/></svg><span class="basico-nav-rotulo">Order Bump</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="packs" title="Packs"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5zM3 8v8l9 5 9-5V8"/></svg><span class="basico-nav-rotulo">Packs</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="previas" title="Prévias"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 1-9z"/></svg><span class="basico-nav-rotulo">Prévias</span><?php echo $icone_cadeado; ?></button>
            <div class="basico-nav-grupo">Operação</div>
            <button type="button" class="basico-nav-item" data-secao="pagamentos" title="Pagamentos"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><span class="basico-nav-rotulo">Pagamentos</span><?php echo $icone_cadeado; ?></button>
            <button type="button" class="basico-nav-item" data-secao="assinatura" title="Assinatura"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M3 21v-5h5"/></svg><span class="basico-nav-rotulo">Assinatura</span><?php echo $icone_cadeado; ?></button>
            <div class="basico-nav-grupo">Análise e ajustes</div>
            <button type="button" class="basico-nav-item" data-secao="conversoes" title="Conversões"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></svg><span class="basico-nav-rotulo">Conversões</span><?php echo $icone_cadeado; ?></button>
            <div class="basico-nav-aviso" id="nav-aviso">Salve o fluxo em <strong>Bots</strong> para liberar as demais seções.</div>
        </nav>

        <div class="basico-conteudo" id="basico-conteudo">
            <section class="basico-secao ativa" data-secao="bots">
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Resumo do fluxo</h2></div>
                    <div class="basico-resumo">
                        <div class="basico-resumo-item"><span>Leads</span><strong id="resumo-leads">0</strong></div>
                        <div class="basico-resumo-item"><span>VIPs</span><strong id="resumo-vips">0</strong></div>
                        <div class="basico-resumo-item"><span>Receita</span><strong id="resumo-receita">R$ 0,00</strong></div>
                    </div>
                </div>
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Bots vinculados</h2><span class="texto-ajuda" id="bots-contagem">0/10 bots</span></div>
                    <p class="texto-ajuda">Gerencie os bots que executam este fluxo.</p>
                    <p class="texto-ajuda" id="bots-aviso-salvar">Salve o fluxo primeiro para poder vincular bots.</p>
                    <div id="lista-bots-vinculados"></div>
                    <div class="basico-vincular" id="area-vincular" hidden>
                        <select id="select-bot-vincular" aria-label="Selecione um bot para adicionar"></select>
                        <button type="button" class="editor-fluxo-icone-btn quadrado" id="btn-atualizar-bots" aria-label="Atualizar lista de bots">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M3 21v-5h5"/></svg>
                        </button>
                    </div>
                    <p class="texto-ajuda" style="margin-top:10px;">Vincular um bot a este fluxo tira ele do fluxo em que estava (máx. 10). <a href="bots" class="editor-fluxo-link">Criar novo bot</a></p>
                </div>
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Descrição</h2></div>
                    <div class="campo">
                        <label for="descricao-fluxo">Uso interno do fluxo</label>
                        <input type="text" id="descricao-fluxo" placeholder="Ex.: Funil VIP">
                    </div>
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
                                    <option value="">Padrão</option>
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
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar upsell</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Plano oferecido</label><select class="of-plano"></select></div>
                            <div class="campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Que tal levar o plano completo com desconto?"></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="downsell">
                    <div class="painel" data-oferta="downsell">
                        <div class="painel-cabecalho"><h2>Downsell</h2></div>
                        <p class="texto-ajuda">Se o cliente recusar o upsell (ou não houver upsell), oferece uma opção mais barata.</p>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar downsell</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Plano oferecido</label><select class="of-plano"></select></div>
                            <div class="campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Espera! Tenho uma condição especial pra você."></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="orderbump">
                    <div class="painel" data-oferta="order_bump">
                        <div class="painel-cabecalho"><h2>Order Bump</h2></div>
                        <p class="texto-ajuda">Último passo antes do Pix: oferece um extra por um valor adicional, somado ao total.</p>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar order bump</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Nome do extra</label><input type="text" class="of-nome" placeholder="Ex.: Pack bônus"></div>
                            <div class="campo"><label>Valor extra (R$)</label><input type="number" min="0" step="0.01" class="of-valor-extra" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Quer adicionar o bônus por só mais R$ 9,90?"></textarea></div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Botão aceitar</label><input type="text" class="of-aceitar" placeholder="Sim, quero!"></div>
                            <div class="campo"><label>Cor do botão aceitar</label>
                                <select class="of-aceitar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
                            <div class="campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo"><label>Cor do botão recusar</label>
                                <select class="of-recusar-cor">
                                    <option value="">Padrão</option>
                                    <option value="primary">Azul</option>
                                    <option value="success">Verde</option>
                                    <option value="danger">Vermelho</option>
                                </select>
                            </div>
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
                            <input type="checkbox" id="pg-mostrar-qrcode" checked> <label style="margin:0">Enviar também o QR Code (foto)</label>
                        </div>
                        <div class="linha-flex">
                            <input type="checkbox" id="pg-mostrar-confirmar" checked> <label style="margin:0">Botão "Já fiz o pagamento"</label>
                        </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="conversoes">
                <div class="conv-filtros" id="conv-filtros">
                    <button type="button" class="conv-filtro" data-periodo="hoje">Hoje</button>
                    <button type="button" class="conv-filtro" data-periodo="7">7 dias</button>
                    <button type="button" class="conv-filtro" data-periodo="30">30 dias</button>
                    <button type="button" class="conv-filtro ativo" data-periodo="tudo">Tudo</button>
                </div>
                <div class="painel conv-vazio" id="conv-vazio" hidden>
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></svg>
                    <strong>Nenhuma venda registrada neste fluxo ainda.</strong>
                    <span>Os dados aparecerão quando houver pagamentos.</span>
                </div>
                <div id="conv-conteudo" hidden>
                    <div class="conv-cartoes">
                        <div class="basico-resumo-item"><span>Leads</span><strong id="conv-leads">0</strong></div>
                        <div class="basico-resumo-item"><span>Pix gerados</span><strong id="conv-gerados">0</strong></div>
                        <div class="basico-resumo-item"><span>Vendas pagas</span><strong id="conv-pagos">0</strong></div>
                        <div class="basico-resumo-item"><span>Receita</span><strong id="conv-receita">R$ 0,00</strong></div>
                        <div class="basico-resumo-item"><span>Ticket médio</span><strong id="conv-ticket">R$ 0,00</strong></div>
                        <div class="basico-resumo-item"><span>Lead → Pago</span><strong id="conv-taxa-lead-pago">0%</strong></div>
                    </div>
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Funil de vendas</h2></div>
                        <div class="conv-funil" id="conv-funil"></div>
                        <p class="texto-ajuda" id="conv-funil-nota"></p>
                    </div>
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Vendas por dia</h2></div>
                        <div id="conv-dias"></div>
                    </div>
                </div>
            </section>
            <section class="basico-secao" data-secao="previas">
                <div class="painel" data-previas>
                    <div class="painel-cabecalho"><h2>Prévias que somem</h2></div>
                    <p class="texto-ajuda">O lead clica, recebe as mídias protegidas e elas se apagam sozinhas depois do tempo escolhido.</p>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pv-ativo"> <span>Ativar prévias</span></label>
                    <div class="of-detalhes" id="pv-detalhes" hidden>
                        <div class="campo">
                            <label for="pv-botao">Texto do botão</label>
                            <input type="text" id="pv-botao" maxlength="40" placeholder="🔥 Prévias Grátis">
                            <small class="texto-ajuda">O botão aparece junto com os planos.</small>
                        </div>
                        <div class="campo">
                            <label>Mídias da prévia <span class="texto-ajuda" id="pv-contagem">(0/8)</span></label>
                            <div id="pv-lista"></div>
                            <button type="button" class="botao botao-claro" id="pv-adicionar">+ Adicionar mídia</button>
                            <input type="file" id="pv-arquivo" accept="image/jpeg,image/png,video/*,audio/*" style="display:none">
                            <small class="texto-ajuda">Fotos, vídeos ou áudios, enviados na ordem da lista.</small>
                        </div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label for="pv-apagar">Apagar após</label>
                                <select id="pv-apagar">
                                    <option value="10">10 segundos</option>
                                    <option value="15">15 segundos</option>
                                    <option value="30">30 segundos</option>
                                    <option value="45">45 segundos</option>
                                    <option value="60">1 minuto</option>
                                    <option value="120">2 minutos</option>
                                    <option value="300">5 minutos</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="pv-rever">Lead pode ver de novo?</label>
                                <select id="pv-rever">
                                    <option value="nunca">Nunca — 1 visualização por lead</option>
                                    <option value="diario">1 vez a cada 24 horas</option>
                                    <option value="sempre">Sempre</option>
                                </select>
                            </div>
                        </div>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pv-proteger"> <span>Proteger conteúdo (bloqueia encaminhar e salvar)</span></label>
                        <div class="campo">
                            <label for="pv-junto">Mensagem junto das prévias (opcional)</label>
                            <textarea id="pv-junto" rows="2" placeholder="⏳ Corre! Essas prévias somem em segundos..."></textarea>
                        </div>
                        <div class="campo">
                            <label for="pv-depois">Depois que as prévias somem</label>
                            <textarea id="pv-depois" rows="2" placeholder="🔒 As prévias expiraram... o conteúdo completo te espera no VIP!"></textarea>
                            <small class="texto-ajuda">Mensagem enviada no lugar das mídias apagadas.</small>
                        </div>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pv-botao-planos"> <span>Incluir botão “Ver Planos” nessa mensagem</span></label>
                    </div>
                </div>
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Como funciona</h2></div>
                    <ol class="texto-ajuda" style="margin:0;padding-left:18px;line-height:1.7;">
                        <li>O lead clica no botão de prévias (conforme a regra de “ver de novo”).</li>
                        <li>As mídias chegam protegidas, sem encaminhar nem salvar.</li>
                        <li>Passado o tempo escolhido, tudo é apagado automaticamente.</li>
                        <li>No lugar, entra a sua mensagem de conversão com o botão de planos.</li>
                    </ol>
                    <p class="texto-ajuda" style="margin-top:10px;">Dica: use de 2 a 4 mídias e um tempo curto (10–15s). A sensação de perda faz o lead correr pro plano. Até 1 minuto o bot apaga na hora; acima disso, a conferência roda a cada minuto.</p>
                </div>
            </section>
            <section class="basico-secao" data-secao="packs">
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Packs</h2></div>
                    <p class="texto-ajuda">Venda conteúdos avulsos além dos planos: até 20 packs por fluxo, cada um com o próprio canal/grupo de entrega e pagamento via Pix.</p>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pk-ativo"> <span>Ativar packs</span></label>
                    <div class="of-detalhes" id="pk-detalhes" hidden>
                        <div class="campo">
                            <label for="pk-botao">Texto do botão</label>
                            <input type="text" id="pk-botao" maxlength="40" placeholder="📦 Packs Disponíveis">
                            <small class="texto-ajuda">O botão aparece junto com os planos.</small>
                        </div>
                        <div class="campo">
                            <label for="pk-mensagem">Mensagem dos packs</label>
                            <textarea id="pk-mensagem" rows="2" placeholder="Escolha um pack:"></textarea>
                            <small class="texto-ajuda">Mensagem exibida acima da lista de packs. Se vazio, usa a mensagem padrão.</small>
                        </div>
                    </div>
                </div>
                <div class="painel" id="pk-painel-lista" hidden>
                    <div class="painel-cabecalho"><h2>Lista de packs</h2><span class="texto-ajuda" id="pk-contagem">0/20</span></div>
                    <div id="pk-lista"></div>
                    <button type="button" class="botao botao-claro" id="pk-adicionar">+ Adicionar pack</button>
                    <input type="file" id="pk-arquivo" accept="image/jpeg,image/png,video/*,audio/*" style="display:none">
                </div>
            </section>
            <section class="basico-secao" data-secao="assinatura">
                <div class="painel">
                    <div class="painel-cabecalho"><h2>Sistema de renovação</h2></div>
                    <p class="texto-ajuda">Configure os avisos de renovação e o que acontece quando o acesso do cliente expira.</p>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho"><h2>Avisos antes de expirar</h2></div>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="as-antes-ativo"> <span>Ativar avisos</span></label>
                    <p class="texto-ajuda" id="as-antes-padrao"><strong>Desligado = aviso padrão do sistema.</strong><br>O cliente recebe uma mensagem automática (texto fixo) quando falta pouco para vencer, com o botão "Recomeçar / Renovar".<br>• Planos de 1 dia ou mais: nas últimas 24 horas.<br>• Planos em minutos: com 15 minutos restantes (5 minutos se o plano for de 15 min ou menos).<br>Ligue para escolher dias, texto e mídia.</p>
                    <div class="of-detalhes" id="as-antes-detalhes" hidden>
                        <div class="campo">
                            <label>Dias antes de expirar</label>
                            <div class="as-chips" id="as-dias">
                                <button type="button" class="as-chip" data-dia="14">14 dias</button>
                                <button type="button" class="as-chip" data-dia="7">7 dias</button>
                                <button type="button" class="as-chip" data-dia="5">5 dias</button>
                                <button type="button" class="as-chip" data-dia="3">3 dias</button>
                                <button type="button" class="as-chip" data-dia="2">2 dias</button>
                                <button type="button" class="as-chip" data-dia="1">1 dia</button>
                                <button type="button" class="as-chip" data-dia="0">No dia</button>
                            </div>
                            <small class="texto-ajuda">Planos mais curtos que o prazo escolhido pulam esse aviso.</small>
                        </div>
                        <div class="campo">
                            <label>Mídia (opcional)</label>
                            <div class="as-chips" data-midia-bloco="antes">
                                <button type="button" class="as-chip ativo" data-tipo="none">Nenhuma</button>
                                <button type="button" class="as-chip" data-tipo="image">Imagem</button>
                                <button type="button" class="as-chip" data-tipo="video">Vídeo</button>
                                <button type="button" class="as-chip" data-tipo="audio">Áudio</button>
                            </div>
                            <small class="texto-ajuda" data-midia-nome="antes"></small>
                        </div>
                        <div class="campo">
                            <label for="as-antes-msg">Mensagem de renovação</label>
                            <textarea id="as-antes-msg" rows="5" data-variaveis="as-vars-antes"></textarea>
                            <div class="as-chips" id="as-vars-antes">
                                <button type="button" class="as-chip as-var" data-var="{nome}">{nome}</button>
                                <button type="button" class="as-chip as-var" data-var="{plano}">{plano}</button>
                                <button type="button" class="as-chip as-var" data-var="{prazo}">{prazo}</button>
                                <button type="button" class="as-chip as-var" data-var="{dias}">{dias}</button>
                                <button type="button" class="as-chip as-var" data-var="{data_expiracao}">{data_expiracao}</button>
                                <button type="button" class="as-chip as-var" data-var="{saudacao}">{saudacao}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho"><h2>Avisos no dia da expiração</h2></div>
                    <p class="texto-ajuda">Envia notificações em horários específicos do dia em que o acesso expira. Usa a mesma mensagem e mídia dos avisos acima.</p>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="as-dia-ativo"> <span>Ativar avisos no dia</span></label>
                    <div class="of-detalhes" id="as-dia-detalhes" hidden>
                        <div class="campo">
                            <label for="as-dia-qtd">Quantas notificações enviar</label>
                            <select id="as-dia-qtd">
                                <option value="1">1 vez</option>
                                <option value="2">2 vezes</option>
                                <option value="3">3 vezes</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label>Horários <span class="texto-ajuda" id="as-dia-contagem"></span></label>
                            <div class="as-horas" id="as-horas"></div>
                        </div>
                    </div>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho"><h2>Mensagem quando expirar</h2></div>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="as-exp-ativo"> <span>Ativar mensagem de expiração</span></label>
                    <p class="texto-ajuda" id="as-exp-padrao"><strong>Desligado = mensagem padrão do sistema.</strong><br>Ao vencer, o cliente recebe "Seu acesso ao grupo expirou!" com o botão "Recomeçar / Renovar" (se "Expulsar do grupo" estiver desligado, a versão sem remoção).<br>Ligue para usar seu texto e mídia.</p>
                    <div class="of-detalhes" id="as-exp-detalhes" hidden>
                        <div class="campo">
                            <label>Mídia (opcional)</label>
                            <div class="as-chips" data-midia-bloco="expirar">
                                <button type="button" class="as-chip ativo" data-tipo="none">Nenhuma</button>
                                <button type="button" class="as-chip" data-tipo="image">Imagem</button>
                                <button type="button" class="as-chip" data-tipo="video">Vídeo</button>
                                <button type="button" class="as-chip" data-tipo="audio">Áudio</button>
                            </div>
                            <small class="texto-ajuda" data-midia-nome="expirar"></small>
                        </div>
                        <div class="campo">
                            <label for="as-exp-msg">Mensagem de expiração</label>
                            <textarea id="as-exp-msg" rows="4" data-variaveis="as-vars-exp"></textarea>
                            <div class="as-chips" id="as-vars-exp">
                                <button type="button" class="as-chip as-var" data-var="{nome}">{nome}</button>
                                <button type="button" class="as-chip as-var" data-var="{plano}">{plano}</button>
                                <button type="button" class="as-chip as-var" data-var="{saudacao}">{saudacao}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho"><h2>Planos de renovação</h2></div>
                    <p class="texto-ajuda">Nos avisos e na mensagem de expiração entra o botão “Renovar agora”, que mostra os planos do fluxo com o desconto abaixo.</p>
                    <div class="campo">
                        <label for="as-desconto">Desconto na renovação</label>
                        <select id="as-desconto">
                            <option value="0">Sem desconto</option>
                            <option value="5">5%</option>
                            <option value="10">10%</option>
                            <option value="15">15%</option>
                            <option value="20">20%</option>
                            <option value="25">25%</option>
                            <option value="30">30%</option>
                            <option value="40">40%</option>
                            <option value="50">50%</option>
                        </select>
                    </div>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho"><h2>Ações ao expirar</h2></div>
                    <label class="basico-interruptor sem-caixa"><input type="checkbox" id="as-expulsar"> <span>Expulsar do grupo</span></label>
                    <p class="texto-ajuda">Remove o usuário do grupo VIP quando o acesso expirar. Desligado, o acesso é encerrado mas a pessoa continua no grupo.</p>
                </div>
                <input type="file" id="as-arquivo" style="display:none">
            </section>
        </div>

        <aside class="editor-fluxo-lateral">
            <div class="painel">
                <div class="painel-cabecalho"><h2>@ Suporte <small class="texto-ajuda">(opcional)</small></h2></div>
                <p class="texto-ajuda">Se preencher, a lista de planos ganha um botão “Falar com o suporte” que abre esse contato no Telegram.</p>
                <div class="campo">
                    <label for="suporte-usuario">Usuário do Telegram</label>
                    <input type="text" id="suporte-usuario" placeholder="@username" maxlength="60">
                </div>
                <div class="campo">
                    <label for="suporte-cor">Cor do botão</label>
                    <select id="suporte-cor">
                        <option value="">Padrão</option>
                        <option value="primary">Azul</option>
                        <option value="success">Verde</option>
                        <option value="danger">Vermelho</option>
                    </select>
                </div>
            </div>
        </aside>
    </div>
</div>

<div class="folha-secoes" id="folha-secoes" hidden>
    <div class="folha-secoes-fundo" id="folha-secoes-fundo"></div>
    <div class="folha-secoes-painel" role="dialog" aria-modal="true" aria-label="Seções do fluxo">
        <span class="folha-secoes-alca"></span>
        <div class="folha-secoes-topo">
            <div><strong>Seções do fluxo</strong><small>Escolha o que deseja configurar</small></div>
            <button type="button" class="editor-fluxo-icone-btn" id="folha-secoes-fechar" aria-label="Fechar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="folha-secoes-lista" id="folha-secoes-lista"></div>
    </div>
</div>
<div class="barra-secoes" id="barra-secoes">
    <button type="button" id="barra-anterior"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg><span id="barra-anterior-nome">Início</span></button>
    <div class="barra-secoes-meio"><small id="barra-posicao">1/11</small><strong id="barra-nome">Bots</strong></div>
    <button type="button" id="barra-proxima"><span id="barra-proxima-nome">Boas-vindas</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></button>
</div>
<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>window.CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;</script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/edicao_fluxo_basico.js?v=<?php echo @filemtime(__DIR__ . '/assets/edicao_fluxo_basico.js'); ?>"></script>
</body>
</html>
