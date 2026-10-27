# Usuário novo na VPS (Chrome real, pela web — sem SSH na VPS)

Rodada de 15/10/2026 em `https://coyotebot.com.br`: cadastro → login → todas as páginas → ações de cada tela, **como um vendedor comum**.
Resultado e achados em `anotacoes/TESTES.md` (linha de 15/10) e `anotacoes/PENDENCIAS.md`.

Não precisa de acesso à VPS: tudo passa pelo HTTPS público. Usa o mini cliente do Chrome de `tests/homologacao/ui/cdp.js` e a biblioteca `tests/paginas/pg_lib.js` (Node 22+, Chrome instalado).

## Onde ficam os segredos (nunca no git)

Os scripts leem/gravam em `estado/` (ou na pasta de `ESTADO=<pasta>`), que está no `.gitignore`:

| Arquivo | Conteúdo | Quem cria |
|---|---|---|
| `usuario_novo.json` | nome, e-mail e senha da conta de teste | `01_cadastro_login.js` (gera a senha) e `09_webhooks_conta.js` (troca a senha e grava a nova **antes** de enviar) |
| `cred_homolog.json` | token do bot, credencial Omega do usuário e split global, **em texto puro** | `ler_cred.php` rodado na homologação (abaixo) |
| `fluxos_homolog.json` | fluxos para importar na VPS | `ler_fluxos.php` rodado na homologação |

Ler da homologação (só leitura, nada é gravado lá; a senha SSH vem de `anotacoes/credenciais-ssh-hostinger.md`, nunca de arquivo novo):

```bash
plink -batch -ssh -P 65002 -pw "$PW" u214219698@185.213.81.10 "EMAIL_DONO=$EMAIL_DONO php" < ler_cred.php   > estado/cred_homolog.json
plink -batch -ssh -P 65002 -pw "$PW" u214219698@185.213.81.10 php < ler_fluxos.php > estado/fluxos_homolog.json
```

## Ordem e o que cada script cobre

