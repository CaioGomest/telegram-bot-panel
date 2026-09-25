# Plano de remoção da InfoPago (checklist completo)

## ✅ EXECUTADO em 2026-09-25

Todo o checklist abaixo foi aplicado ao código local nesse dia, a pedido explícito do
Caio, **sem esperar a OmegaPayments ser validada em sandbox** (risco aceito
conscientemente — ver a conversa que gerou esse plano). Texto original do checklist
mantido abaixo como registro de referência/auditoria de tudo que foi tocado.

**Resumo do que foi feito, exatamente como planejado:**
- Seção 1 (arquivos apagados por completo): os 4 arquivos + `cron/cron_retry_split.php`
  — todos removidos.
- Seção 2 (edição cirúrgica dos arquivos compartilhados): todos os 22 pontos
  `=== 'infopago'` listados foram removidos/ajustados — `funcoes/gateways.php`,
  `webhook.php`, `cron/cron_verificar_pix.php`, `gateways.php`, `admin/usuarios.php`,
  `admin/atualiza_banco.php`, `instalacao.php`, `.htaccess`, `assets/css/coyote.css`,
  `funcoes/criptografia.php`.
- Seção 3 (banco): colunas `cashout_*` de `usuarios_gateways` agora são dropadas por
  `admin/atualiza_banco.php` (idempotente); a linha `'infopago'` em `gateways` **não é
  mais re-semeada**, mas também não foi apagada nem desativada manualmente — segue a
  recomendação original de preservar o histórico de vendas antigas. `usuarios_splits` se
  limpa sozinho na próxima migração (mecanismo já existia).
- Seção 5 (documentação): `como-funciona-pagamento-gateway.md` reescrito pro estado de
  gateway único; `criticas/conta-infopago-unica-compartilhada.md` marcado como resolvido
  (texto original preservado); `README.md`/`CLAUDE.md` atualizados.

**Achados extras durante a execução, fora do que o grep original pegou** (esses eram
consequências funcionais da remoção, não menções literais a "infopago"):
1. **`api.php::gateway_info`** calculava `suporta_recorrente` checando se existia um
   gateway ativo PJ — sem revisar isso, o editor de fluxo continuaria oferecendo "PIX
   Recorrente" pra blocos novos mesmo sem nenhum gateway capaz de processar (só a
   InfoPago tinha PIX Automático; OmegaPayments não suporta). Corrigido pra sempre
   retornar `false`.
2. **`webhook.php`**: o `if ($eh_recorrente && $nome_gateway === 'infopago')` não podia
   simplesmente virar um `else` sem condição — isso faria uma venda configurada como
   "recorrente" degradar silenciosamente pra cobrança avulsa. Em vez disso, virou um
   skip explícito (`"Gateway {$nome_gateway} não suporta PIX Recorrente"`, mesmo padrão
   já usado pro skip de conta PF), então o fluxo de fallback entre gateways continua
   correto.
3. **`.htaccess`**: a exceção de redirect que citava `webhook_infopago` **não tinha o
   equivalente pra `webhook_omegapayments`** — bug pré-existente independente da
   remoção, corrigido de brinde (trocado um pelo outro).
4. **Fluxos antigos com `tipo_cobranca = 'recorrente'` já salvos** no banco não foram
   migrados automaticamente pra `'unica'` — se disparados, vão cair no skip do item 2 e
   falhar silenciosamente pro cliente final. **Conferir manualmente se existe algum em
   produção.**
5. **Crontab da Hostinger** (fora do repo, painel externo): se `cron_retry_split.php`
   estava cadastrado lá, precisa ser removido manualmente — não é algo que o Git ou essa
   migração alcançam.

**O que NÃO foi feito** (fora do escopo desta execução, listado aqui pra não esquecer):
- `DELETE FROM gateways WHERE nome = 'infopago'` — decisão consciente de não apagar,
  ver seção 3 abaixo.
- Apagar fisicamente certificados antigos em `certificados/` no servidor (não existem
  no repo, só em produção — se houver, é limpeza manual no servidor).
- Rodar `admin/atualiza_banco.php` em produção (isso só acontece quando o código for
  deployado + a migração for executada de fato no servidor).

---

Levantamento original feito em 2026-09-24, com o código no estado logo depois da
entrada da OmegaPayments (commit `33aaf3f`) e da correção de split-por-gateway. Ver
[[como-funciona-pagamento-gateway]] pro contexto de como tudo funciona hoje antes de
mexer — o texto abaixo é o checklist original que guiou a execução acima.

---

## 1. Arquivos pra deletar por completo

Nenhum outro gateway usa nada desses 4 arquivos — não sobra código morto se forem
apagados inteiros:

