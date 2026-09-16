<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/relatorio_debug.php';
verificarAdminOuInstalacao();

// Aumentar tempo de execução para garantir que insira tudo
set_time_limit(300);

ob_start();

echo "<h2>Iniciando População do Banco de Dados...</h2>";

$qtd_usuarios = 50;
$senha_padrao = '123456';
$senha_hash = password_hash($senha_padrao, PASSWORD_DEFAULT);

$nomes = ['Ana', 'Bruno', 'Carlos', 'Daniela', 'Eduardo', 'Fernanda', 'Gabriel', 'Helena', 'Igor', 'Julia', 'Lucas', 'Mariana', 'Nicolas', 'Olivia', 'Pedro', 'Rafaela', 'Samuel', 'Tatiana', 'Vitor', 'Yasmin', 'Roberto', 'Luiza', 'Ricardo', 'Beatriz', 'Felipe', 'Larissa', 'Thiago', 'Camila', 'Rodrigo', 'Natália', 'Gustavo', 'Letícia', 'Vinícius', 'Amanda', 'Leonardo', 'Carolina', 'Henrique', 'Vanessa', 'Diego', 'Patrícia', 'Marcelo', 'Priscila', 'André', 'Débora', 'Fernando', 'Juliana', 'Antônio', 'Cláudia', 'Francisco', 'Mônica'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa', 'Rocha', 'Dias', 'Nascimento', 'Andrade', 'Moreira', 'Nunes', 'Marques', 'Machado', 'Mendes', 'Freitas', 'Cardoso', 'Ramos', 'Gonçalves', 'Santana', 'Teixeira'];

$adjetivos_bot = ['Atendimento', 'Vendas', 'Suporte', 'Bot', 'Assistente', 'Loja', 'Promoções', 'Ofertas', 'Contato', 'Sac'];

function dataAleatoria() {
    $int = mt_rand(1, 30);
    return date('Y-m-d H:i:s', strtotime("-{$int} days"));
}

$pdo->beginTransaction();

try {
    $usuarios_criados = 0;
    
    for ($i = 0; $i < $qtd_usuarios; $i++) {
        $nome = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];

        $slug_nome = strtolower(str_replace(' ', '.', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
        $slug_nome = preg_replace('/[^a-z0-9.]/', '', $slug_nome);
        $email = $slug_nome . mt_rand(100, 9999) . '@gmail.com';

        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, criado_em) VALUES (?, ?, ?, 'usuario', ?)");
        $data_criacao_user = dataAleatoria();
        $stmt->execute([$nome, $email, $senha_hash, $data_criacao_user]);
        $id_usuario = (int)$pdo->lastInsertId();
        $usuarios_criados++;

        $stmt_log = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'sistema', 'Cadastro', 'Usuário cadastrado no sistema.', 'default', ?)");
        $stmt_log->execute([$id_usuario, $data_criacao_user]);

        $qtd_bots = mt_rand(1, 3);
        for ($b = 0; $b < $qtd_bots; $b++) {
            $nome_bot = $adjetivos_bot[array_rand($adjetivos_bot)] . ' ' . $nomes[array_rand($nomes)];
            $token_bot = 'TESTE_' . bin2hex(random_bytes(16));
            $user_bot = strtolower(str_replace(' ', '_', $nome_bot)) . '_bot';
            
            $stmt_bot = $pdo->prepare("INSERT INTO bots (id_usuario, token, primeiro_nome, nome_usuario, criado_em) VALUES (?, ?, ?, ?, ?)");
            $data_criacao_bot = dataAleatoria();
            $stmt_bot->execute([$id_usuario, $token_bot, $nome_bot, $user_bot, $data_criacao_bot]);
            $id_bot = (int)$pdo->lastInsertId();

            $stmt_fluxo = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, criado_em) VALUES (?, ?, ?, ?)");
            $stmt_fluxo->execute([$id_usuario, "Fluxo Principal " . $nome_bot, "Fluxo de teste gerado automaticamente", $data_criacao_bot]);
            $id_fluxo = (int)$pdo->lastInsertId();
            
            $pdo->prepare("UPDATE bots SET id_fluxo_conectado = ? WHERE id = ?")->execute([$id_fluxo, $id_bot]);

            $qtd_leads = mt_rand(10, 50);
            for ($l = 0; $l < $qtd_leads; $l++) {
                $id_telegram = mt_rand(100000000, 999999999);
                $nome_lead = $nomes[array_rand($nomes)];
                $data_lead = dataAleatoria();

                $stmt_lead = $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES (?, ?, ?, ?)");
                $stmt_lead->execute([$id_telegram, $nome_lead, $id_bot, $data_lead]);

                if (mt_rand(1, 100) <= 30) {
                    $valor = mt_rand(50, 500) . '.' . mt_rand(0, 99);
                    $status = mt_rand(1, 100) <= 70 ? 'pago' : 'gerado';
                    $pago_em = $status === 'pago' ? $data_lead : null;
                    $comissao = $status === 'pago' ? ($valor * 0.05) : 0.00;

                    $stmt_venda = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, criado_em, pago_em, comissao_admin) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_venda->execute([$id_telegram, $id_bot, $valor, $status, $data_lead, $pago_em, $comissao]);

                    if ($status === 'pago') {
                        $stmt_log_venda = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'venda', 'Venda Aprovada', ?, 'venda', ?)");
                        $desc = "Venda de R$ $valor no bot $nome_bot";
                        $stmt_log_venda->execute([$id_usuario, $desc, $data_lead]);
                    }
                } else {
                    if (mt_rand(1, 100) <= 20) {
                        $stmt_log_lead = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'lead', 'Novo Lead', ?, 'lead', ?)");
                        $desc = "Novo lead $nome_lead no bot $nome_bot";
                        $stmt_log_lead->execute([$id_usuario, $desc, $data_lead]);
                    }
                }
            }
        }
        
        $acoes = ['Login', 'Logout', 'Edição de Perfil', 'Visualização de Relatório'];
        for ($a = 0; $a < 5; $a++) {
            $acao = $acoes[array_rand($acoes)];
            $stmt_log_sys = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'sistema', ?, ?, 'default', ?)");
            $stmt_log_sys->execute([$id_usuario, $acao, "Usuário realizou $acao", dataAleatoria()]);
        }
    }

    $pdo->commit();
    echo "<p style='color: var(--ok); font-weight: bold;'>Sucesso! Foram criados $usuarios_criados usuários com seus respectivos bots, fluxos e dados de vendas/leads.</p>";
    echo "<p>Agora você pode testar a paginação no painel admin e na lista de usuários.</p>";
    echo "<a href='../admin/usuarios.php' style='color:var(--or);'>Ir para Lista de Usuários</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p style='color: var(--da);'>Erro ao popular banco: " . htmlspecialchars($e->getMessage()) . "</p>";
}

exibirRelatorioDebug('Popular Banco (dados de teste)', ob_get_clean(), '../');
