# Plano completo de instalação na VPS

Documento único para colocar o painel no ar numa VPS própria: o que decidir, o que pedir ao cliente, como instalar, como testar, como operar e como voltar atrás.
Tudo aqui foi escrito para a meta **no ar no sábado 17/10/2026**. Os scripts estão em `deploy/vps/` e o schema do banco em `deploy/schema.sql`.

> **Situação (13/10/2026): os scripts JÁ rodaram numa VPS de verdade** — Hostinger KVM 2 (2 vCPU, 8 GB, Boston/EUA) com **Ubuntu 26.04 LTS, PHP 8.5, MariaDB 11.8 e Apache 2.4.66**. A primeira execução achou 9 problemas (todos corrigidos; lista na seção 13) e depois passou por: instalação, reinstalação (idempotência), `atualizar.sh`, bateria de fluxos (575 verificações, 0 falhas), rastreio de 46 telas pela web, backup + restauração com dado marcado, troca de domínio nos dois sentidos, suspensão remota pela internet, carga leve (2.400 requisições) e reinício da VPS. **Ainda não testado:** Ubuntu 22.04 e 24.04 (o plano original) e as partes que dependem de dados reais do cliente (gateway com credenciais, bot de verdade, SMTP, Google, pagamento real) — roteiro nas seções 6 e 7.

---

## 1. Resumo em 10 linhas

1. **Sistema:** Ubuntu **limpo**. Validado na prática em **26.04 LTS (PHP 8.5)** — é o que a Hostinger entrega hoje quando se escolhe "Ubuntu" sem versão. O 22.04 (PHP 8.1, a versão em que o painel nasceu) e o 24.04 (PHP 8.3) são aceitos pelo script mas não foram testados.
2. **Pilha:** Apache 2.4 (MPM event) + PHP-FPM + MariaDB + Let's Encrypt. Apache porque o painel depende do `.htaccess` (regras de segurança e de URL).
3. **Instalação:** um comando — `sudo bash deploy/vps/instalar.sh --dominio painel.SEUDOMINIO --email-ssl SEU@EMAIL`. Leva ~10 minutos.
4. **O script faz:** pacotes, banco (com os ajustes do ambiente testado), PHP, Apache, código, tabelas + `config.php` (pelo instalador do painel), troca da senha do admin padrão, 10 crons, backup diário, firewall, fail2ban, HTTPS, **registro de quem entra na VPS por SSH (IP + localização)** e, se você passar as chaves, a **suspensão remota** (seção 8.1 e 8.2).
5. **Confere sozinho:** `verificar.sh` roda mais de 60 checagens (serviços, HTTPS, URLs bloqueadas, banco, senhas padrão, crons, backup).
6. **Banco novo e limpo** — não leva dado de teste. As configurações (gateway, split, SMTP, Google, bots) são refeitas na VPS com os dados **definitivos**.
7. **Antes de abrir ao público:** 1 venda real de R$ 2,10 pela VPS, conferir o repasse do split, e-mail e login com Google (seção 6 e 7).
8. **Backup:** diário às 03:15 (10 diários + 6 mensais). Configure também uma cópia **fora da VPS** (seção 9.3).
9. **Atualizar depois:** `sudo bash deploy/vps/atualizar.sh --dominio ...` (backup → código → migração → verificação).
10. **Voltar atrás:** `sudo painel-restaurar <backup>` (seção 9.5).

---

## 2. Decisões e por quê

| Decisão | Escolha | Por quê |
|---|---|---|
| Sistema | Ubuntu 22.04 LTS (24.04 aceito) | PHP 8.1 nativo = versão testada. Suporte até 2027 (padrão) / 2032 (ESM). |
| Servidor web | **Apache** + PHP-FPM | O painel usa `.htaccess` (bloqueio de pastas, remoção do `.php` da URL, cabeçalhos de segurança, `/l/slug`). No Nginx seria preciso reescrever e **retestar** tudo isso; no Apache vale o que já foi testado. |
| PHP | 8.1+ (FPM, pool próprio `painel`) | Funções perigosas desligadas como na homologação; sessões numa pasta própria com validade de 30 dias (o padrão do Ubuntu apagaria sessão com 24 min sem uso e deslogaria todo mundo). |
| Banco | MariaDB (a homologação roda 11.8; Ubuntu traz 10.6/10.11 — compatível) | **`sql_mode` sem modo estrito e isolamento `READ-COMMITTED`**, iguais à homologação. No modo estrito padrão, nome de lead com mais de 100 caracteres faz o INSERT falhar e o lead some em silêncio. |
| Timezone | `America/Sao_Paulo` no sistema, PHP e banco (`-03:00`) | O painel já ajusta por conexão, mas alinhar tudo evita erro de 3 horas em consulta direta. |
| HTTPS | Let's Encrypt (certbot, renovação automática) | Grátis; Telegram e OmegaPayments exigem HTTPS no webhook. |
| Código | Copiado para `/var/www/painel`, **do root, só leitura para o PHP**; só `uploads/ logs/ storage/ certificados/` são do `www-data` | Se alguém invadir o PHP, não consegue reescrever o código nem o `config.php`. |
| Dados de teste | **Não migrar** | As senhas, tokens, split e URLs mudam; leva só sujeira (dados de estresse, tokens antigos). Banco novo. |
| URL do painel | Gravada em Admin → Configurações (`url_base`) pelo instalador | O painel monta links de e-mail e o **webhook que a gateway chama nas cobranças geradas por cron** a partir dela (antes usava `http://localhost` em cobrança de renovação — corrigido nesta rodada). |

## 3. O que precisa estar pronto (checklist do cliente)

| # | Item | Quem | Observação |
|---|---|---|---|
| 1 | **VPS** Ubuntu 22.04 *puro* (sem Easypanel/CloudPanel/Plesk/Docker de template) | cliente ou Caio | 2 vCPU / 4–8 GB / 50+ GB NVMe. Na Hostinger: "Sistema operacional → Ubuntu 22.04 sem painel". Peça o **IP** e o acesso `root` por **chave SSH**. |
| 2 | **Domínio** + acesso ao DNS | cliente | Ex.: `painel.seudominio.com.br`. |
| 3 | **Registro A** do domínio apontando para o IP da VPS | quem controla o DNS | Propaga em minutos/horas — **faça primeiro**; sem isso o certificado falha. |
| 4 | **E-mail** para o Let's Encrypt e para o admin do painel | cliente | |
| 5 | **Caixa de e-mail** `no-reply@dominio` (SMTP) | cliente | Para cadastro, confirmação e recuperação de senha. Hostinger: `smtp.hostinger.com`, porta 465 SSL. |
| 6 | **Credenciais OmegaPayments** (Client ID e Client Secret) e a **chave Pix/produtor do split** | cliente / Caio | São as definitivas. |
| 7 | **Login com Google**: projeto no Google Cloud, tela de consentimento e credencial OAuth com URI `https://DOMINIO/google_callback` | Caio (com a conta Google do cliente) | Passo a passo no `INSTALACAO.md` seção 8. Opcional para o lançamento. |
| 8 | **Bot do Telegram** (token do BotFather) e o **grupo VIP** com o bot como administrador (convidar + banir) | cliente | Token **novo** — o token da homologação não vai para a VPS. |
| 9 | **Telefone de suporte** que aparece para o cliente final | cliente | |
| 10 | Quem recebe os **avisos do sistema** (e-mail do admin) | cliente | |

