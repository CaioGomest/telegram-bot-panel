<?php
declare(strict_types=1);

// Página pública: não exige login, porque o link fica no formulário de cadastro.
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/configuracoes.php';

$nome = nomeSistema();
$atualizado_em = '18 de setembro de 2026';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos de uso - <?php echo htmlspecialchars($nome); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="pagina-texto">
    <div class="pagina-texto-cabecalho">
        <a href="cadastro" style="display:flex;align-items:center;gap:12px;">
            <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="" class="login-cabecalho-logo">
            <strong style="font-size:15px;"><?php echo htmlspecialchars($nome); ?></strong>
        </a>
        <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
        </button>
    </div>

    <h1>Termos de uso</h1>
    <p class="texto-suave">Última atualização: <?php echo $atualizado_em; ?></p>

    <h2>1. O que é este serviço</h2>
    <p><?php echo htmlspecialchars($nome); ?> é um painel que permite criar e operar bots do Telegram: montar
    fluxos de conversa, vender acesso a grupos e canais, receber pagamentos por PIX através de um gateway
    integrado e acompanhar leads e vendas. Ao criar uma conta, você concorda com estes termos.</p>

    <h2>2. Sua conta</h2>
    <p>Você é responsável por manter a senha em segredo e por tudo que acontecer na sua conta. Os dados de
    cadastro precisam ser verdadeiros. Cada pessoa ou empresa deve usar a própria conta.</p>

    <h2>3. Seus bots e seu conteúdo</h2>
    <p>Os bots que você conecta, os fluxos que monta e o conteúdo que distribui são seus e de sua
    responsabilidade. Você deve respeitar os Termos de Serviço do Telegram e a legislação brasileira.</p>
    <p>É proibido usar a plataforma para conteúdo sexual envolvendo menores, material que viole direitos
    autorais de terceiros, golpes, venda de produtos ilegais, ou qualquer atividade criminosa. Contas
    usadas para isso são encerradas sem aviso, e podemos comunicar as autoridades quando a lei exigir.</p>

    <h2>4. Pagamentos</h2>
    <p>As cobranças PIX geradas pelos seus bots são processadas por um gateway de pagamento parceiro. O
    repasse, os prazos e as taxas seguem as regras desse gateway. A plataforma pode reter uma comissão
    sobre as vendas, informada no seu painel.</p>
    <p>Reembolsos e contestações (inclusive o mecanismo MED do PIX) são tratados entre você, o comprador
    e o gateway. A plataforma não é parte da relação de consumo entre você e quem compra dos seus bots.</p>

    <h2>5. Dados pessoais</h2>
    <p>Para operar o serviço, guardamos seus dados de cadastro e os dados dos leads que interagem com os
    seus bots (identificador do Telegram, nome de usuário e histórico de compra). Esses dados são usados
    para fazer o produto funcionar e mostrar suas métricas — não são vendidos a terceiros.</p>
    <p>Você é o controlador dos dados dos seus leads perante a LGPD; a plataforma atua como operadora.
    Pedidos de exclusão de dados podem ser feitos pelo suporte.</p>

    <h2>6. Disponibilidade</h2>
    <p>Fazemos o possível para manter o serviço no ar, mas ele depende de terceiros (Telegram, gateway de
    pagamento, hospedagem) e pode ter interrupções. Não garantimos funcionamento ininterrupto nem
    resultado comercial de nenhum tipo.</p>

    <h2>7. Encerramento</h2>
    <p>Você pode encerrar sua conta quando quiser. Podemos encerrar ou suspender contas que violem estes
    termos. Em caso de encerramento, seus dados podem ser mantidos pelo prazo que a lei exigir.</p>

    <h2>8. Mudanças nestes termos</h2>
    <p>Estes termos podem mudar. Alterações relevantes são avisadas no painel, e continuar usando o
    serviço depois disso significa que você aceitou a nova versão.</p>

    <h2>9. Contato</h2>
    <p>Dúvidas sobre estes termos podem ser enviadas pelo canal de suporte informado no seu painel.</p>

    <p style="margin-top:32px;">
        <a href="cadastro" class="botao">Voltar para o cadastro</a>
    </p>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
