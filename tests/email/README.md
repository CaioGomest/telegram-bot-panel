# Testes dos envios de e-mail (recuperação de senha, limites, fila, TLS)

Rodam **na VPS**, contra o painel real, com o SMTP apontado para um servidor **falso** (`sink.py`) que só grava as mensagens:
nada vai para o Brevo, então não gasta cota nem reputação. As requisições saem de `127.0.0.1`, sem consumir o limite do IP de quem está no navegador.

```bash
# no seu PC: copie a pasta para a VPS
pscp -r tests/email root@IP:/root/smtp_test        # ou scp -r
# na VPS (root):
cd /root/smtp_test && chmod +x *.sh *.py
./swap.sh                 # guarda a configuração REAL do SMTP, aponta para o sink e agenda a restauração automática em 45 min
./testes_email.sh         # parte 1: intervalo, cliques simultâneos, limites por e-mail/IP/global, tempo de resposta, código, entrada
./testes_email2.sh        # parte 2: reenvio de confirmação, cadastro, Minha Conta, política da fila, TLS, cabeçalhos, tokens
./restore.sh              # devolve o SMTP real e confere (md5) que ficou idêntico — SEMPRE rode no fim
rm -rf /root/smtp_test    # o backup da configuração do SMTP (cifrada) fica ali: apague
```

- `PAINEL_HOST=outro.dominio` muda o endereço testado (padrão: o da VPS atual).
- Criam e apagam contas `rec-teste-*@estresse.invalid`; limpam também a fila, o histórico e os contadores de limite.
- Rodada de 14/10/2026: parte 1 com **29/29** verificações e parte 2 com **29/30**. A 30ª (aviso do e-mail antigo com o endereço novo mascarado) falhou por defeito do próprio teste (procurava texto cru num corpo em base64) e foi conferida à parte decodificando a mensagem (`r***@estresse.invalid`, sem o endereço completo). O teste já foi corrigido (`inbox.py contem`), mas não foi rodado de novo.
- Se a sessão cair no meio: a restauração automática (`restore.sh`) roda sozinha depois de 45 min; ou rode `./restore.sh` à mão.
