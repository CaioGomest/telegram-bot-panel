# Como funciona o envio de e-mail

Atualizado em 06/10/2026. Cobre: cadastro, confirmação de e-mail, recuperação de senha, aviso de senha alterada, a fila de envio e a configuração do SMTP.

## Resumo em 30 segundos

- O painel envia e-mail por **SMTP próprio** (código nosso, sem biblioteca). Quem configura é o admin em **Admin → Configurações → Envio de e-mail**.
- **A Hostinger bloqueia o `mail()` do PHP** nesta conta (erro `550 Local sendmail disabled`). Sem SMTP configurado, **nenhum e-mail sai**: eles ficam esperando na fila.
- E-mails não urgentes (boas-vindas/confirmação, senha alterada) vão para uma **fila**; o **cron de 1 minuto** envia. O código de recuperação de senha sai **na hora**, e só cai na fila se esse envio falhar.
- Confirmar o e-mail é **opcional** (só um aviso no menu). Não bloqueia o uso do painel.

## Arquivos

| Arquivo | O que faz |
|---|---|
| `funcoes/email.php` | Cliente SMTP, montagem da mensagem, modelos de e-mail, fila (enfileirar, processar, resumo) |
| `funcoes/verificacao_email.php` | Token de confirmação, "está verificado?", confirmar pelo link |
| `verificar_email.php` | Página pública aberta pelo link do e-mail (não exige login) |
| `funcoes/usuario.php` | Dispara os e-mails no cadastro e na recuperação de senha; API de reenvio |
| `google_callback.php` | Conta criada pelo Google já nasce com e-mail verificado |
| `barra_lateral.php` | Aviso "E-mail não confirmado — Reenviar" (desktop e menu mobile) |
| `admin/configuracoes.php` | Tela do SMTP, "Enviar teste" e painel da fila |
| `cron/cron_aviso_vencimento.php` | Chama `processarFilaEmails()` a cada minuto |
| `admin/atualiza_banco.php` / `instalacao.php` | Criam a coluna e as tabelas (ver abaixo) |

## Banco de dados

- `usuarios.email_verificado_em` (DATETIME, nulo = não verificado). Ao criar a coluna, **todas as contas que já existiam** foram marcadas como verificadas (isso roda só uma vez, quando a coluna é criada; rodar "Atualizar Banco" de novo não verifica ninguém por engano).
- `tokens_email` — tokens de confirmação: `id_usuario`, `tipo` (`verificacao`), `token_hash` (SHA-256, único), `expira_em`, `usado_em`.
- `fila_emails` — a fila: `para`, `assunto`, `html`, `tipo`, `status` (`pendente` / `processando` / `enviado` / `falhou`), `tentativas`, `ultimo_erro`, `proximo_envio`, `valido_ate`, `criado_em`, `enviado_em`, `atualizado_em`.
- `configuracoes` (chave/valor, já existia) guarda a configuração de envio:

| Chave | Conteúdo |
|---|---|
| `smtp_host` | ex.: `smtp.hostinger.com` |
| `smtp_porta` | ex.: `465` |
| `smtp_seguranca` | `ssl` (porta 465), `tls` (STARTTLS, porta 587) ou `nenhuma` |
| `smtp_usuario` | normalmente o próprio e-mail da caixa |
| `smtp_senha` | **cifrada** com `criptografarSegredo()` (precisa de `CHAVE_CRIPTOGRAFIA_GATEWAYS` no `config.php`; sem ela a senha fica em texto puro no banco) |
| `email_remetente` | endereço do "De". Vazio = usa o usuário SMTP (ou `no-reply@<domínio>`) |
| `email_remetente_nome` | nome do "De". Vazio = nome do sistema |
| `url_base` | endereço fixo do painel, usado nos links dos e-mails (hoje `https://telegram.stackcode.com.br`) |

## Como o e-mail é enviado (`enviarEmail()`)

1. Valida o destinatário (`FILTER_VALIDATE_EMAIL`) e remove quebras de linha de cabeçalhos (evita injeção de cabeçalho).
2. Monta a mensagem **multipart/alternative**: versão texto simples + versão HTML, as duas em base64, assunto e nome do remetente codificados em UTF-8.
3. Se o SMTP está configurado (host + usuário + senha), conecta no servidor:
   - `ssl` → conexão segura direta; `tls` → conexão normal e `STARTTLS`; timeout de 10 s.
   - Conversa: `EHLO` → (`STARTTLS` + `EHLO`) → `AUTH LOGIN` → `MAIL FROM` → `RCPT TO` → `DATA` → `QUIT`.
   - Linhas que começam com ponto são "escapadas" (dot-stuffing) para não encerrar o `DATA` antes da hora.
