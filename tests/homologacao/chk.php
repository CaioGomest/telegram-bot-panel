<?php
// Asserção sobre o sim.log desde o último "mark".
// Uso: php chk.php "rótulo" 'expressão PHP (true = passou)' [grupo]
require '/tmp/pt/common.php';
$marca = (int) @file_get_contents('/tmp/pt/marca');
$linhas = is_file('/tmp/pt/sim.log') ? file('/tmp/pt/sim.log', FILE_IGNORE_NEW_LINES) : [];
$L = [];
foreach (array_slice($linhas, $marca) as $l) { $d = json_decode($l, true); if ($d) $L[] = $d; }
function tg(string $m): array { global $L; return array_values(array_filter($L, fn($e) => ($e['tg'] ?? '') === $m)); }
function tgok(string $m): array { return array_values(array_filter(tg($m), fn($e) => $e['ok'] ?? false)); }
function tgerr(): array { global $L; return array_values(array_filter($L, fn($e) => isset($e['tg']) && !($e['ok'] ?? true))); }
function gw(): array { global $L; return array_values(array_filter($L, fn($e) => isset($e['gw']) && isset($e['valor']))); }
function txt(array $e): string { return (string) ($e['p']['text'] ?? $e['p']['caption'] ?? ''); }
function textos(string $m = 'sendMessage'): array { return array_map('txt', tg($m)); }
function tem(string $needle, string $m = 'sendMessage'): bool { foreach (tg($m) as $e) if (mb_strpos(txt($e), $needle) !== false) return true; return false; }
function botoes(array $e): array { $o = []; foreach (($e['p']['reply_markup']['inline_keyboard'] ?? []) as $l) foreach ($l as $b) $o[] = $b; return $o; }
function cbs(array $e): array { return array_values(array_filter(array_map(fn($b) => $b['callback_data'] ?? null, botoes($e)))); }
function ultimo(string $m): ?array { $t = tg($m); return $t ? end($t) : null; }
function sequencia(): array { global $L; return array_values(array_map(fn($e) => $e['tg'], array_filter($L, fn($e) => isset($e['tg'])))); }
function semerros(): bool { global $L; foreach ($L as $e) { $s = ($e['resposta'] ?? '') . ($e['saida'] ?? ''); if (preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught|Stack trace)/i', $s)) return false; } return true; }
function erros_php(): array { global $L; $o = []; foreach ($L as $e) { $s = ($e['resposta'] ?? '') . ($e['saida'] ?? ''); if (preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)[^\n]{0,200}/i', $s, $m)) $o[] = $m[0]; } return $o; }
function ev(string $k): array { global $L; return array_values(array_filter($L, fn($e) => isset($e[$k]))); }
function msgs_chat(string $chat): array { global $L; $o = []; foreach ($L as $e) { if (($e["tg"] ?? "") !== "" && in_array($e["tg"], ["sendMessage","sendPhoto","sendVideo","sendVoice","sendAudio","sendDocument"], true) && (string) ($e["p"]["chat_id"] ?? "") === $chat && ($e["ok"] ?? false)) $o[] = txt($e); } return $o; }
function erros_desde(int $n): array { $o = []; foreach (array_slice(file("/tmp/pt/sim.log", FILE_IGNORE_NEW_LINES), $n) as $l) { $e = json_decode($l, true); if (!$e) continue; if (isset($e["tg"]) && !($e["ok"] ?? true)) $o[] = "TG ".$e["tg"].": ".($e["erro"] ?? ""); $s = ($e["resposta"] ?? "").($e["saida"] ?? ""); if (preg_match("/(Warning|Notice|Fatal error|Deprecated|Uncaught)[^
]{0,160}/i", $s, $m)) $o[] = $m[0]; } return $o; }
function lista(array $a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }
$R = json_decode((string) @file_get_contents('/tmp/pt/resp.json'), true) ?: [];
function resp(): array { global $R; return $R['body'] ?? []; }
function http(): int { global $R; return (int) ($R['http'] ?? 0); }
$r = false; $erro = '';
try { $r = (bool) eval('return ' . $argv[2] . ';'); } catch (Throwable $e) { $erro = $e->getMessage(); }
$ok = $r && !$erro;
echo ($ok ? "  PASS" : "  FAIL") . " | " . $argv[1] . ($erro ? " | ERRO: $erro" : '') . "\n";
file_put_contents('/tmp/pt/resultados.txt', ($ok ? 'PASS' : 'FAIL') . ' | ' . ($argv[3] ?? '') . ' | ' . $argv[1] . "\n", FILE_APPEND);
if (!$ok) {
    echo "    api: http=".($R["http"] ?? "-")." ".mb_substr((string)($R["raw"] ?? ""),0,300)."
";
    echo "    --- log desde a marca (" . count($L) . " eventos) ---\n";
    foreach (array_slice($L, 0, 40) as $e) {
        $s = $e;
        if (isset($s['p']['reply_markup'])) $s['p']['reply_markup'] = '[' . count(botoes($s)) . ' botões: ' . implode(' | ', array_map(fn($b) => ($b['text'] ?? '') . '→' . ($b['callback_data'] ?? $b['url'] ?? 'copy'), botoes($s))) . ']';
        echo '    ' . mb_substr(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 420) . "\n";
    }
}
