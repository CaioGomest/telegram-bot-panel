# Varredura 10 — cron_ranking.php sem proteção, segredo de gateway em claro no formulário

Data: 2026-09-16 (pós varredura 09). Rodada de investigação pura — nenhuma correção aplicada. Sem achado crítico dessa vez; o mais grave é de severidade média (integridade de um recurso com prêmio real associado), o resto é baixo risco/cosmético.

## 🔴 Alta prioridade

### 1. `cron/cron_ranking.php` sem proteção de acesso e sem `flock` — pode corromper ranking de campanha com prêmio real ✅ Corrigido

A varredura 06 mapeou "5 arquivos de cron sem autenticação", mas a pasta `cron/` tem **7 arquivos** — `cron_ranking.php` e `cron_metricas_admin.php` nunca foram auditados.

`cron_ranking.php` (arquivo inteiro, 57 linhas): zero checagem de origem (sem `php_sapi_name()==='cli'`, sem chave secreta) — mesma falha já conhecida nos outros 5. Mas aqui tem um agravante que os outros não têm: pra cada campanha ativa, ele roda dentro de uma transação:

```php
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM ranking_cache WHERE campanha_id = ?")->execute([$campanha_id]);
$stmt = $pdo->prepare("
    INSERT INTO ranking_cache (...)
    SELECT ?, b.id_usuario, SUM(v.valor), RANK() OVER (...), NOW()
    FROM vendas v JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'pago' AND v.criado_em BETWEEN ? AND ?
    GROUP BY b.id_usuario
");
$stmt->execute([...]);
$pdo->commit();
```

- **Sem `flock`** — diferente de `cron_verificar_pix.php`/`cron_remarketing.php`/`cron_metricas_admin.php`, nada impede duas execuções simultâneas.
- **Sem `LIMIT`/janela curta** — agrega `SUM`/`GROUP BY`/`RANK()` sobre toda venda `pago` do período da campanha, que pode ser longo (campanha `oficial`). `analise-potencia-e-escala.md` já mediu que um `SUM(valor)` simples leva ~4,6s a 1 milhão de linhas — essa query é mais pesada (agrega por usuário + calcula `RANK()`).
- **Efeito colateral pior que só gastar CPU/IO:** como o `DELETE`+`INSERT` roda numa transação, duas execuções concorrentes não só duplicam trabalho — elas **disputam lock de linha do InnoDB** no `DELETE`, podendo **deadlockar** (uma é morta pelo MySQL, capturada pelo `catch` por campanha, que só loga e segue sem reprocessar). Alguém martelando a URL pode fazer o cron **legítimo** (agendado a cada 1 min conforme `CLAUDE.md`) perder a corrida e ter sua atualização descartada por rollback — ou seja, dá pra manipular *quando* o `ranking_cache` de uma campanha para de refletir a realidade, numa feature que decide **prêmios reais** (`campanhas_ranking_premios`). É uma classe de risco diferente (integridade de um recurso com dinheiro associado) do "gasta chamada de API à toa" já registrado pros outros 5 crons na varredura 06.

**`cron/cron_metricas_admin.php`** (também nunca mapeado): mesmo problema de falta de proteção de acesso, mas risco bem menor — já tem `flock(LOCK_EX | LOCK_NB)` corretamente implementado e só recalcula uma janela fixa de 48h via `ON DUPLICATE KEY UPDATE` (idempotente, sem `DELETE`). Hammering nele só desperdiça uma query relativamente barata repetida.

**Correção recomendada:** aplicar a mesma proteção CLI-ou-chave já recomendada na varredura 06 nos 2 arquivos, **e** adicionar `flock` em `cron_ranking.php` (mesmo padrão de `cron_metricas_admin.php`/`cron_remarketing.php`) — isso sozinho já elimina o risco de deadlock/corrupção sob concorrência, independente da proteção de acesso.

## 🟡 Média prioridade

### 2. Segredo de gateway em texto puro no HTML de `gateways.php` (nova instância do padrão da varredura 03, item 5)

`client_secret`, `chave_pix` e `cashout_client_secret` são ecoados com o valor real no atributo `value=""` do formulário de edição (`gateways.php`). `client_secret`/`cashout_client_secret` são `type="password"` (mascarados visualmente, mas o valor real ainda vai cru no HTML — visível em "inspecionar elemento"); `chave_pix` é `type="text"`, aparece em claro na tela sem precisar de devtools.

O próprio arquivo já usa o padrão certo em `cert_password`/`cashout_cert_password`: nunca ecoa o valor, só `placeholder="••••••••"` com aviso "deixe em branco pra manter a senha já salva". Bastaria replicar esse padrão nos 3 campos citados.

Mesma categoria/risco baixo já registrado na varredura 03 (cosmético), mas instância mais exposta (`chave_pix` nem precisa de devtools).

### 3. Token do bot também aparece em claro, via AJAX (não HTML inicial)

`assets/edicao_bot.js` preenche o campo token a partir da resposta JSON de `api.php?action=obter_bot` (que filtra corretamente por dono, sem IDOR). Não fica embutido no HTML servido pelo PHP, só aparece na aba Network/Console do navegador. Risco mais baixo que o item 2 — é um dado que o próprio dono já possui (veio do BotFather), não um segredo custodiado pela plataforma.

### 4. Nota de estilo (não é vulnerabilidade ativa) — concatenação de SQL em `admin/dashboard.php`