- `funcoes/infopago_banco.php` — classe `InfopagoBanco` (Cash-In/cobrança).
- `funcoes/infopago_cashout.php` — classe `InfopagoCashout` (Cash-Out/split).
- `funcoes/infopago_split.php` — `dispararSplitInfopago()`.
- `webhook_infopago.php` — webhook de confirmação de pagamento InfoPago.
- `cron/cron_retry_split.php` — só existe pra reprocessar split falhado da InfoPago;
  sem objeto quando ela sair (OmegaPayments não tem "split pendente" retentável, ver
  [[como-funciona-pagamento-gateway]]).

## 2. Arquivos que precisam edição cirúrgica (não deletar o arquivo inteiro)

Cada um destes é compartilhado entre InfoPago e OmegaPayments — a remoção é ajustar,
não apagar o arquivo. Lista com toda linha específica de InfoPago encontrada na
varredura de 24/09 (número de linha pode ter deslocado um pouco se o arquivo mudou
depois desta data — conferir antes de aplicar).

### `funcoes/gateways.php`
- L38-55 `getInfopagoCredenciaisAdmin()` — função inteira, deletar (só InfoPago usa
  credencial-admin-compartilhada; OmegaPayments não tem equivalente).
- L71-80 `if ($gateway_nome === 'infopago') { ... }` dentro de `getUserGatewayConfig()`
  — remover o bloco inteiro (a função deve seguir só o caminho genérico depois).
- L108-122 mesmo padrão dentro de `getUserGateways()` — remover.
- L137, L143-144 `case 'infopago':` dentro do switch de `resolveGatewayProvider()` —
  remover o case.
- L160 `return ['infopago', 'omegapayments'];` → vira `return ['omegapayments'];`
  (ajustar conforme os gateways que existirem na hora).
- L201-233 `saveInfopagoCashoutConfig()` — função inteira, deletar.
- L271-292 mesmo padrão dentro de `listarGatewaysUsuario()` (a flag
  `gerenciado_pelo_admin` só existe por causa disso) — remover.

⚠️ **Atenção nos itens acima**: cada `if ($x === 'infopago')` está dentro de uma função
que também tem um caminho genérico por baixo — confirmar que remover o bloco InfoPago
não quebra o fluxo pra OmegaPayments (ela já passa por esses caminhos hoje sem cair
nesses `if`s, então na teoria é seguro, mas testar depois de cada remoção).

### `webhook.php`
- L6 `require_once __DIR__ . '/funcoes/infopago_split.php';` — remover (o arquivo já
  foi deletado no passo 1).
- L369-370 `if ($eh_recorrente && $nome_gateway === 'infopago') { ... }` — como só
  InfoPago tem PIX Automático hoje, esse `if` inteiro fica sem propósito; remover (ou
  manter a estrutura caso outro gateway ganhe recorrência no futuro, mas sem o branch
  InfoPago).
- L421, 432 comentários mencionando `InfopagoBanco` — atualizar/remover.
- L748 `$nome_gw_venda = 'infopago';` (fallback hardcoded) — remover junto com o `if`
  que ele alimenta.
- L771-772 `if ($nome_gw_venda === 'infopago') { dispararSplitInfopago(...); }` —
  remover o bloco inteiro (função já não existe mais).
- L809 comentário citando `webhook_infopago.php::liberarAcessoGrupoInfopago()` —
  atualizar.

### `cron/cron_verificar_pix.php`
- L149 `require_once __DIR__ . '/../funcoes/infopago_split.php';` — remover.
- L218-220 `if ($nome_gateway === 'infopago') { dispararSplitInfopago(...); }` —
  remover o bloco (esse era o único resquício InfoPago-específico deste arquivo, que já
  é gateway-genérico em tudo mais).
- L205, 266 comentários — atualizar.

### `gateways.php` (raiz — UI de configuração)
- L164-197 `elseif (... === 'salvar_infopago_split') { ... saveInfopagoCashoutConfig()
  ... }` — remover o handler de POST inteiro.
- L267-269 três `match($nome) { 'infopago' => ..., ... }` (ícone, letra, subtítulo) —
  remover o case `'infopago'` de cada um, deixando só `default` (ou os cases dos
  gateways que restarem).
- L339, L346 arrays literais `'infopago' => ...` — mesma coisa.
- L470-515 `<?php if ($nome === 'infopago'): ?>` — bloco inteiro do formulário de
  Cash-Out na UI, remover (isso inclui os campos `cashout_client_id`,
  `cashout_client_secret`, `cashout_certificado`, `cashout_cert_password` e o texto
  explicativo "A InfoPago não tem split nativo...").

