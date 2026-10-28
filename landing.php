<?php
declare(strict_types=1);

// ESCONDIDA até o cliente aprovar (20/10/2026): enquanto for false, /landing responde 404 igual a qualquer página que não existe (nem logado vê).
// Para mostrar de novo: trocar para true e publicar. Se ela virar a home (/), ver PENDENCIAS.md (p).
const LANDING_PUBLICA = false;
if (!LANDING_PUBLICA) {
    require __DIR__ . '/404.php';
    exit;
}

/**
 * Landing page pública (/landing). Vem do desenho "Coyote Bot Landing" (artefato de design): página escura com laranja, mascote animado no topo,
 * fluxo e vendas "ao vivo" de exemplo, 3 passos, abas de recursos, bloco mobile, segurança, números e chamada final.
 * Sem banco além do nome/ícone do sistema (white-label); os botões levam ao cadastro (ou ao painel, se a pessoa já estiver logada).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';

$nome = nomeSistema();
$nome_maiusculo = mb_strtoupper($nome, 'UTF-8');
$logado = usuarioLogado();
$destino_cta = $logado ? (ehAdmin() ? 'admin/dashboard' : 'index') : 'cadastro';
$rotulo_cta = $logado ? 'Abrir painel' : 'Criar conta';
$mascote = 'assets/img/coyote-animado.webp';
$mascote_v = $mascote . '?v=' . (int) @filemtime(__DIR__ . '/' . $mascote);
$titulo = $nome . ' — Venda no Telegram no automático';
$descricao = 'Monte o funil do /start ao Pix pago com o modo Guiado, receba com split automático e venda mais com upsell, order bump e ranking. Painel seguro que funciona no celular.';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($titulo); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($descricao); ?>">
    <meta name="theme-color" content="#0c0d11">
    <meta name="color-scheme" content="dark">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($titulo); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($descricao); ?>">
<?php if (function_exists('faviconSistema')): ?>
    <link rel="icon" href="<?php echo htmlspecialchars(urlMarca(faviconSistema())); ?>">
<?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Figtree:wght@400;500;600;700&display=swap">
    <link rel="preload" as="image" href="<?php echo htmlspecialchars($mascote_v); ?>">
    <style>
        :root { --bg: #0c0d11; --card: #14161d; --ink: #f4f2ee; --mut: #9aa0ab; --line: rgba(255, 255, 255, .09); --deep: #ffa163; --mint: rgba(255, 106, 26, .14); --soft: #1b1e27; --accent: #ff6a1a; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 400 16px/1.55 'Figtree', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        h1, h2, h3, p { margin: 0; }
        h1, h2, h3 { font-family: 'Sora', system-ui, sans-serif; letter-spacing: -.03em; text-wrap: balance; }
        a { color: inherit; text-decoration: none; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
        .lp { position: relative; overflow: hidden; }
        .lp-glow { position: absolute; top: -200px; right: -160px; width: 620px; height: 620px; border-radius: 50%; background: radial-gradient(rgba(255, 106, 26, .22), transparent 68%); pointer-events: none; }
        .w { position: relative; max-width: 1180px; margin: 0 auto; padding-inline: 20px; }
        .ic { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }

        /* botões e etiquetas */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 50px; padding: 0 26px; border-radius: 14px; font: 700 16px 'Figtree', sans-serif; border: 1px solid var(--line); background: var(--card); color: var(--ink); cursor: pointer; transition: transform .2s, box-shadow .2s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -12px rgba(0, 0, 0, .8); }
        .btn.pri { background: var(--accent); color: #1a0a00; border-color: transparent; box-shadow: 0 12px 26px -12px var(--accent); }
        .tag { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 99px; background: var(--mint); color: var(--deep); font-size: 13px; font-weight: 700; width: fit-content; }
        .live { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); animation: lp-pulse 1.6s infinite; }
        .titulo-sec { max-width: 700px; margin-bottom: 40px; display: flex; flex-direction: column; align-items: flex-start; gap: 16px; }
        .titulo-sec h2, .h2 { font-size: clamp(32px, 4.2vw, 52px); line-height: 1.05; font-weight: 800; }
        .sec { position: relative; padding-block: 30px 90px; }

        /* topo */
        .nav { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-top: 22px; padding: 12px 14px 12px 22px; border-radius: 20px; background: rgba(20, 22, 29, .8); border: 1px solid var(--line); backdrop-filter: blur(10px); }
        .nav .marca { display: flex; align-items: center; gap: 10px; font: 800 20px 'Sora', sans-serif; }
        .nav .marca img { width: 54px; height: 54px; margin-block: -8px; object-fit: contain; filter: drop-shadow(0 0 12px rgba(255, 106, 26, .45)); }
        .nav .lk { display: flex; gap: 30px; font-weight: 600; color: var(--mut); }
        .nav .lk a:hover { color: var(--ink); }
        .nav .acoes { display: flex; align-items: center; gap: 8px; }
        .nav .entrar { min-height: 44px; display: inline-flex; align-items: center; padding: 0 14px; font-weight: 600; color: var(--mut); border-radius: 12px; }
        .nav .entrar:hover { color: var(--ink); }
        .nav .btn { min-height: 44px; padding: 0 20px; border-radius: 12px; }

        /* herói */
        .hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); gap: 48px; align-items: center; padding-block: 72px 90px; }
        .hero .texto { display: flex; flex-direction: column; gap: 26px; align-items: flex-start; }
        .hero h1 { font-size: clamp(40px, 5.4vw, 70px); line-height: 1.02; font-weight: 800; }
        .hero p { font-size: 19px; color: var(--mut); max-width: 50ch; }
        .hero .botoes { display: flex; flex-wrap: wrap; gap: 12px; }
        .hl { position: relative; display: inline-block; white-space: nowrap; z-index: 0; }
        .hl::after { content: ""; position: absolute; left: -4px; right: -4px; bottom: .06em; height: .34em; background: rgba(255, 106, 26, .38); z-index: -1; border-radius: 6px; transform-origin: left; animation: lp-underline 1s .7s cubic-bezier(.2, .7, .2, 1) both; }

        /* mascote animado */
        .stage { position: relative; width: min(500px, 100%); aspect-ratio: 1; margin: 0 auto; }
        .stage .logo { width: 100%; height: 100%; display: block; object-fit: contain; filter: drop-shadow(0 0 18px rgba(255, 106, 26, .28)); animation: lp-glow 3.2s ease-in-out infinite; }
        .stage .reveal { position: absolute; inset: 0; animation: lp-reveal 1.5s cubic-bezier(.7, 0, .2, 1) both .2s; }
        .stage .aura { position: absolute; left: 50%; top: 46%; width: 100%; aspect-ratio: 1; translate: -50% -50%; border-radius: 50%; background: radial-gradient(circle, rgba(255, 106, 26, .3) 0%, rgba(255, 106, 26, .08) 42%, transparent 66%); opacity: 0; animation: lp-fadein .8s ease forwards 1.7s, lp-breathe 3.2s ease-in-out infinite 1.7s; }
        .stage .hud { position: absolute; left: 50%; top: 46%; translate: -50% -50%; width: 98%; aspect-ratio: 1; }
        .stage .hud svg { width: 100%; height: 100%; overflow: visible; }
        .spin { transform-origin: 50px 50px; animation: lp-spin 18s linear infinite; }
        .spin.rev { animation-duration: 28s; animation-direction: reverse; }
        .late { opacity: 0; animation: lp-fadein .8s ease forwards 1.7s; }
        .eye { position: absolute; top: 39.2%; width: 6.5%; aspect-ratio: 1; translate: -50% -50%; border-radius: 50%; mix-blend-mode: screen; background: radial-gradient(circle, rgba(255, 220, 140, .95) 0%, rgba(255, 140, 30, .55) 28%, transparent 65%); opacity: 0; animation: lp-fadein .8s ease forwards 1.7s, lp-eye 4s ease-in-out infinite 2.5s; }
        .eye.l { left: 41.5%; } .eye.r { left: 58.5%; }
        .ping { position: absolute; top: 38.3%; width: 11%; aspect-ratio: 1; translate: -50% -50%; pointer-events: none; }
        .ping.l { left: 25.4%; } .ping.r { left: 74.8%; }
        .ping i { position: absolute; inset: 0; border-radius: 50%; border: 2px solid var(--accent); opacity: 0; animation: lp-ring 2.4s ease-out infinite 2s; }
        .ping i:nth-child(2) { animation-delay: 2.8s; } .ping i:nth-child(3) { animation-delay: 3.6s; }
        .ping .core { position: absolute; inset: 16%; border-radius: 50%; mix-blend-mode: screen; background: radial-gradient(circle, rgba(255, 170, 80, .55), transparent 70%); animation: lp-core 2.4s ease-in-out infinite 2s; }
        .shine { position: absolute; inset: 0; clip-path: inset(64% 6% 9% 6%); mix-blend-mode: overlay; -webkit-mask: var(--lp-mascote) center / 100% 100% no-repeat; mask: var(--lp-mascote) center / 100% 100% no-repeat; overflow: hidden; }
        .shine::before { content: ""; position: absolute; top: 0; bottom: 0; left: -40%; width: 28%; background: linear-gradient(100deg, transparent, rgba(255, 255, 255, .95) 50%, transparent); animation: lp-shine 4.2s cubic-bezier(.6, 0, .3, 1) infinite 2.6s; }
        .stage .scan { position: absolute; left: -4%; right: -4%; height: 3px; top: 0; background: #ffb347; box-shadow: 0 0 18px 6px rgba(255, 106, 26, .7); opacity: 0; animation: lp-scan 1.5s cubic-bezier(.7, 0, .2, 1) both .2s; }
        .stage .flash { position: absolute; inset: -10%; border-radius: 50%; background: radial-gradient(circle, rgba(255, 190, 110, .85), transparent 60%); opacity: 0; mix-blend-mode: screen; animation: lp-flash 1s ease-out both 1.6s; }

        /* janelas (editor + vendas ao vivo) */
        .duo { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap: 24px; align-items: stretch; }
        .win { border-radius: 22px; background: var(--card); border: 1px solid var(--line); box-shadow: 0 50px 90px -40px rgba(0, 0, 0, .9); overflow: hidden; }
        .win .bar { display: flex; gap: 7px; padding: 14px 18px; border-bottom: 1px solid var(--line); background: var(--soft); }
        .win .bar i { width: 11px; height: 11px; border-radius: 50%; background: #3a3e49; }
        .win .corpo { padding: 22px; display: flex; flex-direction: column; }
        .fn { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--line); background: var(--soft); animation: lp-nodeon 9.6s infinite; }
        .fn b { font-size: 15px; } .fn .sub { font-size: 12.5px; color: var(--mut); }
        .fc { width: 2px; height: 20px; margin-left: 31px; background: repeating-linear-gradient(180deg, rgba(255, 255, 255, .28) 0 5px, transparent 5px 10px); animation: lp-dashv 1s linear infinite; }
        .av { width: 36px; height: 36px; border-radius: 11px; background: var(--mint); color: var(--deep); display: grid; place-items: center; flex: none; }
        .vendas-topo { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
        .vendas-topo .rot { font-size: 13px; color: var(--mut); font-weight: 600; }
        .vendas-topo .num { font: 800 40px 'Sora', sans-serif; letter-spacing: -.04em; }
        .cols { display: flex; align-items: flex-end; gap: 8px; height: 96px; margin-block: 18px; }
        .col { flex: 1; border-radius: 8px 8px 3px 3px; background: linear-gradient(180deg, var(--accent), rgba(255, 106, 26, .22)); transform-origin: bottom; animation: lp-grow 1.2s cubic-bezier(.2, .7, .2, 1) both; }
        .feed { display: flex; flex-direction: column; gap: 8px; }
        .row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; background: var(--soft); animation: lp-slide .5s cubic-bezier(.2, .7, .2, 1) both; }
        .row .cx { flex: 1; min-width: 0; } .row .cx b { font-size: 14.5px; } .row .cx div { font-size: 12.5px; color: var(--mut); } .row .quando { font-size: 12px; color: var(--mut); }

        /* passos */
        .passos { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 18px; }
        .passo { border-radius: 24px; background: var(--card); border: 1px solid var(--line); padding: 30px; transition: transform .3s, box-shadow .3s; }
        .passo:hover { transform: translateY(-6px); box-shadow: 0 30px 50px -30px rgba(0, 0, 0, .9); }
        .passo .n { width: 46px; height: 46px; border-radius: 50%; background: var(--accent); color: #1a0a00; display: grid; place-items: center; font: 700 18px 'Sora', sans-serif; margin-bottom: 18px; }
        .passo h3 { font-size: 22px; margin-bottom: 8px; } .passo p { color: var(--mut); }

        /* abas de recursos */
        .tabs { display: flex; flex-wrap: wrap; gap: 8px; padding: 6px; border-radius: 18px; background: var(--soft); width: fit-content; max-width: 100%; }
        .tab { min-height: 44px; padding: 0 20px; border-radius: 13px; border: 0; background: transparent; font: 700 15px 'Figtree', sans-serif; color: var(--mut); cursor: pointer; transition: background .2s, color .2s; }
        .tab:hover { color: var(--ink); }
        .tab.on { background: var(--accent); color: #1a0a00; }
        .painel-aba { margin-top: 22px; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 24px; align-items: center; border-radius: 28px; background: var(--card); border: 1px solid var(--line); padding: clamp(24px, 4vw, 48px); }
        .painel-aba.anima { animation: lp-rise .5s both; }
        .painel-aba .texto { display: flex; flex-direction: column; gap: 14px; }
        .painel-aba h3 { font-size: clamp(26px, 3vw, 36px); }
        .painel-aba p { color: var(--mut); font-size: 17px; max-width: 48ch; }
        .painel-aba .tags { display: flex; flex-wrap: wrap; gap: 8px; }
        .painel-aba .arte { min-height: 220px; border-radius: 22px; background: linear-gradient(145deg, rgba(255, 106, 26, .16), var(--soft)); display: grid; place-items: center; color: var(--accent); }
        .painel-aba .arte svg { width: 96px; height: 96px; stroke: currentColor; fill: none; stroke-width: 1.2; stroke-linecap: round; stroke-linejoin: round; animation: lp-floaty 5s ease-in-out infinite; }

        /* mobile */
        .mobile-grade { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 56px; align-items: center; }
        .phone { width: 280px; max-width: 100%; border-radius: 36px; background: #05060a; border: 1px solid var(--line); padding: 12px; box-shadow: 0 40px 70px -30px rgba(0, 0, 0, .9); animation: lp-floaty 7s ease-in-out infinite; margin: 0 auto; }
        .phone .scr { border-radius: 26px; background: var(--card); min-height: 460px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
        .phone .scr .topo { display: flex; justify-content: space-between; align-items: center; }
        .phone .scr .topo b { font-family: 'Sora', sans-serif; }
        .phone .scr .tag { padding: 4px 10px; font-size: 11px; }
        .phone .row b { font-size: 14px; } .phone .row div div { font-size: 12px; }
        .mobile-texto { display: flex; flex-direction: column; gap: 18px; align-items: flex-start; }
        .mobile-texto p { color: var(--mut); font-size: 17px; max-width: 50ch; }
        .antes-agora { display: flex; flex-direction: column; gap: 10px; }
        .antes-agora div { display: flex; gap: 12px; align-items: center; }
        .antes-agora .antes { color: var(--mut); } .antes-agora .agora { font-weight: 700; } .antes-agora .agora svg { color: var(--accent); }

        /* segurança */
        .seguranca { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(250px, 100%), 1fr)); gap: 16px; }
        .cartao { border-radius: 20px; border: 1px solid var(--line); background: var(--card); padding: 24px; transition: transform .3s, border-color .3s; }
        .cartao:hover { transform: translateY(-4px); border-color: var(--accent); }
        .cartao .ib { width: 44px; height: 44px; border-radius: 13px; background: var(--mint); color: var(--deep); display: grid; place-items: center; margin-bottom: 14px; }
        .cartao h3 { font-size: 18px; margin-bottom: 6px; } .cartao p { color: var(--mut); font-size: 15px; }

        /* números */
        .faixa { background: linear-gradient(135deg, rgba(255, 106, 26, .14), transparent 60%), #14161d; border-block: 1px solid var(--line); position: relative; overflow: hidden; }
        .faixa .anel { position: absolute; border-radius: 50%; border: 1px solid rgba(255, 106, 26, .2); animation: lp-spin 40s linear infinite; pointer-events: none; }
        .faixa .w { padding-block: 80px; }
        .faixa h2 { font-size: clamp(30px, 4vw, 48px); line-height: 1.05; font-weight: 800; max-width: 20ch; margin-bottom: 44px; }
        .numeros { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 32px; }
        .numeros .big { font: 800 clamp(40px, 5vw, 64px) / 1 'Sora', sans-serif; letter-spacing: -.04em; color: var(--accent); }
        .numeros p { color: var(--mut); margin-top: 8px; }

        /* chamada final + rodapé */
        .cta-bloco { text-align: center; border-radius: 36px; background: radial-gradient(ellipse at 50% 0%, rgba(255, 106, 26, .18), transparent 60%), var(--card); border: 1px solid var(--line); padding: clamp(40px, 7vw, 88px) 24px; box-shadow: 0 40px 80px -50px rgba(255, 106, 26, .35); }
        .cta-bloco img { width: min(220px, 60%); aspect-ratio: 1; object-fit: contain; margin: -24px auto 6px; display: block; filter: drop-shadow(0 20px 40px rgba(255, 106, 26, .4)); animation: lp-floaty 6s ease-in-out infinite; }
        .cta-bloco h2 { font-size: clamp(32px, 4.8vw, 60px); line-height: 1.04; font-weight: 800; max-width: 18ch; margin: 0 auto; }
        .cta-bloco p { color: var(--mut); font-size: 18px; margin: 16px auto 30px; max-width: 46ch; }
        .cta-bloco .botoes { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .cta-bloco .btn { min-height: 56px; padding: 0 34px; font-size: 17px; }
        .rodape { margin-top: 72px; padding-top: 48px; border-top: 1px solid var(--line); }
        .rodape .cols-rodape { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 40px 32px; }
        .rodape .marca { display: flex; align-items: center; gap: 10px; font: 800 20px 'Sora', sans-serif; }
        .rodape .marca img { width: 56px; height: 56px; object-fit: contain; filter: drop-shadow(0 0 12px rgba(255, 106, 26, .4)); }
        .rodape .sobre { display: flex; flex-direction: column; gap: 14px; max-width: 340px; }
        .rodape .sobre p { color: var(--mut); font-size: 15px; }
        .rodape .col-links { display: flex; flex-direction: column; gap: 4px; }
        .rodape .col-links b { font: 700 13px 'Figtree', sans-serif; letter-spacing: .12em; text-transform: uppercase; color: var(--mut); margin-bottom: 8px; }
        .rodape .col-links a, .rodape .volta { color: var(--mut); font-size: 15px; min-height: 28px; display: inline-flex; align-items: center; transition: color .2s; }
        .rodape .col-links a:hover, .rodape .volta:hover { color: var(--accent); }
        .rodape .fim { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 48px; padding-block: 24px; border-top: 1px solid var(--line); color: var(--mut); font-size: 14px; }
        .rodape .volta { gap: 8px; } .rodape .volta svg { width: 18px; height: 18px; }

        @keyframes lp-nodeon { 0%, 15% { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(255, 106, 26, .14), 0 10px 30px -12px var(--accent); } 17%, 100% { border-color: var(--line); box-shadow: none; } }
        @keyframes lp-dashv { to { background-position: 0 10px; } }
        @keyframes lp-glow { 0%, 100% { filter: drop-shadow(0 0 14px rgba(255, 106, 26, .2)); } 50% { filter: drop-shadow(0 0 30px rgba(255, 106, 26, .5)); } }
        @keyframes lp-breathe { 0%, 100% { opacity: .7; scale: .96; } 50% { opacity: 1; scale: 1.04; } }
        @keyframes lp-eye { 0%, 100% { opacity: .55; scale: .9; } 45% { opacity: 1; scale: 1.15; } 50% { opacity: .25; scale: .8; } 55% { opacity: 1; scale: 1.1; } }
        @keyframes lp-ring { 0% { opacity: .9; scale: .7; } 100% { opacity: 0; scale: 2.1; } }
        @keyframes lp-core { 0%, 100% { opacity: .3; } 20% { opacity: 1; } }
        @keyframes lp-shine { 0% { left: -40%; } 55%, 100% { left: 120%; } }
        @keyframes lp-reveal { from { clip-path: inset(0 0 100% 0); filter: brightness(2.2) saturate(.4); } to { clip-path: inset(0 0 0 0); filter: none; } }
        @keyframes lp-scan { 0% { top: 0; opacity: 1; } 92% { top: 100%; opacity: 1; } 100% { top: 100%; opacity: 0; } }
        @keyframes lp-flash { 0% { opacity: 0; scale: .6; } 25% { opacity: 1; } 100% { opacity: 0; scale: 1.3; } }
        @keyframes lp-fadein { to { opacity: 1; } }
        @keyframes lp-rise { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: none; } }
        @keyframes lp-slide { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: none; } }
        @keyframes lp-underline { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        @keyframes lp-pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 106, 26, .55); } 70% { box-shadow: 0 0 0 9px rgba(255, 106, 26, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 106, 26, 0); } }
        @keyframes lp-grow { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        @keyframes lp-spin { to { transform: rotate(360deg); } }
        @keyframes lp-floaty { 0%, 100% { translate: 0 0; } 50% { translate: 0 -12px; } }
        .rise { animation: lp-rise .8s cubic-bezier(.2, .7, .2, 1) both; }
        .d1 { animation-delay: .1s; } .d2 { animation-delay: .22s; } .d3 { animation-delay: .34s; }

        @media (max-width: 860px) { .hero { grid-template-columns: 1fr; padding-block: 48px 64px; } .nav .lk { display: none; } }
        @media (max-width: 640px) { .tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; } .tab { padding: 0 10px; text-align: center; } }
        @media (max-width: 520px) { .nav { padding: 10px 12px 10px 16px; } .nav .marca span { font-size: 17px; } .nav .entrar { display: none; } .hero .botoes .btn { width: 100%; } }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .late, .eye, .stage .aura { opacity: 1; }
            .stage .scan, .stage .flash { display: none; }
        }
    </style>
