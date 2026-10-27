<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/icones.php';
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
            <span class="basico-status" id="status-salvo"><?php echo ($_GET['id'] ?? '') !== '' ? 'Tudo salvo' : 'Fluxo ainda não salvo'; ?></span>
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
                <div><span class="basico-nav-titulo">Navegação</span><span class="basico-nav-contagem" id="nav-contagem">11 seções</span></div>
                <button type="button" class="editor-fluxo-icone-btn" id="btn-recolher-nav" aria-label="Recolher navegação">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
                </button>
            </div>
            <div class="basico-nav-grupo">Estrutura</div>
            <button type="button" class="basico-nav-item ativo" data-secao="bots" title="Bots"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/></svg><span class="basico-nav-rotulo">Bots</span></button>
            <button type="button" class="basico-nav-item" data-secao="boasvindas" title="Boas-vindas"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span class="basico-nav-rotulo">Boas-vindas</span></button>
            <button type="button" class="basico-nav-item" data-secao="planos" title="Planos"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg><span class="basico-nav-rotulo">Planos</span></button>
            <div class="basico-nav-grupo">Conversão</div>
            <button type="button" class="basico-nav-item" data-secao="upsell" title="Upsell"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8M15 7h6v6"/></svg><span class="basico-nav-rotulo">Upsell</span></button>
            <button type="button" class="basico-nav-item" data-secao="downsell" title="Downsell"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l6 6 4-4 8 8M15 17h6v-6"/></svg><span class="basico-nav-rotulo">Downsell</span></button>
            <button type="button" class="basico-nav-item" data-secao="orderbump" title="Order Bump"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l1 13H5zM9 7a3 3 0 0 1 6 0"/></svg><span class="basico-nav-rotulo">Order Bump</span></button>
            <button type="button" class="basico-nav-item" data-secao="packs" title="Packs"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5zM3 8v8l9 5 9-5V8"/></svg><span class="basico-nav-rotulo">Packs</span></button>
            <button type="button" class="basico-nav-item" data-secao="previas" title="Prévias"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 1-9z"/></svg><span class="basico-nav-rotulo">Prévias</span></button>
            <div class="basico-nav-grupo">Operação</div>
            <button type="button" class="basico-nav-item" data-secao="pagamentos" title="Pagamentos"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><span class="basico-nav-rotulo">Pagamentos</span></button>
            <button type="button" class="basico-nav-item" data-secao="assinatura" title="Assinatura"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M3 21v-5h5"/></svg><span class="basico-nav-rotulo">Assinatura</span></button>
            <div class="basico-nav-grupo">Análise e ajustes</div>
            <button type="button" class="basico-nav-item" data-secao="conversoes" title="Conversões"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></svg><span class="basico-nav-rotulo">Conversões</span></button>
            <div class="basico-nav-aviso" id="nav-aviso"><strong>Por onde começar:</strong> preencha <strong>Boas-vindas</strong> e adicione pelo menos 1 plano em <strong>Planos</strong>, depois clique em <strong>Salvar alterações</strong> (no topo). Você pode salvar e vincular o bot a qualquer momento; o bot só consegue vender depois que o fluxo tiver pelo menos 1 plano.</div>
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
                    <p class="texto-ajuda" id="bots-aviso-salvar">Escolha abaixo o bot que vai executar este fluxo: ele é vinculado quando você clicar em <strong>Salvar alterações</strong> (no topo), Lembre de adicionar pelo menos 1 plano em <strong>Planos</strong>: sem plano o bot não tem o que vender.</p>
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
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" id="bv-mostrar-cta" checked> <span>Mostrar botão (CTA) na mensagem</span></label>
                        <small class="texto-ajuda" id="bv-cta-dica" hidden>Sem o botão, a lista de planos é enviada logo depois da mensagem de boas-vindas.</small>
                        <div class="campo">
                            <label>Mídia (opcional)</label>
                            <div class="area-previa-basico" id="bv-midia-previa" data-estado="vazio" data-tipo="none">Sem mídia</div>
                            <input type="file" id="bv-midia-arquivo" accept="image/*,video/*" style="display:none">
                            <div id="bv-midias-extras"></div>
                            <button type="button" class="botao botao-claro" id="bv-midias-adicionar" style="display:none">+ Adicionar outra foto ou vídeo</button>
                            <small class="texto-ajuda" id="bv-midias-dica">Com mais de uma foto/vídeo (até 10), elas vão juntas num álbum, na ordem da lista, e o texto com o botão chega logo depois.</small>
                        </div>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo bv-cta-campo">
                                <label for="bv-cta">Texto do botão (CTA)</label>
                                <input type="text" id="bv-cta" placeholder="Ver Planos" value="Ver Planos">
                            </div>
                            <div class="campo bv-cta-campo">
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
                        <p class="texto-ajuda">Depois que o cliente PAGA, oferece um plano melhor. Se ele aceitar, recebe um Pix novo só desse plano (com o desconto abaixo, se houver) e o acesso do novo plano é liberado quando pagar.</p>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar upsell</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Plano oferecido</label><select class="of-plano"></select></div>
                            <div class="campo of-desconto-campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        </div>
                        <!-- Plano só do Upsell (item 33): cartão igual ao da aba Planos (cabeçalho com o resumo e a seta de recolher; Nome, Valor, Tempo de acesso e Entrega dentro).
                             O campo Entrega (.of-entrega, logo abaixo) é levado para dentro do cartão pelo JS enquanto o plano próprio está escolhido. -->
                        <div class="of-plano-proprio painel-plano" hidden>
                            <div class="plano-topo">
                                <span class="plano-resumo"><strong class="plano-resumo-nome sem-nome">Plano sem nome</strong><small class="plano-resumo-info">R$ 0,00 · 30 dias</small></span>
                                <button type="button" class="btn-icon plano-alternar" aria-expanded="true" title="Recolher plano" aria-label="Recolher plano"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                            </div>
                            <div class="plano-corpo">
                                <div class="grade grade-2 grade-compacta">
                                    <div class="campo"><label>Nome do plano</label><input type="text" class="of-proprio-nome" maxlength="80" placeholder="Ex.: Pacote completo"></div>
                                    <div class="campo"><label>Valor (R$)</label><input type="number" step="0.01" min="0" class="of-proprio-valor" value="0"></div>
                                </div>
                                <div class="grade grade-2 grade-compacta of-proprio-linha" style="margin-top:8px;">
                                    <div class="campo"><label>Tempo de acesso</label>
                                        <div style="display:flex; gap:6px;">
                                            <input type="number" min="1" class="of-proprio-dias" value="30" style="max-width:80px;">
                                            <select class="of-proprio-unidade">
                                                <option value="dias">Dias</option>
                                                <option value="horas">Horas</option>
                                                <option value="minutos">Minutos</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <small class="texto-ajuda of-proprio-ajuda">Este plano existe só no Upsell: não aparece em "Ver Planos" nem na lista de planos. Só é oferecido a quem acabou de pagar, e o valor digitado é o preço da oferta.</small>
                            </div>
                        </div>
                        <div class="of-entrega"></div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Que tal levar o plano completo com desconto?"></textarea></div>
                        <div class="campo"><label>Mídia (opcional)</label>
                            <div class="area-previa-basico of-midia" data-estado="vazio" data-tipo="none">Sem mídia</div>
                            <input type="file" class="of-midia-arquivo" accept="image/*,video/*" style="display:none">
                            <div class="of-midias-extras"></div>
                            <button type="button" class="botao botao-claro of-midias-adicionar" style="display:none">+ Adicionar outra foto ou vídeo</button>
                            <small class="texto-ajuda of-midias-dica" style="display:none">Com mais de uma foto/vídeo (até 10), elas vão juntas num álbum, na ordem da lista, e o texto com os botões chega logo depois.</small>
                        </div>
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
                            <label class="basico-interruptor sem-caixa" style="grid-column:1/-1"><input type="checkbox" class="of-esconder-recusar"> <span>Esconder o botão de recusar</span></label>
                            <small class="texto-ajuda of-esconder-recusar-dica" style="grid-column:1/-1" hidden>Só o botão aceitar aparece. Quem não quiser o upsell não consegue seguir com o plano escolhido, e o Downsell não chega a ser oferecido.</small>
                            <div class="campo of-recusar-campo"><label>Botão recusar</label><input type="text" class="of-recusar" placeholder="Não, obrigado"></div>
                            <div class="campo of-recusar-campo"><label>Cor do botão recusar</label>
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
                        <p class="texto-ajuda">Mostra os planos que você escolher, com desconto, como botões. Não tem botão de aceitar nem de recusar: o cliente toca no plano que quiser e recebe o Pix dele. Você escolhe se a oferta sai antes do Pix ou depois dele, se o cliente não pagar.</p>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar downsell</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="campo"><label>Quando enviar</label>
                            <select class="of-quando">
                                <option value="depois_pix">Depois do Pix, se o cliente não pagar</option>
                                <option value="antes_pix">Antes do Pix, assim que o cliente escolhe o plano</option>
                            </select>
                        </div>
                        <div class="of-temporizador">
                            <div class="grade grade-2 grade-compacta">
                                <div class="campo"><label>Primeiro envio após (minutos)</label><input type="number" min="1" max="1440" step="1" class="of-espera" value="10"></div>
                                <div class="campo"><label>Quantos envios</label><input type="number" min="1" max="5" step="1" class="of-envios" value="1"></div>
                                <div class="campo of-intervalo-campo" style="display:none"><label>Repetir a cada (minutos)</label><input type="number" min="1" max="1440" step="1" class="of-intervalo" value="30"></div>
                            </div>
                            <small class="texto-ajuda">O cliente recebe o Pix normal. Se não pagar nesse prazo, chega a oferta com desconto; com mais de 1 envio, ela se repete no intervalo escolhido até ele pagar ou tocar num plano. A conferência roda a cada minuto.</small>
                        </div>
                        <div class="campo"><label>Planos oferecidos</label>
                            <div class="of-planos-lista"></div>
                            <small class="texto-ajuda">Só os planos marcados aparecem para o cliente (o plano que ele já escolheu antes não é repetido).</small>
                        </div>
                        <div class="campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="of-desconto" value="0"></div>
                        <div class="of-entrega"></div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Espera! Tenho uma condição especial pra você."></textarea></div>
                        <div class="campo"><label>Mídia (opcional)</label>
                            <div class="area-previa-basico of-midia" data-estado="vazio" data-tipo="none">Sem mídia</div>
                            <input type="file" class="of-midia-arquivo" accept="image/*,video/*" style="display:none">
                            <div class="of-midias-extras"></div>
                            <button type="button" class="botao botao-claro of-midias-adicionar" style="display:none">+ Adicionar outra foto ou vídeo</button>
                            <small class="texto-ajuda of-midias-dica" style="display:none">Com mais de uma foto/vídeo (até 10), elas vão juntas num álbum, na ordem da lista, e o texto com os botões chega logo depois.</small>
                        </div>
                    </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="orderbump">
                    <div class="painel" data-oferta="order_bump">
                        <div class="painel-cabecalho"><h2>Order Bump</h2></div>
                        <p class="texto-ajuda">Oferece um extra por um valor adicional. Você escolhe quando: depois do Pix, se o cliente não pagar (aí dá para ter até 5 order bumps, um de cada vez, cada um com o seu tempo), ou só depois que ele paga (um order bump). Aceitando um bump antes de pagar, o cliente recebe um Pix novo com o plano + o extra; aceitando depois de pagar, um Pix novo só do extra.</p>
                        <label class="basico-interruptor sem-caixa"><input type="checkbox" class="of-ativo"> <span>Ativar order bump</span></label>
                        <div class="of-detalhes" hidden>
                        <div class="grade grade-2 grade-compacta bp-quando-grade">
                            <div class="campo bp-quando-campo"><label>Quando enviar</label>
                                <select class="of-quando">
                                    <option value="depois_pix">Depois do Pix, se o cliente não pagar (sequência)</option>
                                    <option value="depois_pagamento">Depois que o cliente paga (um só)</option>
                                </select>
                            </div>
                            <div class="campo of-espera-campo"><label>Enviar quantos minutos depois do Pix</label><input type="number" min="1" max="1440" step="1" class="of-espera" value="5"></div>
                        </div>
                        <small class="texto-ajuda bp-sequencia-dica">Se o cliente não pagar, o 1º order bump chega depois do tempo acima; cada um dos seguintes chega depois do tempo que você marcar nele, contado do order bump anterior. A sequência para quando ele pagar ou aceitar um bump. A conferência roda a cada minuto.</small>
                        <h3 class="bp-item-titulo">Order Bump 1</h3>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo"><label>Nome do extra</label><input type="text" class="of-nome" placeholder="Ex.: Pack bônus"></div>
                            <div class="campo"><label>Valor extra (R$)</label><input type="number" min="0" step="0.01" class="of-valor-extra" value="0"></div>
                        </div>
                        <div class="campo"><label>Mensagem da oferta</label><textarea class="of-mensagem" rows="3" placeholder="Quer adicionar o bônus por só mais R$ 9,90?"></textarea></div>
                        <div class="campo"><label>Mídia (opcional)</label>
                            <div class="area-previa-basico of-midia" data-estado="vazio" data-tipo="none">Sem mídia</div>
                            <input type="file" class="of-midia-arquivo" accept="image/*,video/*" style="display:none">
                        </div>
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
                        <div id="bp-extras"></div>
                        <button type="button" class="botao botao-claro" id="bp-adicionar">+ Adicionar order bump</button>
                    </div>
                    </div>
                </section>

                <section class="basico-secao" data-secao="pagamentos">
                    <div class="pg-abas" role="tablist" aria-label="Mensagens do pagamento">
                        <button type="button" class="pg-aba ativa" role="tab" data-pg-aba="gerado" aria-selected="true">Pagamento Gerado</button>
                        <button type="button" class="pg-aba" role="tab" data-pg-aba="aprovado" aria-selected="false">Pagamento Aprovado</button>
                    </div>
                    <input type="file" id="pg-midia-arquivo" accept="image/*,video/*" style="display:none">

                    <div class="pg-painel" data-pg-painel="gerado">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Layout da mensagem</h2></div>
                            <div class="pg-layouts" role="radiogroup" aria-label="Layout da mensagem do Pix">
                                <label class="pg-layout">
                                    <input type="radio" name="pg-layout" value="padrao" checked>
                                    <span class="pg-layout-mini" aria-hidden="true"><i class="pg-linha-cheia"></i><i class="pg-linha-media"></i><i class="pg-linha-cheia"></i><i class="pg-linha-curta"></i><i class="pg-linha-botao"></i></span>
                                    <strong>Padrão</strong><small>Resumo do pedido (produto e valor) e depois o seu texto</small>
                                </label>
                                <label class="pg-layout">
                                    <input type="radio" name="pg-layout" value="compacto">
                                    <span class="pg-layout-mini" aria-hidden="true"><i class="pg-linha-cheia"></i><i class="pg-linha-media"></i><i class="pg-linha-botao"></i></span>
                                    <strong>Compacto</strong><small>Só o seu texto, sem o resumo do pedido</small>
                                </label>
                            </div>
                        </div>

                        <div class="painel" data-pg-editor="pg-msg-instrucoes">
                            <div class="painel-cabecalho"><h2>Texto do Pix</h2></div>
                        <div class="pg-midias-topo"><span class="pg-rotulo-campo">Mídias</span><span class="texto-ajuda" data-pg-contagem="gerado">0/3 mídias</span></div>
                        <div class="pg-midias" data-pg-midias="gerado"></div>
                        <div class="pg-barra" role="toolbar" aria-label="Formatação do texto">
                            <button type="button" class="pg-fmt" data-pg-tag="b" title="Negrito" aria-label="Negrito"><b>B</b></button>
                            <button type="button" class="pg-fmt" data-pg-tag="i" title="Itálico" aria-label="Itálico"><i>I</i></button>
                            <button type="button" class="pg-fmt" data-pg-tag="u" title="Sublinhado" aria-label="Sublinhado"><u>U</u></button>
                            <button type="button" class="pg-fmt" data-pg-tag="s" title="Tachado" aria-label="Tachado"><s>S</s></button>
                            <button type="button" class="pg-fmt" data-pg-tag="code" title="Código (toque para copiar)" aria-label="Código">&lt;/&gt;</button>
                            <button type="button" class="pg-fmt" data-pg-tag="tg-spoiler" title="Spoiler (texto escondido)" aria-label="Spoiler"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c5 0 8.5 4.2 9.6 6a14 14 0 0 1-2.6 3.2M6.2 6.9A14 14 0 0 0 2.4 12c1.1 1.8 4.6 6 9.6 6a9.5 9.5 0 0 0 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button>
                            <button type="button" class="pg-fmt" data-pg-tag="blockquote" title="Citação" aria-label="Citação"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M7 7h4v4c0 2.5-1.2 4-3.5 4.4l-.5-1.4c1-.3 1.6-1 1.7-2H7zm7 0h4v4c0 2.5-1.2 4-3.5 4.4l-.5-1.4c1-.3 1.6-1 1.7-2H14z"/></svg></button>
                        </div>
                            <textarea id="pg-msg-instrucoes" class="pg-texto" rows="8" maxlength="3000" spellcheck="true" aria-label="Texto do Pix"></textarea>
                        <div class="pg-variaveis" aria-label="Variáveis (toque para inserir no texto)">
                            <button type="button" class="pg-variavel" data-pg-variavel="nome" title="Primeiro nome do cliente">{nome}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="plano" title="Nome do plano comprado">{plano}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="valor" title="Valor do pedido (R$)">{valor}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="saudacao" title="Bom dia, Boa tarde ou Boa noite">{saudacao}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="codigo_pix" title="O código Pix copia e cola, no formato escolhido">{codigo_pix}</button>
                        </div>
                            <div class="pg-rodape-texto"><small class="texto-ajuda">Formatação em HTML do Telegram. Uma variável fica entre chaves e é trocada na hora de enviar.</small><small class="texto-ajuda pg-contador" data-pg-contador="pg-msg-instrucoes">0/3000</small></div>
                        </div>

                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Botões &amp; CTA</h2></div>
                            <div class="pg-linha" id="pg-linha-antes-botoes">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-antes-botoes-ativo"> <span>Mensagem antes dos botões</span></label>
                                <input type="text" id="pg-antes-botoes" maxlength="300" data-pg-liga="pg-antes-botoes-ativo" aria-label="Mensagem antes dos botões">
                                <small class="texto-ajuda">Quando ligada, os botões vão numa mensagem à parte, com este texto.</small>
                            </div>
                            <div class="pg-linha">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-codigo-na-mensagem"> <span>Código Pix na mesma mensagem</span></label>
                                <small class="texto-ajuda">Desligado, o código sai numa mensagem separada (mais fácil de copiar).</small>
                            </div>
                            <div class="pg-linha" id="pg-linha-antes-codigo">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-antes-codigo-ativo"> <span>Mensagem antes do código Pix</span></label>
                                <input type="text" id="pg-antes-codigo" maxlength="300" data-pg-liga="pg-antes-codigo-ativo" aria-label="Mensagem antes do código Pix">
                            </div>
                            <!-- Mesma ordem do Telegram (e da prévia ao lado): copiar o código em cima, verificar o pagamento embaixo -->
                            <div class="pg-linha pg-linha-botao">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-mostrar-copiar" checked> <span>Botão para copiar o código</span></label>
                                <input type="text" id="pg-btn-copiar-texto" maxlength="40" data-pg-liga="pg-mostrar-copiar" aria-label="Texto do botão de copiar">
                            </div>
                            <div class="pg-linha pg-linha-botao">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-mostrar-confirmar" checked> <span>Botão para verificar o pagamento</span></label>
                                <input type="text" id="pg-btn-verificar-texto" maxlength="40" data-pg-liga="pg-mostrar-confirmar" aria-label="Texto do botão de verificar">
                            </div>
                        </div>

                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Configurações do Pix</h2></div>
                            <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-mostrar-qrcode" checked> <span>Enviar o QR Code (imagem)</span></label>
                            <div class="grade grade-2 grade-compacta">
                                <div class="campo">
                                    <label for="pg-qr-posicao">Onde fica o QR Code</label>
                                    <select id="pg-qr-posicao">
                                        <option value="antes">Antes do texto (imagem separada)</option>
                                        <option value="junto">Na mesma mensagem do texto (legenda da imagem)</option>
                                        <option value="depois">Depois do texto (imagem separada)</option>
                                    </select>
                                </div>
                                <div class="campo">
                                    <label for="pg-codigo-formato">Formato do código Pix</label>
                                    <select id="pg-codigo-formato">
                                        <option value="inline">Em destaque (toque para copiar)</option>
                                        <option value="bloco">Bloco de código (com botão copiar)</option>
                                        <option value="simples">Texto simples</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pg-painel" data-pg-painel="aprovado" hidden>
                        <div class="painel" data-pg-editor="pg-msg-confirmado">
                            <div class="painel-cabecalho"><h2>Mensagem de Aprovação</h2></div>
                        <div class="pg-midias-topo"><span class="pg-rotulo-campo">Mídias</span><span class="texto-ajuda" data-pg-contagem="aprovado">0/3 mídias</span></div>
                        <div class="pg-midias" data-pg-midias="aprovado"></div>
                        <div class="pg-barra" role="toolbar" aria-label="Formatação do texto">
                            <button type="button" class="pg-fmt" data-pg-tag="b" title="Negrito" aria-label="Negrito"><b>B</b></button>
                            <button type="button" class="pg-fmt" data-pg-tag="i" title="Itálico" aria-label="Itálico"><i>I</i></button>
                            <button type="button" class="pg-fmt" data-pg-tag="u" title="Sublinhado" aria-label="Sublinhado"><u>U</u></button>
                            <button type="button" class="pg-fmt" data-pg-tag="s" title="Tachado" aria-label="Tachado"><s>S</s></button>
                            <button type="button" class="pg-fmt" data-pg-tag="code" title="Código (toque para copiar)" aria-label="Código">&lt;/&gt;</button>
                            <button type="button" class="pg-fmt" data-pg-tag="tg-spoiler" title="Spoiler (texto escondido)" aria-label="Spoiler"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c5 0 8.5 4.2 9.6 6a14 14 0 0 1-2.6 3.2M6.2 6.9A14 14 0 0 0 2.4 12c1.1 1.8 4.6 6 9.6 6a9.5 9.5 0 0 0 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button>
                            <button type="button" class="pg-fmt" data-pg-tag="blockquote" title="Citação" aria-label="Citação"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M7 7h4v4c0 2.5-1.2 4-3.5 4.4l-.5-1.4c1-.3 1.6-1 1.7-2H7zm7 0h4v4c0 2.5-1.2 4-3.5 4.4l-.5-1.4c1-.3 1.6-1 1.7-2H14z"/></svg></button>
                        </div>
                            <textarea id="pg-msg-confirmado" class="pg-texto" rows="7" maxlength="3000" spellcheck="true" aria-label="Mensagem de aprovação"></textarea>
                        <div class="pg-variaveis" aria-label="Variáveis (toque para inserir no texto)">
                            <button type="button" class="pg-variavel" data-pg-variavel="nome" title="Primeiro nome do cliente">{nome}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="plano" title="Nome do plano comprado">{plano}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="valor" title="Valor do pedido (R$)">{valor}</button>
                            <button type="button" class="pg-variavel" data-pg-variavel="saudacao" title="Bom dia, Boa tarde ou Boa noite">{saudacao}</button>
                        </div>
                            <div class="pg-rodape-texto"><small class="texto-ajuda">Depois deste texto o bot informa o acesso (grupo ou link) e, no grupo, até quando ele vale.</small><small class="texto-ajuda pg-contador" data-pg-contador="pg-msg-confirmado">0/3000</small></div>
                        </div>
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Botão de Acesso</h2></div>
                            <div class="pg-linha pg-linha-botao">
                                <label class="basico-interruptor sem-caixa"><input type="checkbox" id="pg-botao-acesso-ativo" checked> <span>Mostrar o botão de acesso</span></label>
                                <input type="text" id="pg-botao-acesso-texto" maxlength="40" data-pg-liga="pg-botao-acesso-ativo" aria-label="Texto do botão de acesso">
                            </div>
                            <small class="texto-ajuda">O botão abre o link do grupo (ou o link de entrega) do plano comprado. Desligado, o endereço vai escrito na mensagem.</small>
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
                                    <option value="2">2 segundos</option>
                                    <option value="3">3 segundos</option>
                                    <option value="5">5 segundos</option>
                                    <option value="7">7 segundos</option>
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
                    <p class="texto-ajuda" style="margin-top:10px;">Dica: use de 2 a 4 mídias e um tempo curto (5s é o ideal; com 10s o lead dá print ou grava a tela). A sensação de perda faz o lead correr pro plano. Até 1 minuto o bot apaga na hora; acima disso, a conferência roda a cada minuto.</p>
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
            <div class="painel pg-previa-cartao" id="pg-previa" aria-label="Prévia da conversa no Telegram">
                <div class="pg-previa-rotulo">Prévia do Telegram</div>
                <div class="pg-tg">
                    <div class="pg-tg-topo"><span class="pg-tg-avatar" aria-hidden="true">S</span><div><strong>Seu Bot</strong><small>bot</small></div></div>
                    <div class="pg-tg-corpo" id="pg-previa-corpo" aria-live="polite"></div>
                    <div class="pg-tg-rodape" aria-hidden="true">Escrever mensagem...</div>
                </div>
                <small class="texto-ajuda">Exemplo com dados de mentira (João, Premium VIP, R$ 29,90). O QR Code e o código são ilustrativos.</small>
            </div>
            <div class="painel lateral-suporte">
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
<script src="assets/pagamentos_guiado.js?v=<?php echo @filemtime(__DIR__ . '/assets/pagamentos_guiado.js'); ?>"></script>
<script src="assets/edicao_fluxo_basico.js?v=<?php echo @filemtime(__DIR__ . '/assets/edicao_fluxo_basico.js'); ?>"></script>
</body>
</html>
