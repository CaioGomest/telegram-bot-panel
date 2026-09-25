# Como instalar o painel

Guia simples pra colocar o sistema no ar do zero, seja num servidor novo (Hostinger ou
qualquer hospedagem PHP+MySQL) ou local (XAMPP/similar). Leva uns 10 minutos.

## 1. O que você precisa antes de começar

- **PHP 8.1 ou mais novo**, com estas extensões (a maioria já vem ligada por padrão em
  qualquer hospedagem/XAMPP): `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `json`.
- **MySQL ou MariaDB** — não precisa criar o banco antes, o instalador cria sozinho.
- Um jeito de subir os arquivos pro servidor: `git clone`/`git pull`, FTP, ou upload de zip.

Não precisa de Node, Composer, build step nem nada disso — é PHP puro, roda direto.

## 2. Suba os arquivos

Coloque a pasta `telegram-bot-panel/` (o conteúdo deste repositório) na raiz do domínio
ou subdomínio que vai servir o painel. Se for por Git:

```bash
git clone https://github.com/CaioGomest/telegram-bot-panel.git
```

Se o painel vai morar num subdomínio (ex.: `painel.seusite.com`), aponte a pasta desse
subdomínio pra cá. Se vai morar na raiz do domínio, coloque direto lá.

## 3. Confira as permissões de pasta

Essas 4 pastas precisam de permissão de **escrita** pelo PHP (upload de foto, logo,
stories, certificado, log de erro). Na maioria das hospedagens já vem certo, mas se der
erro de "não foi possível salvar", ajuste com `chmod 755` (ou `775`) nelas:

- `uploads/`
- `logs/`
- `certificados/`
- `storage/`

## 4. Rode o instalador

Abra no navegador:

```
https://seudominio.com/instalacao.php
```

Preencha o formulário:

| Campo | O que colocar |
|---|---|
| Servidor do Banco (Host) | Normalmente `localhost` |
| Nome do Banco de Dados | Um nome à sua escolha (ex.: `telegram_saas`) — **não precisa existir ainda**, o instalador cria |
| Usuário do Banco | Usuário do MySQL com permissão de criar banco/tabela |
| Senha do Banco | Senha desse usuário (pode ficar em branco se não tiver) |
| Nome do sistema / Logo / Favicon | Opcional — dá pra configurar ou trocar depois em **Configurações**, já logado |

Clique em **Instalar e Criar Banco**. Isso faz tudo de uma vez:

- Cria o banco (se não existir) e as ~22 tabelas do sistema.
- Gera o `config.php` sozinho, com uma chave de criptografia própria e aleatória pra essa
  instalação (usada pra proteger as credenciais de gateway salvas no banco).
- Cria o usuário administrador padrão.

Se aparecer "Instalação concluída com sucesso!", tá pronto.

## 5. Primeiro login — troque a senha já

O instalador sempre cria o mesmo admin padrão:

- **E-mail:** `admin@exemplo.com`
- **Senha:** `123456`

⚠️ **Troque essa senha assim que entrar** (em **Minha Conta**, você vai precisar informar
a senha atual — que é `123456` — pra definir a nova) e, se quiser, troque também o
e-mail. Deixar a senha padrão num sistema com dado real é risco de segurança.

## 6. Configure o gateway de pagamento

Vá em **Gateways** (menu lateral):

1. Como admin, habilite o gateway **OmegaPayments** no painel de administração (ele
   começa desativado por padrão).
2. Ainda como admin (ou cada usuário na própria conta), cadastre suas credenciais
   (Client ID / Client Secret) — são as credenciais da própria conta na OmegaPayments,
   cada usuário usa a sua.
3. Se quiser que uma fatia de cada venda seja repassada automaticamente (comissão da
   plataforma), configure o **split** direto no card do gateway, na visão admin —
   percentual (ou valor fixo) + a chave Pix de destino. É uma regra única que vale pra
   todo mundo que usar esse gateway, não precisa repetir por usuário.

## 7. Configure os cron jobs

O sistema depende de 8 rotinas agendadas pra funcionar de verdade (confirmar pagamento,
liberar/cortar acesso a grupo, avisos, remarketing, etc.). Na maioria das hospedagens
isso se cadastra num painel tipo "Cron Jobs" (na Hostinger: hPanel → Avançado → Cron
Jobs). O comando de cada um é sempre no formato:

```
php /caminho/completo/do/site/cron/NOME_DO_ARQUIVO.php
```

| Arquivo | Frequência sugerida | Pra que serve |
|---|---|---|
| `cron_verificar_pix.php` | a cada 1 minuto | Confirma Pix pendente (rede de segurança caso o webhook falhe) |
| `cron_verificar_acessos.php` | a cada 1 minuto | Remove quem venceu o acesso ao grupo |
| `cron_aviso_vencimento.php` | a cada 1 minuto | Avisa cliente antes do acesso vencer |
| `cron_ranking.php` | a cada 1 minuto | Recalcula o placar do ranking |
| `cron_renovacao.php` | 1x por dia | Gera Pix de renovação pra quem está perto de vencer |
| `cron_remarketing.php` | a cada 5 minutos | Envia campanhas de remarketing agendadas |
| `cron_metricas_admin.php` | 1x por hora | Recalcula o cache de métricas do dashboard admin |
| `cron_limpar_stories.php` | 1x por dia | Apaga stories expirados (arquivo + banco) |

Rodando via linha de comando (CLI, que é como cron normalmente chama), nenhum desses
precisa de configuração extra — funcionam direto.

## 8. (Opcional) Login com Google

Sem configurar isso, o login normal por e-mail/senha funciona igual — o botão
"Continuar com Google" só não aparece até você preencher as credenciais (não fica um
botão quebrado, fica escondido). Pra habilitar:

1. Acesse **console.cloud.google.com/apis/credentials** (logado com uma conta Google).
2. Se não tiver um projeto ainda, crie um (qualquer nome).
3. **Criar credenciais** → **ID do cliente OAuth**.
   - Se pedir pra configurar a "Tela de consentimento OAuth" primeiro, faça isso — nome
     do sistema + seu e-mail de suporte.
   - Tipo de aplicativo: **Aplicativo da Web**.
4. Em **URIs de redirecionamento autorizados**, adicione exatamente (sem espaço, sem
   barra no final, trocando pelo seu domínio de verdade):
   ```
   https://seudominio.com/google_callback
   ```
5. Salvar. O Google mostra um **Client ID** (termina em `.apps.googleusercontent.com`) e
   um **Client Secret** (começa com `GOCSPX-`).
6. No painel, vá em **Configurações** (menu → Administração) → seção **"Login com
   Google"** → cole os dois valores → Salvar.
7. Teste em `/login` numa aba anônima — o botão deve aparecer.

Se alguém já tem conta criada por e-mail/senha e faz login com Google usando o mesmo
e-mail, o sistema liga as duas contas automaticamente na primeira vez (não duplica).

## 9. (Opcional) Chave secreta pra rodar cron por URL

Só é necessário se sua hospedagem **não** tiver um jeito de rodar cron via CLI e você
precisar chamar os arquivos de `cron/` por HTTP (`?chave=...`) em vez de linha de
comando. Se for o seu caso, abra `config.php` e adicione uma linha:

```php
define('CHAVE_SECRETA_CRON', 'uma-string-aleatoria-bem-grande-aqui');
```

Sem isso definido, chamar um cron por URL sem CLI simplesmente não vai funcionar — é
uma trava de segurança de propósito, pra ninguém disparar seus crons só descobrindo a
URL.

## 10. Pronto — o que fazer a seguir

- Crie seu primeiro bot em **Meus Bots** (vai pedir o token do BotFather do Telegram).
- Monte um fluxo de conversa em **Fluxos**.
- Teste uma venda de ponta a ponta com um valor baixo antes de divulgar pra valer.

## Problemas comuns

- **"Instalação concluída" mas o login não funciona depois** — confira se o `config.php`
  foi realmente criado na raiz do projeto (o PHP precisa ter permissão de escrita ali
  também, só durante a instalação).
- **Erro de conexão com o banco** — confira host/usuário/senha; se o MySQL não estiver
  na mesma máquina, o host não é `localhost`.
- **Quero rodar `instalacao.php` de novo** — só funciona sozinho na primeira vez (sem
  admin cadastrado ainda). Depois que existe um admin, só um admin já logado consegue
  abrir essa página de novo (proteção pra ninguém recriar o banco por acidente).