4. Se **não** está configurado, tenta o `mail()` do PHP (que, nesta hospedagem, é recusado).
5. Resultado: devolve `true`/`false`; o motivo da falha fica em `emailUltimoErro()` e a linha vai para **`logs/email_debug.log`** (`OK`/`FALHA`, via, destinatário, assunto, erro — nunca a senha).

## Os e-mails e quando saem

### 1) Cadastro (boas-vindas + confirmação)
- Cadastro em `cadastro.php` → `criarUsuario()` cria a conta (sem verificação).
- Em seguida `enviarVerificacaoParaUsuario($id, true)` gera um token e coloca o e-mail **"Bem-vindo(a)"** na fila. Esse e-mail já traz o botão **"Confirmar meu e-mail"**.
- Se qualquer coisa falhar aqui, **o cadastro não é derrubado** (só vai para o log de erros).
- Há um só e-mail no cadastro (boas-vindas e confirmação juntos), para não mandar dois seguidos.

### 2) Confirmação de e-mail
- Link: `https://<url_base>/verificar_email?token=<64 caracteres>`.
- O token é aleatório (256 bits). **No banco fica só o hash SHA-256**; o token puro só existe no e-mail.
- Validade: **48 horas**. O link é de uso único; abrir de novo mostra "Este e-mail já estava confirmado".
- Pedir um novo link **invalida o anterior** (links antigos passam a dar "inválido ou já substituído").
- Reenvio: o botão **"Reenviar"** do aviso no menu chama `reenviar_verificacao_email` (exige login e token CSRF). Limite de **5 pedidos a cada 15 minutos por conta**. O e-mail de reenvio usa o modelo só de confirmação e também vai pela fila.
- O aviso "E-mail não confirmado" aparece no menu lateral (e no menu mobile) para quem ainda não confirmou e some depois. O status é guardado na sessão para não consultar o banco a cada página.
- Contas criadas pelo **Google** nascem verificadas, e contas antigas foram marcadas como verificadas na migração.

### 3) Recuperação de senha (`Esqueci a senha`, na tela de login)
- A pessoa informa o e-mail; o painel gera um **código de 6 dígitos** e guarda **na sessão** (`recuperacao_codigo`, expira em 15 minutos). Isso significa que o código só funciona **no mesmo navegador** que pediu.
- A resposta é **sempre a mesma**, exista o e-mail ou não (não revela quem tem conta). Limite de pedidos: 5 a cada 15 minutos por e-mail.
- O e-mail com o código sai **na hora**, direto no SMTP (a pessoa está esperando). Se falhar, o e-mail entra na fila e o cron tenta de novo — mas só enquanto o código ainda vale (15 min); depois disso o item vira "falhou: Expirou antes de ser enviado".
- Ao trocar a senha com o código certo: o painel grava a nova senha, **marca o e-mail como verificado** (receber o código prova que a pessoa é dona dele) e manda o aviso de "senha alterada". Tentativas de código errado são limitadas (mesmo limite do login).

### 4) Aviso de "Senha alterada"
- Sai (pela fila) depois de qualquer redefinição de senha. Avisa a data/hora e orienta quem **não** fez a troca a redefinir de novo.

## A fila de e-mails

- **Entrar na fila** (`enfileirarEmail`): grava o e-mail pronto (HTML incluso) com `status = pendente`. Se a fila estiver indisponível (tabela ausente), o painel envia direto como plano B.
- **Quem esvazia**: `processarFilaEmails()`, chamada pelo `cron/cron_aviso_vencimento.php` **a cada minuto** (junto com o apagar de prévias e os avisos de assinatura). Por rodada: até **25 e-mails** e no máximo **40 segundos**.
- **Sem envio duplicado**: antes de enviar, cada e-mail é "reivindicado" com um `UPDATE ... WHERE status = 'pendente'`; se outro processo já pegou, pula. Um item preso em `processando` há mais de 10 min (cron que morreu) volta para `pendente`.
- **Falhou?** Conta uma tentativa e espera mais antes da próxima:

| Tentativa que falhou | Próxima em |
|---|---|
| 1ª | 1 min |
| 2ª | 5 min |
| 3ª | 15 min |
| 4ª | 60 min |
| 5ª | vira `falhou` (para de tentar) |

