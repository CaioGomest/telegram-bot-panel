# Reset para o lançamento — passo a passo

> **Nada nesta pasta roda sozinho.** Os scripts só apagam depois da frase **`RESETAR TUDO`** (digitada ou passada em `--frase`).
> Este documento foi escrito em 16/10/2026; **nenhum script foi executado** ao escrevê-lo (só conferência de sintaxe).
> Alvo: a **VPS** (`coyotebot.com.br`). A homologação (`telegram.stackcode.com.br`) **não é tocada**.

Resumo em uma linha: **backup → pausar rotinas → zerar o banco (mantendo admin, gateway/split e configuração) → limpar arquivos de teste → ajustar split → trocar a senha do admin → religar → verificar → guardar o marco zero.**

---

## O que o reset apaga e o que mantém

| | O que é |
|---|---|
| **APAGA** | todos os usuários que **não** estão em `MANTER_ADMINS` · bots · fluxos · leads · starts · vendas e splits por venda · assinantes/grupos · remarketing · webhooks (e entregas) · links de rastreamento e redirecionadores · stories · traqueamento · atividades/notificações · **fila e histórico de e-mails** · tentativas de login e limites de taxa · cache do ranking · métricas |
| **APAGA (arquivos)** | `uploads/` de usuários (avatares de bot, mídia de fluxos, remarketing, fotos de perfil, stories) · `storage/cache*` e travas da Omega · certificados de gateways de teste · sessões do PHP · zera os logs |
| **MANTÉM** | os admins de `MANTER_ADMINS` · **`gateways`** (inclui o split da plataforma) · **`configuracoes`** (nome, logo, cor, domínio/`url_base`, SMTP/Brevo, Google, domínios de redirecionamento) · `comunidade_links` e `campanhas_ranking*` (conforme sua escolha) · `config.php` (chaves de criptografia e da suspensão remota) |
| **NUNCA toca** | `uploads/marca_logo*` e `uploads/marca_favicon*` · `storage/controle_estado.json` e `controle_chave.json` (**suspensão remota**) · `logs/controle.log` · `.htaccess` |

### Por que NÃO usar o `/root/estado-zero` antigo
Ele foi tirado logo após a instalação de 13/10. Restaurá-lo **desfaria**: o domínio (`url_base`), o SMTP/Brevo, a senha do admin, e removeria colunas do banco que o código já publicado na VPS usa (ex.: `arquivada_em` do ranking). Por isso este reset **apaga só os dados** e mantém o resto. O passo 09 renomeia o `estado-zero` para `estado-zero-OBSOLETO-<data>`.

### Regras que o reset segue
- **Para sozinho** se: aparecer uma tabela que ele não conhece, os crons não estiverem pausados, não houver backup de hoje, ou um admin de `MANTER_ADMINS` não existir.
- Trabalha por **lista do que apagar** (nunca "apaga tudo menos…"); arquivo desconhecido é só listado.
- Nunca imprime senha, token nem chave: só nomes, contagens e tamanhos.
- Guarda **logs** de cada execução em `/root/resetar-logs/`.

---

## Fase 0 — Decidir e preparar (antes de qualquer comando)

### 0.1 Decisões que são do dono (entram no `config.env`)
| Decisão | Opções | Observação |
|---|---|---|
| **Admins que ficam** (`MANTER_ADMINS`) | e-mail(s) | todos os outros admins somem. `00_conferir.sh` lista quem é admin hoje |
| **Split da plataforma** | `fixo` (R$ **por compra**) ou `percentual` (% **sobre o valor** de cada venda) | em 15/10: VPS = fixo R$ 0,25; homologação = fixo R$ 1,40, **recebedores diferentes**. O dono diz qual vale |
| **Recebedor do split** (`SPLIT_ID_RECEBEDOR`) | o `producerId` da conta na Omega | **não é chave Pix**. Fica só no `config.env` da VPS |
| **Ranking** | `manter` / `apagar` | vale para campanhas e prêmios (o cache é zerado de qualquer jeito) |
| **Comunidade** | `manter` / `apagar` / `ids:N` | o card "123" de teste aponta para o endereço antigo — apague com `ids:N` |
| **Bot @Claude_Gomes_bot** | ver 0.3 | hoje o webhook dele aponta para a VPS |

