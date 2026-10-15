-- =====================================================================================================================
-- schema.sql — estrutura COMPLETA do banco do painel (39 tabelas), sem dados, para MariaDB 10.6+ / MySQL 8+.
--
-- ORIGEM: gerado a partir do que o instalador (instalacao.php) cria, em 11/10/2026, e conferido campo a campo (colunas, tipos,
-- nulos, padrões, índices e chaves estrangeiras) contra o banco de homologação, que é a instalação antiga + todas as migrações.
-- A única diferença aceita são os índices "wdbi_*", que a hospedagem compartilhada cria sozinha (não fazem parte do schema).
--
-- COMO USAR:
--   • Instalação normal NÃO precisa deste arquivo: o instalador (instalacao.php) e o instalar.sh já criam tudo isto.
--   • Serve para: recriar o banco do zero (restaurar estrutura), revisar o schema, ou criar um banco de teste:
--       mysql -u USUARIO -p NOME_DO_BANCO < deploy/schema.sql
--   • É idempotente (CREATE TABLE IF NOT EXISTS) e já traz a linha de gateway que a tela Gateways espera.
--   • NÃO cria usuário admin: quem cria é o instalador (instalacao.php) ou o instalar.sh (com senha forte).
--
-- ATENÇÃO: o .htaccess da raiz do painel bloqueia *.sql pela web e o instalar.sh não copia a pasta deploy/ para o site.
-- Se mudar o schema: altere instalacao.php + admin/atualiza_banco.php e gere este arquivo de novo (ver deploy/PLANO-VPS.md, seção Banco).
-- =====================================================================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `apelido_publico` varchar(40) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `email_verificado_em` datetime DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `senha` varchar(255) NOT NULL,
  `perfil` enum('admin','usuario') DEFAULT 'usuario',
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `descricao` varchar(500) DEFAULT NULL,
  `link_suporte` varchar(255) DEFAULT NULL,
  `modo` enum('avancado','basico') NOT NULL DEFAULT 'avancado',
  `dados_fluxograma` longtext DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  CONSTRAINT `fluxos_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `id_bot_telegram` bigint(20) DEFAULT NULL,
  `nome_usuario` varchar(100) DEFAULT NULL,
  `primeiro_nome` varchar(100) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `descricao_curta` varchar(255) DEFAULT NULL,
  `id_fluxo_conectado` int(11) DEFAULT NULL,
  `caminho_foto` varchar(255) DEFAULT NULL,
  `url_webhook` varchar(255) DEFAULT NULL,
  `webhook_secret` varchar(64) DEFAULT NULL,
  `token_invalido_em` datetime DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  KEY `id_fluxo_conectado` (`id_fluxo_conectado`),
  CONSTRAINT `bots_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bots_ibfk_2` FOREIGN KEY (`id_fluxo_conectado`) REFERENCES `fluxos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_telegram` varchar(50) NOT NULL,
  `nome` varchar(100) DEFAULT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `nome_usuario_telegram` varchar(32) DEFAULT NULL,
  `origem_rastreio` varchar(100) DEFAULT NULL,
  `pagou` tinyint(1) NOT NULL DEFAULT 0,
  `bot_id` int(11) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `id_fluxo` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leads_bot` (`bot_id`,`id_telegram`),
  KEY `idx_leads_bot_criado_em` (`bot_id`,`criado_em` DESC),
  KEY `idx_leads_bot_pagou_criado` (`bot_id`,`pagou`,`criado_em` DESC),
  KEY `idx_leads_fluxo` (`id_fluxo`,`criado_em`),
  CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads_starts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `criado_em` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_starts_bot_data` (`bot_id`,`criado_em`),
  KEY `idx_starts_lead` (`lead_id`,`criado_em`),
  CONSTRAINT `leads_starts_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_telegram` varchar(50) NOT NULL,
  `bot_id` int(11) DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `status` enum('gerado','pago','cancelado','expirado') DEFAULT 'gerado',
  `transacao_id` varchar(255) DEFAULT NULL,
  `id_operador_fluxo` varchar(50) DEFAULT NULL,
  `id_gateway` int(11) DEFAULT NULL,
  `tempo_expiracao_minutos` int(11) DEFAULT 15,
  `id_grupo_telegram` varchar(50) DEFAULT NULL,
  `dias_acesso` int(11) DEFAULT 30,
  `tempo_acesso_minutos` bigint(20) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `pago_em` datetime DEFAULT NULL,
  `comissao_admin` decimal(10,2) DEFAULT 0.00,
  `split_status` enum('sem_split','sem_credenciais','pago','falhou','parcial') DEFAULT NULL,
  `split_valor` decimal(10,2) DEFAULT NULL,
  `split_em` datetime DEFAULT NULL,
  `tipo_cobranca` enum('unica','assinatura','recorrente') DEFAULT 'unica',
  `status_renovacao` enum('pendente','renovada','cancelada') DEFAULT 'pendente',
  `venda_pai_id` int(11) DEFAULT NULL,
  `id_assinatura` varchar(100) DEFAULT NULL,
  `id_plano` int(11) DEFAULT NULL,
  `ultimo_txid_renovacao` varchar(255) DEFAULT NULL,
  `id_fluxo` int(11) DEFAULT NULL,
  `nome_produto` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_transacao_id` (`transacao_id`),
  KEY `idx_id_assinatura` (`id_assinatura`),
  KEY `idx_vendas_lookup` (`id_telegram`,`bot_id`,`status`),
  KEY `idx_vendas_ranking` (`status`,`criado_em`,`bot_id`),
  KEY `idx_vendas_criado_em` (`criado_em` DESC),
  KEY `idx_vendas_fluxo` (`id_fluxo`,`criado_em`),
  KEY `idx_vendas_bot_criado` (`bot_id`,`criado_em`),
  CONSTRAINT `vendas_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `atividades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `titulo` varchar(100) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `icone` varchar(50) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `lido_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_atividades_tipo` (`tipo`),
  KEY `idx_atividades_notif` (`id_usuario`,`lido_em`),
  CONSTRAINT `atividades_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_grupos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bot_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bot_grupo` (`bot_id`,`id_telegram`),
  CONSTRAINT `bot_grupos_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membros_grupos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_telegram` varchar(50) NOT NULL,
  `id_grupo_telegram` varchar(50) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `venda_id` int(11) DEFAULT NULL,
  `invite_link` varchar(255) DEFAULT NULL,
  `data_expiracao` datetime DEFAULT NULL,
  `status` enum('ativo','expirado','banido') DEFAULT 'ativo',
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `data_tolerancia` datetime DEFAULT NULL,
  `em_renovacao` tinyint(1) DEFAULT 0,
  `aviso_enviado` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unico_membro` (`id_telegram`,`id_grupo_telegram`,`bot_id`),
  KEY `bot_id` (`bot_id`),
  KEY `venda_id` (`venda_id`),
  KEY `idx_membros_plano_ativo` (`id_telegram`,`bot_id`,`status`,`data_expiracao`),
  CONSTRAINT `membros_grupos_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `membros_grupos_ibfk_2` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `avisos_assinatura_enviados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `membro_id` int(11) NOT NULL,
  `marco` varchar(40) NOT NULL,
  `enviado_em` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unico_aviso` (`membro_id`,`marco`),
  KEY `idx_avisos_enviado_em` (`enviado_em`),
  CONSTRAINT `avisos_assinatura_enviados_ibfk_1` FOREIGN KEY (`membro_id`) REFERENCES `membros_grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxo_continuacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bot_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `id_fluxo` int(11) NOT NULL,
  `id_operador_destino` varchar(60) NOT NULL,
  `executar_em` datetime NOT NULL,
  `status` enum('pendente','processando','feito','falhou','cancelado') NOT NULL DEFAULT 'pendente',
  `criado_em` datetime NOT NULL,
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_continuacoes_pendentes` (`status`,`executar_em`),
  KEY `idx_continuacoes_lead` (`bot_id`,`id_telegram`,`status`),
  CONSTRAINT `fluxo_continuacoes_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fluxo_ofertas_aceitas` (
  `bot_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `tipo` enum('upsell','downsell','order_bump') NOT NULL,
  `desconto_percentual` decimal(5,2) NOT NULL DEFAULT 0.00,
  `valor_extra` decimal(10,2) NOT NULL DEFAULT 0.00,
  `nome` varchar(100) DEFAULT NULL,
  `criado_em` datetime NOT NULL,
  PRIMARY KEY (`bot_id`,`id_telegram`,`tipo`),
  CONSTRAINT `fluxo_ofertas_aceitas_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mensagens_agendadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bot_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `message_id` bigint(20) NOT NULL,
  `apagar_em` datetime NOT NULL,
  `apagada` tinyint(1) NOT NULL DEFAULT 0,
  `depois_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_agendadas_pendentes` (`apagada`,`apagar_em`),
  KEY `bot_id` (`bot_id`),
  CONSTRAINT `mensagens_agendadas_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `previas_vistas` (
  `bot_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `visto_em` datetime NOT NULL,
  PRIMARY KEY (`bot_id`,`id_telegram`),
  CONSTRAINT `previas_vistas_ibfk_1` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `titulo` varchar(100) NOT NULL,
  `ativo` tinyint(1) DEFAULT 0,
  `taxa_split` decimal(5,2) DEFAULT 0.00,
  `chave_pix_split` varchar(255) DEFAULT NULL,
  `tipo_split` enum('percentual','fixo') DEFAULT 'percentual',
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios_gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `id_gateway` int(11) NOT NULL,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(500) DEFAULT NULL,
  `certificado` varchar(255) DEFAULT NULL,
  `cert_password` varchar(500) DEFAULT NULL,
  `chave_pix` varchar(500) DEFAULT NULL,
  `tipo_conta` enum('pf','pj') DEFAULT 'pj',
  `ativo` tinyint(1) DEFAULT 0,
  `prioridade` int(11) DEFAULT 100,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_gateway` (`id_usuario`,`id_gateway`),
  KEY `id_gateway` (`id_gateway`),
  CONSTRAINT `usuarios_gateways_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `usuarios_gateways_ibfk_2` FOREIGN KEY (`id_gateway`) REFERENCES `gateways` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `gateway_nome` varchar(50) NOT NULL,
  `tipo_split` enum('percentual','fixo') DEFAULT 'percentual',
  `taxa_split` decimal(10,2) DEFAULT 0.00,
  `chave_pix_split` varchar(255) NOT NULL,
  `descricao` varchar(100) DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  CONSTRAINT `usuarios_splits_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendas_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venda_id` int(11) NOT NULL,
  `usuario_split_id` int(11) DEFAULT NULL,
  `chave_pix` varchar(255) NOT NULL,
  `descricao` varchar(100) DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `status` enum('pago','falhou') NOT NULL,
  `erro` text DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `venda_id` (`venda_id`),
  KEY `usuario_split_id` (`usuario_split_id`),
  CONSTRAINT `vendas_splits_ibfk_1` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendas_splits_ibfk_2` FOREIGN KEY (`usuario_split_id`) REFERENCES `usuarios_splits` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remarketing_campanhas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `nome` varchar(100) DEFAULT NULL,
  `audiencia` enum('nao_comprou','comprou') NOT NULL,
  `mensagem` text NOT NULL,
  `midia_caminho` varchar(255) DEFAULT NULL,
  `midia_tipo` enum('foto','video') DEFAULT NULL,
  `midia_file_id` varchar(255) DEFAULT NULL,
  `agendado_em` datetime NOT NULL,
  `status` enum('pendente','processando','concluida','falha') DEFAULT 'pendente',
  `total_destinatarios` int(11) DEFAULT 0,
  `enviados` int(11) DEFAULT 0,
  `entregues` int(11) DEFAULT 0,
  `falhas` int(11) DEFAULT 0,
  `offset_envio` int(11) DEFAULT 0,
  `processado_em` datetime DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  KEY `bot_id` (`bot_id`),
  KEY `idx_status_agendado` (`status`,`agendado_em`),
  CONSTRAINT `remarketing_campanhas_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `remarketing_campanhas_ibfk_2` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remarketing_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campanha_id` int(11) NOT NULL,
  `id_telegram` varchar(50) NOT NULL,
  `resultado` enum('sucesso','falha') NOT NULL,
  `resposta` text DEFAULT NULL,
  `enviado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campanha` (`campanha_id`),
  CONSTRAINT `remarketing_envios_ibfk_1` FOREIGN KEY (`campanha_id`) REFERENCES `remarketing_campanhas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios_traqueamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `facebook_ativo` tinyint(1) DEFAULT 0,
  `facebook_pixel_id` varchar(255) DEFAULT NULL,
  `facebook_access_token` text DEFAULT NULL,
  `tiktok_ativo` tinyint(1) DEFAULT 0,
  `tiktok_pixel_id` varchar(255) DEFAULT NULL,
  `tiktok_access_token` text DEFAULT NULL,
  `utmfy_ativo` tinyint(1) DEFAULT 0,
  `utmfy_token` text DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`id_usuario`),
  CONSTRAINT `usuarios_traqueamento_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `links_rastreamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `identificador` varchar(100) NOT NULL,
  `bot_id` int(11) NOT NULL,
  `starts` int(11) DEFAULT 0,
  `leads` int(11) DEFAULT 0,
  `vendas_qtd` int(11) DEFAULT 0,
  `vendas_valor` decimal(10,2) DEFAULT 0.00,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_identificador_usuario` (`id_usuario`,`identificador`),
  KEY `bot_id` (`bot_id`),
  CONSTRAINT `links_rastreamento_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `links_rastreamento_ibfk_2` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo_midia` enum('foto','video') NOT NULL,
  `arquivo` varchar(255) NOT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `expira_em` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stories_expira` (`expira_em`),
  KEY `idx_stories_usuario` (`id_usuario`),
  CONSTRAINT `stories_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stories_visualizacoes` (
  `story_id` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `visto_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`story_id`,`id_usuario`),
  KEY `id_usuario` (`id_usuario`),
  CONSTRAINT `stories_visualizacoes_ibfk_1` FOREIGN KEY (`story_id`) REFERENCES `stories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stories_visualizacoes_ibfk_2` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comunidade_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(30) NOT NULL DEFAULT 'outro',
  `titulo` varchar(80) NOT NULL,
  `subtitulo` varchar(120) DEFAULT NULL,
  `url` varchar(500) NOT NULL,
  `icone` varchar(30) NOT NULL DEFAULT 'link',
  `membros_atual` int(10) unsigned NOT NULL DEFAULT 0,
  `membros_max` int(10) unsigned NOT NULL DEFAULT 0,
  `ordem` int(11) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `nome` varchar(80) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(128) DEFAULT NULL,
  `eventos` varchar(120) NOT NULL,
  `bot_id` int(11) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `falhas_consecutivas` int(11) NOT NULL DEFAULT 0,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bot_id` (`bot_id`),
  KEY `idx_webhooks_usuario_ativo` (`id_usuario`,`ativo`),
  CONSTRAINT `webhooks_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `webhooks_ibfk_2` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhooks_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) NOT NULL,
  `evento` varchar(40) NOT NULL,
  `http_status` smallint(6) DEFAULT NULL,
  `sucesso` tinyint(1) NOT NULL DEFAULT 0,
  `erro` varchar(180) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_webhooks_envios_webhook` (`webhook_id`,`criado_em`),
  CONSTRAINT `webhooks_envios_ibfk_1` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `configuracoes` (
  `chave` varchar(50) NOT NULL,
  `valor` text DEFAULT NULL,
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_email` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expira_em` datetime NOT NULL,
  `usado_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unico_token` (`token_hash`),
  KEY `idx_tokens_usuario` (`id_usuario`,`tipo`),
  CONSTRAINT `tokens_email_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fila_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `para` varchar(190) NOT NULL,
  `assunto` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'geral',
  `status` enum('pendente','processando','enviado','falhou') NOT NULL DEFAULT 'pendente',
  `tentativas` tinyint(4) NOT NULL DEFAULT 0,
  `ultimo_erro` varchar(255) DEFAULT NULL,
  `proximo_envio` datetime NOT NULL,
  `valido_ate` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL,
  `enviado_em` datetime DEFAULT NULL,
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fila_pendentes` (`status`,`proximo_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_envios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `para` varchar(190) NOT NULL,
  `assunto` varchar(255) NOT NULL DEFAULT '',
  `tipo` varchar(30) NOT NULL DEFAULT 'geral',
  `origem` varchar(12) NOT NULL DEFAULT 'direto',
  `via` varchar(10) NOT NULL DEFAULT 'smtp',
  `de_email` varchar(190) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL,
  `resposta` varchar(500) DEFAULT NULL,
  `tentativa` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `fila_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_envios_data` (`criado_em`),
  KEY `idx_email_envios_status` (`status`,`criado_em`),
  KEY `idx_email_envios_para` (`para`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `limites_taxa` (
  `chave` varchar(120) NOT NULL,
  `janela_inicio` datetime NOT NULL,
  `contagem` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`chave`),
  KEY `idx_limites_janela` (`janela_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tentativas_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identificador` varchar(150) NOT NULL,
  `tentativas` int(11) DEFAULT 0,
  `bloqueado_ate` datetime DEFAULT NULL,
  `atualizado_em` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_identificador` (`identificador`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campanhas_ranking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `titulo` varchar(100) NOT NULL,
  `subtitulo` varchar(255) DEFAULT NULL,
  `imagem_banner` varchar(255) DEFAULT NULL,
  `data_inicio` datetime NOT NULL,
  `data_fim` datetime NOT NULL,
  `ativa` tinyint(1) DEFAULT 1,
  `finalizada_em` datetime DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campanhas_ranking_premios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campanha_id` int(11) NOT NULL,
  `posicao` int(11) NOT NULL,
  `titulo` varchar(100) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unico_campanha_posicao` (`campanha_id`,`posicao`),
  CONSTRAINT `campanhas_ranking_premios_ibfk_1` FOREIGN KEY (`campanha_id`) REFERENCES `campanhas_ranking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ranking_cache` (
  `campanha_id` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `faturamento` decimal(12,2) NOT NULL DEFAULT 0.00,
  `posicao` int(11) NOT NULL,
  `atualizado_em` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`campanha_id`,`id_usuario`),
  KEY `idx_ranking_posicao` (`campanha_id`,`posicao`),
  CONSTRAINT `ranking_cache_ibfk_1` FOREIGN KEY (`campanha_id`) REFERENCES `campanhas_ranking` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `metricas_horarias_admin` (
  `data` date NOT NULL,
  `hora` tinyint(3) unsigned NOT NULL,
  `faturamento` decimal(14,2) NOT NULL DEFAULT 0.00,
  `comissao` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantidade` int(11) NOT NULL DEFAULT 0,
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`data`,`hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `metricas_horarias_usuario` (
  `id_usuario` int(11) NOT NULL,
  `data` date NOT NULL,
  `hora` tinyint(3) unsigned NOT NULL,
  `valor_pago` decimal(14,2) NOT NULL DEFAULT 0.00,
  `qtd_paga` int(11) NOT NULL DEFAULT 0,
  `qtd_gerada` int(11) NOT NULL DEFAULT 0,
  `qtd_leads` int(11) NOT NULL DEFAULT 0,
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_usuario`,`data`,`hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirecionadores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `titulo` varchar(120) NOT NULL,
  `slug` varchar(40) NOT NULL,
  `dominio` varchar(190) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `plataforma` varchar(20) NOT NULL DEFAULT 'meta',
  `protecao` varchar(20) NOT NULL DEFAULT 'nenhuma',
  `modo` varchar(20) NOT NULL DEFAULT 'aleatorio',
  `cliques` int(11) NOT NULL DEFAULT 0,
  `starts` int(11) NOT NULL DEFAULT 0,
  `leads` int(11) NOT NULL DEFAULT 0,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slug` (`slug`),
  KEY `idx_redirecionadores_usuario` (`id_usuario`),
  CONSTRAINT `redirecionadores_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirecionador_destinos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `redirecionador_id` int(11) NOT NULL,
  `bot_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_destino` (`redirecionador_id`,`bot_id`),
  KEY `bot_id` (`bot_id`),
  CONSTRAINT `redirecionador_destinos_ibfk_1` FOREIGN KEY (`redirecionador_id`) REFERENCES `redirecionadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `redirecionador_destinos_ibfk_2` FOREIGN KEY (`bot_id`) REFERENCES `bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirecionador_cliques_dia` (
  `redirecionador_id` int(11) NOT NULL,
  `dia` date NOT NULL,
  `campanha` varchar(100) NOT NULL DEFAULT '',
  `cliques` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`redirecionador_id`,`dia`,`campanha`),
  CONSTRAINT `redirecionador_cliques_dia_ibfk_1` FOREIGN KEY (`redirecionador_id`) REFERENCES `redirecionadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Linha que a tela Gateways espera (o instalador também cria): gateway OmegaPayments, desativado até o admin ligar.
INSERT INTO gateways (nome, titulo, ativo)
SELECT 'omegapayments', 'OmegaPayments (Pix)', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM gateways WHERE nome = 'omegapayments');
