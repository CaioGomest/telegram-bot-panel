# Gateways — NxGate, WiinPay e fallback (item 96 de `tarefas_revisao_final.md`)

Registro de tudo que foi feito, por quê, como foi testado e **as decisões que tomei sozinho para você ler e aprovar ou trocar** (seção 3). Pedido do dono (08/11/2026): "pode fazer tudo com base no que achar nas docs, faz uma varredura, anota tudo... quando tiver pronto me avisa e a gente revisa; os pontos onde tive que tomar decisão eu leio e vejo se aprovo".

**Status (09/11/2026): ✅ pronto no PC, NADA publicado** (nem VPS, nem homologação, nem GitHub, nada commitado). Aguardando a sua revisão.

## 1. O que existe agora

### Arquitetura (uma peça por gateway, nada de `if` espalhado)
Pasta nova `funcoes/gateways/`:

| Arquivo | Para quê |
|---|---|
| `_contrato.php` | interface `ProvedorGateway` (criar cobrança, assinatura, consultar) que todo gateway implementa |
| `_registro.php` | `registroGateways()` descobre sozinho todo `*.meta.php`; `registroGateway()`, `gatewayCredenciaisCompletas()`, `gatewayStatusPago()` |
| `_http.php` | a **única** função que usa rede (`gatewayRequisicaoHttp`), `gatewayChamadaJson()` (monta JSON, lê resposta, erro padronizado, log sem o corpo da requisição = sem chave de API) e `gatewayUrlWebhook()` |
| `_util.php` | leitura tolerante de respostas (procura campo em qualquer profundidade; reconhece o copia-e-cola pelo formato EMV `000201`), UUID de idempotência |
| `_fallback.php` | `gerarPixComFallback()` (o laço de fallback, usado por fluxo e renovação), `gatewayPrecoMinimo()`, `precoMinimoPixDoUsuario()`, aviso de fallback |
| `omegapayments.meta.php`, `nxgate.php` + `nxgate.meta.php`, `wiinpay.php` + `wiinpay.meta.php` | cada gateway; a classe da Omega continua em `funcoes/omegapayments_banco.php` (decisão D1) |

Outros arquivos novos: `funcoes/pagamento_confirmado.php` (pós-pagamento comum a todos), `funcoes/webhook_rotas.php`, `webhook_nxgate.php`, `webhook_wiinpay.php`, `tests/gateways/integracao/*`.

**Adicionar o 4º gateway = classe + `.meta.php` + `webhook_<nome>.php` + uma linha no `schema.sql` + teste** (explicado no CLAUDE.md, seção "Gateways de pagamento"). Tela, seed do banco, status de pago e preço mínimo leem do registro.

### O que mudou nos arquivos que já existiam
- `funcoes/gateways.php`: `gatewaysSuportados()`/`resolveGatewayProvider()` usam o registro; `saveUserGatewayConfig()` põe o gateway que entra (novo ou reativado) como **último fallback** e **não mexe na ordem ao salvar credenciais** (`proximaPrioridadeGateway()`); `listarGatewaysUsuario()` traz `cert_password` e calcula "conectado" pelo registro.
- `funcoes/omegapayments_banco.php`: `implements ProvedorGateway` + timeout de criação de cobrança 15 s (era 30 s) e conexão 5 s. Nenhuma regra mudou.
- `webhook_omegapayments.php`: de 539 para 199 linhas; só lê o payload da Omega e as vendas de ciclo de assinatura; o resto virou `processarPagamentoGateway()`.
- `funcoes/fluxo_blocos.php`: o laço de gateways (99 linhas) virou uma chamada a `gerarPixComFallback()`.
- `cron/cron_renovacao.php`: usava só o 1º gateway; agora tem fallback e **grava `vendas.id_gateway`** (ver D6).
- `cron/cron_verificar_pix.php`: status "pago" vem do registro.
- `gateways.php` (tela): formulário e cartões vêm do registro (campos por gateway, letra/cor, destino do split, caixa de limites); **setas ↑/↓** em cada card; texto de ajuda explicando a fila.
- `api.php`: o preço mínimo que o editor aceita é o do usuário (`precoMinimoPixDoUsuario`), não mais só o da Omega.
- `funcoes/controle.php` e `funcoes/manutencao.php`: reconhecem `webhook_<gateway>.php` por regra (`ehWebhookPagamentoGateway`), sem lista.
- `instalacao.php`, `admin/atualiza_banco.php`, `deploy/schema.sql`: uma linha de `gateways` (desativada) por gateway do registro.
- `assets/css/painel.css`, `layout_a.css`: setas e cores dos ícones (NxGate azul "N", WiinPay verde "W" — provisórias, ver pendências).
- `tests/homologacao/patch.php`, `tests/estresse/vps/patch_estresse.php`: o simulador do Telegram agora é injetado em `funcoes/pagamento_confirmado.php` (onde a função foi parar).
- `CLAUDE.md`: regra permanente de código (pedido do dono) + "como adicionar um gateway".