## 4. Cronograma até o sábado 17/10

| Dia | O que fazer | Resultado esperado |
|---|---|---|
| **Seg 12/10** | Receber VPS, domínio e DNS (itens 1–4). Reinstalar a VPS com Ubuntu 22.04 puro, se vier com painel. Conferir `ping DOMINIO` = IP da VPS. | VPS acessível por SSH; DNS apontando. |
| **Ter 13/10** | Seções 5.1–5.6: enviar o código, `--dry-run`, `instalar.sh`, `verificar.sh`. | Painel no ar em HTTPS, 0 falhas no `verificar.sh`. |
| **Qua 14/10** | Seção 6: senha, marca, SMTP, gateway, split, bot, grupo, fluxo de teste. | Tudo configurado com dados definitivos. |
| **Qui 15/10** | Seção 7: **venda real R$ 2,10**, renovação, expiração, remarketing, e-mail, Google. Conferir o split no painel da OmegaPayments. | Roteiro de aceitação todo verde. |
| **Sex 16/10** | Teste de backup **e restauração** (seção 9.5); backup fora da VPS; monitor de uptime; revisar `PENDENCIAS.md`; congelar mudanças. | Plano B comprovado. |
| **Sáb 17/10** | `sudo bash deploy/vps/verificar.sh ...`, olhar `logs/` e o painel do Telegram, liberar. Acompanhar a primeira venda real. | No ar. |

> Se a VPS atrasar, o caminho crítico é: **VPS + DNS → instalar → venda real**. SMTP, Google e remarketing real podem ficar para a semana seguinte sem impedir vender.

---

## 5. Instalação, passo a passo

Convenção: comandos em `bloco de código`; **VPS$** = digitar na VPS (como root); **PC$** = no seu computador (Git Bash).

### 5.1 Preparar o acesso

```bash
PC$  ssh root@IP_DA_VPS          # primeiro acesso (senha ou chave que a Hostinger cadastrou)
VPS$ apt-get update && apt-get -y upgrade && reboot   # reinicia se pedir; reconecte depois
```
Se a VPS veio com o **Ubuntu sem painel**, siga. Se veio com Easypanel/Docker/CloudPanel, **reinstale o sistema** pelo painel da hospedagem (o script recusa portas 80/443 ocupadas).

### 5.2 DNS

Crie o registro **A**: `painel.seudominio.com.br → IP_DA_VPS` (TTL baixo, 300 s). Confirme:
```bash
PC$  nslookup painel.seudominio.com.br      # tem que mostrar o IP da VPS
```

### 5.3 Enviar o código para a VPS

**Opção A — pelo Git (recomendada, depois que você der `push` com a pasta `deploy/`):**
```bash
VPS$ apt-get install -y git
VPS$ git clone --depth 1 -b new https://github.com/CaioGomest/telegram-bot-panel.git /root/painel-src
```
**Opção B — direto do seu PC (não depende do GitHub). Nunca envie `anotacoes/` (tem senhas):**
```bash
PC$  cd "/c/xampp/htdocs/telegram gerenciador bot - luigi/telegram-bot-panel"
PC$  tar czf /tmp/painel.tgz --exclude=./anotacoes --exclude=./tests --exclude=./.git --exclude=./.claude \
        --exclude='./logs/*' --exclude='./uploads/*' --exclude='./storage/*' --exclude='./certificados/*' --exclude=./config.php .
PC$  scp /tmp/painel.tgz root@IP_DA_VPS:/root/
VPS$ mkdir -p /root/painel-src && tar xzf /root/painel.tgz -C /root/painel-src
```

**Nas duas opções, rode isto uma vez** (o Windows pode ter gravado os scripts com fim de linha CRLF, e o bash da VPS quebra com "\r: command not found"; o comando é inofensivo se já estiver certo):
```bash
VPS$ find /root/painel-src/deploy -type f \( -name '*.sh' -o -name '*.sql' -o -name '*.php' \) -exec sed -i 's/\r$//' {} +
VPS$ chmod +x /root/painel-src/deploy/vps/*.sh
```

### 5.4 Simular (não altera nada)

```bash
VPS$ cd /root/painel-src
VPS$ bash deploy/vps/instalar.sh --dominio painel.seudominio.com.br --email-ssl voce@seudominio.com.br --dry-run
```
Leia o resumo: domínio, pasta, banco, ajustes calculados pelo tamanho da VPS. Ele avisa se o DNS ainda não aponta ou se o sistema não é suportado.

### 5.5 Instalar

```bash
VPS$ bash deploy/vps/instalar.sh --dominio painel.seudominio.com.br --email-ssl voce@seudominio.com.br \
        --admin-email voce@seudominio.com.br --nome-sistema "NomeDaMarca"
```
Para já deixar a suspensão remota pronta (seção 8.2), acrescente `--controle-id ID --controle-chave-publica CHAVE` (saída do `gerar-chaves.php`). Opções úteis: `--com-www` (inclui www no certificado) · `--sem-ssl` (DNS ainda não propagou; peça o certificado depois com o `certbot` que o script mostra) · `--endurecer-ssh` (desliga login por senha, **só se já entra por chave**) · `--swap-gb N`.

Ao final ele mostra a **senha do admin** (aleatória) e grava tudo em `/root/painel-credenciais.txt` (só root lê). **Guarde num cofre de senhas e apague o arquivo.**

### 5.6 Verificar

```bash
VPS$ bash deploy/vps/verificar.sh --dominio painel.seudominio.com.br
```
Meta: **0 falhas**. Avisos comuns na primeira hora: "ainda não há logs de cron" (espere 2 min), "ainda não existe backup" (`sudo painel-backup` uma vez), "backup só dentro da VPS" (seção 9.3).

Se algo falhar: `tail -50 /var/log/painel-instalacao.log`, `tail /var/log/apache2/painel-error.log`, `tail /var/log/php/painel-erros.log`.

---

## 6. Configuração do painel (primeiro acesso)

Entre em `https://DOMINIO/login` com o admin do passo 5.5.

1. **Minha Conta:** troque a senha. (A de `/root/painel-credenciais.txt` é só provisória.)
2. **Admin → Configurações:** nome/logo/favicon da marca; **URL do painel** (já preenchida); **Envio de e-mail (SMTP)** — servidor, porta 465/SSL, usuário e senha do `no-reply@`; teste o envio. Opcional: **Login com Google** (Client ID/Secret).
3. **Gateways:** habilite a **OmegaPayments**, cadastre Client ID/Secret do dono; no card do gateway (visão admin) configure o **split** (valor fixo ou %, e a chave de destino).
4. **Webhook da OmegaPayments:** cada cobrança já informa o endereço de retorno (`https://DOMINIO/webhook_omegapayments.php`, montado a partir da "URL do painel"). Se o painel da OmegaPayments também pedir um webhook cadastrado, use esse mesmo endereço. De qualquer forma, o cron de Pix confirma pagamentos em até 1 minuto como rede de segurança.
5. **Meus Bots:** crie o bot (token novo). O painel registra sozinho o webhook do Telegram em `https://DOMINIO/webhook.php?token=...`.
6. **Grupo VIP:** coloque o bot como **administrador** com *convidar usuários* e *banir usuários*.
7. **Fluxos:** crie o fluxo definitivo, ou importe os de teste (`deploy/fluxos-teste/*.json`, em **Fluxos → Importar**) e escolha o grupo em cada plano. Conecte o fluxo ao bot.
8. **Remarketing / Traqueamento / Webhooks:** só se for usar já (Facebook/UTMify exigem as chaves reais).