- **Sem SMTP configurado**: a falha é de configuração, não do e-mail. Então o item **não gasta tentativa**: fica `pendente` e é reavaliado a cada 15 minutos, com a mensagem "Aguardando configuração do SMTP". Quando o SMTP for configurado, tudo que está na fila sai sozinho.
- **Validade** (`valido_ate`): usada no código de recuperação (15 min). Passou da validade, o item é marcado `falhou` sem enviar.
- **Limpeza automática**: enviados somem após 7 dias; falhos após 30 dias.
- **Reprocessar**: no admin, o botão **"Tentar de novo os que falharam"** devolve os `falhou` para `pendente` com 0 tentativas.

## Tela do admin (Configurações → Envio de e-mail)

- Selo **"SMTP ativo"** ou **"SMTP não configurado"**.
- Campos: servidor, porta, segurança, usuário, senha (em branco = mantém a atual), e-mail e nome do remetente, URL do painel.
- Salvar pede **confirmação em duas etapas** (mesma tela de confirmação das outras configurações) e registra a atividade "E-mail".
- **Enviar e-mail de teste**: manda **direto** (sem fila) para o endereço digitado e mostra o erro técnico se falhar (ex.: "Usuário ou senha do SMTP recusados").
- Painel da fila: quantos **aguardando**, **enviados (7 dias)** e **com falha**.

## Como configurar na Hostinger

1. hPanel → **E-mails** → criar uma caixa do domínio, ex.: `no-reply@stackcode.com.br`.
2. Admin → Configurações → Envio de e-mail:
   - Servidor `smtp.hostinger.com`, porta `465`, segurança **SSL**.
   - Usuário: o e-mail completo da caixa; senha: a senha da caixa.
   - Remetente: o mesmo e-mail. URL do painel: `https://telegram.stackcode.com.br`.
3. **Enviar teste** para o seu e-mail; confira a caixa de entrada e o spam.
4. Os e-mails que estavam esperando na fila saem sozinhos no minuto seguinte.

## Segurança

- Token de confirmação: 256 bits aleatórios, só o hash no banco, uso único, 48 h.
- Links usam `url_base` fixa; sem ela, o endereço viria do cabeçalho `Host` da requisição (que o visitante controla). **Mantenha `url_base` preenchida.**
- Cabeçalhos do e-mail são limpos de `\r`/`\n`/nulo (sem injeção de cabeçalho).
- Senha do SMTP cifrada no banco (se a chave do `config.php` existir) e nunca escrita em log.
- Recuperação de senha não revela se o e-mail existe e tem limite de pedidos e de tentativas.
- Reenvio de confirmação exige login + CSRF e tem limite de 5 por 15 min.

## Como conferir que está funcionando

- `logs/email_debug.log` no servidor: uma linha por tentativa (`OK`/`FALHA`).
- Admin → Configurações → "Fila de e-mails": se "Com falha" ou "Aguardando" crescer, veja o motivo no log ou clique em "Enviar teste".
- `logs/cron_aviso.log`: aparece "E-mails: X enviado(s), Y com falha nesta rodada." quando o cron processa algo.
- Banco: `SELECT status, ultimo_erro FROM fila_emails ORDER BY id DESC LIMIT 20;`

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Nada chega e a fila só cresce | SMTP não configurado (a Hostinger bloqueia o `mail()`) |
| "Usuário ou senha do SMTP recusados" | Usuário não é o e-mail completo, ou senha da caixa errada |
| "Não conectou em host:porta" | Servidor/porta errados, ou segurança errada (465 = SSL, 587 = STARTTLS) |
| Chega no spam | Configurar SPF/DKIM do domínio (a Hostinger faz isso sozinha se o DNS estiver nela) e usar um remetente do próprio domínio |
| Link de confirmação "expirado" | Passaram 48 h; usar "Reenviar" no menu |
| Código de recuperação "inválido" | Pedido em outro navegador (o código fica na sessão) ou passou de 15 min |

## Limites conhecidos / pendências

- Verificar o e-mail **não é obrigatório**; só aparece o aviso. Dá para exigir depois (redirecionar quem não verificou).
- Tokens de confirmação expirados/usados não são apagados automaticamente da tabela `tokens_email` (só os não usados são substituídos ao gerar um novo).
- O código de recuperação fica na sessão do navegador; não há link de redefinição por e-mail (decisão herdada do fluxo anterior).
- Testado com servidor SMTP falso local e com dados descartáveis no servidor; **falta o primeiro envio real** assim que o SMTP for configurado.
