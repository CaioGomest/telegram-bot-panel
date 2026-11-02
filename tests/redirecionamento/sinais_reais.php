<?php
chdir(__DIR__ . '/../..');
define('BANCO_SENHA', 'x'); define('BANCO_NOME', 'x');
require_once 'funcoes/protecao_redirecionamento.php';
$ua_and = 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.0.0 Mobile Safari/537.36 Instagram 330.0';
$ua_ios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0';
$ua_pc  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$ua_ff  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0';
$ua_saf = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
$casos = [
 // [nome, ua, sinais, esperado passa?]
 ['Android WebView (Instagram), GPU Adreno',   $ua_and, ['webdriver'=>false,'tela_l'=>412,'tela_a'=>915,'idiomas'=>2,'chrome_obj'=>false,'gpu'=>'Adreno (TM) 740','canvas'=>true], true],
 ['iPhone WKWebView, GPU Apple',               $ua_ios, ['webdriver'=>false,'tela_l'=>390,'tela_a'=>844,'idiomas'=>2,'chrome_obj'=>false,'gpu'=>'Apple GPU','canvas'=>true], true],
 ['iPhone sem WebGL (gpu vazia)',              $ua_ios, ['webdriver'=>false,'tela_l'=>390,'tela_a'=>844,'idiomas'=>1,'chrome_obj'=>false,'gpu'=>'','canvas'=>true], true],
 ['Chrome PC com placa NVIDIA',                $ua_pc,  ['webdriver'=>false,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>true,'gpu'=>'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0)','canvas'=>true], true],
 ['Chrome PC com vídeo da Intel (integrada)',  $ua_pc,  ['webdriver'=>false,'tela_l'=>1366,'tela_a'=>768,'idiomas'=>1,'chrome_obj'=>true,'gpu'=>'ANGLE (Intel, Intel(R) UHD Graphics 620 Direct3D11 vs_5_0 ps_5_0)','canvas'=>true], true],
 ['Firefox PC (sem window.chrome)',            $ua_ff,  ['webdriver'=>false,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>false,'gpu'=>'NVIDIA GeForce RTX 3060','canvas'=>true], true],
 ['Safari Mac',                                $ua_saf, ['webdriver'=>false,'tela_l'=>1440,'tela_a'=>900,'idiomas'=>1,'chrome_obj'=>false,'gpu'=>'Apple M2','canvas'=>true], true],
 ['Chrome PC com extensão que bloqueia canvas',$ua_pc,  ['webdriver'=>false,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>true,'gpu'=>'ANGLE (NVIDIA, GeForce)','canvas'=>false], true],
 ['Chrome PC só com GPU por software (VM)',    $ua_pc,  ['webdriver'=>false,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>true,'gpu'=>'Google SwiftShader','canvas'=>true], true],
 ['Selenium (webdriver ligado)',               $ua_pc,  ['webdriver'=>true,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>true,'gpu'=>'ANGLE (NVIDIA)','canvas'=>true], false],
 ['Puppeteer headless (SwiftShader, 800x600)', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36', ['webdriver'=>false,'tela_l'=>800,'tela_a'=>600,'idiomas'=>1,'chrome_obj'=>false,'gpu'=>'Google SwiftShader','canvas'=>true], false],
 ['Chrome falso: sem window.chrome + sem GPU + sem canvas', $ua_pc, ['webdriver'=>false,'tela_l'=>1920,'tela_a'=>1080,'idiomas'=>2,'chrome_obj'=>false,'gpu'=>'','canvas'=>false], false],
 ['Script sem JS de verdade (tudo zerado)',    $ua_pc,  ['webdriver'=>false,'tela_l'=>0,'tela_a'=>0,'idiomas'=>0,'chrome_obj'=>false,'gpu'=>'','canvas'=>false], false],
];
$ok = $fa = 0;
foreach ($casos as [$n, $ua, $s, $esp]) {
    $r = sinaisDesafioRedirecionamentoOk($s, $ua);
    if ($r === $esp) { $ok++; echo "OK     " . ($esp ? 'passa' : 'barra ') . "  $n\n"; } else { $fa++; echo "FALHOU $n (esperado " . ($esp ? 'passar' : 'barrar') . ")\n"; }
}
echo "== $ok ok, $fa falha(s)\n";
