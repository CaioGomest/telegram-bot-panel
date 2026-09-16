# Telegram Bot Panel

Painel administrativo em PHP para gerenciar bots de venda no Telegram: fluxos de atendimento, gateways de pagamento, splits entre usuários, remarketing, rastreamento de leads e relatórios financeiros.

## Stack

- **Backend:** PHP (procedural, `declare(strict_types=1)` na maioria dos arquivos)
- **Banco de dados:** MySQL/MariaDB via PDO
- **Frontend:** HTML + CSS + JS puro (jQuery em partes específicas, ex. editor de fluxograma)
- **Hospedagem alvo:** Hostinger (hospedagem compartilhada/VPS)
- **Integrações:** Telegram Bot API, gateway de pagamento InfoPago (PIX), Facebook Pixel/CAPI, TikTok Pixel, UTMify

## Estrutura de pastas

```
/
├── config.php              # Credenciais do banco (não versionar valores reais)
├── conexao.php             # Conexão PDO com o banco
├── index.php                # Dashboard / entrada do painel
├── login.php / cadastro.php # Autenticação de usuários
├── bot.php / bots.php       # Cadastro e edição de bots
├── fluxo.php / fluxos.php   # Editor de fluxo de conversa do bot
├── gateways.php             # Configuração de gateways de pagamento
├── usuarios.php             # Gestão de usuários (admin)
├── leads.php                # Captura e listagem de leads
├── links_rastreamento.php   # Links de rastreamento de campanhas
├── remarketing.php          # Disparos de remarketing
├── traqueamento.php         # Tracking de eventos/conversões
├── admin_dashboard.php      # Dashboard administrativo
├── admin_transacoes.php     # Relatório de transações (admin)
├── webhook.php               # Webhook principal do Telegram
├── webhook_infopago.php      # Webhook do gateway InfoPago
├── cron/                       # Rotinas agendadas (verificação de PIX, renovação, avisos, remarketing, acessos)
├── api.php                    # Endpoints de API interna
├── instalacao.php             # Instalador inicial do sistema
├── atualiza_banco.php         # Script de migração/atualização de schema
├── funcoes/                   # Funções auxiliares por domínio (usuario, gateways, infopago, tracking, etc.)
├── ajax/                      # Endpoints AJAX (CRUD de usuários e splits)
├── assets/                    # CSS/JS do painel
├── certificados/              # Certificados usados por integrações (gitignored)
├── logs/                      # Logs da aplicação (gitignored)
└── uploads/                   # Uploads de usuários (gitignored)
```

## Funcionalidades principais

- Gestão de múltiplos bots do Telegram por usuário
- Editor visual de fluxo de conversa (fluxograma)
- Processamento de pagamentos via PIX (InfoPago), com split entre usuários
- Cron jobs para verificação de pagamento, renovação, avisos de vencimento e remarketing
- Rastreamento de leads e links de campanha (Facebook, TikTok, UTMify)
- Painel admin com relatório de transações e status de split

## Instalação (dev local)

1. Configure `config.php` com as credenciais do banco local.
2. Rode `instalacao.php` para criar o schema inicial, ou `seeds/popular_banco.php` / `admin/atualiza_banco.php` conforme o caso.
3. Sirva a pasta com PHP embutido ou Apache/Nginx apontando para a raiz do projeto.

## Hospedagem

Deploy alvo: **Hostinger**. Estrutura pensada para hospedagem compartilhada/VPS com PHP + MySQL, sem dependência de build step (sem framework, sem bundler).

## Status do projeto

🔧 **Fase atual: limpeza e organização do código.** Objetivo é reduzir complexidade, aplicar responsabilidade única nas funções, remover código morto/duplicado e preparar a base para escalar — sem alterar layout/UI (isso fica para uma etapa futura, com o Claude Code). Ver `CLAUDE.md` para as diretrizes de trabalho.