</head>
<body>
<div class="lp" id="top">
    <div class="lp-glow" aria-hidden="true"></div>

    <header class="w">
        <nav class="nav" aria-label="Principal">
            <a class="marca" href="#top"><img src="<?php echo htmlspecialchars($mascote_v); ?>" width="54" height="54" alt=""><span><?php echo htmlspecialchars($nome_maiusculo); ?></span></a>
            <div class="lk">
                <a href="#editor">Editor</a><a href="#como">Como funciona</a><a href="#recursos">Recursos</a><a href="#mobile">Mobile</a><a href="#seguranca">Segurança</a>
            </div>
            <div class="acoes">
                <?php if (!$logado): ?><a class="entrar" href="login">Entrar</a><?php endif; ?>
                <a class="btn pri" href="<?php echo $destino_cta; ?>"><?php echo $rotulo_cta; ?></a>
            </div>
        </nav>
    </header>

    <main>
        <section class="w hero" aria-labelledby="titulo-principal">
            <div class="texto">
                <span class="tag rise"><span class="live"></span>Plataforma atualizada</span>
                <h1 id="titulo-principal" class="rise d1">Venda no Telegram no <span class="hl">automático,</span> do /start ao Pix pago.</h1>
                <p class="rise d2">Monte o funil com o modo Guiado, receba por Pix com split automático e use upsell, order bump e ranking para vender mais. Tudo num painel seguro que funciona no celular.</p>
                <div class="botoes rise d3">
                    <a class="btn pri" href="<?php echo $destino_cta; ?>"><?php echo $logado ? 'Abrir meu painel' : 'Criar minha conta'; ?><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
                    <a class="btn" href="#como">Ver como funciona</a>
                </div>
            </div>
            <div class="stage rise d2" style="--lp-mascote: url('<?php echo htmlspecialchars($mascote_v); ?>')">
                <div class="aura late" aria-hidden="true"></div>
                <div class="hud late" aria-hidden="true"><svg viewBox="0 0 100 100">
                    <g class="spin"><circle cx="50" cy="50" r="47" fill="none" stroke="#ff6a1a" stroke-opacity=".55" stroke-width=".4" stroke-dasharray="2 3 14 3"/></g>
                    <g class="spin rev"><circle cx="50" cy="50" r="43" fill="none" stroke="#ff6a1a" stroke-opacity=".3" stroke-width=".3" stroke-dasharray="1 2"/></g>
                </svg></div>
                <div class="reveal">
                    <img class="logo" src="<?php echo htmlspecialchars($mascote_v); ?>" width="900" height="900" alt="Mascote do <?php echo htmlspecialchars($nome); ?>">
                    <i class="eye l"></i><i class="eye r"></i>
                    <span class="ping l late" aria-hidden="true"><i></i><i></i><i></i><b class="core"></b></span>
                    <span class="ping r late" aria-hidden="true"><i></i><i></i><i></i><b class="core"></b></span>
                    <div class="shine" aria-hidden="true"></div>
                </div>
                <div class="scan" aria-hidden="true"></div><div class="flash" aria-hidden="true"></div>
            </div>
        </section>

        <section id="editor" class="w sec" style="padding-top: 10px" aria-labelledby="t-editor">
            <div class="titulo-sec"><span class="tag">Editor de fluxo</span><h2 id="t-editor">Funil montado em blocos, vendendo em tempo real.</h2></div>
            <div class="duo">
                <div class="win">
                    <div class="bar" aria-hidden="true"><i></i><i></i><i></i></div>
                    <div class="corpo">
                        <div class="fn" style="animation-delay: 0s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span><div><b>/start</b><div class="sub">Cliente chama o bot</div></div></div><div class="fc" aria-hidden="true"></div>
                        <div class="fn" style="animation-delay: 1.6s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a8 8 0 01-12 7l-5 1 1-5a8 8 0 1116-3z"/></svg></span><div><b>Boas-vindas</b><div class="sub">Mensagem com mídia</div></div></div><div class="fc" aria-hidden="true"></div>
                        <div class="fn" style="animation-delay: 3.2s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18"/></svg></span><div><b>Planos</b><div class="sub">Cliente escolhe o plano</div></div></div><div class="fc" aria-hidden="true"></div>
                        <div class="fn" style="animation-delay: 4.8s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span><div><b>Order bump</b><div class="sub">Extra no pagamento</div></div></div><div class="fc" aria-hidden="true"></div>
                        <div class="fn" style="animation-delay: 6.4s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4zM4 11h16"/></svg></span><div><b>Pix</b><div class="sub">Gerado e confirmado</div></div></div><div class="fc" aria-hidden="true"></div>
                        <div class="fn" style="animation-delay: 8s"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span><div><b>Acesso liberado</b><div class="sub">Entrega automática</div></div></div>
                    </div>
                </div>
                <div class="win rise d2">
                    <div class="bar" aria-hidden="true"><i></i><i></i><i></i></div>
                    <div class="corpo">
                        <div class="vendas-topo">
                            <div><div class="rot">Vendas de hoje</div><div class="num" id="vendas-num">128</div></div>
                            <span class="tag"><span class="live"></span>ao vivo · exemplo</span>
                        </div>
                        <div class="cols" aria-hidden="true">
                            <div class="col" style="height: 38%; animation-delay: .2s"></div><div class="col" style="height: 55%; animation-delay: .3s"></div><div class="col" style="height: 44%; animation-delay: .4s"></div><div class="col" style="height: 72%; animation-delay: .5s"></div><div class="col" style="height: 60%; animation-delay: .6s"></div><div class="col" style="height: 86%; animation-delay: .7s"></div><div class="col" style="height: 100%; animation-delay: .8s"></div>
                        </div>
                        <div class="feed" id="feed">
                            <div class="row"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span><div class="cx"><b>Venda aprovada</b><div>Plano 2 · Pix</div></div><span class="quando">agora</span></div>
                            <div class="row"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span><div class="cx"><b>Pix gerado</b><div>aguardando pagamento</div></div><span class="quando">2 min</span></div>
                            <div class="row"><span class="av"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span><div class="cx"><b>Upsell aceito</b><div>oferta extra convertida</div></div><span class="quando">4 min</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="como" class="w sec" aria-labelledby="t-como">
            <div class="titulo-sec" style="max-width: 660px"><span class="tag">Como funciona</span><h2 id="t-como">Três passos e seu funil está no ar.</h2></div>
            <div class="passos">
                <div class="passo"><div class="n" aria-hidden="true">1</div><h3>Monte o fluxo</h3><p>Use o modo Guiado: preencha boas-vindas, planos e pagamento num formulário simples.</p></div>
                <div class="passo"><div class="n" aria-hidden="true">2</div><h3>Conecte o Pix</h3><p>Integre o gateway e configure o split de comissão uma única vez.</p></div>
                <div class="passo"><div class="n" aria-hidden="true">3</div><h3>Acompanhe e escale</h3><p>Receba avisos de cada venda e use ranking e ofertas para vender mais.</p></div>
            </div>
        </section>

        <section id="recursos" class="w sec" aria-labelledby="t-recursos">
            <div class="titulo-sec" style="max-width: 660px; margin-bottom: 30px"><span class="tag">Recursos</span><h2 id="t-recursos">Ferramentas pensadas para converter.</h2></div>
            <div class="tabs" role="tablist" aria-label="Recursos">
                <button class="tab on" type="button" role="tab" id="aba-0" aria-selected="true" aria-controls="aba-painel" data-aba="0">Fluxo Guiado</button>
                <button class="tab" type="button" role="tab" id="aba-1" aria-selected="false" tabindex="-1" aria-controls="aba-painel" data-aba="1">Blocos de oferta</button>
                <button class="tab" type="button" role="tab" id="aba-2" aria-selected="false" tabindex="-1" aria-controls="aba-painel" data-aba="2">Ranking</button>
                <button class="tab" type="button" role="tab" id="aba-3" aria-selected="false" tabindex="-1" aria-controls="aba-painel" data-aba="3">Sua marca</button>
            </div>
            <div class="painel-aba" id="aba-painel" role="tabpanel" aria-labelledby="aba-0" tabindex="0">
                <div class="texto">
                    <h3 id="aba-titulo">Monte o funil preenchendo um formulário</h3>
                    <p id="aba-texto">Boas-vindas, planos e pagamento num passo a passo. Sem aprender fluxograma e sem depender de ninguém.</p>
                    <div class="tags" id="aba-tags"><span class="tag">Sem código</span><span class="tag">Pronto em minutos</span></div>
                </div>
                <div class="arte"><svg viewBox="0 0 24 24" aria-hidden="true"><path id="aba-icone" d="M4 3h16v18H4zM8 8h8M8 12h8M8 16h5"/></svg></div>
            </div>
        </section>

        <section id="mobile" class="w sec" aria-labelledby="t-mobile">
            <div class="mobile-grade">
                <div class="phone" aria-hidden="true"><div class="scr">
                    <div class="topo"><b>Painel</b><span class="tag">18/18 telas</span></div>
                    <div class="row" style="animation-delay: .2s"><span class="av"><svg class="ic" viewBox="0 0 24 24"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></span><div><b>Vendas</b><div>em tempo real</div></div></div>
                    <div class="row" style="animation-delay: .4s"><span class="av"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8"/></svg></span><div><b>Editor de fluxo</b><div>arraste e zoom</div></div></div>
                    <div class="row" style="animation-delay: .6s"><span class="av"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8z"/></svg></span><div><b>Notificações</b><div>venda, Pix, lead</div></div></div>
                    <div class="row" style="animation-delay: .8s"><span class="av"><svg class="ic" viewBox="0 0 24 24"><path d="M12 2l2.4 7.2H22l-6 4.6 2.3 7.2-6.3-4.6-6.3 4.6 2.3-7.2-6-4.6h7.6z"/></svg></span><div><b>Ranking</b><div>pódio da campanha</div></div></div>
                </div></div>
                <div class="mobile-texto">
                    <span class="tag">Experiência mobile</span>
                    <h2 id="t-mobile" class="h2">O painel inteiro cabe no seu bolso.</h2>
                    <p>Redesenhado do zero e testado tela por tela. No editor de fluxo, você arrasta blocos com 1 dedo e dá zoom com pinça.</p>
                    <div class="antes-agora">
                        <div class="antes"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"/></svg>Antes: painel pensado só para computador</div>
                        <div class="agora"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>Agora: 100% utilizável no celular</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="seguranca" class="w sec" aria-labelledby="t-seguranca">
            <div class="titulo-sec" style="max-width: 660px"><span class="tag">Segurança</span><h2 id="t-seguranca">Mais de 15 rodadas de auditoria.</h2></div>
            <div class="seguranca">
                <div class="cartao"><div class="ib"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg></div><h3>Contas isoladas</h3><p>Nenhum cliente acessa dados de outro.</p></div>
                <div class="cartao"><div class="ib"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div><h3>Login blindado</h3><p>Tentativas de adivinhar senha são bloqueadas.</p></div>
                <div class="cartao"><div class="ib"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4zM4 11h16"/></svg></div><h3>Pagamento em 3 camadas</h3><p>Acesso só é liberado com o Pix confirmado.</p></div>
                <div class="cartao"><div class="ib"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg></div><h3>Uploads validados</h3><p>O conteúdo real do arquivo é conferido.</p></div>
            </div>
        </section>

        <section class="faixa" aria-labelledby="t-numeros">
            <div class="anel" style="width: 520px; height: 520px; top: -200px; left: -120px" aria-hidden="true"></div>
            <div class="anel" style="width: 380px; height: 380px; bottom: -160px; right: 6%; animation-direction: reverse" aria-hidden="true"></div>
            <div class="w">
                <h2 id="t-numeros">Capacidade testada, não prometida.</h2>
                <div class="numeros">
                    <div><div class="big">~1.000</div><p>vendas por dia, hoje</p></div>
                    <div><div class="big">60+</div><p>requisições simultâneas</p></div>
                    <div><div class="big">~65 mil</div><p>vendas/dia projetadas em VPS</p></div>
                    <div><div class="big">0</div><p>erros nos testes de carga</p></div>
                </div>
            </div>
        </section>
    </main>

    <footer id="cta" class="w" style="padding-block: 90px 50px">
        <div class="cta-bloco">
            <img src="<?php echo htmlspecialchars($mascote_v); ?>" width="220" height="220" loading="lazy" alt="">
            <h2>Pronto para vender mais com menos esforço?</h2>
            <p>Tudo já está rodando e testado. Crie sua conta e coloque sua operação no ar.</p>
            <div class="botoes">
                <a class="btn pri" href="<?php echo $destino_cta; ?>"><?php echo $logado ? 'Abrir meu painel' : 'Criar minha conta'; ?></a>
                <?php if (!$logado): ?><a class="btn" href="login">Já tenho conta</a><?php endif; ?>
            </div>
        </div>
        <div class="rodape">
            <div class="cols-rodape">
                <div class="sobre">
                    <a class="marca" href="#top"><img src="<?php echo htmlspecialchars($mascote_v); ?>" width="56" height="56" loading="lazy" alt=""><span><?php echo htmlspecialchars($nome_maiusculo); ?></span></a>
                    <p>Sistema para gerenciar bots de venda, com fluxos prontos, Pix e painel que funciona no celular.</p>
                </div>
                <nav class="col-links" aria-label="Produto">
                    <b>Produto</b>
                    <a href="#editor">Editor de fluxo</a><a href="#recursos">Recursos</a><a href="#mobile">Mobile</a>
                </nav>
                <nav class="col-links" aria-label="Plataforma">
                    <b>Plataforma</b>
                    <a href="#como">Como funciona</a><a href="#seguranca">Segurança</a><a href="<?php echo $destino_cta; ?>"><?php echo $rotulo_cta; ?></a><?php if (!$logado): ?><a href="login">Entrar</a><?php endif; ?><a href="termos">Termos de uso</a>
                </nav>
            </div>
            <div class="fim">
                <span>© <?php echo htmlspecialchars($nome); ?>. Todos os direitos reservados.</span>
                <a class="volta" href="#top">Voltar ao topo<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg></a>
            </div>
        </div>
    </footer>
