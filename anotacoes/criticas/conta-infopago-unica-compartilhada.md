# 🔴 CRÍTICO — Conta InfoPago única compartilhada por todos os usuários

Nota de referência criada em 2026-09-17, a pedido do Caio, na pasta `anotacoes/criticas/` — itens que **precisam de alinhamento/decisão antes de publicar** a plataforma pra usuários reais. Este é o primeiro item dessa pasta.

## Confirmado no código, hoje, sem ambiguidade

**Sim, é assim hoje: existe uma única conta InfoPago (a do usuário `admin`) processando o dinheiro de todos os usuários da plataforma.** Usuários comuns não têm — e não conseguem ter — suas próprias credenciais de verdade na InfoPago dentro do sistema atual.

- `funcoes/gateways.php:41-55` (`getInfopagoCredenciaisAdmin()`) busca as credenciais de **um único usuário**, filtrando explicitamente `WHERE g.nome = 'infopago' AND u.perfil = 'admin'`.
- `funcoes/gateways.php:57-85` (`getUserGatewayConfig()`) — quando o gateway é `'infopago'`, **sobrescreve** `client_id`, `client_secret`, `certificado`, `cert_password`, `chave_pix` do usuário comum com os valores do admin (linhas 76-81). O que o usuário comum tem no banco (`usuarios_gateways`) nesse caso é só o `ativo`/`prioridade` — liga/desliga, não credencial de verdade.
- Mesmo padrão em `getUserGateways()` (linhas 87-128) pra telas de listagem.
- O próprio comentário no código já documenta a decisão de propósito: *"A InfoPago usa credenciais únicas do admin (compartilhadas por toda a plataforma) — usuários comuns só ligam/desligam o gateway"* (`gateways.php:37-40`).

Reconfirmado agora, na íntegra, lendo o arquivo de novo — não é um achado antigo que pode ter mudado, é o estado atual do código.

## O que isso significa na prática

1. **Todo o dinheiro de todos os usuários cai primeiro na mesma conta bancária** (a conta InfoPago do admin), não na conta de quem vendeu. O "repasse" pro dono do bot (`funcoes/infopago_split.php`) é uma transferência Cash-Out **de saída** dessa mesma conta, feita depois, não uma divisão automática de uma cobrança que já nasceu separada por dono. Enquanto o split não roda (ou se falhar — ver `varredura`/`analise-potencia-e-escala.md`, split não tem retry automático), o dinheiro do usuário fica retido na conta do admin.

2. **Ponto único de falha.** Se o certificado mTLS do admin expira, a senha é revogada, ou a InfoPago suspende/bloqueia a conta por qualquer motivo, **todos os usuários da plataforma param de vender ao mesmo tempo** — não é isolado por usuário.

3. **Risco de volume/compliance não está sob controle da plataforma.** Provedores de pagamento (InfoPago incluso, como qualquer PSP) aplicam limites de KYC, antifraude e volume por conta mercante. Uma única conta processando a soma de centenas de usuários (potencialmente dezenas de milhões de transações/ano, se a plataforma crescer como planejado) tende a acionar revisão manual, congelamento temporário ou exigência de upgrade de conta bem antes de qualquer limite técnico de API virar o problema.

4. **Possível questão regulatória/legal** (fora do meu campo de análise técnica, mas vale levantar pra quem entende de compliance financeiro): a plataforma está, na prática, **recebendo e retendo dinheiro em nome de terceiros** numa única conta antes de repassar. Dependendo da jurisdição e do volume, isso pode se enquadrar em regras de instituição de pagamento/intermediador financeiro que exigem licenciamento específico (não é o caso de simplesmente "processar o próprio pagamento"). Recomendo validar isso com um advogado/contador especializado em meios de pagamento antes de publicar em escala — isso está além do que dá pra resolver só no código.

## Perguntas que precisam de resposta antes de publicar

1. **É intencional manter assim?** Ou a ideia original era cada usuário ter a própria conta/credencial InfoPago, e isso ainda não foi implementado?
2. Se for manter compartilhado: **já foi validado com a InfoPago diretamente** que a conta aguenta o volume esperado (700 usuários, ~120 vendas/dia cada, ~84 mil vendas/dia no total) sem acionar bloqueio por risco/KYC?
3. **Existe plano de contingência** se essa conta for suspensa/congelada um dia — mesmo que temporariamente, isso paralisa a receita de todos os usuários ao mesmo tempo?
4. **Foi confirmado com contador/advogado** que operar dessa forma (reter e repassar dinheiro de terceiros por uma conta só) está dentro da lei pro volume e modelo de negócio planejado?

## O que mudaria se cada usuário tivesse conta própria

Não é uma mudança pequena — é uma decisão de arquitetura, não um bug pra corrigir com uma linha. Envolveria:
- Cada usuário se cadastrar diretamente na InfoPago (ou provedor equivalente) e configurar as próprias credenciais reais em `usuarios_gateways` (a tabela e a tela já existem, só não são usadas de verdade pra InfoPago hoje).
- `getUserGatewayConfig()`/`getUserGateways()` parariam de sobrescrever com as credenciais do admin.
- O split (`infopago_split.php`) deixaria de fazer sentido do jeito atual (hoje ele existe porque o dinheiro cai numa conta só e precisa ser repartido depois) — cada cobrança já nasceria na conta certa.
- Não decidi nem recomendei essa mudança sozinho — é exatamente o tipo de decisão que pedi alinhamento antes de mexer, porque muda a arquitetura de pagamento inteira, não é um ajuste pontual.

## Notas relacionadas

- `como-funciona-pagamento-gateway.md` — fluxo completo de pagamento/gateway/split como funciona hoje.
- `analise-potencia-e-escala.md`, seção 3 — primeira vez que esse achado foi documentado, do ângulo de escala/volume.
- `como-funciona-ciclo-acesso.md` — o que acontece depois que o pagamento é confirmado (liberação/corte de acesso), não depende dessa decisão.