`$where_bot_vendas`/`$where_data_vendas`/`$where_data_metricas` são montados por concatenação direta, mas confirmadamente seguros hoje: `bot_id` é validado contra whitelist de IDs reais do banco antes de entrar na string, e as datas passam por `DateTime::createFromFormat` com checagem de round-trip exato (qualquer caractere de injeção quebra o round-trip e zera o filtro). Funcional e seguro, mas frágil a longo prazo comparado a bind — vale trocar numa limpeza futura, não por ter achado brecha.

## 🟢 Checado e sem problema

- **LFI dinâmico fora do já corrigido na varredura 08** — nenhuma ocorrência de `include`/`require` com caminho montado a partir de `$_GET`/`$_POST`/`$_REQUEST` em todo o projeto. `bot.php`/`fluxo.php`/`index.php` só incluem arquivos fixos.
- **Ação destrutiva disparável via GET puro** — `api.php` aceita `action` via GET ou POST, mas os parâmetros de cada ação (`id`, etc.) só vêm de `dadosRequisicao()` (JSON body ou `$_POST`), nunca `$_GET` — um `<img src="...api.php?action=excluir_fluxo">` não teria `id`, nada seria apagado. `ajax/deletar_usuario.php`/`editar_usuario.php` e `admin/ranking.php` checam `REQUEST_METHOD === 'POST'` explicitamente. Continua sendo "só" a pendência de CSRF já conhecida (varredura 02), não um vetor mais grave de link único.
- **Custo de `password_hash()`** — todas as 6 ocorrências usam `PASSWORD_DEFAULT` sem `cost` customizado. Sem enfraquecimento.
- **SQL injection em `admin/consultar_venda.php`, `admin/logs.php`, `admin/dashboard.php`, `admin/transacoes.php`** — todos os filtros usam prepared statement com bind. Nenhum `ORDER BY`/coluna dinâmica vinda de request em todo o projeto.

## Resumo por prioridade

1. ~~🔴 `cron/cron_ranking.php` sem proteção de acesso + sem `flock`~~ **Feito** (junto com `cron_metricas_admin.php`, que só precisava da proteção de acesso).
2. **🟡 Segredo de gateway em claro no HTML** (`gateways.php`: `client_secret`, `chave_pix`, `cashout_client_secret`) — correção trivial (replicar padrão `placeholder="••••••••"` já usado no mesmo arquivo).
3. **🟡 Token do bot em claro via JS** — mesma categoria, risco ainda mais baixo.
4. **🟡 Nota de estilo** — concatenação de SQL em `admin/dashboard.php` (seguro hoje, frágil a longo prazo).
5. **🟢 Sem achado novo:** LFI dinâmico, ação destrutiva via GET puro, custo de `password_hash()`, SQLi/`ORDER BY` dinâmico nas 4 ferramentas admin auditadas.

## Correções aplicadas (2026-09-16)

### `cron_ranking.php` e `cron_metricas_admin.php` — proteção de acesso + `flock`

Nova constante `CHAVE_SECRETA_CRON` em `config.php` (placeholder, mesmo padrão de `CHAVE_CRIPTOGRAFIA_GATEWAYS` — precisa ser trocada no servidor antes de produção). Em ambos os arquivos, logo após a definição da função de log:

```php
if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logCron...('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}
```

Chamada via linha de comando (`php cron_ranking.php`, forma mais comum de configurar cron na Hostinger) passa direto, sem precisar de chave — `php_sapi_name()` retorna `'cli'` nesse caso. Se o crontab estiver configurado via HTTP/wget, a URL cadastrada precisa incluir `?chave=<valor de CHAVE_SECRETA_CRON>`.

Em `cron_ranking.php`, também adicionado `flock(LOCK_EX | LOCK_NB)` sobre um arquivo em `sys_get_temp_dir()` (mesmo padrão de `cron_verificar_pix.php`/`cron_remarketing.php`/`cron_metricas_admin.php`), liberado explicitamente em todo ponto de saída do script (nenhuma campanha ativa, fim normal). `cron_metricas_admin.php` já tinha essa trava — só faltava a proteção de acesso.

Testado: execução via CLI local (`php cron/cron_ranking.php` e `php cron/cron_metricas_admin.php`) roda normalmente contra o banco de dev, gravando log de sucesso; lógica de `hash_equals`/`CHAVE_SECRETA_CRON` testada isoladamente (sem chave → bloqueado, chave errada → bloqueado, chave certa → liberado).

**Importante:** se o crontab de produção (Hostinger) estiver configurado via HTTP em vez de CLI, as URLs de `cron_ranking.php`/`cron_metricas_admin.php` cadastradas lá precisam ganhar `?chave=...` depois desse deploy, senão os dois passam a retornar 403 e param de rodar silenciosamente (mesmo aviso que já existe no `CLAUDE.md` pra outras mudanças de caminho de cron).

## Notas relacionadas

- `varredura-06-cron-sem-autenticacao.md` — pendência original dos 5 crons; item 1 desta rodada estende a mesma correção pros 2 arquivos que faltaram.
- `varredura-03-webhooks-credenciais-logs.md` — item 5 (token em claro), mesma categoria do item 2/3 desta rodada.
- [[analise-potencia-e-escala]] — base da estimativa de custo da query de `SUM(valor)` em volume alto.
- [[como-funciona-ranking]] — contexto de `ranking_cache`/`cron_ranking.php`.
- [[pendencias]] — lista consolidada.
