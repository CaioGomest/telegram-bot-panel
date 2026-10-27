# Testes dos avisos push (Web Push) — plano em `deploy/PLANO-PUSH-NOTIFICACOES.md`

Resultados e números de cada rodada: `anotacoes/TESTES.md`. Nada aqui fala com a internet: os "serviços de push" são `tests/push/servidor_falso.php` (127.0.0.1).

## Sem banco nem rede (rodam em qualquer máquina)
No Windows local, a geração de chaves do PHP precisa de `OPENSSL_CONF=C:/xampp/php2/extras/ssl/openssl.cnf`.
| Arquivo | O que prova |
|---|---|
| `unitario_cripto.php` | RFC 8291 (vetor oficial byte a byte), ida e volta com chaves aleatórias, limites do payload, JWT VAPID e DER→R‖S (64 bytes sempre), mensagem sem dado de cliente |
| `unitario_ssrf.php` | validador do endereço do aparelho (só https dos serviços de push permitidos; `http`, IP, `localhost`, porta, `user@`, `evil.com`, IDN…) |
| `unitario_agrupamento.php` | Pix gerado (1 por 30 s por aparelho, ≥ 2 viram resumo), Pix pago (individual; > 20 vira "+N"), esperas e máximo de tentativas |
| `unitario_icones.php` | logo PNG transparente / JPEG / WebP / retangular / pequena / ausente → 5 PNG nos tamanhos certos, badge sem quadrado branco, versão muda com a logo |
| `unitario_sw.js` (`node`) | `sw.js` num sandbox: o aviso SEMPRE aparece (até com conteúdo ilegível), ícone com `?v=`, tag/renotify, clique (nunca sai do painel), renovação da inscrição |

## Na VPS, com o banco real (cria dados de teste e apaga tudo no fim)
`sudo -u www-data env PUSH_MODO_TESTE=1 php tests/push/<arquivo> …` (a VPS não é produção; roda como `www-data`).
| Arquivo | O que prova |
|---|---|
| `integracao_navegador_simulado.php` | aparelho simulado (gera P-256, inscreve, **decifra** o que chega): texto certo sem dado de cliente, VAPID verificado, caminhos A e B, agrupamento, 410/403/500/429/timeout, 3 trabalhadores sem duplicar, processo morto, ganchos (ligado/desligado/tabela quebrada), inscrição/limite de 10/troca de dono, aviso de teste, métricas, alerta de saúde |
| `carga_latencia.php latencia_a` | evento → chegada ao serviço de push com o trabalhador vivo (metas p50 < 150 ms, p95 < 500 ms) |
| `carga_latencia.php latencia_b` | idem sem trabalhador (cada evento num processo PHP novo; meta p95 < 800 ms) |
| `carga_latencia.php estavel --ritmos=50,100,200` | carga contínua: latência por ritmo, fila máxima, CPU/memória do trabalhador |
| `carga_latencia.php backlog --itens=200000 --trabalhadores=1,2` | fila grande drenada: vazão, CPU, memória, banco e o **site medido (GET /login) durante** a drenagem |
| `carga_latencia.php gancho` | custo do gancho no caminho quente (ligado × desligado; sem aparelho, 3 aparelhos, fila de 200 mil) |

Limites do que isso mede: o serviço falso é HTTP/1.1 local (o FCM real usa HTTP/2 com conexão aquecida, então o real tende a ser igual ou melhor); a latência da internet até FCM/Apple/Mozilla não entra.

## Tela (Chrome de teste) — `tests/vps/usuario-novo/36_push_tela.js`
`VPS_PW=<senha root> node 36_push_tela.js`. Liga os avisos só durante o teste, troca o `PushManager` por um falso (o Chrome de teste não recebe push de verdade), cria uma sessão de admin só para a aba do admin e devolve tudo no fim (`push_preparar.php` roda na VPS). Se travar no meio: `plink … "cd /var/www/painel && sudo -u www-data php -- desligar" < push_preparar.php`.

## Roteiro com aparelhos reais (só o dono consegue; nada disso é automatizável)
Antes: Admin → Configurações → Notificações → ligar (as chaves nascem sozinhas) e, na VPS, instalar o serviço `painel-push` (com autorização) ou deixar o envio pelo próprio site.
1. **Android** (Chrome): Minha Conta → Ativar avisos neste aparelho → Enviar aviso de teste. Feche o navegador, apague a tela, gere um Pix e pague → 2 avisos chegam, com a logo, e tocar abre `/vendas`.
2. **iPhone** (iOS 16.4+): Safari → Compartilhar → Adicionar à Tela de Início → abrir pelo ícone → Ativar avisos → teste. Tela bloqueada: Pix gerado e pago chegam com o ícone da logo.
3. Chrome, Edge e Firefox no computador; Safari no Mac.
4. Trocar a logo no admin → o ícone do aviso novo muda (o antigo some).
5. Negar a permissão → a tela explica como liberar.
6. Sair da conta → o aparelho para de receber (e some da lista).
7. Brave: precisa de "Usar serviços do Google para mensagens push" (a tela avisa).