</div>

<script>
(function () {
    // ---- Abas de recursos ----
    var abas = [
        { titulo: 'Monte o funil preenchendo um formulário', texto: 'Boas-vindas, planos e pagamento num passo a passo. Sem aprender fluxograma e sem depender de ninguém.', tags: ['Sem código', 'Pronto em minutos'], icone: 'M4 3h16v18H4zM8 8h8M8 12h8M8 16h5' },
        { titulo: 'Aumente o ticket médio em cada venda', texto: 'Sorteio, oferta extra (upsell), oferta menor (downsell) e extra no pagamento (order bump) para usar no momento certo do funil.', tags: ['Upsell', 'Downsell', 'Order bump', 'Sorteio'], icone: 'M12 2v6M12 16v6M2 12h6M16 12h6M4.9 4.9l4.2 4.2M14.9 14.9l4.2 4.2' },
        { titulo: 'Gamifique a venda com placar ao vivo', texto: 'Crie campanhas de faturamento com pódio e prêmios. Quem opera o bot passa a competir para vender mais.', tags: ['Pódio', 'Prêmios', 'Placar ao vivo'], icone: 'M12 2l2.4 7.2H22l-6 4.6 2.3 7.2-6.3-4.6-6.3 4.6 2.3-7.2-6-4.6h7.6z' },
        { titulo: 'O painel com a cara do seu negócio', texto: 'Nome, logo, favicon e cor de destaque personalizáveis, com login por Google para o cadastro ser um clique.', tags: ['White-label', 'Login com Google'], icone: 'M4 4h16v16H4zM4 9h16M9 21V9' }
    ];
    var botoes = [].slice.call(document.querySelectorAll('[data-aba]'));
    var painel = document.getElementById('aba-painel');
    function escolher(i, foco) {
        botoes.forEach(function (b, j) {
            var ativa = j === i;
            b.classList.toggle('on', ativa);
            b.setAttribute('aria-selected', ativa ? 'true' : 'false');
            b.tabIndex = ativa ? 0 : -1;
        });
        var a = abas[i];
        document.getElementById('aba-titulo').textContent = a.titulo;
        document.getElementById('aba-texto').textContent = a.texto;
        document.getElementById('aba-icone').setAttribute('d', a.icone);
        var tags = document.getElementById('aba-tags');
        tags.textContent = '';
        a.tags.forEach(function (t) { var s = document.createElement('span'); s.className = 'tag'; s.textContent = t; tags.appendChild(s); });
        painel.setAttribute('aria-labelledby', 'aba-' + i);
        painel.classList.remove('anima'); void painel.offsetWidth; painel.classList.add('anima');
        if (foco) botoes[i].focus();
    }
    botoes.forEach(function (b, i) {
        b.addEventListener('click', function () { escolher(i, false); });
        b.addEventListener('keydown', function (e) {
            var n = botoes.length, destino = -1;
            if (e.key === 'ArrowRight') destino = (i + 1) % n;
            else if (e.key === 'ArrowLeft') destino = (i - 1 + n) % n;
            else if (e.key === 'Home') destino = 0;
            else if (e.key === 'End') destino = n - 1;
            if (destino >= 0) { e.preventDefault(); escolher(destino, true); }
        });
    });

    // ---- Vendas "ao vivo" (exemplo): o contador e a lista andam sozinhos ----
    var eventos = [
        ['Venda aprovada', 'Plano 2 · Pix'], ['Order bump aceito', 'extra adicionado ao pedido'], ['Lead novo', 'entrou no funil'],
        ['Venda aprovada', 'Plano 1 · Pix'], ['Upsell aceito', 'oferta extra convertida'], ['Pix gerado', 'aguardando pagamento']
    ];
    var feed = document.getElementById('feed');
    var num = document.getElementById('vendas-num');
    var tick = 0;
    var CHECK = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';
    function linha(e, k) {
        var r = document.createElement('div'); r.className = 'row';
        var av = document.createElement('span'); av.className = 'av'; av.innerHTML = CHECK;
        var cx = document.createElement('div'); cx.className = 'cx';
        var b = document.createElement('b'); b.textContent = e[0];
        var d = document.createElement('div'); d.textContent = e[1];
        cx.appendChild(b); cx.appendChild(d);
        var q = document.createElement('span'); q.className = 'quando'; q.textContent = k === 0 ? 'agora' : (k * 2) + ' min';
        r.appendChild(av); r.appendChild(cx); r.appendChild(q);
        return r;
    }
    function desenhar() {
        num.textContent = String(128 + tick);
        feed.textContent = '';
        for (var k = 0; k < 3; k++) feed.appendChild(linha(eventos[(tick + 3 - k) % eventos.length], k));
    }
    var relogio = null;
    function ligar() { if (!relogio) relogio = setInterval(function () { tick++; desenhar(); }, 2800); }
    function desligar() { clearInterval(relogio); relogio = null; }
    document.addEventListener('visibilitychange', function () { document.hidden ? desligar() : ligar(); });
    ligar();
})();
</script>
</body>
</html>