---

## 7. Roteiro de aceitação na VPS (antes de abrir ao público)

Marque cada item. Os de dinheiro usam **R$ 2,10** (mínimo da gateway com o split atual).

| # | Teste | Como conferir |
|---|---|---|
| 1 | `verificar.sh` sem falhas | Saída final "Tudo certo". |
| 2 | **Venda real, plano com grupo** | Pix gerado (QR + copia e cola) → pagar → mensagem de aprovado em ~1 s → link do grupo (uso único) → entrar → aparece em **Vendas** e no sino. |
| 3 | **Split** | No painel da OmegaPayments a venda mostra o repasse para a chave de destino. (O nosso banco **não** registra o split.) |
| 4 | Plano **sem grupo** | Paga, não recebe link; o sino mostra "Venda sem grupo". |
| 5 | **Plano de 30 minutos** | Aviso aos 15 min; removido do grupo no vencimento (cron `verificar_acessos`). |
| 6 | **Renovação** (já está no grupo) | Paga de novo; o prazo **soma**; não é removido. |
| 7 | **Remarketing** | Campanha para a sua conta; chega; contadores corretos. |
| 8 | **E-mail** | Cadastrar usuário novo → chega o link de confirmação; "Esqueci a senha" funciona. |
| 9 | **Login com Google** | Aba anônima: botão aparece e entra. |
| 10 | **Celular real** | Painel (login, dashboard, vendas) e o bot no Telegram pelo celular. |
| 11 | **Falha provocada**: pare o PHP-FPM 1 min (`systemctl stop php8.1-fpm` (ou `php8.3-fpm` no Ubuntu 24.04)) e religue | O Telegram reenvia o webhook; nada se perde (a venda é confirmada pelo cron de Pix em ≤ 1 min). |
| 12 | **Backup e restauração** | `sudo painel-backup`; depois, num momento calmo, `painel-restaurar` e conferir (seção 9.5). |
| 13 | Reiniciar a VPS | `reboot` → tudo volta sozinho (Apache, FPM, MariaDB, cron). `verificar.sh` ok. |

---

## 8. Segurança e segredos

**O que muda em relação à homologação (nada da homologação vai para a VPS):**

| Segredo | Na VPS |
|---|---|
| Senha do admin | Aleatória (20 caracteres); trocar no primeiro acesso. O admin padrão `admin@exemplo.com / 123456` **deixa de existir** (o script troca antes de qualquer acesso; o `verificar.sh` falha se achar senha padrão). |
| Senha do banco | Aleatória (28 caracteres), só no `config.php` (root:www-data 640) e em `/root/painel-credenciais.txt`. |
| `CHAVE_CRIPTOGRAFIA_GATEWAYS` | Nova, gerada pelo instalador. **Guarde o `config.php` no backup** — sem ela as credenciais da gateway salvas no banco não abrem. (O backup diário já inclui.) |
| Tokens de bot, chaves da gateway/Google/SMTP | Os **novos**, digitados no painel. |
| SSH | Idealmente só por chave (`--endurecer-ssh`); fail2ban bane tentativas; firewall só 22/80/443. |
| Histórico do GitHub | A senha SSH antiga e um token de bot antigo continuam no histórico **público** do repositório. Como tudo foi trocado, o risco é baixo; apagar o histórico (`git filter-repo` + force push) é opcional. |

**Já protegido pelo painel/servidor:** arquivos de inclusão (`funcoes/`, `parciais/`, `config`, `conexao`) respondem 403 pela web (só `funcoes/usuario`, a API de recuperação de senha, fica aberta de propósito); `logs/ storage/ certificados/ anotacoes/` bloqueados; `.git` e `*.sql/*.md/*.bak` bloqueados; crons só por linha de comando; cabeçalhos X-Frame-Options, nosniff e HSTS; versão do Apache/PHP ocultas.

**Se colocar Cloudflare/proxy na frente:** o painel usa `REMOTE_ADDR` (limite de tentativas de login por IP). Atrás de um proxy todos os acessos pareceriam do mesmo IP — avise antes, é preciso ajustar o Apache (`mod_remoteip`).

### 8.1 Quem entra na VPS — registro dos logins SSH (IP e localização)

O `instalar.sh` instala o serviço **`painel-ssh-acessos`**: ele acompanha o log do SSH da VPS e grava, para cada login, **quem** (usuário Linux), **como** (senha ou chave), **de que IP** e **de onde** (país/estado/cidade/operadora, consultados no ipwho.is, com cache). Também guarda as tentativas que falharam. Não altera o SSH nem o PAM (não há como travar o seu acesso) e não tem relação com o login do painel web.

```bash
sudo painel-ssh-acessos                    # logins dos últimos 30 dias + quem está dentro agora
sudo painel-ssh-acessos relatorio --dias=90 --todos   # inclui as tentativas que falharam
sudo painel-ssh-acessos agora              # sessões abertas neste momento
sudo painel-ssh-acessos ips                # um resumo por IP: quantos logins, primeiro/último, onde fica
sudo painel-ssh-acessos falhas             # quem mais tentou entrar sem conseguir (robôs de força bruta)
sudo painel-ssh-acessos localizar 8.8.8.8  # onde fica um IP (e diagnostica se a consulta está falhando)
systemctl status painel-ssh-acessos        # o serviço está registrando?
```
Os eventos ficam em `/var/log/painel-ssh/acessos.jsonl` (um JSON por linha, guardados por 24 meses via logrotate). Um IP que **nunca tinha entrado antes** aparece marcado `[IP NOVO]`.

**Aviso no seu Telegram (recomendado, 5 minutos):** o registro dentro da VPS pode ser apagado por quem tem root; a mensagem no Telegram é a cópia que fica **fora**. Crie um bot no @BotFather, mande `/start` para ele, descubra seu `chat id` em `https://api.telegram.org/bot<TOKEN>/getUpdates`, preencha `TELEGRAM_BOT_TOKEN` e `TELEGRAM_CHAT_ID` em `/etc/painel/ssh-acessos.conf`, rode `systemctl restart painel-ssh-acessos` e confirme com `painel-ssh-acessos testar-alerta`. A partir daí, todo login por **IP novo** chega assim: *"⚠️ Login SSH de IP NOVO na VPS … Usuário: root (publickey) · IP: … · Local: Campinas/SP, Brasil — Vivo"*. Seus próprios IPs fixos podem ir em `IPS_CONHECIDOS` (não avisam, mas continuam registrados); `ALERTAR=sempre` avisa todo login.

