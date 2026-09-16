# Criptografia das credenciais de gateway (varredura 03, item pendente → resolvido)

## O que foi feito

- Criado `funcoes/criptografia.php` com `criptografarSegredo()` / `descriptografarSegredo()` — AES-256-CBC, chave em `CHAVE_CRIPTOGRAFIA_GATEWAYS` (config.php), IV aleatório por valor, formato salvo: `enc:v1:` + base64(iv + cifrado).
- Campos cifrados na tabela `usuarios_gateways`: `client_secret`, `cert_password`, `chave_pix`, `cashout_client_secret`, `cashout_cert_password`. (`certificado`/`cashout_certificado` continuam como estavam — é só o **caminho** do arquivo, não o conteúdo; o arquivo em si já tá protegido pelo `.htaccess` de `/certificados`.)
- `funcoes/gateways.php`: cifra em `saveUserGatewayConfig()` e `saveInfopagoCashoutConfig()`; decifra em `getInfopagoCredenciaisAdmin()`, `getUserGatewayConfig()`, `getUserGateways()`, `listarGatewaysUsuario()`.
- `funcoes/infopago_split.php`: também decifra as credenciais de Cash-Out antes de usar (tinha uma query direta que eu quase deixei passar — bom que revisei todos os pontos que tocam `usuarios_gateways` antes de fechar).
- **Retrocompatível:** valor sem o prefixo `enc:v1:` é tratado como texto puro legado — continua funcionando normal, sem quebrar nada que já tava salvo. Migração automática em `atualiza_banco.php`: varre a tabela e cifra qualquer valor que ainda esteja em texto puro (idempotente, não recifra o que já foi migrado).
- Colunas alargadas de `VARCHAR(255)` pra `VARCHAR(500)` (valor cifrado ocupa mais espaço que o original).
- `instalacao.php`: gera uma chave aleatória própria (`bin2hex(random_bytes(32))`) em cada instalação nova.
- `config.php` (dev, local): recebeu um placeholder óbvio (`TROQUE_ESTA_CHAVE_ANTES_DE_IR_PRA_PRODUCAO...`), mesma lógica do resto do arquivo (nunca é a credencial real).

## ⚠️ Ação manual necessária pra funcionar na Hostinger

O `config.php` que já está (ou vai ficar) no servidor **não tem** a constante `CHAVE_CRIPTOGRAFIA_GATEWAYS` ainda — eu não tenho como editar o arquivo lá diretamente. Sem ela, o sistema continua funcionando normal (só não cifra nada, comportamento igual a antes). Passos:

1. Gerar uma chave: `php -r "echo bin2hex(random_bytes(32));"` (ou peça pra mim gerar uma).
2. Adicionar no `config.php` do servidor: `define('CHAVE_CRIPTOGRAFIA_GATEWAYS', 'a_chave_gerada_aqui');`
3. Rodar `atualiza_banco.php` (menu Debug) uma vez — isso alarga as colunas e migra qualquer credencial que já esteja salva em texto puro pra criptografado.
4. **Guardar essa chave em lugar seguro fora do repositório** (gerenciador de senhas, por exemplo). Se perder essa chave, as credenciais cifradas no banco não têm mais como ser recuperadas — por isso o cuidado.

## Fora do escopo dessa rodada (anotado, não mexido)

- `usuarios_splits.chave_pix_split` — chave Pix de quem recebe o split (configurada pelo admin), tabela diferente de `usuarios_gateways`. Não fazia parte do que foi prometido pra essa correção (que era especificamente client_secret/cert_password/chave_pix do **gateway**), mas é candidato a entrar numa próxima rodada se quiser proteger também.
- `vendas_splits.chave_pix` — é só um registro histórico (snapshot de qual chave recebeu cada split já executado), usado em relatório admin. Risco bem menor.
