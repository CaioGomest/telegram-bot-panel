# Como funciona o ciclo de vida de acesso (pagamento → liberação → aviso → corte → renovação)

Nota de referência (não é TODO) pra não precisar reler tudo do zero da próxima vez. Criada em 2026-09-17, a pedido do Caio, pra revisar com calma tudo relacionado a "validar pagamento, tirar acesso, avisar que tá vencendo" e garantir que funciona igual não importa por qual caminho o pagamento é confirmado.

## O ciclo completo

```
cliente paga → 1 de 4 caminhos confirma → libera/estende acesso em membros_grupos
    → (se assinatura/recorrente) aviso de tolerância perto do vencimento (cron_verificar_acessos.php)
    → (se compra única) aviso de "vence em breve" antes do vencimento (cron_aviso_vencimento.php)
    → vencimento chega → tolerância (se aplicável) → corte de acesso (cron_verificar_acessos.php)
    → (se assinatura manual, sem cobrança nativa recorrente) cron_renovacao.php gera novo PIX 3 dias antes de vencer
```

## Os 4 caminhos que confirmam pagamento e liberam acesso

Todos fazem a mesma coisa em essência (marcar venda como paga, criar/estender `membros_grupos`, revogar link antigo, gerar link novo, avisar o cliente) — mas são implementações **separadas**, então uma inconsistência entre elas afeta o cliente dependendo só de **qual caminho confirmou o pagamento primeiro** (corrida entre webhook, botão manual e cron — quem chega primeiro processa, os outros dois são bloqueados pelo `UPDATE ... WHERE status != 'pago'` + `rowCount()`).

| # | Caminho | Arquivo | Quando dispara |
|---|---|---|---|
| 1 | Webhook InfoPago, PIX comum | `webhook_infopago.php`, campo `pix` | Notificação automática do gateway |
| 2 | Webhook InfoPago, PIX Automático | `webhook_infopago.php`, campo `cobsr` | Notificação automática do gateway (recorrência nativa) |
| 3 | Botão manual "Já fiz o pagamento" | `webhook.php` | Cliente clica no bot, sistema reconsulta o gateway |
| 4 | Varredura de fallback | `cron/cron_verificar_pix.php` (primeira metade) | A cada minuto, pra vendas que nenhum webhook confirmou |

Caminhos 1 e 2 são, na prática, **o mesmo código** — os dois chamam `liberarAcessoGrupoInfopago()`, uma função compartilhada. Só 3 e 4 têm lógica própria de liberação de acesso, cada um reimplementando a mesma coisa separadamente (não reaproveitam a função de 1/2 nem uma função em comum entre si).

## O que foi encontrado e corrigido (2026-09-17)

Comparando os 4 caminhos lado a lado (com ajuda de uma pesquisa dedicada, não só leitura corrida), os caminhos 3 e 4 tinham dois problemas reais que os caminhos 1/2 já resolviam corretamente:

### 1. 🔴 Expiração não estendia a partir do acesso atual — corrigido

Os caminhos 1/2 sempre faziam: "se o cliente ainda tem acesso ativo no futuro, soma o tempo novo em cima da expiração atual; senão, conta a partir de agora" — protegendo o cliente que renova antes de vencer (ele não perde os dias que já tinha pago).

Os caminhos 3 e 4 faziam sempre `nova_expiracao = agora + tempo_do_plano`, **descartando silenciosamente os dias restantes** se o cliente renovasse antes do vencimento e a confirmação passasse por um desses dois caminhos. Como a corrida entre os 4 caminhos é imprevisível (depende de qual chega primeiro), isso significava que o mesmo cenário de renovação podia dar resultados diferentes pro cliente dependendo de pura sorte (webhook chegou primeiro = correto; cron/botão confirmou primeiro = perdeu dias).

**Corrigido** em `webhook.php` e `cron/cron_verificar_pix.php` — agora os 4 caminhos calculam a expiração da mesma forma.

### 2. 🔴 Acesso não era concedido se a criação do link do Telegram falhasse — corrigido

Nos caminhos 3 e 4, o `UPDATE`/`INSERT` em `membros_grupos` (a extensão/liberação de acesso de verdade) só rodava **dentro do `if` que checava se `createChatInviteLink` tinha dado certo**. Se essa chamada à API do Telegram falhasse por qualquer motivo (Telegram instável, bot sem permissão de admin no grupo, grupo excluído, etc.), a venda **já tinha sido marcada `status = 'pago'`** (isso já acontece antes, e é irreversível — nenhum dos outros 3 caminhos reprocessa uma venda que já está `'pago'`), mas o cliente **nunca recebia o acesso**, sem nenhuma tentativa automática depois.

Ou seja: cliente pagou de verdade, sistema sabe que pagou, mas o acesso ficava preso pra sempre esperando um retry que nunca vinha.