**O que cobre:** logins SSH (senha, chave, certificado) e tentativas falhas. **O que não cobre:** acesso pelo console de emergência do provedor (VNC/KVM do painel da Hostinger) e o que a pessoa faz depois de entrar (para isso existe o histórico de comandos/auditd, não instalado). Se o provedor tiver "terminal no navegador", ele entra por SSH e aparece no registro com o IP do provedor.

### 8.2 Suspensão remota — desligar o sistema por fora do servidor

**Para que serve:** você (e o cliente) podem ter a senha do painel, do banco e do SSH, e o cliente pode trocar tudo e sumir. Aqui o poder de suspender **não depende de nenhuma senha do servidor**: depende de uma **chave privada que só você tem**. Você manda um comando assinado para `https://DOMINIO/controle`; o servidor confere a assinatura com a chave **pública** (gravada no `config.php`) e, se confere, suspende ou reativa. Quem não tem a chave privada não consegue nem suspender nem reativar — nem o cliente, nem um invasor, nem a senha trocada.

**Montagem (uma vez por cliente, no SEU computador):**
```bash
php deploy/controle/gerar-chaves.php NOME_DO_CLIENTE --url=https://painel.cliente.com.br/controle
```
Cria `~/.painel-controle/NOME_DO_CLIENTE/` com `privada.pem` (**secreta — copie para um cofre de senhas; nunca vai para a VPS, para o GitHub nem para o cliente; sem ela você não consegue reativar**), `publica.txt`, `id.txt` e `perfil.json`. Ele imprime o comando para o instalador:
```bash
VPS$ bash deploy/vps/instalar.sh --dominio ... --email-ssl ... --controle-id 'ID' --controle-chave-publica 'CHAVE'
```
(ou, numa instalação já feita, cole as duas linhas `define('CONTROLE_ID', ...)` e `define('CONTROLE_CHAVE_PUBLICA', ...)` no fim do `config.php` — que é do root, o PHP só lê). Sem essas constantes o recurso fica desligado e o sistema nunca é suspenso por engano.

**Uso (no seu computador):**
```bash
php deploy/controle/enviar.php NOME consultar                       # mostra o estado e confere chave/URL/relógio
php deploy/controle/enviar.php NOME suspender --mensagem="Contrato em atraso — fale com Fulano"
php deploy/controle/enviar.php NOME suspender --modo=total --dias=7  # suspensão total, que acaba sozinha em 7 dias
php deploy/controle/enviar.php NOME reativar
php deploy/controle/enviar.php NOME reativar --dias=35 --modo=painel # LICENÇA renovável: se você não renovar em 35 dias, suspende sozinho
```
Suspender e reativar pedem para você digitar a palavra de confirmação (`--sim` pula). O comando usa a hora do servidor (não a do seu computador).

| | **modo `painel`** (padrão) | **modo `total`** |
|---|---|---|
| Painel, login, cadastro, API, telas | **503 "Sistema suspenso"** | 503 |
| Bot no Telegram (`webhook.php`) | segue vendendo | para de responder (a mensagem é descartada) |
| Links `/l/slug` dos anúncios | seguem funcionando | 503 |
| Confirmação de pagamento (`webhook_omegapayments.php`) | segue | **segue** — quem já pagou é registrado e atendido |
| Crons | seguem | param os de mensagens/renovação/aviso; seguem `verificar_pix` e `verificar_acessos` |
| Linha de comando (SSH, `atualizar-banco`, `painel-backup`) | nunca é barrada | nunca é barrada |
| `/controle` (a porta de reativação) | sempre aberta | sempre aberta |

Use `painel` como primeiro passo (o cliente perde o controle, os clientes dele não são prejudicados) e `total` só depois de avisar.

**Segurança do mecanismo:** assinatura RSA-SHA256 (OpenSSL); o servidor só tem a chave pública; cada comando é preso ao ID da instalação, vale 5 minutos (relógio) e só uma vez (hora estritamente crescente — copiar e reenviar um comando antigo é recusado); corpo máximo de 4 KB; nada é revelado a quem não assinou corretamente; toda recusa e todo comando aplicado ficam em `logs/controle.log`. Arquivo de estado corrompido ou apagado = sistema ativo (um defeito nunca suspende). Testado: 31 casos unitários (`php tests/controle/unitario.php`) e o ciclo completo por HTTP na homologação (suspender/reativar, os dois modos, replay, adulteração).

**Limites — leia antes de prometer isto a alguém:**
- **Quem tem root na VPS pode desligar o recurso** (apagar `controle.php`, editar o `conexao.php` ou o `config.php`, restaurar um backup anterior à suspensão, copiar o site e o banco para outro servidor). Nenhum software no servidor impede isso. A proteção real é **de quem controla a conta do provedor e o root**: se a VPS está na conta/no nome do cliente, ele sempre pode redefinir a senha de root e fazer o que quiser. Combine isto com o cliente: ou a VPS/o root ficam com você (e ele recebe só o admin do painel), ou aceite que a suspensão vale contra quem só tem o painel e contra acidente, não contra um cliente determinado.
- Mantenha as cópias de backup **fora** da VPS e sob o seu controle (seção 9.3). Sem o banco e o `config.php`, ninguém recria o sistema.
- **Perdeu a chave privada?** Você não consegue mais reativar um sistema suspenso (só editando o `config.php`/removendo `storage/controle_estado.json` como root). **Vazou?** Qualquer um com ela suspende. Em ambos os casos: gere um par novo e troque `CONTROLE_ID`/`CONTROLE_CHAVE_PUBLICA` no `config.php`.
- Suspender por inadimplência deve estar **no contrato** (prazo de aviso, o que acontece com os dados e com os clientes finais). Isto é um recurso técnico, não aconselhamento jurídico.
- Teste uma vez antes de entregar: `consultar` → `suspender` → abrir `/login` (deve dar 503) → `reativar` → `/login` volta.

## 9. Operação do dia a dia

### 9.1 Comandos que você vai usar

```bash
tail -f /var/www/painel/logs/*.log               # logs do painel (webhooks, crons, Pix)
tail -f /var/log/php/painel-erros.log            # erros de PHP
tail -f /var/log/apache2/painel-error.log        # erros do Apache
systemctl status apache2 php8.1-fpm mariadb      # serviços (php8.3-fpm no Ubuntu 24.04)
bash /root/painel-src/deploy/vps/verificar.sh --dominio DOMINIO   # saúde geral
grep CRON /var/log/syslog | tail                 # o cron do sistema está rodando?
mysql painel -e "SELECT COUNT(*) FROM vendas WHERE status='pago'"  # exemplo de consulta
```

### 9.2 Monitoramento
- **Uptime:** cadastre `https://DOMINIO/login` num monitor grátis (UptimeRobot) com alerta por e-mail/Telegram.
- **Disco:** `verificar.sh` falha acima de 85%. O backup se cancela acima de 92%.
- O próprio painel avisa no sino: bot desconectado, venda sem grupo, remoção do grupo que falhou, pagamento sem venda, falha ao gravar venda.

