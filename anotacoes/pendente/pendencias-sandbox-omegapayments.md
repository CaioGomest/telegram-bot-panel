# OmegaPayments — pendências de validação em sandbox

Nota de referência (não é bug, é lista de "testar antes de ir pra produção"). Criada junto com a implementação do segundo gateway (OmegaPayments), ao lado da InfoPago. Ver `como-funciona-pagamento-gateway.md` pra entender o fluxo geral de pagamento/gateway do projeto.

## Por que essa nota existe

A documentação pública da OmegaPayments (`app.omegapayments.com.br/docs/v1`) tem bot-detection agressivo — depois das duas primeiras páginas (autenticação e endpoint de cobrança PIX), todo o resto passou a responder 403 Forbidden, mesmo tentando de novo em sessões diferentes (confirmado duas vezes, em datas diferentes). Não achei espelho público nem documentação de terceiros sobre a API dela. `funcoes/omegapayments_banco.php` foi implementado com o que deu pra confirmar, e com palpites claramente marcados `[A CONFIRMAR]` em comentário no código pra tudo que não foi possível validar. Nenhum desses pontos foi testado contra uma chamada real — **não habilitar em produção pra usuários reais antes de rodar os testes abaixo com credenciais de sandbox de verdade.**

## Itens a confirmar

1. **URL base da API** (`OmegaPaymentsBanco::__construct()`, `funcoes/omegapayments_banco.php`) — hoje está `https://api.omegapayments.com.br`, um palpite baseado no padrão do domínio principal. Não foi confirmada com o suporte nem testada.
2. **Path do endpoint "Buscar transação"** (`OmegaPaymentsBanco::consultarCobranca()`) — hoje usa `GET /gateway/pix/{identificador}`, um palpite seguindo o mesmo padrão REST do endpoint de criação (`POST /gateway/pix/receive`, esse sim confirmado). Pode estar errado.
3. **Schema de cada item do array `splits[]`** (`OmegaPaymentsBanco::montaPayloadCobranca()`) — mapeado hoje como `{pixKey, value}`. Testar com um split de 2 destinos e **inspecionar a resposta bruta da API** pra confirmar se foi aceito e interpretado do jeito esperado.
4. **Schema de `products[]`** — a doc confirmou que o campo existe no request, mas não o formato exato de cada item. Hoje manda um único item genérico (`name`, `quantity`, `price` = valor total).
5. **`client.email`/`client.phone` sintéticos** — a plataforma não coleta e-mail nem telefone do comprador em nenhum ponto do fluxo (só nome via lead do Telegram + CPF/CNPJ). Hoje manda `cliente+{documento}@telegrambot.local` e telefone fixo `11999999999`. Confirmar se a OmegaPayments exige formato "válido" de verdade (não só não-vazio) e se ela usa esse e-mail pra mandar algo pro comprador — se usar, o placeholder passa a ser um problema real, não só cosmético.
6. **Formato de `dueDate`** — hoje manda só a data (`Y-m-d`), calculada a partir do tempo de expiração configurado no bloco Pix do fluxo. Não confirmado se a API espera só data ou data+hora.
7. **Payload do webhook de pagamento** — schema do corpo que a OmegaPayments envia pro nosso `webhook_omegapayments.php` não foi confirmado. `extrairIdentificadorOmegapayments()` tenta vários nomes de campo prováveis (`transactionId`, `identifier`, aninhados em `data`/`transaction`). Isso não é um risco de segurança (a venda só é marcada como paga depois de reconsultar a API direto, nunca só pelo payload recebido — mesmo padrão já auditado da InfoPago), mas se o campo certo não for nenhum dos tentados, o webhook vai ignorar a notificação e só o `cron/cron_verificar_pix.php` (fallback) vai confirmar o pagamento, com atraso.
8. **Enums completos de status/erro** (`/docs/v1/enums`, também bloqueado com 403) — `consultarCobranca()` confia que o status de "pago" cai em `['CONCLUIDA','PAGO','LIQUIDADO','PAID','APPROVED','COMPLETED']` (mesma lista usada pra InfoPago). Se a OmegaPayments usar um valor diferente desses, o pagamento nunca vai ser confirmado automaticamente.
9. **Cadastro da URL de webhook no painel da OmegaPay** — a doc avisa que existe um limite de 20 webhooks por integração e que mandar uma `callbackUrl` diferente por transação estoura esse limite. `montaCallbackUrl()` sempre monta a mesma URL fixa (baseada no host da própria instalação), nunca uma por venda — mas ainda falta confirmar no painel da OmegaPay quais eventos assinar (pelo menos "transação paga") e se cadastrar via painel também é necessário além de mandar no payload.

## O que já foi confirmado (não precisa reconfirmar)

- Autenticação: headers `x-public-key`/`x-secret-key` (sem OAuth2, sem mTLS).
- Endpoint de criação de cobrança: `POST /gateway/pix/receive`.
- Campos de resposta da criação: `transactionId`, `status`, `fee`, `webhookToken`, `order{id,url,receiptUrl}`, `pix{code,image,base64,expiresAt}`.
- `amount` é em reais (não centavos), diferente de outros gateways Pix que usam centavos.

## Como testar antes de habilitar de verdade

1. Cadastrar credenciais de sandbox num usuário de teste em `gateways.php`.
2. Gerar uma cobrança PIX de teste pelo fluxo do bot — conferir que `pixCopiaECola`/`txid` normalizados em `criarCobranca()` batem com o que a resposta real trouxe, e que o QR/copia-e-cola funciona de verdade no app do banco.
3. Configurar um split de teste (2 destinos, ex. 80%/20%) pro mesmo usuário, gerar cobrança, e inspecionar a resposta bruta pra validar o item 3 acima.
4. Confirmar pagamento pelos dois caminhos: webhook real chegando em `webhook_omegapayments.php`, e fallback do `cron/cron_verificar_pix.php` — os dois têm que levar a exatamente um único envio de mensagem/liberação de grupo.
5. Ajustar os `[A CONFIRMAR]` no código (`funcoes/omegapayments_banco.php`, `webhook_omegapayments.php`) conforme o que a sandbox real revelar, e remover os comentários de incerteza depois de confirmado.

## Notas relacionadas

- `como-funciona-pagamento-gateway.md` — fluxo geral de pagamento/gateway/split (InfoPago).
- `criptografia-credenciais-gateway.md` — `client_secret`/`chave_pix` da OmegaPayments já caem automaticamente no mesmo mecanismo de cifra, sem código extra.
