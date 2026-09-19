# Varreduras 13 e 14 — achados

19/09/2026. O pedido era fazer 5 varreduras seguidas. Fiz duas e a terceira virou o
incidente do `config.php` (ver `urgente/incidente-config-php-sobrescrito.md`), que consumiu
o resto do tempo. As varreduras 15–17 não foram feitas.

---

## Varredura 13 — estados vazios e limites de UI

16 telas, mobile e desktop, com conta de usuário e de admin.

### ✅ Corrigido: 180px de buraco no bloco de Atividade vazio

`.lista-atividade` tinha `height: 300px` **fixo**. Com a lista vazia, a caixa tracejada de
"Nenhum evento recente" tem 120px — sobravam 180px de nada embaixo dela. Foi o que o Caio
viu na tela.

Virou `max-height`. Medido depois: painel no mobile caiu de 397px → 217px, sobra 0. O caso
com 10 itens não mudou (300px, rolando).

### Resto limpo

Zero overflow horizontal, zero elemento visível com altura 0, zero erro de PHP/JS nas 16
telas. Um detalhe menor: 5 textos truncados no `admin/dashboard` (nomes longos de usuário no
log, cortados com reticências) — comportamento esperado, não é bug.

---

## Varredura 14 — consultas e dados

### ✅ Corrigido (parcial): N+1 nos gráficos do dashboard

Quando um **bot específico** era selecionado, o gráfico fazia **uma consulta por ponto** —
até 24 por hora, 30 por dia. E cada uma filtrava com `DATE(v.criado_em)` / `HOUR(v.criado_em)`
no `WHERE`: função na coluna impede o índice de ser usado, então cada consulta varria as
vendas do usuário (766 mil linhas na conta de teste).

Medido em produção, na conta `carlos3anos`:

| filtro | todos os bots | um bot específico |
|---|---|---|
| hoje | 0,42s | **6,2s** |
| ontem | 0,45s | **34,2s** |
| 7 dias | 0,57s | 2,2s |

34 segundos, com o proxy da Hostinger cortando em 60s. Com mais dado, vira 504.

**Corrigido no `index.php`**: uma consulta agregada com `GROUP BY` por hora/dia, e `WHERE`
por faixa (`criado_em >= X AND criado_em < Y`) em vez de função na coluna, pra usar o
índice `idx_vendas_ranking (status, criado_em, bot_id)`.

> ⚠️ **Não consegui confirmar o ganho.** A medição que faria isso caiu junto com o login
> (as respostas viraram 302 pro login). O código está deployado e passa no `php -l`, mas
> **precisa ser medido de novo depois que o login voltar** — inclusive conferindo se os
> valores do gráfico continuam batendo com o banco. Se por algum motivo estiver errado, o
> commit anterior é o `ecc9c85`.

### ❌ Pendente: o mesmo N+1 em `admin/dashboard.php`

Cinco laços iguais, nas linhas 187, 204, 222, 289 e 314, agregando `SUM(v.comissao_admin)`.
Meu patch não pegou porque lá as consultas não usam `$where_user_vendas`. O dashboard do
admin agrega a **plataforma inteira**, então tende a ser pior que o do usuário.

### ❌ Pendente: `log_errors` está Off em produção

Confirmado no `php -i` do servidor: `log_errors => Off`, `display_errors => Off`. O
`error_log` do projeto não recebe nada desde 18/09 07:51.

Consequência prática: quando o login quebrou, **o fatal não apareceu em lugar nenhum**.
Levei várias tentativas até reproduzir o POST na mão com `display_errors=1` pra ver a
mensagem. Em produção com cliente real, isso é operar no escuro.

### ❌ Pendente: `conexao.php` engole a falha de conexão

```php
} catch (PDOException $e) {
    error_log("Erro na conexão com o banco: " . $e->getMessage());
    // ... e segue em frente com $pdo indefinido
}
```

Quando o banco não responde, o código continua e o erro só estoura mais adiante como
`Call to a member function prepare() on null`, num arquivo aleatório. Foi exatamente o que
mascarou o incidente de hoje.

Deveria parar ali e mostrar uma página honesta de indisponibilidade.

---

## Varreduras 15, 16 e 17 — não feitas

Ficaram de fora: segurança (autorização de endpoints, escaping, uploads), resiliência
(tratamento de erro, crons, timeouts) e consistência de produto (rotas mortas, textos).
