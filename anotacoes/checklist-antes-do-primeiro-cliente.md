# Checklist antes de entrar cliente real

Aberto em 2026-09-18 numa revisão pré-lançamento. Marcar conforme for resolvendo.

## Feito nesta revisão

- [x] **Erro cru do banco vazando pro usuário final.** O toast vermelho que apareceu no celular do
      Caio mostrava `u214219698_telegram`@`localhost` e o nome da tabela. Vinha de `api.php` (dois
      `catch`) e de `ajax/salvar_splits_usuario.php`, que mandavam `$e->getMessage()` direto pro
      navegador. Agora o detalhe vai pro `error_log` do servidor (com ação e usuário, pra não
      perder o rastro) e o usuário recebe mensagem genérica.
- [x] **Páginas de teste do gateway apagadas.** `teste_gateway_infopago.php` e
      `teste_gateway_infopago_recorrente.php` continuavam publicadas: qualquer usuário logado
      conseguia abrir e **gerar cobrança PIX real** na conta compartilhada do admin, sem CSRF nem
      rate limit. Já estava mapeado na `varredura-09` e nunca corrigido. Apagados (o próprio texto
      dentro deles pedia isso); ninguém linkava pra elas.

## Pendente — precisa de ação sua

- [ ] **Definir a marca do painel** em Administração → Identidade Visual (nome, logo, favicon).
      Hoje está no padrão genérico "Painel de Bots" — a marca fixa "Coyote" saiu do código.
      Ver `white-label-marca.md`.

- [ ] **Teste ponta a ponta com bot e pagamento reais.** O mais importante da lista. Tudo que foi
      validado até aqui é código, banco e HTTP. Ninguém nunca rodou o caminho completo: `/start` →
      escolher plano → pagar PIX de verdade → receber o link → entrar no grupo → acesso vencer →
      receber aviso → ser removido. É o produto inteiro, e é o único teste que prova que funciona.
- [ ] **Trocar a senha `123456`** de todas as contas (intencional no ambiente de teste).
- [ ] **Trocar `CHAVE_SECRETA_CRON`** no `config.php` do servidor (ver `varredura-10`).
- [ ] **Cadastrar `cron_retry_split.php`** no crontab (ver `crontab-hostinger-checklist.md`).
- [ ] **Confirmar backup do banco** no hPanel e **testar uma restauração** — depois do episódio da
      cota estourada, não dá pra assumir que existe.
- [ ] **Decidir sobre estorno/chargeback (PIX MED).** Hoje não há tratamento nenhum: se o cliente
      pedir devolução, ele continua no grupo e o split já saiu. É decisão de negócio antes de código.
- [ ] **Decidir sobre a conta InfoPago única** compartilhada (ver `criticas/conta-infopago-unica-compartilhada.md`).
- [ ] **LGPD / retenção de dados** — precisa de olhada jurídica, não técnica.
- [ ] **Apagar o dataset sintético** do bot "Carlos 3 anos" se não for mais útil (libera ~1 GB, ou
      ~350 mil vendas de fôlego na cota).

## Pendente — correções de código que eu posso fazer

- [ ] **Rotação de logs.** Hoje 1,2 MB, mas `cron_verificar_acessos.log` cresce todo minuto sem
      teto e guarda TXID e valor. Em volume real vira gigabytes.
- [ ] **Segredo de gateway em claro no formulário** (`gateways.php` ecoa `client_secret`/`chave_pix`
      com valor real — ver `varredura-10`).
- [ ] **Timeout de cURL** em `enviarEventoFacebook`/`enviarEventoTikTok` — **já feito** numa rodada
      anterior desta sessão; confirmar que a nota da varredura 09 foi atualizada.
- [ ] As 4 mudanças do motor (ver `quatro-mudancas-pro-motor-escalar.md`) — só viram gargalo acima
      de ~30 mil vendas/dia, não bloqueiam o lançamento.
