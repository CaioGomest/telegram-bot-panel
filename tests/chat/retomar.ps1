# Vigia do tests/chat/rodar.js (Windows): retoma os php.exe da bateria que ficaram CONGELADOS (todas as threads "Suspended").
# Nesta máquina o Windows/antivírus às vezes congela o processo PHP no meio do teste (sem erro nenhum, ele só para até alguém retomar);
# já foi visto: o PHP pai parado com todas as threads Suspended e nenhum filho vivo, e voltando a andar com NtResumeProcess.
# Só mexe em processos cujo comando aponta para a cópia de teste (C:/tmp/chat_int). Imprime uma linha por processo retomado.
Add-Type -Namespace Nt -Name Proc -MemberDefinition '[DllImport("ntdll.dll")] public static extern int NtResumeProcess(IntPtr h);'
foreach ($proc in (Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match 'chat_int' })) {
    $p = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
    if (-not $p) { continue }
    $threads = @($p.Threads)
    if ($threads.Count -gt 0 -and @($threads | Where-Object { $_.WaitReason -ne 'Suspended' }).Count -eq 0) {
        [void][Nt.Proc]::NtResumeProcess($p.Handle)
        "retomei o processo $($p.Id) (todas as threads estavam congeladas)"
    }
}