### 0.2 O que precisa estar pronto FORA da VPS (não é script)
- [ ] **Omega:** liberar o IP da VPS (`179.236.76.180`) no painel da Omega (a credencial deu 401 fora da homologação; que a causa seja o IP é hipótese). Sem isso, nenhum Pix funciona.
- [ ] **Brevo:** autenticar o domínio do remetente (senão o e-mail de recuperação de senha cai no spam do Gmail).
- [ ] **Google Cloud:** cadastrar `https://coyotebot.com.br/google_callback` (senão o login com Google falha).
- [ ] **SSH:** instalar a chave do dono e rodar `instalar.sh --endurecer-ssh`; trocar a senha de root (ela passou no chat); backup fora da VPS (rclone). Ver `deploy/PLANO-VPS.md`.
- [ ] **Termos/LGPD:** `termos.php` ainda é rascunho.

### 0.3 Destino do bot @Claude_Gomes_bot
Hoje o webhook dele no Telegram aponta para a VPS e o token está na conta de teste (que o reset apaga). Escolha:
- **(a) Ele vai ser o bot do dono na VPS:** depois do reset, o dono entra com a conta **real** dele e conecta o bot de novo (Meus Bots → Novo Bot) — isso refaz o webhook.
- **(b) Volta para a homologação:** abra o bot na homologação (Meus Bots) e **salve de novo** (re-registra o webhook lá).
- Enquanto não decidir: o webhook continua apontando para a VPS e o painel simplesmente ignora o token desconhecido.

### 0.4 Venda de aceite (recomendado ANTES do reset)
O pagamento ponta a ponta **nunca foi provado na VPS** (só na homologação, em 08/10). Faça agora, no ambiente de teste, **antes** de zerar:
1. IP liberado na Omega (0.2). 2. Na conta de teste que já tem o bot e a Omega, dê `/start` no bot e compre um plano de **R$ 2,10** (pague o Pix).
3. Confira: mensagem de aprovado no Telegram; **Admin → Transações** mostra a venda como paga **e o split como "pago"** com o valor do split; o dinheiro aparece no painel da Omega.
O reset apaga esses dados depois (o dinheiro real já foi feito e é seu). Fazer **antes** deixa o reset como último passo antes do lançamento.

### 0.5 Janela
Faça sem tráfego (madrugada). O reset leva ~10–20 minutos (a maior parte é o backup do banco de ~3 GB).

---

## Fase 1 — Preparar a VPS

```bash
# do seu PC (Windows, na pasta do projeto; a senha/chave é pedida na hora — não fica em arquivo):
pscp -r resetar root@179.236.76.180:/root/
# (se a porta SSH não for 22, acrescente -P PORTA)
```
```bash
# na VPS, como root:
cd /root/resetar
chmod +x *.sh
cp config.exemplo.env config.env && chmod 600 config.env
nano config.env                      # preencha com as decisões da Fase 0.1
bash 00_conferir.sh                  # SOMENTE LEITURA — leia a saída inteira
```
O que conferir na saída do `00_conferir.sh`:
- **Admins:** são os que você quer manter?
- **"Contas que NÃO são do teste de carga":** alguma é real? (Se for, **pare**: o reset apaga.)
- **Comunidade e ranking:** anote os ids do que deve ir embora.
- **Tabelas desconhecidas:** se aparecer, o reset se recusa a rodar até classificar em `lib.sh`.
- **`config.php`:** `CHAVE_CRIPTOGRAFIA_GATEWAYS` precisa existir. **Nunca troque essa chave**: a senha do SMTP e outros segredos guardados em `configuracoes` dependem dela.