### `admin/usuarios.php`
- L200 `... ?? 'infopago'` — trocar o fallback padrão pra outro gateway válido (ou pro
  primeiro item de `listarGatewaysAdmin()` sem hardcode nenhum).

### `admin/atualiza_banco.php`
- L483-486 bloco que insere a linha `'infopago'` em `gateways` — remover (não inserir
  mais esse catálogo; ver seção 3 sobre a linha existente no banco).
- L307-341 colunas `cashout_*` em `usuarios_gateways` (criação + loop de criptografia)
  — ver seção 3, decisão é sobre dropar ou não, não necessariamente sobre esse arquivo
  em si (a migração só *cria* a coluna, dropar é outro `ALTER TABLE` a acrescentar aqui
  quando for a hora).

### `instalacao.php` (fresh install)
- L230-233 colunas `cashout_*` na criação de `usuarios_gateways` — remover se a decisão
  for dropar as colunas (ver seção 3).
- L427-429 seed que insere `'infopago'` em `gateways` — trocar pelo seed de
  `omegapayments` (hoje só existe em `admin/atualiza_banco.php`, não em
  `instalacao.php` — instalação nova está sem esse gateway até rodar a migração; vale
  corrigir isso de qualquer forma, InfoPago saindo ou não).

### `.htaccess` (raiz)
- L54 `RewriteCond %1 !^(webhook|webhook_infopago|api)$ [NC]` — remover
  `webhook_infopago` da lista (regra funcional, não é só comentário — se não tirar,
  fica uma exceção de rota morta, inofensiva mas suja).
- L43-44 comentário explicando a exceção — atualizar/remover.

### `assets/css/coyote.css`
- L974 `.icone-gateway-infopago { background: linear-gradient(135deg, #0ea5e9,
  #0369a1); }` — remover (fica sem uso depois que `gateways.php` parar de referenciar
  essa classe).

### `funcoes/criptografia.php`
- L91 `foreach ([..., 'cashout_client_secret', 'cashout_cert_password'] as $campo)` —
  se as colunas `cashout_*` forem dropadas (seção 3), tirar essas duas do array
  também.

### `admin/transacoes.php`
- L243, L317 label "Sem credenciais de Cash-Out" (`sem_credenciais` no filtro de
  `split_status`) — decidir se remove o status inteiro do filtro (se nenhum gateway
  restante usa esse conceito de "credenciais de cash-out") ou se generaliza o texto.

