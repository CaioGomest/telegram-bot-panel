# E-mail de verdade e isolamento entre contas (VPS, 15/10/2026)

Complementa os roteiros de `../` (que não conseguem ver a caixa de entrada). Usa uma caixa **descartável** (mail.tm) para o e-mail chegar de verdade pelo SMTP da VPS e deixa o Chrome seguir os links. Só pela web, sem SSH.

| Script | O que cobre |
|---|---|
| `00_preparar.js` | cria a caixa descartável e grava `../estado/email-e-isolamento/teste_vps.env` (e-mail, senha do painel, token da caixa) |
| `01_cadastro_email.js` | cadastro (senhas diferentes, curta, e-mail inválido, ok, repetido), login errado/certo, **e-mail de boas-vindas entregue**, link de confirmação (ok, reaberto, token inexistente), logout. **Gasta 4 dos 5 cadastros/hora por IP** |
| `02_recuperacao_senha.js` | "Esqueci a senha" até o fim: **código de 6 dígitos entregue**, código errado recusado, código certo troca a senha e o login novo funciona (senha fixa `RecupVps#2026Rr`, gravada no `.env` antes de enviar) |
| `03_isolamento_contas.js` | usa o 5º cadastro da hora: conta B vê o aviso "E-mail não confirmado" e o "Reenviar"; B **não** exporta/lê/exclui/sobrescreve o fluxo de A nem vê o webhook dele |

Cuidados
- Navegar de novo para a **mesma URL** de uma página de resposta POST vira "recarregar" no Chrome e **reenvia o formulário** (gasta o limite de cadastros e conta logins errados em dobro). `lib.js` sempre passa por `about:blank` antes de navegar.
- Esperar o toast/aviso anterior sumir antes de ler o próximo, senão o teste lê o aviso velho. O aviso de "Senha atualizada" some em 1,8 s e a página recarrega.
- O aviso "E-mail não confirmado" só aparece no HTML com a barra lateral montada (o `innerText` da página pode não trazê-lo): conferir o `outerHTML`.
- As contas de teste ficam na VPS: apagar em Admin → Usuários depois.
