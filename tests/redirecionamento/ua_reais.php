<?php
// Visitantes REAIS (navegadores dos apps de anúncio) não podem ser barrados; robôs conhecidos têm que ser.
chdir(__DIR__ . '/../..');
define('BANCO_SENHA', 'x'); define('BANCO_NOME', 'x');
require_once 'funcoes/protecao_redirecionamento.php';
$humanos = [
 'Facebook (Android, navegador interno)' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.122 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/470.0.0.40.109;]',
 'Facebook (iPhone, navegador interno)' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21F90 [FBAN/FBIOS;FBAV/468.0.0.40.109;FBBV/600000000;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.5.1;FBSS/3;FBID/phone;FBLC/pt_BR;FBOP/5]',
 'Instagram (Android)' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230901.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/125.0.6422.165 Mobile Safari/537.36 Instagram 330.0.0.40.97 Android (33/13; 420dpi; 1080x2400; Google/google; Pixel 7; panther; panther; pt_BR; 600000000)',
 'Instagram (iPhone)' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.2.20.105 (iPhone14,5; iOS 17_5; pt_BR; pt-BR; scale=3.00; 1170x2532; 600000000)',
 'TikTok (Android)' => 'Mozilla/5.0 (Linux; Android 13; SM-A546E Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/125.0.6422.165 Mobile Safari/537.36 trill_2023404010 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/34.4.0 ByteLocale/pt-BR BytedanceWebview/d8a21c6',
 'TikTok (iPhone)' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_34.4.0 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/pt-BR Region/BR',
 'Google app / YouTube (Android)' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UD1A.231105.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.122 Mobile Safari/537.36 GSA/15.26.40.26.arm64',
 'Chrome Android' => 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
 'Safari iPhone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
 'Samsung Internet' => 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
 'Firefox Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
 'Edge Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
 'Safari Mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
 'Telegram (navegador interno Android)' => 'Mozilla/5.0 (Linux; Android 13; SM-A135F Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/125.0.6422.165 Mobile Safari/537.36 Telegram-Android/10.14.5',
 'Kwai (Android)' => 'Mozilla/5.0 (Linux; Android 12; M2101K6G Build/SKQ1.211006.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.5481.153 Mobile Safari/537.36 Kwai/12.2.20.5111',
];
$robos = [
 'facebookexternalhit' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
 'Facebot' => 'Mozilla/5.0 (compatible; Facebot 1.0; https://developers.facebook.com/docs/sharing/webmasters/crawler)',
 'Google AdsBot' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/W.X.Y.Z Mobile Safari/537.36 (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)',
 'Mediapartners-Google' => 'Mediapartners-Google',
 'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
 'TikTok Bytespider' => 'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
 'TikTok TikTokSpider' => 'Mozilla/5.0 (compatible; TikTokSpider; +https://www.tiktok.com)',
 'Pinterestbot' => 'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)',
 'TelegramBot' => 'TelegramBot (like TwitterBot)',
 'WhatsApp link preview' => 'WhatsApp/2.23.20.0',
 'Twitterbot' => 'Twitterbot/1.0',
 'Bing' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
 'curl' => 'curl/8.5.0',
 'python-requests' => 'python-requests/2.31.0',
 'Go http' => 'Go-http-client/1.1',
 'Puppeteer/Headless' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
 'Lighthouse' => 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse',
 'vazio' => '',
];
$ok = 0; $fa = 0;
foreach ($humanos as $n => $ua) {
    $robo = ehRoboDeAnuncioRedirecionamento($ua, 'GET', 'pt-BR,pt;q=0.9');
    if (!$robo) { $ok++; echo "OK     humano passa: $n\n"; } else { $fa++; echo "FALHOU humano BARRADO: $n\n"; }
}
foreach ($robos as $n => $ua) {
    $robo = ehRoboDeAnuncioRedirecionamento($ua, 'GET', 'pt-BR,pt;q=0.9');
    if ($robo) { $ok++; echo "OK     robô barrado: $n\n"; } else { $fa++; echo "FALHOU robô PASSOU: $n\n"; }
}
echo "== $ok ok, $fa falha(s)\n";
