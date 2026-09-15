# Varredura 05 — Integridade de valores, escalonamento de privilégio, XSS armazenado

Data: pós-correção do IDOR da varredura 04 (branch `new`).

Rodada mais tranquila — não achei nada crítico novo. Registro o que foi checado porque a ausência de problema também vale a pena documentar (evita re-checar a mesma coisa numa varredura futura).

## 🟢 Checado e sem problema

### 1. Valor cobrado no PIX não vem do cliente do bot
O `$valor` usado pra gerar uma cobrança PIX (`webhook.php`) vem das `properties` do bloco "pix" configurado no editor de fluxo (`dados_fluxograma`, salvo pelo dono do bot) — não é algo que o cliente final (quem conversa com o bot no Telegram) consegue alterar na hora de pagar. Ou seja, ninguém consegue "negociar" um preço menor manipulando a conversa com o bot.

### 2. Sem mass assignment / escalonamento de privilégio no cadastro
`criarUsuario()` grava `perfil = 'usuario'` fixo no INSERT — o campo não vem do formulário, então não dá pra um usuário se cadastrar já como admin manipulando o POST. `configuracao_usuario.php` (onde o próprio usuário edita a conta) nem toca no campo `perfil`.

### 3. XSS armazenado — conferido nos pontos mais expostos
Dado que vem de fora (nome de lead capturado via Telegram, nome de usuário no cadastro, nome de bot) é **atacável de propósito** — um "cliente" mal-intencionado pode colocar `<script>` no próprio nome do Telegram, por exemplo. Conferi os lugares onde esse tipo de dado é exibido pro admin/dono (`leads.php`, `admin_dashboard.php`, `admin_transacoes.php`, `index.php`, `usuarios.php`) e todos passam por `htmlspecialchars()` antes de imprimir. Não achei nenhum ponto vazando isso sem escape.

### 4. Split de pagamento é configuração só de admin
A divisão de comissão (`usuarios_splits`) só é configurável via `ajax/salvar_splits_usuario.php`, que exige `verificarAdmin()`. Um usuário comum (dono de bot) não consegue mexer no próprio split — é regra de negócio da plataforma, não uma brecha.

## Conclusão dessa rodada

Não tem item novo pra corrigir. O que ainda está pendente de rodadas anteriores continua valendo:
- Criptografar `client_secret`/`cert_password`/`chave_pix` no banco (varredura 03, item 4).
- CSRF nas rotas administrativas (varredura 02, item 5).
- Mascarar token de integração no formulário (varredura 03, item 5 — cosmético).
