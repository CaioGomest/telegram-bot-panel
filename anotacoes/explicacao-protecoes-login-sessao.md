# Explicação: proteções de login e sessão (aplicadas na varredura 02)

Anotação pra lembrar depois o que foi feito e por quê.

## 1. Cookie de sessão (`HttpOnly`, `SameSite=Lax`, `Secure`)

Quando loga no painel, o PHP cria uma sessão e manda um cookie pro navegador guardar (o "crachá" que prova que você tá logado). Esse cookie agora sai com 3 flags de proteção:

- **`HttpOnly`** — impede que JavaScript leia o cookie (`document.cookie` não mostra ele). Se um dia rolar um XSS na aplicação, o invasor não consegue roubar a sessão só lendo o cookie via JS.
- **`SameSite=Lax`** — o navegador só manda esse cookie em requisições que partem do próprio site. Se você tiver logado e clicar num link malicioso em outro site que tenta mandar requisição pro painel, o navegador não anexa o cookie — ajuda contra CSRF (que ainda não tá 100% protegido de outras formas).
- **`Secure`** — o cookie só trafega em conexão HTTPS, nunca "pelado" numa rede não criptografada. Só ativa quando o site já tá em HTTPS (pra não quebrar teste local sem certificado).

Onde: `funcoes/usuario.php`, antes do `session_start()`.

## 2. Rate limiting no login (bloqueio após 5 erros)

Antes dava pra tentar senha infinitas vezes (brute-force). Agora cada erro de login fica registrado por e-mail numa tabela nova (`tentativas_login`). Na 5ª tentativa errada seguida, aquele e-mail fica bloqueado por 15 minutos, mesmo digitando a senha certa nesse meio tempo. Login certo zera o contador.

Não impede 100% (dá pra tentar de novo depois dos 15min), mas torna um ataque de força bruta inviável — de milhares de tentativas por minuto pra 5 a cada 15 minutos.

**Importante:** só funciona se a tabela `tentativas_login` existir no banco. Precisa rodar `atualiza_banco.php` (menu Debug) depois do deploy pra criar ela. Sem a tabela, não quebra nada — só fica sem limitar tentativas.

Onde: `funcoes/usuario.php` (`loginEstaBloqueado`, `registrarTentativaLoginFalha`, `resetarTentativasLogin`, chamadas dentro de `fazerLogin()`), `login.php` (mensagem "bloqueado"), `atualiza_banco.php` (cria a tabela).

## 3. Senha mínima de 8 caracteres

Antes exigia só 6, agora exige 8. Cada caractere a mais multiplica exponencialmente o tempo necessário pra "adivinhar" a senha por força bruta (ex. se o hash vazar e alguém tentar quebrar offline). 8 é o mínimo geralmente recomendado hoje; 6 já é considerado fraco.

Só afeta contas **novas** — quem já tem conta com senha de 6 caracteres continua acessando normal, a regra só vale no cadastro.

Onde: `funcoes/usuario.php`, dentro de `criarUsuario()`.
