# resetar/ — zerar a VPS para o lançamento

**Nada aqui roda sozinho.** Quem apaga dado exige a frase **`RESETAR TUDO`**. Comece por **[PASSO-A-PASSO.md](PASSO-A-PASSO.md)**.

Escrito em 16/10/2026. **Nenhum script foi executado** ao criar esta pasta (só conferência de sintaxe com `bash -n`).
Esta pasta **não vai para o site** (`atualizar.sh`/`instalar.sh` a excluem e o `.htaccess` a bloqueia): ela é copiada para `/root/resetar/` na VPS à mão.

| Arquivo | Para quê | Muda algo? |
|---|---|---|
| `PASSO-A-PASSO.md` | o roteiro completo (decisões, fases, o que apaga/mantém, o que fazer se der errado) | — |
| `config.exemplo.env` | decisões do dono (admins que ficam, split, ranking, comunidade…) → copiar para `config.env` | — |
| `lib.sh` | funções comuns e a lista de tabelas (manter × apagar) | — |
| `00_conferir.sh` | mostra o estado atual e o que o reset faria | **não** (só leitura) |
| `01_backup_antes.sh` | backup de segurança em `/root/resetar-backups/` | só cria arquivos |
| `02_pausar.sh` | pausa os crons e desmonta o teste de carga | sim · pede a frase |
| `03_zerar_banco.sh` | zera os dados do banco (mantém admin, gateways/split, configuração) | sim · pede a frase |
| `04_limpar_arquivos.sh` | apaga uploads de usuários, cache, certificados de teste; zera logs | sim · pede a frase |
| `05_configurar_gateway.sh` | liga a Omega e grava o split de `config.env` | sim · pede a frase |
| `06_senha_admin.sh` | troca a senha do admin por uma forte (interativo) | sim · precisa de terminal |
| `07_retomar.sh` | religa os crons, recarrega PHP-FPM e Apache | sim (volta ao normal) |
| `08_verificar.sh` | confere se ficou **pronto para lançar** | **não** (só leitura) |
| `09_marco_zero.sh` | guarda o estado limpo e aposenta o `estado-zero` antigo | só cria cópias e renomeia |
| `resetar-tudo.sh` | encadeia 00→09 com uma confirmação (`--simular` = ensaio) | sim · pede a frase |
| `desfazer.sh` | volta ao backup do passo 01 | sim · pede `DESFAZER RESET` |

Em uma frase: **apaga os dados de teste e mantém o que é configuração** (admin, gateways e split, marca, SMTP, domínio, logo, suspensão remota).
Não usa o `/root/estado-zero` de 13/10 de propósito: restaurá-lo desfaria domínio, SMTP, senha e colunas novas do banco.