### 9.3 Backup (3 camadas)
1. **Automático local:** `/usr/local/sbin/painel-backup` às 03:15 → `/var/backups/painel/{diario,mensal}` (`banco_*.sql.gz` + `arquivos_*.tar.gz` com `config.php`, uploads, certificados, storage + `sistema_*.tar.gz` com `/etc/painel`: aviso de login SSH, destino do backup fora da VPS, retenção; este último se restaura à mão: `tar xzf sistema_AAAA-MM-DD_HHMMSS.tar.gz -C /`).
   Guarda os **10 últimos dias** + 1 por mês por 6 meses (`DIAS_DIARIOS` e `MESES_MENSAIS` em `/etc/painel/painel.conf`). Já sai comprimido (`gzip -9`): medido em 13/10, um banco de 2,8 GB em disco (6 mil vendedores, 5,8 mi de leads) gera um backup de **221 MB (7,8%)** em ~104 s de uma CPU. `xz` ficaria ~43% menor porém ~4× mais lento — não compensa; para economizar disco reduza `DIAS_DIARIOS` ou mande uma cópia por dia para fora da VPS. Contando 10 diários + 6 mensais, o banco pode chegar a ~35 GB num disco de 96 GB antes de as cópias deixarem de caber.
2. **Fora da VPS (faça!):** instale o rclone (`apt-get install -y rclone`), configure um destino (Google Drive/Backblaze/S3: `rclone config`) e crie `/etc/painel/offsite.conf` com `RCLONE_DESTINO="meudrive:backups-painel"`. O backup passa a enviar a cópia todo dia.
3. **Snapshot da VPS** no painel da hospedagem antes de qualquer mudança grande.

### 9.4 Atualizar o painel para uma nova versão

```bash
VPS$ cd /root/painel-src && git pull        # ou envie o código novo como no passo 5.3
VPS$ sudo bash deploy/vps/atualizar.sh --dominio DOMINIO
```
Faz backup → copia o código (não toca em `config.php`, uploads, logs, storage, certificados, `.well-known`, banco) → recarrega o PHP → roda a migração do banco → verifica. `--sem-banco` e `--sem-backup` existem para casos especiais. Depois de copiar, ele **prova** que o `config.php` ficou byte a byte igual e que os arquivos de uploads/storage/certificados não mudaram (se o `config.php` mudar, aborta e manda restaurar o backup do passo 1). **Nunca** faça `git pull` dentro de `/var/www/painel`, nem copie a pasta inteira do seu PC por cima: use sempre o `atualizar.sh` (ver seção 15). Arquivo posto à mão na pasta do site, fora de `uploads/`, **some** na próxima atualização (o `--delete` remove o que não está na versão nova).

### 9.5 Voltar atrás (restaurar)

```bash
VPS$ ls -lh /var/backups/painel/diario/
VPS$ sudo painel-restaurar /var/backups/painel/diario/banco_AAAA-MM-DD_HHMM.sql.gz /var/backups/painel/diario/arquivos_AAAA-MM-DD_HHMM.tar.gz
```
Antes de restaurar, ele tira um backup do estado atual (para desfazer), pausa os crons, recria o banco, importa, restaura os arquivos e religa os crons. **Teste isso na sexta (item 12 da seção 7)** — backup que nunca foi restaurado não é backup.

### 9.6 Trocar de domínio (ex.: do endereço provisório `srvNNN.hstgr.cloud` para o domínio do cliente)
Aponte o registro A do domínio novo para o IP da VPS, espere propagar e rode o instalador **de novo** com o novo `--dominio` (mesmos outros parâmetros; ele é idempotente: não recria banco, admin nem `config.php`):
```bash
VPS$ bash deploy/vps/instalar.sh --dominio painel.cliente.com.br --email-ssl voce@email --admin-email ... [--controle-id ... --controle-chave-publica ...]
```
Ele regrava o Apache só com o domínio novo, descarta o vhost HTTPS antigo, pede o certificado novo e troca a "URL do painel". Depois: `certbot delete --cert-name DOMINIO_ANTIGO` (o certificado velho segue renovando à toa) e **atualize a `url` do perfil da suspensão remota** (`~/.painel-controle/NOME/perfil.json`). **Troque o domínio ANTES de criar os bots**: o webhook do Telegram de um bot é gravado com o endereço da hora; bots já criados precisam ser abertos em Meus Bots e salvos de novo para apontar para o domínio novo (o instalador avisa quantos são). Testado nos dois sentidos em 13/10/2026.

### 9.7 Escalar
2 vCPU / 8 GB aguentam folgado o volume previsto (a hospedagem compartilhada, bem menor, aguentou ~50 requisições/s). Se crescer: (a) subir o plano da VPS e rodar `instalar.sh` de novo não é necessário — ajuste `innodb_buffer_pool_size` (25% da RAM) e `pm.max_children` em `/etc/php/*/fpm/pool.d/painel.conf`; (b) os crons de acesso/aviso/renovação são sequenciais (~45 membros/min): com muitos milhares de assinantes ativos, converse antes.

---

## 10. Revisão do SQL (resultado desta rodada)

O que existia: **não há arquivo `.sql` no repositório** — o schema mora em `instalacao.php` (tabelas novas) e em `admin/atualiza_banco.php` (migração de bancos antigos). Foi feita a conferência completa:

1. As ~39 tabelas que o instalador cria foram **executadas num banco real e comparadas, coluna por coluna, índice por índice e chave por chave** com o banco de homologação (instalação antiga + todas as migrações).
2. **Achados e corrigidos em `instalacao.php`:**
   - **5 tabelas faltavam** na instalação nova: `avisos_assinatura_enviados`, `fluxo_continuacoes`, `fluxo_ofertas_aceitas`, `mensagens_agendadas`, `previas_vistas` (só o Atualizar Banco criava).
   - **Colunas faltando:** `leads.id_fluxo`, `vendas.id_fluxo`, `vendas.nome_produto` — **sem `leads.id_fluxo` o painel não consegue gravar lead novo** (o `INSERT` do `/start` falha e o erro é engolido). Esse era o risco real de uma instalação nova sem rodar o Atualizar Banco.
   - **Índices faltando:** `idx_leads_fluxo`, `idx_vendas_fluxo`, e dois novos que a hospedagem de homologação cria sozinha (índices `wdbi_*`) e que numa VPS não existiriam: `idx_vendas_bot_criado (bot_id, criado_em)` e `idx_membros_plano_ativo (id_telegram, bot_id, status, data_expiracao)`.
3. Hoje o schema do instalador **é igual** ao do banco de homologação, exceto os índices `wdbi_*` (criados automaticamente pela hospedagem compartilhada, não fazem parte do schema) e o `idx_vendas_bot_criado` novo.
4. **`deploy/schema.sql`** — o schema completo (39 tabelas) em SQL puro, gerado do instalador e validado do mesmo modo. Use para revisar, recriar a estrutura ou montar um banco de teste: `mysql -u USUARIO -p BANCO < deploy/schema.sql`. A instalação normal **não** precisa dele.
5. **Configuração do MariaDB** que o script aplica (`/etc/mysql/mariadb.conf.d/99-painel.cnf`): `sql_mode=NO_ENGINE_SUBSTITUTION` e `transaction-isolation=READ-COMMITTED` (iguais à homologação), `wait_timeout=3600` (a hospedagem tinha 20 s, que derrubou a gravação de uma venda), buffer do InnoDB = 25% da RAM, `slow_query_log` (consultas > 1 s em `/var/log/mysql/slow.log`).
6. **Se mudar o schema no futuro:** altere `instalacao.php` **e** `admin/atualiza_banco.php` (regra já existente) e regenere o `deploy/schema.sql` (comparador em `tests/paginas/servidor/zz_schema.php`, modo `gerar`).
7. Pendências de banco que ficam: a coluna `vendas.split_status/comissao_admin` não é preenchida pelo fluxo atual (o repasse acontece na gateway); `tokens_email` não apaga tokens vencidos.