Ensaio, sem mudar nada (opcional, recomendado):
```bash
bash resetar-tudo.sh --simular
```

---

## Fase 2 — Executar o reset

**Só quando o dono mandar: `RESETAR TUDO`.**

### Caminho curto (tudo encadeado, uma confirmação)
```bash
cd /root/resetar
bash resetar-tudo.sh                       # pede para digitar:  RESETAR TUDO
# ou, sem terminal (ex.: via plink):  bash resetar-tudo.sh --frase "RESETAR TUDO"
```
Ele roda 00 → 09 e **para no primeiro erro**. Se faltar terminal, o passo 06 (senha) fica **PENDENTE** e o 08 mostra "NÃO PRONTO" até você fazer a senha (Fase 3).

### Caminho passo a passo (se preferir olhar cada etapa)
| # | Comando | O que faz | Pede a frase? |
|---|---|---|---|
| 01 | `bash 01_backup_antes.sh` | backup de segurança em `/root/resetar-backups/<data>/` (banco completo, config e admins, arquivos, sistema, logs) | não |
| 02 | `bash 02_pausar.sh` | pausa os crons, espera terminarem, desmonta o teste de carga | **sim** |
| 03 | `bash 03_zerar_banco.sh` | zera os dados do banco (mantém admins/gateways/configuração) e confere o resultado | **sim** |
| 04 | `bash 04_limpar_arquivos.sh` | apaga uploads de usuários, cache, certificados de teste; zera logs | **sim** |
| 05 | `bash 05_configurar_gateway.sh` | liberar a Omega e gravar o split de `config.env` | **sim** |
| 06 | `bash 06_senha_admin.sh` | troca a senha de um admin por uma **forte** (digitada, sem aparecer) | não (precisa de terminal) |
| 07 | `bash 07_retomar.sh` | religa os crons e recarrega PHP-FPM/Apache (valida a config antes) | não |
| 08 | `bash 08_verificar.sh` | confere tudo e dá o veredito | não (só leitura) |
| 09 | `bash 09_marco_zero.sh` | guarda o estado limpo e aposenta o `estado-zero` antigo | não |

Todos aceitam `--simular` (03, 04, 05, 02) para mostrar o que fariam.

---

## Fase 3 — Verificar

```bash
bash 06_senha_admin.sh      # se ainda não foi feito (precisa de terminal)
bash 08_verificar.sh        # tem que terminar com:  PRONTO PARA LANÇAR
```
O `08_verificar.sh` confere: tabelas de dados **vazias**, só admins em `usuarios`, nenhuma conta de teste, `url_base` no domínio certo, **SMTP preenchido**, **Omega ligada com split e recebedor**, suspensão remota e logo presentes, nenhum card da Comunidade no endereço antigo, **senha do admin trocada**, SSH/backup (avisos), crons ativos, serviços no ar, sem sobras do teste de carga, e pela internet: `/login` e `/cadastro` 200, `/bots` e `/admin/dashboard` redirecionando, `config.php`/`deploy/`/`resetar/` bloqueados, certificado TLS válido, sem erro grave no log do PHP.

Depois, **no navegador** (2 minutos):
1. Entre como admin com a **senha nova**. **Visão Geral** deve mostrar tudo **zerado** (0 usuários clientes, R$ 0,00).
2. **Admin → Gateways:** OmegaPayments ativa e o split como decidido. **Admin → Configurações → E-mail → "Enviar teste"** (deve chegar).
3. **Admin → Comunidade:** só os cards oficiais. **Ranking:** como escolhido.

## Fase 4 — Marco zero
```bash
bash 09_marco_zero.sh
```
Guarda `/root/estado-lancamento/<data>/` (banco limpo + arquivos + sistema, com `COMO-VOLTAR.txt`). É o ponto de volta até entrar o **primeiro cliente de verdade**.

