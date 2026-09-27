# Banner de campanha no Ranking + bug de criação de campanha (27/09/2026)

## 1. ✅ CORRIGIDO — Bug grave: criar campanha nova no Ranking sempre falhava

**Onde:** `admin/ranking.php`, handler `acao === 'salvar_campanha'`.

**O bug:** o `INSERT` de campanha nova tinha 6 colunas listadas
(`slug, titulo, subtitulo, data_inicio, data_fim, ativa`) mas **7** placeholders `?` no
`VALUES`, com apenas 6 valores passados pro `execute()`. Como o banco roda em
`PDO::ERRMODE_EXCEPTION`, isso lançava `PDOException` (número de parâmetros não bate) em
**toda tentativa de criar uma campanha nova** — caía no `catch` genérico e mostrava
"Erro ao salvar campanha.", sem detalhe nenhum. Editar uma campanha já existente
funcionava normalmente (o `UPDATE` tinha a contagem certa).

**Impacto:** ninguém conseguia criar uma campanha de Ranking do zero pelo painel — só
editar as que já existiam de antes (como a "Teste titulo" usada nos testes). Bug grave,
achado ao mexer no código pra adicionar o campo de banner (ver abaixo).

**Fix:** corrigida a contagem de colunas/valores do INSERT.

## 2. ✅ NOVO — Banner de campanha (recurso)

Pedido do Caio: colocar um banner promocional no topo da tela pública de Ranking.

**O que foi feito:**
- Nova coluna `campanhas_ranking.imagem_banner` (migração em `admin/atualiza_banco.php`,
  schema novo em `instalacao.php`).
- `admin/ranking.php`: campo de upload de imagem (jpg/png/webp, até 5MB, com
  `getimagesize()` confirmando o conteúdo — mesmo padrão já usado pra foto de bot em
  `api.php`) no formulário de campanha, com preview da imagem atual ao editar.
- `ranking.php` (tela pública): quando a campanha tem banner, ele aparece num card
  próprio no topo, acima do card de "Sua posição/Placar" que já existia. Sem banner
  configurado, continua exatamente como antes (logo geral do sistema no lugar de sempre).

## Pendente

- **SSH pro servidor continua fora do ar** (mesmo erro `/sbin/nologin`) — código
  commitado e no GitHub, mas nada disso está no servidor ainda. Por isso não consegui
  configurar o banner de verdade na campanha "Teste titulo" ao vivo.
- Gerado um banner de exemplo (1200×400, PNG, via GD) pra testar/usar assim que o deploy
  for possível: `anotacoes/testes/banner-ranking-vendas-exemplo.png`.
