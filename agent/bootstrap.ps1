<#
.SYNOPSIS
    Gmina IT Monitor - Active Directory Bootstrap Launcher (Auto-Patcher)
.DESCRIPTION
    Lekki launcher wdrażany przez GPO (Zasady Grup Active Directory).
    Pobiera najnowszą wersję agenta (monitor.ps1) z serwera Ubuntu (Nginx)
    i uruchamia ją dyskretnie w tle. Umożliwia centralne, natychmiastowe
    aktualizowanie skryptu diagnostycznego na wszystkich stacjach roboczych.
#>

param(
    # Adres Twojego serwera Nginx na maszynie wirtualnej Ubuntu
    [string]$ServerUrl = "http://adminmonitor.gminaleszno.local/agent/monitor.ps1",
    [string]$CacheDir  = ""
)

if ([string]::IsNullOrWhiteSpace($CacheDir)) {
    # Dla zalogowanego usera w AD: LocalAppData (100% praw zapisu bez admina)
    # Dla konta maszyny SYSTEM: ProgramData
    $CacheDir = if ($env:LOCALAPPDATA) { "$env:LOCALAPPDATA\GminaITMonitor" } else { "$env:ProgramData\GminaITMonitor" }
}

# Wymuszenie protokołów TLS 1.2 i TLS 1.1 dla starszych stacji z PowerShell 5.1
try {
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls11 -bor [System.Net.SecurityProtocolType]::Tls
} catch {}

if (-not (Test-Path $CacheDir)) {
    New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null
}

$localScript = Join-Path $CacheDir "monitor.ps1"
$tempScript  = Join-Path $CacheDir "monitor.tmp.ps1"

# 1. Pobierz najnowszą wersję skryptu z serwera WWW
try {
    $wc = New-Object System.Net.WebClient
    $wc.Encoding = [System.Text.Encoding]::UTF8
    $wc.Headers.Add("User-Agent", "GminaITMonitor-Bootstrap/2.0 ($env:COMPUTERNAME)")
    
    # Pobranie do pliku tymczasowego
    $wc.DownloadFile($ServerUrl, $tempScript)

    if ((Test-Path $tempScript) -and ((Get-Item $tempScript).Length -gt 1024)) {
        # Nadpisanie lokalnego skryptu tylko gdy pobrany plik jest poprawny
        Copy-Item -Path $tempScript -Destination $localScript -Force
        Remove-Item -Path $tempScript -Force -ErrorAction SilentlyContinue
    }
} catch {
    # W przypadku braku sieci lub niedostępności serwera, skrypt przechodzi do wykonania ostatniej lokalnej kopii
    if (Test-Path $tempScript) {
        Remove-Item -Path $tempScript -Force -ErrorAction SilentlyContinue
    }
}

# 2. Wykonaj skrypt diagnostyczny w tle (bez widocznego okna konsoli)
if (Test-Path $localScript) {
    Start-Process -FilePath "powershell.exe" `
        -ArgumentList "-ExecutionPolicy Bypass -NoProfile -WindowStyle Hidden -File `"$localScript`"" `
        -WindowStyle Hidden `
        -Wait
}
