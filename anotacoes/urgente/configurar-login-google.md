# 🔴 Configurar Login com Google — pendente, precisa do Caio

Criado em 21/09/2026. O código do login com Google já está implementado, testado ponta a
ponta em produção e no ar — mas **fica invisível pro usuário até isto aqui ser feito**, porque
depende de credenciais que só dá pra criar no Google Cloud Console. Ver
`anotacoes/varredura-15-completa.md` não fala disso; a implementação em si não tem nota
própria além deste checklist.

## O que fazer

1. Acesse **console.cloud.google.com/apis/credentials** (logado com uma conta Google).
2. Se ainda não tiver um projeto, crie um (qualquer nome, ex. "Painel de Bots").
3. Clique em **"Criar credenciais" → "ID do cliente OAuth"**.
   - Se pedir pra configurar a "Tela de consentimento OAuth" primeiro, configure com o nome
     do sistema (o que estiver em Configurações → Identidade Visual) e seu e-mail de suporte.
   - Tipo de aplicativo: **Aplicativo da Web**.
4. Em **"URIs de redirecionamento autorizados"**, adicione exatamente (sem espaço, sem barra
   no final):
   ```
   https://telegram.stackcode.com.br/google_callback
   ```
5. Salvar. O Google mostra um **Client ID** (termina em `.apps.googleusercontent.com`) e um
   **Client Secret** (começa com `GOCSPX-`).
6. No painel, vá em **Configurações** (menu → Administração) → seção **"Login com Google"** →
   cole os dois valores → Salvar.
7. Teste: abra `/login` numa aba anônima. O botão "Continuar com Google" deve aparecer acima
   do formulário. Clique, escolha uma conta Google de teste, confirme que entra no painel.

## Por que não dá pra eu fazer sozinho

Client ID e Client Secret são gerados só dentro do Google Cloud Console, amarrados à conta
Google de quem cria — não existe API/comando pra isso, é sempre feito na tela do Google. Sem
essas duas informações o recurso fica pronto mas invisível (comportamento intencional: sem
credencial, o botão não aparece, não é um botão quebrado).

## O que já está pronto (não precisa mexer em código)

- Botão em `/login` e `/cadastro`, escondido até o passo 6 acima ser feito.
- Se alguém já tem conta com o mesmo e-mail (criada por senha), o login com Google liga as
  duas automaticamente na primeira vez — não cria conta duplicada.
- Client Secret fica criptografado no banco (mesma chave usada nos segredos de gateway),
  não em texto puro.
- Toda a URL de redirecionamento (passo 4) já vem pronta pra copiar dentro da própria tela de
  Configurações → Login com Google, pra não errar digitando na mão.