| Script | O que faz |
|---|---|
| `00_nova_conta [sufixo]` | recria a **conta de teste** (1 cadastro pela tela, e-mail `seu-email+<sufixo>@gmail.com`) e grava em `estado/usuario_novo.json` — para quando a conta anterior foi apagada (o login dela trava por 5 falhas/15 min). Sem bot nem gateway |
| `01_cadastro_login` | páginas públicas; cadastro (senha curta, e-mail inválido, senhas diferentes, sucesso, repetido); login (senha errada, e-mail inexistente, ok); logout. **O cadastro tem limite de 5/hora por IP** — o script gasta 4 |
| `02_paginas [rotulo]` | todas as páginas do vendedor + dinâmicas, em desktop (1500) e celular (390): HTTP, erro de PHP, JS, imagem, rolagem horizontal; rotas `/admin/*` barradas; anônimo barrado |
| `03_gateway` | grava a credencial Omega do usuário (`client_id`/`client_secret`) pela tela e confere "salvo/ativo" |
| `04_bot`, `04b_bot_erros` | criar bot: sem token, token lixo, token falso (mensagens traduzidas), token real; mesmo token não duplica. **Registrar o token real MUDA o webhook do bot no Telegram para a VPS** |
| `05_fluxos` | importar `.json` (quebrado/estrutura inválida/4 reais), exportar de volta (conteúdo idêntico), abrir os editores |
| `06_vincular` | vincular o bot ao fluxo pelo editor e pela tela do bot; trocar; "vincular tira do anterior" |
| `07_webhook` | webhook do Telegram sem segredo/segredo errado/lixo (403 "assinatura inválida"), webhook da gateway |
| `08_acoes`, `08b_remarketing_api` | links de rastreamento, redirecionamento (`/l/<código>` redireciona e conta o clique), remarketing (agendar, data passada, 4096, bot alheio, CSRF, cancelar), traqueamento (token não volta no HTML) |
| `09_webhooks_conta` | webhooks do usuário (http, 127.0.0.1, 10.x, 169.254.x, localhost recusados), perfil, XSS no nome, senha (5 erros + troca + login antigo recusado), foto (PHP disfarçado recusado) |
| `10_navegacao` | menu lateral, períodos do dashboard, sino, tema (persiste), filtros de vendas/leads/ranking com SQL/XSS na busca |
| `11_email`, `11b_reenviar` | aviso "E-mail não confirmado", link de confirmação inválido, "Reenviar" |
| `12_admin_login` | 1 tentativa de login de admin (`ADMIN_EMAIL`/`ADMIN_SENHA` no ambiente; o limite é 5 erros/15 min por e-mail — **não insistir**) |
| `13_admin_paginas` | as páginas do menu do admin em desktop e celular + admin aberto nas telas de operação do vendedor (só leitura) |
| `14_admin_controles` | utilitário: formulários/botões de rotas de admin (`MSYS_NO_PATHCONV=1`) |
| `15_admin_acoes` | (A) usuário comum x endpoints `ajax/` de admin; (B) admin: usuários (filtros, criar, editar, excluir, auto-exclusão), transações, comunidade. **Cria e apaga o que usa** (usuário `+admintest`, link de teste); se travar no meio, apagar o resíduo com `16_admin_comunidade` (`ACAO=excluir TITULO=...`) e em Admin → Usuários |
| `16_admin_comunidade` | mostra a linha de um link da Comunidade e, com `ACAO=desativar|excluir`, age **só** no link com o `TITULO` dado (botões da tela são só ícone: achar por `title`) |
| `17_admin_resto` | Configurações (segredos fora do HTML, "Enviar teste" de e-mail), Gateways (split, só leitura), E-mails, consultar venda, logs, dashboard, sino, logout |
| `18_admin_ranking_arquivar` | arquivar/desarquivar campanha de ranking, em 2 fases (`PHASE=1`, espera a cron de 1 min fechar o placar, `PHASE=2`). Cria a campanha "TESTE arquivar (pode ignorar)" e a deixa arquivada. **Não usar `inspecionar()` onde há `confirm()`** (zera `c.eventos` e o auto-aceitador de diálogos trava) |
| `19_admin_salvar_config_split` | salva de verdade Configurações (identidade, domínios, Google, e-mail) e o Split, e **restaura**; antes/depois: fotografia do banco com `vps_snapshot_config.php` desta pasta, rodado na VPS como `sudo -u www-data php < vps_snapshot_config.php` (só hashes; compare os dois JSON). Os originais ficam em `estado/config_original.json` e `estado/split_original.json` |
| `20_admin_cor_invalida` | cor inválida por POST direto (o `<input type=color>` do navegador "conserta" o valor) e "tudo ou nada" |
| `21_admin_split_validacao` | split **depois da correção**: 11 valores fora da regra por POST direto (nada é gravado), tudo ou nada, id inexistente, mensagem de sucesso, válidos aceitos e **restauração**; fotografia do banco antes/depois com `vps_snapshot_config.php` |
| `22_admin_transacoes_filtro` | layout do filtro de Admin → Transações em 3 larguras (rótulos, alturas iguais, alinhamento, sem rolagem lateral, "Limpar" só com filtro) e a busca por TXID; salva as capturas em `estado/transacoes_<largura>.png` |
| `23_admin_usuarios_filtro` | Admin → Usuários: sem abas/listas de e-mail e bots, com busca + ordem + **Filtrar/Limpar**; parâmetros antigos ignorados; paginação mantém a busca; capturas em `estado/usuarios_<largura>.png` |
| `24_fluxo_novo_lixeira` | Editor Visual: lixeira num fluxo novo (vazio, com bloco cancelando, com bloco confirmando) e que o salvamento automático não cria o fluxo descartado. Usa o helper `__drop` para soltar blocos |
| `25_fluxos_modo_cartoes` | Meus Fluxos: modal "Como deseja criar?" (ordem, textos, ícones, 2 temas, celular) e ícone do modo no selo e na miniatura da lista |
| `26_basico_sem_trava` | Editor Guiado de fluxo novo: sem abas trancadas, avisos novos, status "Fluxo ainda não salvo", salva com 1 plano e depois **apaga o fluxo de teste** |
| `27_basico_bots_no_fluxo_novo` | Editor Guiado de fluxo novo: escolher/tirar o bot antes de salvar ("será vinculado ao salvar"), vínculo automático no 1º salvar, e **restaura o fluxo original do bot** da conta de teste (guardado em `estado/bot_vinculo_original.json`); apaga o fluxo de teste |
| `28_icone_x_bot_pendente` | Fluxo Guiado novo: o botão "Tirar da lista" do bot escolhido antes de salvar usa um X (antes: corrente quebrada); não salva nada. O botão "Desvincular" de bot já vinculado mantém a corrente |
| `29_basico_rascunho_sem_plano` | Editor Guiado: **nada trava por falta de plano** — salvar sem plano (rascunho), vincular bot a fluxo sem plano e salvar com bot vinculado funcionam (com aviso); só pergunta quando o bot está em outro fluxo e este não tem plano (cancelar = nada muda). 6 cenários, fluxo salvo e fluxo novo. **Restaura o vínculo original do bot** e apaga os fluxos de teste |
| `30_ranking_fonte_valores` | Ranking: os valores em R$ (mini ranking do Início, pódio e lista de `/ranking`) usam a mesma fonte do `body` (Manrope), não a monoespaçada; desktop e celular; só leitura (usa elemento de amostra se a conta não tem campanha ativa); captura em `estado/ranking_fonte_<largura>.png` |
| `31_login_marca_centralizada` | `/login` e `/cadastro` no desktop: mascote animado (ou logo), título e descrição centralizados na coluna da esquerda (desvio ≤ 2 px do centro, `text-align: center`, sem rolagem horizontal) em 1360/1920/1100/900 px; ≤ 860 px a coluna some (só o cabeçalho do mobile). Só leitura; capturas em `estado/login_marca_<largura>.png` |
| `32_basico_midia_ofertas` | Editor Guiado: a caixa de **mídia** (vazio/anexada com miniatura/Trocar/Tirar, arquivo falso recusado), mídia nas 3 **ofertas**, **"Esconder o botão de recusar"** do Upsell, **Downsell só com planos marcados** (sem aceitar/recusar; recusa sem plano marcado; fluxo antigo abre com o plano marcado e converte ao salvar), persistência ao salvar/reabrir e POST com caminho de mídia adulterado. Cria 1 fluxo e **apaga**; deixa ~6 arquivos minúsculos em `uploads/` (a tela não apaga). Usa os arquivos de `tests/homologacao/ui/midia/`. Capturas em `estado/midia_*.png`. O que o bot **manda** (foto/vídeo, botões, preço do downsell) é testado sem rede em `tests/guiado/unitario_oferta_midia.php` |
| `33_basico_aviso_minimo` | aviso de valor abaixo do mínimo da Omega: na tela (plano de R$ 1,00) e direto no servidor (R$ 1,00 e R$ 0,01) devolvem **só** "Para a Omega, o valor mínimo é R$ X,XX." — sem comissão, sem nome de plano. Não grava nada. Captura em `estado/aviso_minimo.png` |
| `34_landing` | landing pública `/landing` (sem login). **Escondida** (`LANDING_PUBLICA = false`): confere só que é um 404 igual aos outros; **publicada**: a bateria completa: título, 1 H1, sem rolagem lateral em 1360 e 390 px, imagens, âncoras e links de saída (cadastro/login/termos respondem), abas por mouse e teclado, contador "ao vivo", movimento reduzido, erros de JS. Só leitura; capturas `estado/landing_1360.png` e `landing_390.png` (página inteira) |
| `35_menu_mobile_aviso_email` | menu "Mais" do celular (360/390/430 px) com a conta de e-mail **não confirmado**: o aviso "E-mail não confirmado + Reenviar" tem margem de 10 px como os itens, respiro em cima e embaixo, texto e botão na mesma linha, botão ≥ 32 px e sem rolagem lateral. Só leitura; captura `estado/menu_mobile_aviso.png` |
| `36_push_tela` | avisos push, com Chrome: desligado (cartão "desligado pelo administrador", sem manifest/push.js, endpoints 409); ligado (head com manifest/theme-color/apple-touch-icon, manifest e 5 PNG nos tamanhos certos, `sw.js` no-cache, ativar só no clique, lista com "Este aparelho", interruptores, sincronização diária, sair cancela o aparelho, convite no sino, aviso de teste e limite de 10 s, aparelho morto, chaves trocadas, iPhone fora/dentro da Tela de Início, permissão negada, sem suporte, erro do Brave, celular 390 px) e aba "Notificações" do admin (sessão de admin criada pelo teste; contato inválido, REGENERAR com frase errada, ligar/desligar). `PushManager` falso; **liga os avisos só durante o teste e devolve tudo** (usa `push_preparar.php` na VPS, senha em `VPS_PW`). Capturas `estado/push_*.png` |
| `37_espacamento_colado` | varredura de espaçamento nas páginas do vendedor (1360 e 390 px): pares de elementos vizinhos (botão, campo, título, parágrafo, cartão) com folga < 6 px. Os que sobram são de desenho (abas lado a lado, faixa de faturamento colada ao cabeçalho no celular). Só leitura |
| `38_layout_a_vs_modelo` | valida as páginas (Dashboard, Bots, Fluxos, Leads, Vendas, Ranking, Conta) contra o **Modelo A** aprovado: monta o HTML do modelo (`layout-a-modelo/`, cópia dos artefatos de design) e compara os estilos calculados (fonte, cor, raio, espaçamento, sombra) de cada tipo de elemento com os da página real da VPS, no desktop e (`LARG=390`) no celular, mais os textos que faltam. Usa a conta de volume `e2-1@estresse.invalid`. Diferenças esperadas: cor de destaque (white-label), nome do sistema, dados. Só leitura; capturas `estado/modelo_<página>[_m]_{modelo,real}.png` |
| `tg_info`, `omega_check` | só leitura: `getMe`/`getWebhookInfo` do bot; 1 consulta de transação inexistente na Omega (401 = credencial recusada **para o IP de quem chamou**, 404 = credencial aceita) |
| `controles`, `modal` | utilitários: listam formulários/botões de uma rota e o que um clique abre (`MSYS_NO_PATHCONV=1` no Git Bash para o `/rota` não virar caminho do Windows) |

## Cuidados

- Ids fixos da rodada de 15/10 (bot `12168`, fluxos `6144`–`6147`): em outra conta, trocar (`grep -n 12168`).
- Se o domínio mudar, trocar `https://coyotebot.com.br` nos scripts.
- Dentro do Chrome do teste, strings com `\` (regex) quebram quando o script é gerado por heredoc: processar o texto no Node, não na página.
- Toast: esperar o anterior sumir antes de ler o próximo (senão o teste lê o aviso velho).
- Não faz Pix real nem manda mensagem ao Telegram: para isso é preciso uma pessoa dando `/start` no bot.
- Apagar a conta de teste depois (Admin → Usuários), pois guarda token de bot e credencial da gateway.

## E-mail do dono nos scripts

Os scripts que criam contas ou mandam e-mail de teste usam aliases (+admintest, +vps1510) do e-mail do dono. Para não publicar o endereço no repositório, ele vem da variável `EMAIL_DONO` (padrão `dono@exemplo.com`): `EMAIL_DONO=seu@gmail.com ADMIN_EMAIL=... ADMIN_SENHA=... node 15_admin_acoes.js`.