### `funcoes/omegapayments_banco.php` e `webhook_omegapayments.php`
- Comentários que comparam com a InfoPago ("diferente da InfoPago", "não é conta
  compartilhada como a InfoPago") — limpar depois, só cosmético, sem pressa.

### `api.php`, `funcoes/stories.php`, `funcoes/traqueamento.php`, `funcoes/webhooks.php`
- Comentários/exemplos avulsos citando InfoPago (`api.php:231`,
  `funcoes/stories.php:5`, `funcoes/traqueamento.php:85`,
  `funcoes/webhooks.php:532` — este último é o `'gateway' => 'infopago'` num payload
  de exemplo) — limpar depois, cosmético.

## 3. Banco de dados

- **Tabela `gateways`**: depois de parar de inserir a linha (seção 2), rodar
  `DELETE FROM gateways WHERE nome = 'infopago'` manualmente (não é automático — a
  migração só evita reinserir, não apaga o que já existe). Confirmar antes que não tem
  `usuarios_gateways`/`vendas.id_gateway`/`usuarios_splits` ainda apontando pra essa
  linha (FK/órfãos).
- **`usuarios_gateways.cashout_*`** (4 colunas: `cashout_client_id`,
  `cashout_client_secret`, `cashout_certificado`, `cashout_cert_password`) — só
  InfoPago usa. Decisão: dropar (`ALTER TABLE usuarios_gateways DROP COLUMN
  cashout_client_id, DROP COLUMN cashout_client_secret, DROP COLUMN
  cashout_certificado, DROP COLUMN cashout_cert_password`) ou deixar como colunas
  mortas (mais seguro no curto prazo, custo zero de espaço real). Recomendo dropar só
  depois de confirmar em produção que nada mais lê essas colunas.
- **`usuarios_splits`**: **limpeza automática, não precisa fazer nada manual** — assim
  que `'infopago'` sair de `gatewaysSuportados()` (seção 2), a próxima execução de
  `admin/atualiza_banco.php` já roda `DELETE FROM usuarios_splits WHERE gateway_nome
  NOT IN (...)` com a lista atualizada, apagando sozinho os splits órfãos da InfoPago.
- **`vendas`**: nenhuma coluna InfoPago-específica no nome (`id_assinatura`,
  `ultimo_txid_renovacao`, `tipo_cobranca` etc. são genéricas, só que hoje só a
  InfoPago as popula de fato). Não precisa mexer no schema — só ficam colunas
  "reservadas" pra um gateway que suporte recorrência no futuro.
- **`vendas_splits`**: histórico antigo de repasses InfoPago fica no banco como
  registro histórico (não apagar — é dado de venda real). Só não vai receber linha
  nova depois que o split parar de rodar.
- **Vendas antigas com `id_gateway` apontando pra InfoPago**: continuam existindo no
  histórico (`admin/transacoes.php` mostra `titulo_gateway` via join — se a linha do
  catálogo for apagada, o join quebra o nome exibido pra vendas antigas). Considerar
  manter a linha em `gateways` com `ativo=0` só pra preservar o histórico, em vez de
  fazer o `DELETE` de fato — **isso muda a recomendação acima**: talvez seja melhor
  **desativar** (`ativo=0`, já é o padrão) e nunca mais oferecer na UI, sem apagar a
  linha do catálogo. Decidir com o Caio antes de rodar qualquer `DELETE` em `gateways`.

## 4. Certificados

`certificados/` no repo só tem `.gitkeep`/`.htaccess` (gitignored). Em produção, os
arquivos reais de certificado InfoPago seguem o padrão `cert_{user_id}_{gateway_id}.*`
e `cashout_cert_{user_id}_{gateway_id}.*` — não são nomeados por "infopago" no arquivo,
só identificáveis cruzando o `gateway_id` com a linha `nome='infopago'` de `gateways`.
Depois de decidir o que fazer com a linha do catálogo (seção 3), apagar os arquivos
físicos correspondentes no servidor (não é algo que o Git rastreia).

## 5. Documentação (`anotacoes/`)

- `anotacoes/criticas/conta-infopago-unica-compartilhada.md` — fica sem objeto (é
  inteiro sobre o risco do modelo de credencial única da InfoPago). Arquivar ou
  apagar depois que a remoção for concluída.
- `anotacoes/como-funciona-pagamento-gateway.md` — já atualizado em 24/09 pra refletir
  as duas gateways; precisa de uma nova passada depois da remoção pra tirar toda menção
  a InfoPago que ainda restar (a maior parte do texto vira só sobre OmegaPayments).
- Outras ~25 notas em `anotacoes/` citam InfoPago de passagem (contexto histórico,
  varreduras de segurança já resolvidas, planos de escala) — não precisam edição
  urgente, são registro do que já aconteceu. Não apagar essas, só não tratar como
  documentação viva do sistema depois que a InfoPago sair.
- `README.md` (linhas 11, 32, 37, 49) e `CLAUDE.md` (linhas 77, 90) — atualizar as
  menções a InfoPago pra refletir só a(s) gateway(s) que restar(em).

## 6. Ordem recomendada de execução

1. Confirmar que OmegaPayments está validada em produção de verdade (não só em
   código) — sandbox testado, split conferido, webhook recebendo eventos reais.
2. Editar os arquivos compartilhados (seção 2) removendo os branches InfoPago, **sem
   ainda apagar os 4 arquivos da seção 1** — assim dá pra rodar `grep -ri infopago .`
   de novo e confirmar que não sobrou nenhuma referência funcional antes do passo
   destrutivo.
3. Deletar os 4 arquivos da seção 1.
4. Rodar `grep -ri infopago` de novo no repo inteiro — só deve sobrar acerto em
   `anotacoes/` (histórico) e talvez `README.md`/`CLAUDE.md` se ainda não atualizados.
5. Testar de ponta a ponta: gerar Pix via OmegaPayments, confirmar webhook, confirmar
   split, confirmar liberação de grupo, confirmar que `gateways.php` não quebra pra
   nenhum usuário que só tinha InfoPago configurada (ele vai simplesmente não ter
   nenhum gateway ativo — checar que o fluxo trata isso sem erro fatal).
6. Decidir e executar a limpeza de banco (seção 3) — com backup antes de qualquer
   `DELETE`/`DROP COLUMN`.
7. Atualizar a documentação (seção 5).
8. Registrar o resultado em `anotacoes/testes/` (seguindo a convenção já estabelecida
   em `plano-de-testes-24-09.md`), incluindo qualquer achado/ajuste que não estava
   previsto neste checklist.

## Referências

- [[como-funciona-pagamento-gateway]] — como tudo funciona hoje, com as duas gateways.
- Varredura completa que gerou este checklist: 49 arquivos tocam a string "infopago"
  no repo (contando documentação); 8 arquivos de código têm lógica condicional
  hardcoded (`=== 'infopago'`) que precisa edição cirúrgica, listados na íntegra na
  seção 2 acima.
