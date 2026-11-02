# Vigia do tests/redirecionamento/rodar.js (Windows): retoma php.exe / php-cgi.exe da bateria que ficaram CONGELADOS (todas as threads "Suspended").
# Mesma armadilha do tests/chat (ver retomar.ps1 de lá). Só mexe em processos cujo comando aponta para a cópia de teste (rd_int) ou para o php-cgi.
Add-Type -Namespace Nt -Name Proc -MemberDefinition '[DllImport("ntdll.dll")] public static extern int NtResumeProcess(IntPtr h);'
foreach ($proc in (Get-CimInstance Win32_Process -Filter "Name='php.exe' or Name='php-cgi.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match 'rd_int|php-cgi' })) {
    $p = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
    if (-not $p) { continue }
    $threads = @($p.Threads)
    if ($threads.Count -gt 0 -and @($threads | Where-Object { $_.WaitReason -ne 'Suspended' }).Count -eq 0) {
        [void][Nt.Proc]::NtResumeProcess($p.Handle)
        "retomei o processo $($p.Id) (todas as threads estavam congeladas)"
    }
}
