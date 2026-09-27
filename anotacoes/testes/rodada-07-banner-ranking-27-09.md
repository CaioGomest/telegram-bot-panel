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

SSH voltou (era o toggle de acesso desativado no hPanel — o Caio reativou). Deploy feito,
migração rodada, testado ao vivo: criar campanha nova funciona, banner sobe e aparece na
tela pública. Contas/campanha descartáveis do teste já apagadas.

## 3. ✅ CORRIGIDO — Banner ficava desarmônico e não escalava no mobile

O Caio testou ao vivo e reportou: o primeiro formato (card de banner separado, com
título/subtítulo/CTA **desenhados dentro do PNG**) ficava duplicado — o card de baixo já
mostra o título/data/selo reais da campanha, então apareciam dois "cabeçalhos"
empilhados dizendo coisas parecidas. E por ser uma imagem raster de tamanho fixo com
texto embutido, encolhia inteira no mobile (o texto do PNG ficava ilegível, diferente do
texto de verdade da página, que é HTML e se adapta).

**Fix:** o banner não é mais um bloco separado — vira o **plano de fundo do próprio
card** (`.hero-ranking-capa`, `assets/css/coyote.css`), com um gradiente que esmaece pra
cor do painel do lado esquerdo (onde fica o texto real) e mostra a imagem cheia do lado
direito. Sem texto embutido na imagem — o título, selo e datas continuam sendo o HTML de
sempre, por cima, então escalam normal em qualquer largura de tela. Quando tem banner, o
logo pequeno do sistema (que ficava no canto) some, pra não competir com a imagem de
fundo. Texto de ajuda do campo de upload atualizado avisando pra não colocar texto na
imagem. Gerado um novo exemplo sem texto (`anotacoes/testes/capa-ranking-exemplo.png`) e
reenviado na campanha "Teste titulo" em produção.