**Corrigido**: a gravação em `membros_grupos` agora roda **incondicionalmente** nesses 2 caminhos (mesmo padrão que os caminhos 1/2 já usavam via `COALESCE(VALUES(invite_link), invite_link)` — grava o link novo se deu certo, mantém o anterior registrado se não deu, mas a **expiração sempre estende**). A mensagem ao cliente também mudou: em vez de simplesmente "não foi possível gerar o link", agora avisa "o administrador entrará em contato" (mesmo texto que os caminhos 1/2 já usavam), deixando claro que o pagamento foi processado.

### 3. 🟢 `em_renovacao` não resetava no botão manual — corrigido, mas sem efeito prático hoje

Os caminhos 1/2/4 resetam `membros_grupos.em_renovacao = 0` ao confirmar um pagamento; o caminho 3 (botão manual) não tocava nessa coluna. **Conferido via grep: `em_renovacao` nunca é lido em lugar nenhum do código hoje** — só é escrito (`cron_renovacao.php` marca `1` ao gerar o PIX de renovação; os outros 3 caminhos resetam pra `0` ao confirmar). Corrigido por consistência, mas não corrigia nenhum bug funcional ativo — é só dado de auditoria/inspeção manual do banco, hoje sem consequência prática se ficar "preso" em 1.

## Tolerância (grace period) depois do vencimento — confirmado com o Caio

Em `cron_verificar_acessos.php`, `$DIAS_CARENCIA`:
- Assinatura manual (PIX gerado por `cron_renovacao.php`, sem `id_assinatura` nativo) **e** PIX Automático nativo (`id_assinatura` preenchido pelo gateway): **2 dias de tolerância** pros dois, depois de `data_expiracao`, antes de cortar o acesso — o cliente continua no grupo mesmo tendo "vencido", só recebe um aviso. Unificado em 2026-09-17 (a pedido do Caio — antes era 5 dias só pra assinatura manual, 2 pra recorrência nativa); o motivo é dar tempo do cliente conseguir pagar a renovação sem perder o acesso no meio do processo.
- Compra única (`tipo_cobranca = 'unica'`): **zero tolerância** — corta na hora que vence.

## Timeline consolidada por tipo de cobrança

**Assinatura manual** (sem PIX Automático nativo):
1. Compra confirmada → acesso liberado até `data_expiracao`.
2. `data_expiracao - 3 dias` → `cron_renovacao.php` gera novo PIX automaticamente, manda mensagem.
3. Se pago a tempo → um dos 4 caminhos estende o acesso, ciclo reinicia do passo 1.
4. Se não pago → `data_expiracao` chega → `cron_verificar_acessos.php` entra em modo tolerância (2 dias), manda 1 aviso quando faltam ≤2 dias de tolerância (ou seja, logo no início da janela).
5. Tolerância esgota sem pagamento → acesso cortado (ban+unban no grupo, link revogado, `status='expirado'`), mensagem final ao cliente.

**PIX Automático nativo** (`id_assinatura` do gateway):
1. Mesma coisa, mas sem `cron_renovacao.php` — o próprio gateway tenta cobrar automaticamente e manda webhook `cobsr` quando conseguir.
2. Se `data_expiracao` chegar sem confirmação → tolerância de 2 dias, aviso quando falta ≤1 dia.
3. Tolerância esgota → corte, igual ao caso manual.

**Compra única**:
1. Compra confirmada → acesso liberado até `data_expiracao`.
2. `cron_aviso_vencimento.php` manda aviso de "vence em breve" antes de vencer (regra: ≤24h antes se o plano é em dias; ≤5-15min antes se o plano é em minutos).
3. `data_expiracao` chega → `cron_verificar_acessos.php` corta na hora, sem tolerância.

## Mapa de arquivos-chave

| Responsabilidade | Arquivo |
|---|---|
| Confirma pagamento + libera/estende acesso (PIX comum e Automático) | `webhook_infopago.php` (função `liberarAcessoGrupoInfopago()`) |
| Confirma pagamento + libera/estende acesso (botão manual) | `webhook.php` |
| Confirma pagamento + libera/estende acesso (fallback) | `cron/cron_verificar_pix.php` (primeira metade) |
| Expira PIX pendente sem confirmar (segunda metade do mesmo arquivo) | `cron/cron_verificar_pix.php` |
| Gera PIX de renovação 3 dias antes de vencer (só assinatura manual) | `cron/cron_renovacao.php` |
| Aviso de "vence em breve" (só compra única) | `cron/cron_aviso_vencimento.php` |
| Tolerância + aviso + corte de acesso (assinatura/recorrente) e corte imediato (única) | `cron/cron_verificar_acessos.php` |

## Notas relacionadas

- `como-funciona-pagamento-gateway.md` — fluxo completo de pagamento/gateway/split (a parte que acontece *antes* da liberação de acesso descrita aqui).
- `analise-potencia-e-escala.md` — seção 2 documenta a correção de `flock`/`LIMIT`/idempotência do `cron_verificar_pix.php` (achado anterior, sobre concorrência/performance — este documento aqui é sobre correção do resultado final pro cliente, não sobre performance).
