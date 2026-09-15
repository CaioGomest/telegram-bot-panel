# Varredura 04 — Isolamento entre usuários (IDOR)

Data: pós-correções das varreduras 01, 02 e 03 (branch `new`).

Foco dessa rodada: como o painel é multiusuário (cada um só devia ver/mexer nos próprios bots, fluxos, links), procurei pontos onde um usuário logado poderia acessar ou alterar dado de **outro** usuário só manipulando um ID.

## 🔴 Alta prioridade

### 1. `api.php` (`salvar_fluxo`) vazia dados de fluxo de outro usuário
No case `salvar_fluxo`, quando `$id_fluxo > 0` (edição), o código faz:

```php
$stmt = $pdo->prepare("UPDATE fluxos SET ... WHERE id = ? AND id_usuario = ?");
$stmt->execute([..., $id_fluxo, $usuario_id]);

$stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");   // ← sem checar id_usuario!
$stmt->execute([$id_fluxo]);
$fluxo = $stmt->fetch();
```

O `UPDATE` é seguro (só atualiza se for dono). Mas o `SELECT` que vem depois **não confere o dono** — busca só pelo `id`. Ou seja: se o Usuário A mandar uma requisição de "salvar fluxo" usando o `id` de um fluxo do Usuário B, o `UPDATE` não faz nada (0 linhas afetadas, dado do B continua intacto), só que o `SELECT` seguinte ainda retorna o fluxo inteiro do B (incluindo `dados_fluxograma`, que é toda a estrutura de conversa/mensagens do bot) na resposta JSON pro Usuário A.

**Não é possível alterar** o fluxo de outro usuário por aqui — só **ler** (fluxo inteiro, dado sensível dependendo do que tem configurado ali). É um IDOR de leitura, explorável só enumerando/adivinhando IDs numéricos sequenciais.

**Correção recomendada:** trocar o `SELECT` final pra também filtrar `AND id_usuario = ?`, e se não achar nada, retornar erro em vez de seguir com um `$fluxo` de outra pessoa.

## 🟢 Conferido e sem problema

Chequei os pontos mais prováveis de vazamento entre contas e todos filtram certo por `id_usuario`:

- `api.php`: `salvar_bot`, `atualizar_perfil_bot`, `reiniciar_webhook`, `excluir_fluxo` — todos buscam o registro (`bot`/`fluxo`) já filtrando por `id_usuario` **antes** de fazer qualquer coisa com o ID, então não dá pra um usuário "assumir" recurso de outro trocando o ID.
- `funcoes/links_rastreamento.php` — todas as funções (`listar`, `obter`, `criar`, `editar`, `excluir`) recebem e filtram por `usuario_id` em toda query.
- `leads.php`, `remarketing.php`, `traqueamento.php` — todos filtram a listagem principal por `id_usuario` da sessão.
- `ajax/*.php` — todos exigem `verificarAdmin()` (ações administrativas, não há conceito de "dono" ali, é tudo admin mesmo).

## Próximos passos sugeridos

1. ~~Corrigir o `SELECT` sem filtro em `salvar_fluxo`~~ **Feito** — agora filtra `AND id_usuario = ?` tanto na edição quanto na criação, e retorna erro 404 se não achar (em vez de seguir com dado de outra pessoa).
2. ~~Aplicar o mesmo filtro no `SELECT` de `importar_fluxo`~~ **Feito**, por consistência.
