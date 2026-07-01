<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';

// Aumentar tempo de execução para garantir que insira tudo
set_time_limit(300);

echo "<h1>Iniciando População do Banco de Dados...</h1>";

// Configurações
$qtdUsuarios = 50;
$senhaPadrao = '123456';
$senhaHash = password_hash($senhaPadrao, PASSWORD_DEFAULT);

// Listas para geração de dados aleatórios
$nomes = ['Ana', 'Bruno', 'Carlos', 'Daniela', 'Eduardo', 'Fernanda', 'Gabriel', 'Helena', 'Igor', 'Julia', 'Lucas', 'Mariana', 'Nicolas', 'Olivia', 'Pedro', 'Rafaela', 'Samuel', 'Tatiana', 'Vitor', 'Yasmin', 'Roberto', 'Luiza', 'Ricardo', 'Beatriz', 'Felipe', 'Larissa', 'Thiago', 'Camila', 'Rodrigo', 'Natália', 'Gustavo', 'Letícia', 'Vinícius', 'Amanda', 'Leonardo', 'Carolina', 'Henrique', 'Vanessa', 'Diego', 'Patrícia', 'Marcelo', 'Priscila', 'André', 'Débora', 'Fernando', 'Juliana', 'Antônio', 'Cláudia', 'Francisco', 'Mônica'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa', 'Rocha', 'Dias', 'Nascimento', 'Andrade', 'Moreira', 'Nunes', 'Marques', 'Machado', 'Mendes', 'Freitas', 'Cardoso', 'Ramos', 'Gonçalves', 'Santana', 'Teixeira'];

$adjetivosBot = ['Atendimento', 'Vendas', 'Suporte', 'Bot', 'Assistente', 'Loja', 'Promoções', 'Ofertas', 'Contato', 'Sac'];

// Função auxiliar para gerar datas aleatórias nos últimos 30 dias
function dataAleatoria() {
    $int = mt_rand(1, 30);
    return date('Y-m-d H:i:s', strtotime("-{$int} days"));
}

$pdo->beginTransaction();

try {
    $usuariosCriados = 0;
    
    for ($i = 0; $i < $qtdUsuarios; $i++) {
        // Gerar Nome Único
        $nome = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
        
        // Gerar Email: nome.sobrenome + numero aleatorio @ gmail.com
        $slugNome = strtolower(str_replace(' ', '.', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
        // Remover caracteres especiais do slug
        $slugNome = preg_replace('/[^a-z0-9.]/', '', $slugNome);
        $email = $slugNome . mt_rand(100, 9999) . '@gmail.com';

        // Inserir Usuário
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, criado_em) VALUES (?, ?, ?, 'usuario', ?)");
        $dataCriacaoUser = dataAleatoria();
        $stmt->execute([$nome, $email, $senhaHash, $dataCriacaoUser]);
        $idUsuario = (int)$pdo->lastInsertId();
        $usuariosCriados++;

        // Registrar Log de Cadastro
        $stmtLog = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'sistema', 'Cadastro', 'Usuário cadastrado no sistema.', 'default', ?)");
        $stmtLog->execute([$idUsuario, $dataCriacaoUser]);

        // Criar Bots para este usuário (1 a 3 bots)
        $qtdBots = mt_rand(1, 3);
        for ($b = 0; $b < $qtdBots; $b++) {
            $nomeBot = $adjetivosBot[array_rand($adjetivosBot)] . ' ' . $nomes[array_rand($nomes)];
            $tokenBot = 'TESTE_' . bin2hex(random_bytes(16));
            $userBot = strtolower(str_replace(' ', '_', $nomeBot)) . '_bot';
            
            $stmtBot = $pdo->prepare("INSERT INTO bots (id_usuario, token, primeiro_nome, nome_usuario, criado_em) VALUES (?, ?, ?, ?, ?)");
            $dataCriacaoBot = dataAleatoria();
            $stmtBot->execute([$idUsuario, $tokenBot, $nomeBot, $userBot, $dataCriacaoBot]);
            $idBot = (int)$pdo->lastInsertId();

            // Criar Fluxo para o Bot
            $stmtFluxo = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, criado_em) VALUES (?, ?, ?, ?)");
            $stmtFluxo->execute([$idUsuario, "Fluxo Principal " . $nomeBot, "Fluxo de teste gerado automaticamente", $dataCriacaoBot]);
            $idFluxo = (int)$pdo->lastInsertId();
            
            // Conectar Fluxo ao Bot
            $pdo->exec("UPDATE bots SET id_fluxo_conectado = $idFluxo WHERE id = $idBot");

            // Gerar Leads e Vendas para este Bot
            $qtdLeads = mt_rand(10, 50);
            for ($l = 0; $l < $qtdLeads; $l++) {
                $idTelegram = mt_rand(100000000, 999999999);
                $nomeLead = $nomes[array_rand($nomes)];
                $dataLead = dataAleatoria();

                // Inserir Lead
                $stmtLead = $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES (?, ?, ?, ?)");
                $stmtLead->execute([$idTelegram, $nomeLead, $idBot, $dataLead]);

                // Chance de 30% de gerar venda
                if (mt_rand(1, 100) <= 30) {
                    $valor = mt_rand(50, 500) . '.' . mt_rand(0, 99);
                    $status = mt_rand(1, 100) <= 70 ? 'pago' : 'gerado'; // 70% de chance de pago
                    $pagoEm = $status === 'pago' ? $dataLead : null;
                    $comissao = $status === 'pago' ? ($valor * 0.05) : 0.00; // 5% comissão

                    $stmtVenda = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, criado_em, pago_em, comissao_admin) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtVenda->execute([$idTelegram, $idBot, $valor, $status, $dataLead, $pagoEm, $comissao]);

                    if ($status === 'pago') {
                        // Log de Venda
                        $stmtLogVenda = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'venda', 'Venda Aprovada', ?, 'venda', ?)");
                        $desc = "Venda de R$ $valor no bot $nomeBot";
                        $stmtLogVenda->execute([$idUsuario, $desc, $dataLead]);
                    }
                } else {
                    // Log de Lead (alguns apenas)
                    if (mt_rand(1, 100) <= 20) {
                        $stmtLogLead = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'lead', 'Novo Lead', ?, 'lead', ?)");
                        $desc = "Novo lead $nomeLead no bot $nomeBot";
                        $stmtLogLead->execute([$idUsuario, $desc, $dataLead]);
                    }
                }
            }
        }
        
        // Logs genéricos de sistema para volume
        $acoes = ['Login', 'Logout', 'Edição de Perfil', 'Visualização de Relatório'];
        for ($a = 0; $a < 5; $a++) {
            $acao = $acoes[array_rand($acoes)];
            $stmtLogSys = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, 'sistema', ?, ?, 'default', ?)");
            $stmtLogSys->execute([$idUsuario, $acao, "Usuário realizou $acao", dataAleatoria()]);
        }
    }

    $pdo->commit();
    echo "<p style='color: green; font-weight: bold;'>Sucesso! Foram criados $usuariosCriados usuários com seus respectivos bots, fluxos e dados de vendas/leads.</p>";
    echo "<p>Agora você pode testar a paginação no painel admin e na lista de usuários.</p>";
    echo "<a href='usuarios.php'>Ir para Lista de Usuários</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>Erro ao popular banco: " . $e->getMessage() . "</p>";
}