## Fase 5 — Primeiro dia de lançamento
- [ ] Conecte o bot (decisão 0.3) pela conta **real** do dono, se for usá-lo na VPS.
- [ ] Faça o **primeiro cadastro com um e-mail real** (não descartável): confira que o e-mail de boas-vindas **chega na caixa de entrada (não no spam)** e que o link de confirmação funciona.
- [ ] Confira o **backup diário** (`/var/backups/painel/diario/` deve ganhar arquivos às 03:15) e, se configurado, a cópia externa.
- [ ] Monitore `/var/log/php/painel-erros.log` e **Admin → E-mails** (falhas de envio) nas primeiras horas.

---

## Se algo der errado

| Situação | O que fazer |
|---|---|
| O script **parou com erro** antes do 03 | nada foi apagado ainda; leia a mensagem, corrija e rode de novo. Os crons ficam pausados: `bash 07_retomar.sh` os devolve |
| **Erro no meio do 03/04** | `bash desfazer.sh --frase "DESFAZER RESET"` volta ao backup do passo 01 (banco, `config.php`, uploads, certificados, storage) |
| Quer **voltar depois** de concluído | com o `desfazer.sh` (backup completo) ou com o `COMO-VOLTAR.txt` do marco zero (volta ao estado **limpo**) |
| `08_verificar.sh` acusa **NÃO PRONTO** | corrija o item em ✘ (cada linha diz o que é) e rode o `08` de novo; só então o `09` |
| Esqueceu a senha nova do admin | `bash 06_senha_admin.sh` de novo (pede o e-mail do admin) |
| Tabela desconhecida | classifique-a em `lib.sh` (`TABELAS_MANTER` ou `TABELAS_APAGAR`) — o reset não adivinha |

**Atenção ao `desfazer.sh`:** ele devolve **também os dados de teste** (é um desfazer de verdade). Só funciona se `BACKUP_COMPLETO=sim`.

---

## O que NÃO fazer
- ❌ **Não** restaurar `/root/estado-zero` (nem `estado-zero-OBSOLETO-*`): volta domínio, SMTP, senha e esquema antigos.
- ❌ **Não** trocar `CHAVE_CRIPTOGRAFIA_GATEWAYS` no `config.php` (os segredos de `configuracoes` ficariam ilegíveis).
- ❌ **Não** apagar `storage/controle_*.json` (a suspensão remota deixa de funcionar).
- ❌ **Não** rodar `semear.php apagar` / scripts do `tests/estresse` — o reset já cuida disso.
- ❌ **Não** publicar código novo na VPS **durante** o reset (e só uma sessão/pessoa por vez publica: o `atualizar.sh` de uma árvore desatualizada pode sobrescrever o que já está lá).
- ❌ **Não** deixar a senha trivial de teste do admin: o `08` não libera o lançamento sem o `06`.

## Notas para quem for executar (o Claude, numa próxima sessão)
1. **Só execute depois que o dono escrever `RESETAR TUDO`.** Antes disso, nada nesta pasta é rodado.
2. Você vai precisar de **acesso root à VPS** (a senha/chave não está guardada em lugar nenhum; peça ao dono). Não grave senha em arquivo.
3. **Pergunte** ao dono os valores do `config.env` (admins, split, ranking, comunidade): **não adivinhe**, principalmente o split.
4. Rode `bash 00_conferir.sh` e `bash resetar-tudo.sh --simular`, mostre o resultado, e só então rode de verdade com `--frase "RESETAR TUDO"`.
5. Sem terminal, o passo 06 fica pendente: peça ao dono para rodar `bash /root/resetar/06_senha_admin.sh` num terminal dele (a senha dele nunca passa por você).
6. Depois, atualize `anotacoes/TESTES.md` (uma linha) e `anotacoes/PENDENCIAS.md` (apague o item 5 e o que foi resolvido).
