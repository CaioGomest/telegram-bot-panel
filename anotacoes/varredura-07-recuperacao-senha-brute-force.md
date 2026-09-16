# Varredura 07 — Recuperação de senha vulnerável a brute-force (crítico)

Data: pós-correção dos crons (branch `new`, varredura 06 ainda não corrigida).

Esse é provavelmente o achado mais grave de todas as varreduras até agora — permite sequestrar a conta de **qualquer usuário só sabendo o email dele**.

## 🔴 Crítico — troca de senha por código, sem limite de tentativas

O fluxo "Esqueci minha senha" (`login.php` → `funcoes/usuario.php?api=usuario`) funciona assim:

1. `enviar_codigo_senha_login`: gera um código de 6 dígitos (`sprintf('%06d', mt_rand(0, 999999))`), guarda na sessão de quem pediu, manda por email pro dono da conta.
2. `trocar_senha_login`: recebe `email` + `codigo` + `nova_senha`. Se o código bater com o que tá salvo na sessão (e não tiver expirado — 15 min), troca a senha na hora, sem confirmar mais nada.

**O problema:** um código de 6 dígitos tem só 1 milhão de combinações possíveis, e **não existe nenhum limite de tentativas** em `trocar_senha_login`. Um atacante pode:

1. Pedir o código pro email da vítima (a vítima recebe um email estranho, mas o atacante não vê o código).
2. Ficar mandando requisição pra `trocar_senha_login` testando código por código (`000000`, `000001`, `000002`...), com `nova_senha` já sendo a senha que o atacante quer definir.
3. Como não tem limite de tentativas nem trava por IP/sessão, com automação simples (e principalmente com algumas conexões em paralelo) dá pra testar as 1 milhão de combinações dentro da janela de 15 minutos — ou ter sorte bem antes disso.
4. Assim que acertar, a senha da vítima já foi trocada pro valor que o atacante escolheu. **Conta sequestrada** — inclusive de admin, se o atacante souber o email de um admin.

Ainda por cima, `mt_rand()` não é uma função criptograficamente segura — pra gerar um código de uso único de segurança, o certo é `random_int()`.

## Correção recomendada

1. **Limitar tentativas** de `trocar_senha_login` — o mesmo mecanismo que já existe pro login (`tentativas_login`) dá pra reaproveitar aqui: depois de poucas tentativas erradas (ex. 5) pro mesmo email, invalidar o código atual e exigir pedir um novo (ou bloquear por alguns minutos).
2. Trocar `mt_rand()` por `random_int()` na geração do código.
3. (Opcional, reforço extra) Aumentar o código pra mais dígitos, ou usar um token mais longo (ex. um link com token aleatório grande, em vez de um código curto digitável) — 6 dígitos é o padrão de SMS/2FA que normalmente já vem com rate limiting agressivo por natureza; sem isso, é fraco demais sozinho.

## Próximos passos sugeridos

1. Adicionar limite de tentativas em `trocar_senha_login` (prioridade máxima — é o item que fecha a brecha de verdade).
2. Trocar `mt_rand()` por `random_int()`.