## 11. Riscos conhecidos e plano B

| Risco | Probabilidade | Mitigação / plano B |
|---|---|---|
| Script ter um problema na primeira execução real | Média (não foi rodado numa VPS) | Reservado o dia 13→14 para isso; `--dry-run` antes; log em `/var/log/painel-instalacao.log`; o script é idempotente (corrige e roda de novo). Pior caso: instalação manual seguindo os mesmos passos (cada etapa do script é um comando comum). |
| Gateway lenta/fora do ar | Média | Já tratado: reconecta ao banco, não entrega Pix sem venda gravada, avisa o dono, pagamento sem venda avisa os admins. |
| DNS demora | Média | `--sem-ssl` instala já; o certificado sai depois com 1 comando. |
| Pix recusado pelo banco do cliente | Baixa (visto 1 vez, sem causa) | Anotar horário/banco e abrir chamado na OmegaPayments. |
| VPS cair / disco encher | Baixa | Monitor de uptime, `verificar.sh`, backups + snapshot. |
| PHP 8.3 (se usar Ubuntu 24.04) comportar-se diferente | Baixa | Preferir 22.04. Em 24.04 rodar o roteiro da seção 7 completo. |
| Sessão/URL erradas atrás de proxy | Só se usar Cloudflare | Ver seção 8. |

## 12. Referência rápida

| Onde | O quê |
|---|---|
| `/var/www/painel` | Código (root:www-data 750/640). Gravável pelo PHP só: `uploads/ logs/ storage/ certificados/`. |
| `/var/www/painel/config.php` | Banco + chave de criptografia. **Entra no backup.** |
| `/etc/apache2/sites-available/painel.conf` (+ `painel-le-ssl.conf`) | Site. Global: `conf-available/zz-painel-global.conf`. |
| `/etc/php/*/fpm/pool.d/painel.conf` · `conf.d/99-painel.ini` | Pool do PHP e limites. |
| `/etc/mysql/mariadb.conf.d/99-painel.cnf` | Ajustes do banco. |
| `/etc/cron.d/painel` · `/etc/cron.d/painel-backup` | Crons do painel e do backup. |
| `/etc/painel/painel.conf` | Variáveis usadas pelos scripts de backup/restauração/atualização (retenção ajustada aqui é mantida pelo instalador). Entra no `sistema_*.tar.gz`. |
| `/var/backups/painel/config-antes/` | Versão anterior de cada arquivo de configuração que o `instalar.sh` reescreveu (Apache, PHP-FPM, MariaDB, cron, `/etc/painel`), por data da execução. |
| `/usr/local/sbin/painel-backup` · `painel-restaurar` | Backup e restauração. |
| `/var/backups/painel/` | Backups locais. |
| `/root/painel-credenciais.txt` | Senhas geradas (apagar depois de guardar). |
| `/usr/local/sbin/painel-ssh-acessos` · `painel-ssh-acessos.service` | Registro dos logins SSH (IP + localização). Eventos em `/var/log/painel-ssh/`; Telegram em `/etc/painel/ssh-acessos.conf`. |
| `/var/www/painel/controle.php` · `funcoes/controle.php` · `storage/controle_estado.json` · `logs/controle.log` | Suspensão remota: porta `/controle`, lógica, estado atual e histórico de comandos. Ferramentas (no seu computador): `deploy/controle/gerar-chaves.php`, `enviar.php`. |
| `/var/log/painel-instalacao.log` · `/var/log/painel-backup.log` | Logs dos scripts. |

**Parâmetros do `instalar.sh`:** `--dominio` · `--email-ssl` · `--admin-email` · `--nome-sistema` · `--origem` · `--site-dir` · `--banco-nome` · `--banco-usuario` · `--com-www` · `--sem-ssl` · `--endurecer-ssh` · `--swap-gb` · `--controle-id` · `--controle-chave-publica` · `--forcar` · `--dry-run` · `--sim`.

**Os 10 crons** (`/etc/cron.d/painel`, como `www-data`): `verificar_pix`, `verificar_acessos`, `aviso_vencimento`, `ranking`, `remarketing` (todo minuto) · `metricas_admin` (5 min) · `renovacao` (hora em hora) · `limpar_stories` (03:30) · `limpar_remarketing_envios` (03:45) · `rotacionar_logs` (04:15).

---

## 13. Primeira instalação real (13/10/2026) — o que foi validado e o que o teste achou