### Como cada gateway funciona
**NxGate** (docs https://nxgate.com.br/doc, lidas em 08/11): `api_key` no corpo; `POST /v2/cobranca` (valor mín. R$ 1,00) → `idTransaction` + `paymentCode`; `POST /v2/consultar` → `paid`/`waiting`. Chave `nxg_test_` usa o sandbox sozinha. Webhook assinado (HMAC-SHA256, 300 s). Sem reenvio de webhook (o cron do Pix confirma). Split em centavos (máx. 90%). Sem Pix recorrente.
**WiinPay** (PDFs + dono): `https://api-v2.wiinpay.com.br`; `POST /payment/create` (`api_key` no corpo, valor mín. R$ 3,00, nome, e-mail, descrição, `webhook_url`, split `{user_id, value}`); `GET /payment/list/{id}` com `Authorization: Bearer`. Webhook com status `PAID`. Sem Pix recorrente.
**OmegaPayments**: igual a antes (única com Pix recorrente).

### Fallback (como o usuário escolhe e como funciona)
- Ordem = `usuarios_gateways.prioridade`, mostrada na tela como Principal / Fallback 1 / Fallback 2, com **setas ↑ ↓ (e arrastar)**, funciona no celular. Gateway novo ou reativado entra no fim da fila.
- O Pix sai pelo primeiro que conseguir. É **pulado** (sem falar com o cliente) o gateway que: tem credencial incompleta; não faz Pix recorrente (quando a venda é assinatura); tem valor mínimo acima do preço; respondeu erro/timeout/exceção; respondeu "ok" sem código Pix.
- Se o principal caiu e outro atendeu, o dono recebe um aviso no sino ("Gateway com falha"), no máximo 1 por hora; o motivo vai para `logs/vendas_debug.log`. Se todos falham, tudo como antes ("Não foi possível gerar seu Pix").
- A venda grava qual gateway atendeu (`vendas.id_gateway`); webhook e cron conferem o pagamento nesse mesmo gateway.

## 2. Testes (todos no PC)

| Teste | Comando | Resultado |
|---|---|---|
| Integração (MariaDB do WAMP, Telegram e APIs simulados, 7 fases A-G) | `node tests/gateways/integracao/rodar.js` | **121 ok, 0 falhas** |
| Tela no Chrome real (setas, ordem, celular, JS) | `node tests/gateways/integracao/ui_gateways.js` (depois do anterior) | **21 ok, 0 falhas** (desktop 1440 e celular 390) |
| Unitários de split/preço/comissão | `php tests/gateways/unitario_*.php` | 104 ok |
| Mensagem de preço mínimo do editor (JS) | `node tests/gateways/unitario_preco_guiado_js.js` | 23 ok |

Fases da integração: **A** Omega (pago, repetido, API não confirma = anti-forja, sem venda, grupo, ciclo de assinatura, ciclo forjado, ping, JSON inválido) · **B** fallback (ordem, falha, exceção, sem código, mínimo, recorrência, credencial, reativação, cron de renovação) · **C** NxGate (cobrança, split, sandbox, timeout, assinatura válida/inválida/replay/adulterada, evento pendente/saque ignorados, cron confirma sem webhook) · **D** WiinPay (dois formatos de resposta, split, mínimo, PAID/paid, anti-forja, ids candidatos) · **E** fallback entre os três gateways reais · **G** mínimo do editor · **F** HTML real da tela (ordem, setas, campos de cada gateway, nenhuma chave no HTML).

Defeitos que os testes acharam **e eu corrigi**: (1) salvar credenciais de um gateway ativo regravava a prioridade com o valor velho do formulário e desfazia a ordem escolhida (já existia, as setas agravariam); (2) a seta usava o irmão direto do card no DOM, que é o modal, então a ordem na tela não mudava (só o Chrome real pegou); (3) faltavam as linhas `nxgate`/`wiinpay` na tabela `gateways` (seed); (4) o `require` de `traqueamento.php` no arquivo movido estava com o caminho antigo.

O harness novo tem um **vigia** que retoma sozinho o `php.exe` congelado do Windows (já aconteceu 3 vezes e ele avisa "(vigia) retomei o processo").

Não testado (precisa de chave real, ver seção 5): chamada de verdade à NxGate (sandbox) e à WiinPay; Telegram real; Pix pago de verdade.

## 3. Decisões que tomei — para você aprovar ou trocar

| # | Decisão | Por quê | Como trocar |
|---|---|---|---|
| D1 | A classe da Omega **não saiu** de `funcoes/omegapayments_banco.php` | os testes de homologação e estresse alteram esse arquivo por regex; mover quebraria a bateria | mover + ajustar `arquivo` no `omegapayments.meta.php` e os 2 patches |
| D2 | Registro por **descoberta de arquivos** (`*.meta.php`), sem lista central | a regra nova: gateway novo = criar arquivo | — |
| D3 | `webhook_omegapayments.php` foi separado já agora, com teste de regressão (18 verificações da Omega) | evitar copiar 300 linhas por gateway | — |
| D4 | Criar cobrança na Omega: timeout **15 s** (era 30) e conexão 5 s; consultas seguem 30 s | com fallback, um gateway pendurado atrasava o cliente (e o MySQL fecha a conexão em 20 s) | `CURLOPT_TIMEOUT` em `omegapayments_banco.php` |
| D5 | Resposta "sucesso" sem código Pix ou sem id **conta como falha** e vai para o próximo | gateway novo com formato desconhecido não pode gerar venda sem Pix | `_fallback.php` |
| D6 | A cobrança de **renovação por cron** agora grava `id_gateway` | achado: sem isso, com fallback, o pagamento seria conferido no gateway errado (o primeiro) e nunca confirmaria | — |
| D7 | Aviso "Gateway com falha" no sino, **1 por hora por usuário** | não lotar o sino quando o gateway fica fora do ar | `avisarFallbackUsado()` |
| D8 | Valor **abaixo do mínimo** de um gateway = pula para o próximo (não é erro) — era a sua pergunta 3 | recomendação que fiz | — |
| D9 | **Pix recorrente só com a Omega**; NxGate/WiinPay são pulados nesse caso (e conta PF, como antes) — pergunta 4 | as docs não têm recorrência | `aceita_recorrencia` no `.meta.php` |
| D10 | Ordem: gateway novo/reativado = **último**; salvar credenciais **não mexe** na ordem | o empate em 100 era resolvido por nome, não pela escolha do usuário | `proximaPrioridadeGateway()` |
| D11 | **Credenciais sem tabela nova**: NxGate/WiinPay guardam a API Key em `client_secret` (cifrada, como a Omega); o **segredo do webhook da NxGate** fica em `cert_password` (cifrado). Com o segredo salvo, o webhook só vale com assinatura válida; sem ele, vale a reconsulta na API. Campo em branco = "manter" (não dá para apagar o segredo deixando vazio) | evitar migração de schema | coluna nova + `campos` no meta |
| D12 | **Split NxGate** sempre `fixed` em centavos, calculado pela mesma conta da comissão gravada (`valorDaFatiaDoSplit`); documento do comprador só vai se for CPF/CNPJ válido (senão a NxGate usa o da conta); idempotência derivada do `magic_id`; chave `nxg_test_` = sandbox | a comissão gravada na venda tem que ser exatamente o que foi enviado | `nxgate.php` |
| D13 | Webhook NxGate: 401 se a assinatura estiver errada (ela não reenvia, mas aviso forjado não merece 200); eventos de saque/conversão e "pendente" são ignorados | — | `webhook_nxgate.php` |
| D14 | **WiinPay com leitura tolerante** (a doc não traz o formato da resposta nem do webhook): id e status procurados por nome em qualquer profundidade, copia-e-cola reconhecido pelo formato EMV; split só com `value` em reais (não envio `percentage`, a doc é ambígua); e-mail sintético `cliente+...@telegrambot.local` (obrigatório na API, a plataforma não coleta); `PAID` ou `paid` valem | não tenho exemplos reais | `wiinpay.php`, `webhook_wiinpay.php` |
| D15 | Webhook WiinPay não tem assinatura: **sempre reconsulta** `payment/list/{id}` antes de liberar | segurança (igual à Omega) | — |
| D16 | Tela: formulário por gateway vem do registro; "Conectado" agora exige **todas** as credenciais obrigatórias (antes só `client_id`) | consequência de ter gateways sem client_id | — |
| D17 | O **editor** valida o preço mínimo pelo **menor mínimo entre os gateways do usuário** (sem nenhum configurado, o da Omega, como antes) | senão um plano de R$ 2,00 seria aceito com só WiinPay (mín. R$ 3,00) e falharia na venda | `precoMinimoPixDoUsuario()` |
| D18 | Mantive a frase "Para a Omega, o valor mínimo é R$ X" nos avisos do editor | está cravada em ~20 testes; troco para "seus gateways" se você aprovar | `funcoes/gateways.php` (2x), `edicao_fluxo_basico.js`, testes |
| D19 | Cores/letras provisórias dos ícones (NxGate azul "N", WiinPay verde "W") — pergunta 5 | não tenho os logos | mandar os logos e eu ponho (como o da Omega) |
| D20 | **Fora do escopo** (pergunta 6): "Todos os fluxos"/gateway por fluxo, Carteira, Extrato, Cartão, saque | outra funcionalidade | — |
| D21 | Gateway **"falso"** existe só na cópia de teste (`tests/gateways/integracao/gateway_falso.*`), nunca no painel | provar o fallback sem gateway de verdade | — |
| D22 | Webhooks novos recebem a URL **por cobrança** (campo `webhook`/`webhook_url`), sem cadastro no painel dos gateways; vale a "URL do painel" de Admin → Configurações (necessária para a renovação por cron) | as docs permitem; a Omega exigia URL fixa | `gatewayUrlWebhook()` |

## 4. Incidente durante o trabalho (por transparência)
Ao verificar um teste, rodei sem querer um `git stash` e depois um `git stash pop` (cerca de 1 minuto). **Nenhum conteúdo foi perdido** (pop limpo, sem conflito, 52 arquivos de volta, stash vazio). Efeito colateral: o `git` (autocrlf) regravou como CRLF arquivos que estavam em LF (os meus e os de outras sessões); isso quebrou um teste que depende de `\n` (`unitario_preco_guiado_js.js`). **Já restaurei LF** nos 37 arquivos que eu sabia que estavam em LF (lista nos avisos do git de antes) e o teste voltou a passar. Se alguma outra sessão notar diff de fim de linha, é isso. Lição guardada: nunca rodar `git stash` neste repositório (várias sessões trabalham na mesma pasta).

## 5. O que falta / riscos (para a revisão)
1. **Testar de verdade**: NxGate no sandbox (precisa de chave `nxg_test_`) e WiinPay (chave real, R$ 3,00 de mínimo). Para a WiinPay, conferir o corpo real da resposta e do webhook e ajustar `CHAVES_ID`/`CHAVES_CODIGO_PIX` se vier diferente. O que for estranho aparece em `logs/wiinpay_debug.log` e `logs/webhook_wiinpay.log` (sem chave).
2. **WiinPay `payment/list/{id}`**: se a resposta for uma lista de pagamentos (e não um registro), o status lido pode ser de outro pagamento — conferir no primeiro teste real.
3. **Valor mínimo**: NxGate R$ 1,00 e WiinPay R$ 3,00 vêm das docs; a tarifa de cada uma não foi informada (só a da Omega é calculada). Outros pontos que ainda usam o mínimo da Omega: oferta pós-venda em `execucao_fluxo.php`, `remarketing_campanhas.php` e descontos de renovação em `assinatura.php` (sem ID de usuário à mão; a validação do editor, que é a que importa, já usa o do usuário).
4. **Publicar**: nada foi publicado. Para a VPS: `deploy/vps/atualizar.sh`, **fusão de 3 vias** (a VPS ainda não tem os itens 36/ordem dos botões e outros — ver memória do projeto), depois **Admin → Atualizar Banco** (cria as 2 linhas de gateway), o admin liga NxGate/WiinPay e cada usuário cadastra a chave. Configurar "URL do painel" em Admin → Configurações. Os 7 crons não mudam.
5. O texto "Para a Omega..." (D18) e os ícones (D19).
6. Como **voltar atrás**: nada publicado, então basta não publicar. No PC: os arquivos novos estão listados na seção 1 (apagar); os alterados voltam com `git checkout -- <arquivo>` (cuidado: outras sessões têm alterações não commitadas nos mesmos arquivos, sem backup).

**Atualiza��o 10/11/2026 (D11 revisada):** o suporte da NxGate (Patrick) confirmou que plataforma multi-cliente nao deve usar HMAC ("hmac e para plataformas onde a pessoa utiliza so 1 conta"). O campo "Segredo do webhook" saiu da tela (so a API Key). O pagamento segue confirmado na API; `webhook_nxgate.php` ainda valida a assinatura se houver `cert_password` salvo (nao ha mais como salvar pela tela). Chave real da NxGate testada na VPS em 09/11: Pix criado e consultado (WAITING). WiinPay: chave recebida foi recusada ("Chave API invalida").