**Ambiente:** Hostinger KVM 2 (2 vCPU, 8 GB, 100 GB, Boston/EUA — não há São Paulo no checkout), Ubuntu 26.04.1 LTS (kernel 7.0), Apache 2.4.66, PHP 8.5.4-FPM, MariaDB 11.8.6 (a mesma da homologação), certbot 4.0, fail2ban 1.1. Endereço provisório `srv2059665.hstgr.cloud` (o nome que a Hostinger dá à VPS já aponta para o IP; o certificado Let's Encrypt saiu normalmente). Backup automático da Hostinger **não** contratado (o do script cobre).

**Problemas que só a execução real mostrou (todos corrigidos nos scripts/código):**
| # | Problema | Correção |
|---|---|---|
| 1 | O script só aceitava Ubuntu 22.04/24.04 | aceita 26.04 |
| 2 | O `/etc/hosts` da VPS aponta o nome dela para 127.0.1.1 e o teste de DNS falhava | consulta um DNS público (cloudflare-dns.com) quando o resultado local é 127.* |
| 3 | `ServerTokens Prod` não valia: o `security.conf` do Ubuntu carrega depois e sobrescrevia (Server: Apache/2.4.66) | arquivo renomeado `zz-painel-global.conf` |
| 4 | O `config.php` gerado não tinha `CHAVE_SECRETA_CRON`; 4 crons chamados pela web davam erro **500** em vez de 403 | o instalador do painel grava a chave; os crons usam `defined()`; o `instalar.sh` completa configs antigos |
| 5 | `verificar.sh` dava falso "falta extensão/fuso" (`cmd \| grep -q` com `pipefail`: o PHP 8.5 escreve aos poucos e o grep fecha o cano antes) | saída capturada em variável antes do grep |
| 6 | `atualizar.sh` falhava na migração: roda como `www-data`, que **não lê /root** (onde o plano manda guardar o código) | copia o `atualizar-banco.php` para `/usr/local/lib/painel` antes |
| 7 | `curl_close()` virou *deprecated* no PHP 8.5 (41 avisos nos testes, que derrubavam as checagens "sem aviso de PHP") | as 16 chamadas removidas (sem efeito desde o PHP 8.0) |
| 8 | **`painel-restaurar` podia restaurar o estado ERRADO**: o backup de segurança que ele tira antes usava nome com minuto e, no mesmo minuto, sobrescrevia o backup que você escolheu | nomes com segundos e sufixo único; o restaurar trabalha numa cópia do arquivo escolhido. Testado com um usuário-marcador |
| 9 | Depois de trocar de domínio, o http→https deixava de redirecionar (sobra do vhost HTTPS antigo gerado pelo certbot) | o vhost antigo é descartado antes de pedir o certificado novo |

**Resultados:** `verificar.sh` 80 ok / 0 falhas (4 avisos que dependem de você: backup fora da VPS, aviso por Telegram, SSH ainda aceita senha, log do cron do Pix só aparece com Pix pendente); 106 arquivos PHP sem erro de compilação no 8.5; bateria `tests/homologacao` **575 PASS / 0 FAIL** (sem as 16 verificações do editor no Chrome; precisa de gateway ligada com split 1,40 e de "outro usuário" — `vps_pre.sh`/`vps_pos.sh`); `tests/vps/rastrear.sh` 46 telas ok como administrador, vendedor, anônimo e público, 0 linhas novas no log de erros do PHP; backup + restauração; instalador reexecutado; `atualizar.sh`; troca de domínio ida e volta; suspensão remota pela internet (painel e total); registro de logins SSH com o journal real (`sshd-session`) e localização real; fail2ban enxerga as tentativas (o filtro casa `sshd-session`); reinício da VPS (tudo volta sozinho); 2.400 requisições com 30 simultâneas sem erro.

**Para repetir numa VPS nova:** `tests/vps/rastrear.sh --dominio DOMINIO` (na VPS, como root) e, para a bateria de fluxos, enviar `tests/homologacao/` para `/tmp/pt`, `bash /tmp/pt/vps_pre.sh`, `PAINEL_REAL=/var/www/painel SEM_UI=1 bash /tmp/pt/all.sh`, `php /tmp/pt/cleanup.php` e `bash /tmp/pt/vps_pos.sh` (deixa a gateway e as crons como o instalador entregou).

---

## 14. Capacidade medida e dimensionamento (teste de carga de 13/10/2026)

Medido na própria VPS (KVM 2: 2 vCPU, 8 GB), com 6.021 vendedores, 5,6 milhões de leads e 1,5 milhão de vendas no banco, Telegram a 150 ms e gateway a 700 ms (simulados, **com** a espera). Tabelas completas em `anotacoes/TESTES.md`; causas e sugestões em `anotacoes/OBSERVACOES.md` 6g; método em `tests/estresse/vps/README.md`.

| | Como a VPS está hoje | Com o dimensionamento abaixo + índice em `bots.token` + cache do ranking |
|---|---|---|
| Clientes entrando pelo bot (sustentável) | ~20/s | ~50/s |
| Confirmações de pagamento em rajada | ~70/s | ~166/s |
| Vendedores ativos juntos com o funil rodando | ~300 | ~800 |
| Vendas/dia (teto do servidor; use metade para planejar) | ~40–85 mil | ~110–215 mil |

**O que mais limita:** (1) o número de processos do PHP-FPM — cada webhook fica parado ~2 s esperando Telegram e gateway, com a CPU quase ociosa; (2) o `MaxRequestWorkers` do Apache; (3) no painel, o ranking recalculado a cada abertura do dashboard e a falta de índice em `bots.token` (cada webhook varre toda a tabela de bots) — esses dois são do código, não da configuração. **Depois** disso o limite é a CPU (2 vCPU ≈ 65 clientes/s ou ~500 telas/s). Memória e disco não limitaram (RAM usada ≤ 3,5 GB, disco +52 MB por 36 mil clientes).

### Dimensionamento (já no `instalar.sh`; novas VPS saem assim)
| RAM | PHP-FPM `pm.max_children` | Apache `MaxRequestWorkers` / `ServerLimit` | `max_connections` do banco |
|---|---|---|---|
| 2 GB | 40 | 200 / 16 | 300 |
| 4 GB | 80 | 250 / 16 | 300 |
| 8 GB | 158 | 475 / 19 | 308 |
| 16 GB+ | 200 | 600 / 24 | 350 |

### Aplicar numa VPS que já está no ar (8 GB)
```bash
# 1) PHP-FPM — os 4 parâmetros têm de ser coerentes (start_servers entre min e max spare) e a configuração é VALIDADA antes de recarregar;
#    uma configuração inválida derruba o PHP-FPM e o painel (aconteceu no teste)
sudo cp /etc/php/8.5/fpm/pool.d/painel.conf /root/painel-pool.conf.bak
sudo sed -i -E "s/^pm.max_children *=.*/pm.max_children = 158/; s/^pm.start_servers *=.*/pm.start_servers = 39/; s/^pm.min_spare_servers *=.*/pm.min_spare_servers = 19/; s/^pm.max_spare_servers *=.*/pm.max_spare_servers = 78/" /etc/php/8.5/fpm/pool.d/painel.conf
sudo php-fpm8.5 -t && sudo systemctl reload php8.5-fpm || sudo cp /root/painel-pool.conf.bak /etc/php/8.5/fpm/pool.d/painel.conf
# 2) Apache — em /etc/apache2/conf-available/zz-painel-global.conf, dentro de <IfModule mpm_event_module>: ServerLimit 19 e MaxRequestWorkers 475
#    (ServerLimit só vale com restart, não com reload; leva ~2 s fora do ar)
sudo apache2ctl configtest && sudo systemctl restart apache2
# 3) Banco — em /etc/mysql/mariadb.conf.d/99-painel.cnf: max_connections = 308  (vale na hora com:  mysql -e "SET GLOBAL max_connections=308")
```
Conferir depois: `bash deploy/vps/verificar.sh --dominio ...` e `ps -eo cmd | grep -c "php-fpm: pool painel"` (sobe conforme a carga).

### Planejar com folga
- Use **metade** do teto da tabela. A espera pela gateway domina: Pix em 1,5 s corta a vazão pela metade.
- Limites **fora** do servidor: um bot manda ~30 mensagens/s (≈ 6 clientes/s por bot); a gateway pode devolver 429 em consultas repetidas.
- Cresceu o número de vendedores? O dashboard fica mais caro (ranking). Cresceu o número de bots? Sem o índice em `bots.token` cada webhook fica mais caro. Vendedor com milhões de leads: telas de 1–2 s e consulta de notificações de 0,7 s.
- Sobrecarga não perde dado: 5× o teto por 60 s gerou timeouts, nenhum evento perdido, e voltou ao normal ~35 s depois. Mas o Telegram reenvia o que dá timeout e hoje o reenvio gera **Pix duplicado** (pendência 3c).

---

## 15. Versionamento e atualização sem perder configuração (auditoria de 13/10/2026)

**Pergunta:** se eu atualizar a VPS com código novo, o que NÃO pode ser sobrescrito? **Resposta:** a atualização só mexe em código. Tudo que é configuração ou dado do cliente mora fora do git e fora do que o `atualizar.sh` copia.

| O quê | Onde mora | No git? | Na atualização | Backup |
|---|---|---|---|---|
| Código (PHP, JS, CSS, `.htaccess` da raiz) | `/var/www/painel` | sim | substituído (`rsync --delete`: o que saiu da versão some) | o próprio git |
| `config.php`: senha do banco, **chave que descriptografa as credenciais das gateways**, `CHAVE_SECRETA_CRON`, chave pública da suspensão | `/var/www/painel/config.php` | **não** (`.gitignore`) | `--exclude=/config.php`; conferido byte a byte antes/depois | `arquivos_*.tar.gz` |
| Logo, banners, mídia de stories e remarketing | `uploads/` | só `.gitkeep`/`.htaccess` | excluído | `arquivos_*.tar.gz` |
| Certificados das gateways | `certificados/` | idem | excluído | `arquivos_*.tar.gz` |
| Estado da suspensão remota (`controle_estado.json`), caches, bloqueios 429 | `storage/` | idem | excluído | `arquivos_*.tar.gz` |
| Logs | `logs/` | idem | excluído | não (só diagnóstico) |
| Banco: usuários, bots, fluxos, vendas, **Admin → Configurações** (marca, SMTP, URL do painel), gateways (ativo, split) | MariaDB | não | a migração só cria/adiciona (exceções abaixo) | `banco_*.sql.gz` |
| Apache, PHP-FPM, MariaDB, cron, firewall | `/etc/...` | não (o `instalar.sh` gera) | o `atualizar.sh` **não toca**; o `instalar.sh` reescreve e **guarda a versão anterior** em `/var/backups/painel/config-antes/` | recriável pelo instalador |
| `/etc/painel/`: `painel.conf`, `ssh-acessos.conf` (Telegram), `offsite.conf` (rclone) | `/etc/painel/` | não | `instalar.sh` mantém a retenção, **nunca** sobrescreve `ssh-acessos.conf` nem toca em `offsite.conf` | `sistema_*.tar.gz` |
| Senhas geradas | `/root/painel-credenciais.txt` | não | regravado, mantendo a senha do admin | **apague depois de guardar num cofre** |
| **Chave privada** da suspensão remota | **só no seu PC** (`~/.painel-controle/vps-cliente/`) | não (`*.pem`, `.painel-controle/` ignorados) | não existe na VPS | **faça cópia num cofre: sem ela você perde o controle** |
| Certificado HTTPS | `/etc/letsencrypt` | não | renova sozinho (`certbot.timer`) | reemitível |

### O que foi testado (na VPS, numa CÓPIA do site; produção intocada)
Código vindo de `git archive HEAD` (como seria numa atualização real) com **contaminantes na origem** (`config.php`, uploads, log, estado de controle e certificado "de dev") sobre um site com dados "do cliente": o `config.php` ficou idêntico (hash igual), o do dev não chegou, uploads/logs/certificados/`controle_estado.json`/`.well-known` do cliente ficaram, nada do dev vazou para as pastas de dados, o arquivo que saiu da versão foi removido, o arquivo posto à mão foi removido (esperado), permissões `root:www-data 640`/`www-data 750` corretas e o código novo confere com o commit.

### O que a auditoria achou e já foi corrigido
1. **Reexecutar o `instalar.sh` depois de apagar `painel-credenciais.txt`** (o que o final do próprio script recomenda) gerava uma senha de banco nova e fazia `ALTER USER`, mas o `config.php` (que não é reescrito) ficava com a antiga: **o painel perdia a conexão com o banco**. Agora a senha que o `config.php` já usa manda (depois o arquivo de credenciais, e só então uma nova). Testado: a senha extraída do `config.php` abre o banco.
2. O `instalar.sh` reescrevia Apache, PHP-FPM, MariaDB, cron e `/etc/painel/painel.conf` **sem guardar o anterior** (e voltava a retenção do backup ao padrão). Agora guarda a versão anterior de tudo que muda e preserva a retenção.
3. `/etc/painel` (token do aviso de login SSH, destino do backup fora da VPS) **não entrava no backup**. Agora entra (`sistema_*.tar.gz`).
4. O `atualizar.sh` não tinha prova de que a configuração ficou intacta, e o `--delete` podia apagar `.well-known` (validação do certificado). Agora tem a conferência antes/depois e `.well-known` é protegido.
5. **Documentação contraditória:** o `CLAUDE.md` mandava deploy por `git pull` na pasta, o que já apagou o `config.php` real da homologação em 19/09 (o arquivo era versionado até o commit `c2d04ff`; um `pull` apaga o que o commit removeu). Corrigido: homologação = `pscp` por arquivo; VPS = `atualizar.sh`.

### Conferido e sem problema
- Nenhum arquivo que o `.gitignore` manda ignorar está rastreado; `uploads/ logs/ storage/ certificados/` só têm `.gitkeep` e `.htaccess`; nada acima de 500 KB no repositório.
- **Nenhuma senha, token ou chave real** nos arquivos versionados (varredura antes do commit). No histórico do git o `config.php` antigo só tinha placeholder (a VPS usa chave própria).
- Tudo que o PHP grava em tempo de execução vai para `uploads/`, `logs/`, `storage/` ou o temporário do sistema: nada fica solto no código (onde o `--delete` apagaria).
- Nenhum domínio, IP ou caminho absoluto fixo no código (o endereço do painel vem do banco: Admin → Configurações → "URL do painel").
- `atualizar-banco.php` (a migração da VPS) recusa rodar sem administrador cadastrado: não cria o "admin123" por engano.

### Regras (para não ter problema depois)
1. **Atualizar a VPS só com `deploy/vps/atualizar.sh`** (ou, para um arquivo só, `pscp` para `/root/painel-src/...` e `install` para `/var/www/painel/...`, mantendo as duas pastas iguais). Nunca `git pull`/`git reset`/`git clean -x` dentro da pasta do site; nunca copiar a pasta inteira do PC por cima.
2. **Nunca** versionar `config.php`, uploads, certificados, logs, chaves, `anotacoes/credenciais-*` nem saídas de teste (`.gitignore` já cobre). Saída de teste já chegou a guardar a senha SSH numa linha de erro.
3. Configuração nova vai para o `config.php` (segredo) ou para a tabela de configurações do painel (preferência), nunca fixa no código.
4. Qualquer ajuste manual em Apache/PHP-FPM/MariaDB **se perde na próxima execução do `instalar.sh`** (fica guardado em `config-antes/`): se for definitivo, ponha no `instalar.sh` e no git.
5. Mudança de schema só em `admin/atualiza_banco.php` (idempotente). Exceções que apagam dado, por serem limpeza de legado: `DROP COLUMN` das colunas de cashout e de `campanhas_ranking.tipo`; `DELETE` de splits de gateways que não existem mais. Ele também **recalcula as métricas por hora a partir das vendas a cada execução** (não apaga nada; o tempo com milhões de vendas não foi medido).
6. O backup fora da VPS leva o `config.php` (chave das gateways) e o token do Telegram: use destino **privado e criptografado** (`rclone crypt`).
7. Guarde fora da VPS: a chave privada da suspensão remota, a senha de root e o conteúdo de `painel-credenciais.txt`.
