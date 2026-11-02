<#
.SYNOPSIS
    Gmina IT Monitor - Agent PowerShell (stacja robocza Windows)
.DESCRIPTION
    Wykonuje lokalne testy infrastruktury sieciowej oraz wysyła raport HTTP POST do API.
    Obsługuje automatyczne wykrywanie kontrolera domeny AD oraz drukarki domyślnej w pokoju pracownika.
    Działa bez uprawnień administratora i bez zewnętrznych modułów.
#>

[CmdletBinding()]
param(
    [string]$ConfigFile = ""
)

# Wymuszenie TLS 1.2
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls11 -bor [System.Net.SecurityProtocolType]::Tls

# 1. Odczyt konfiguracji
if ([string]::IsNullOrWhiteSpace($ConfigFile)) {
    $scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Definition }
    if (-not $scriptDir) { $scriptDir = (Join-Path (Get-Location).Path "agent") }
    $ConfigFile = Join-Path $scriptDir "config.ps1"
}

$Config = @{}
if (Test-Path $ConfigFile) {
    try {
        $Config = . $ConfigFile
    } catch {
        Write-Warning "Blad podczas wczytywania pliku konfiguracji: $_"
    }
}

# Wartości domyślne (działają nawet przy braku pliku config.ps1 przy pobraniu przez GPO)
$ApiUrl      = if ($Config.ApiUrl)      { [string]$Config.ApiUrl }      else { "http://adminmonitor.gminaleszno.local/api/heartbeat.php" }
$DeviceToken = if ($Config.DeviceToken) { [string]$Config.DeviceToken } else { "gmina-it-adm-token-2026-secret" }
$AgentVer    = if ($Config.AgentVersion) { [string]$Config.AgentVersion } else { "1.1.0" }
$PingTimeout = if ($Config.PingTimeoutMs) { [int]$Config.PingTimeoutMs } else { 1500 }
$TcpTimeout  = if ($Config.TcpTimeoutMs)  { [int]$Config.TcpTimeoutMs }  else { 2000 }
$HttpTimeout = if ($Config.HttpTimeoutMs) { [int]($Config.HttpTimeoutMs / 1000) } else { 5 }
$SubnetFilter = if ($Config.SubnetFilter) { [string]$Config.SubnetFilter } else { "192.168." }

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "       Gmina IT Monitor - Agent Domenowy v$AgentVer       " -ForegroundColor White
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Czas: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Gray

# 2. Funkcje pomocnicze
function Test-IcmpPing {
    param([string]$Address, [int]$TimeoutMs = 1500)
    if ([string]::IsNullOrWhiteSpace($Address)) { return @{ Success = $false; Rtt = $null; Error = "Brak adresu" } }
    
    $p = New-Object System.Net.NetworkInformation.Ping
    try {
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $reply = $p.Send($Address, $TimeoutMs)
        $sw.Stop()
        if ($reply.Status -eq [System.Net.NetworkInformation.IPStatus]::Success) {
            return @{ Success = $true; Rtt = [int]$reply.RoundtripTime; Error = $null }
        } else {
            return @{ Success = $false; Rtt = $null; Error = "Status ICMP: $($reply.Status)" }
        }
    } catch {
        return @{ Success = $false; Rtt = $null; Error = $_.Exception.Message }
    } finally {
        $p.Dispose()
    }
}

function Test-TcpPort {
    param([string]$HostName, [int]$Port, [int]$TimeoutMs = 2000)
    if ([string]::IsNullOrWhiteSpace($HostName) -or $Port -le 0) {
        return @{ Success = $false; DurationMs = $null; Error = "Niepoprawny host lub port" }
    }
    
    $client = New-Object System.Net.Sockets.TcpClient
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        $asyncResult = $client.BeginConnect($HostName, $Port, $null, $null)
        $waitSuccess = $asyncResult.AsyncWaitHandle.WaitOne($TimeoutMs, $false)
        $sw.Stop()
        
        if ($waitSuccess -and $client.Connected) {
            $client.EndConnect($asyncResult)
            return @{ Success = $true; DurationMs = [int]$sw.ElapsedMilliseconds; Error = $null }
        } else {
            return @{ Success = $false; DurationMs = [int]$sw.ElapsedMilliseconds; Error = "Limit czasu polaczenia ($TimeoutMs ms)" }
        }
    } catch {
        $sw.Stop()
        return @{ Success = $false; DurationMs = [int]$sw.ElapsedMilliseconds; Error = $_.Exception.Message }
    } finally {
        $client.Close()
    }
}


# Wykrywanie edycji pakietu biurowego (np. Office 2021 Home & Business, Office 2019 Standard, M365)
$OfficeVersion = ""
try {
    # 1. Sprawdzenie zarejestrowanych aplikacji w gałęzi deinstalatora Windows
    $uninstallKeys = @(
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )
    $installedApps = Get-ItemProperty $uninstallKeys -ErrorAction SilentlyContinue | Where-Object { $null -ne $_.DisplayName }
    $officeApp = $installedApps | Where-Object {
        ($_.DisplayName -match 'Microsoft Office' -or $_.DisplayName -match 'Microsoft 365' -or $_.DisplayName -match 'LibreOffice') -and
        $_.DisplayName -notmatch 'Click-to-Run|Localization|MUI|Proof|Language|Shared|Support|Access Runtime|Teams|Lync|Skype'
    } | Select-Object -First 1

    if ($officeApp) {
        $cleanName = [string]$officeApp.DisplayName
        $cleanName = $cleanName -replace ' - [a-z]{2}-[a-z]{2}$', ''
        $cleanName = $cleanName -replace 'dla U[^ ]+ Domowych i Ma[^ ]+ Firm', 'Home & Business'
        $cleanName = $cleanName -replace 'dla U[^ ]+ Domowych i Uczni[^ ]+', 'Home & Student'
        $OfficeVersion = $cleanName.Trim()
    }

    # 2. Rezerwa: Sprawdzenie ClickToRun (jeśli brak wpisu w Uninstall)
    if (-not $OfficeVersion) {
        $c2r = Get-ItemProperty 'HKLM:\Software\Microsoft\Office\ClickToRun\Configuration' -ErrorAction SilentlyContinue
        if ($c2r) {
            $pIds = [string]$c2r.ProductReleaseIds
            if ($pIds -like "*HomeBusiness2021*") { $OfficeVersion = "Microsoft Office 2021 Home & Business" }
            elseif ($pIds -like "*ProPlus2021*") { $OfficeVersion = "Microsoft Office 2021 Professional Plus" }
            elseif ($pIds -like "*HomeBusiness2019*") { $OfficeVersion = "Microsoft Office 2019 Home & Business" }
            elseif ($pIds -like "*ProPlus2019*") { $OfficeVersion = "Microsoft Office 2019 Professional Plus" }
            elseif ($pIds -like "*Standard2019*") { $OfficeVersion = "Microsoft Office 2019 Standard" }
            elseif ($pIds -like "*Standard2016*") { $OfficeVersion = "Microsoft Office 2016 Standard" }
            elseif ($pIds -like "*O365*" -or $pIds -like "*M365*") { $OfficeVersion = "Microsoft 365 Apps" }
            else { $OfficeVersion = "Microsoft Office ($pIds)" }
        }
    }

    # 3. Rezerwa: plik Excel.exe
    if (-not $OfficeVersion) {
        $appPath = (Get-ItemProperty 'HKLM:\Software\Microsoft\Windows\CurrentVersion\App Paths\excel.exe' -ErrorAction SilentlyContinue).'(Default)'
        if ($appPath -and (Test-Path $appPath)) {
            $fInfo = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($appPath)
            if ($fInfo) {
                $OfficeVersion = if ($fInfo.FileDescription) { $fInfo.FileDescription } else { "Microsoft Office" }
            }
        }
    }
} catch {}
if (-not $OfficeVersion) { $OfficeVersion = "Brak Office" }
# 3. Zbieranie informacji o komputerze i karcie LAN
Write-Host "`n[1/3] Wykrywanie konfiguracji stacji..." -ForegroundColor Yellow
$Hostname = $env:COMPUTERNAME
$DomainName = $env:USERDNSDOMAIN
$OsName = "Windows"
$OsVersion = ""

# Wykrywanie zalogowanego użytkownika
$LoggedUser = $env:USERNAME
try {
    $cs = Get-CimInstance Win32_ComputerSystem -ErrorAction SilentlyContinue
    if ($cs -and $cs.UserName) {
        $LoggedUser = $cs.UserName.Split('\')[-1]
    }
} catch {}

# Wykrywanie stanu Windows Update i oczekującego restartu
$PendingReboot = $false
try {
    $reboot1 = Test-Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired"
    $reboot2 = Test-Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending"
    $PendingReboot = ($reboot1 -or $reboot2)
} catch {}

$LastUpdateInfo = ""
$DaysSinceUpdate = 0
try {
    $hotfix = Get-HotFix -ErrorAction SilentlyContinue | Sort-Object InstalledOn -Descending | Select-Object -First 1
    if ($hotfix) {
        $instDateStr = if ($hotfix.InstalledOn) { $hotfix.InstalledOn.ToString("yyyy-MM-dd") } else { "Brak daty" }
        $LastUpdateInfo = "$($hotfix.HotFixID) ($instDateStr)"
        if ($hotfix.InstalledOn) {
            $DaysSinceUpdate = [Math]::Floor(([DateTime]::Now - $hotfix.InstalledOn).TotalDays)
        }
    }
} catch {}

try {
    $osInfo = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
    $OsName = if ($osInfo -and $osInfo.Caption) { $osInfo.Caption.Trim() } else { "Microsoft Windows" }

    # Wykrywanie szczegółowej wersji kompilacji i aktualizacji Windows (DisplayVersion, CurrentBuild, UBR)
    $buildDetails = ""
    try {
        $regCv = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue
        if ($regCv) {
            $dispVer = if ($regCv.DisplayVersion) { [string]$regCv.DisplayVersion.Trim() } elseif ($regCv.ReleaseId) { [string]$regCv.ReleaseId.Trim() } else { "" }
            $buildNum = if ($regCv.CurrentBuild) { [string]$regCv.CurrentBuild.Trim() } elseif ($osInfo) { [string]$osInfo.BuildNumber } else { "" }
            $ubr = if ($null -ne $regCv.UBR) { [string]$regCv.UBR } else { "" }
            
            $fullBuild = if ($ubr) { "$buildNum.$ubr" } else { "$buildNum" }
            if ($dispVer -and $fullBuild) {
                $buildDetails = "$dispVer, kompilacja $fullBuild"
            } elseif ($fullBuild) {
                $buildDetails = "kompilacja $fullBuild"
            } elseif ($dispVer) {
                $buildDetails = "$dispVer"
            }
        }
    } catch {}

    if ($buildDetails) {
        $OsVersion = $buildDetails
    } elseif ($osInfo -and $osInfo.Version) {
        $OsVersion = "kompilacja $($osInfo.Version)"
    } else {
        $OsVersion = [System.Environment]::OSVersion.VersionString
    }
} catch {
    $OsVersion = [System.Environment]::OSVersion.VersionString
}

# Wykrywanie numeru seryjnego komputera (BIOS / Płyta główna)
$ComputerSerialNumber = ""
try {
    $bios = Get-CimInstance Win32_BIOS -ErrorAction SilentlyContinue
    if ($bios -and $bios.SerialNumber) {
        $ComputerSerialNumber = [string]$bios.SerialNumber.Trim()
    }
} catch {
    try {
        $ComputerSerialNumber = [string]((Get-WmiObject Win32_BIOS -ErrorAction SilentlyContinue).SerialNumber).Trim()
    } catch {}
}
if (-not $ComputerSerialNumber -or $ComputerSerialNumber -match "To be filled|Default|System Serial") {
    try {
        $bb = Get-CimInstance Win32_BaseBoard -ErrorAction SilentlyContinue
        if ($bb -and $bb.SerialNumber) {
            $ComputerSerialNumber = [string]$bb.SerialNumber.Trim()
        }
    } catch {}
}
if (-not $ComputerSerialNumber) { $ComputerSerialNumber = "Brak SN" }

# Wykrywanie podłączonych monitorów i ich numerów seryjnych
$MonitorsList = @()
try {
    $monitorsRaw = Get-CimInstance -Namespace root\wmi -ClassName WmiMonitorID -ErrorAction SilentlyContinue
    if ($monitorsRaw) {
        foreach ($m in $monitorsRaw) {
            $manuf = if ($m.ManufacturerName) { ($m.ManufacturerName | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { "" }
            $model = if ($m.UserFriendlyName) { ($m.UserFriendlyName | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { "" }
            $serial = if ($m.SerialNumberID) { ($m.SerialNumberID | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join '' } else { "" }

            if (-not $model -and $m.ProductCodeID) {
                $model = ($m.ProductCodeID | Where-Object { $_ -ne 0 } | ForEach-Object { [char]$_ }) -join ''
            }

            $manuf = $manuf.Trim()
            $model = $model.Trim()
            $serial = $serial.Trim()

            if ($manuf -or $model -or $serial) {
                $MonitorsList += [PSCustomObject]@{
                    Manufacturer = if ($manuf) { $manuf } else { "Nieznany" }
                    Model        = if ($model) { $model } else { "Monitor" }
                    SerialNumber = if ($serial) { $serial } else { "Brak SN" }
                }
            }
        }
    }
} catch {}

# Wykrywanie oprogramowania antywirusowego
$AntivirusInfo = ""
$AntivirusList = @()
try {
    $avProducts = Get-CimInstance -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct -ErrorAction SilentlyContinue
    if (-not $avProducts) {
        $avProducts = Get-WmiObject -Namespace root\SecurityCenter2 -ClassName AntiVirusProduct -ErrorAction SilentlyContinue
    }
    if ($avProducts) {
        foreach ($av in $avProducts) {
            $name = [string]$av.displayName
            $hex = "{0:X6}" -f [int]$av.productState
            $isActive = $false
            $isUpToDate = $false
            if ($hex.Length -ge 6) {
                $activeByte = $hex.Substring(2, 2)
                $updateByte = $hex.Substring(4, 2)
                $isActive = ($activeByte -eq "10" -or $activeByte -eq "11")
                $isUpToDate = ($updateByte -eq "00")
            }
            $AntivirusList += [PSCustomObject]@{
                Name     = $name
                Active   = $isActive
                UpToDate = $isUpToDate
            }
        }
    }
} catch {}

if ($AntivirusList.Count -eq 0) {
    try {
        if (Get-Service -Name "ekrn" -ErrorAction SilentlyContinue) {
            $AntivirusList += [PSCustomObject]@{ Name = "ESET Security"; Active = $true; UpToDate = $true }
        } elseif (Get-Service -Name "WinDefend" -ErrorAction SilentlyContinue) {
            $s = Get-Service -Name "WinDefend"
            $AntivirusList += [PSCustomObject]@{ Name = "Windows Defender"; Active = ($s.Status -eq "Running"); UpToDate = $true }
        }
    } catch {}
}

$activeAv = $AntivirusList | Where-Object { $_.Active -and $_.Name -notmatch "Windows Defender" } | Select-Object -First 1
if (-not $activeAv) { $activeAv = $AntivirusList | Where-Object { $_.Active } | Select-Object -First 1 }
if (-not $activeAv -and $AntivirusList.Count -gt 0) { $activeAv = $AntivirusList[0] }

if ($activeAv) {
    $stateTxt = if ($activeAv.Active) { "Aktywny" } else { "Nieaktywny" }
    $AntivirusInfo = "$($activeAv.Name) ($stateTxt)"
} else {
    $AntivirusInfo = "Brak lub nieznany"
}

# Wykrywanie zainstalowanego oprogramowania
$InstalledSoftwareList = @()
try {
    $uninstallKeys = @(
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )
    $rawApps = Get-ItemProperty $uninstallKeys -ErrorAction SilentlyContinue | 
        Where-Object { 
            $null -ne $_.DisplayName -and 
            $_.DisplayName.Trim() -ne "" -and 
            $_.SystemComponent -ne 1 -and
            $_.DisplayName -notmatch 'Update for|Security Update|Hotfix|KB[0-9]{6}'
        } | 
        Select-Object DisplayName, DisplayVersion, Publisher | 
        Sort-Object DisplayName -Unique

    foreach ($app in $rawApps) {
        $InstalledSoftwareList += [PSCustomObject]@{
            Name      = [string]$app.DisplayName.Trim()
            Version   = if ($app.DisplayVersion) { [string]$app.DisplayVersion.Trim() } else { "" }
            Publisher = if ($app.Publisher) { [string]$app.Publisher.Trim() } else { "" }
        }
    }
} catch {}

$VirtualRegex = 'Tailscale|Tunnel|AnyDesk|Virtual|VMware|ZeroTier|TAP|Hamachi|Loopback|Hyper-V|vEthernet|WireGuard|OpenVPN'
$PrimaryIp = ""
$GatewayAddress = ""
$DnsServerList = @()
$ActiveIpv4List = @()
$LanAdapterName = ""

try {
    $allAdapters = Get-CimInstance Win32_NetworkAdapterConfiguration -Filter "IPEnabled = TRUE" -ErrorAction SilentlyContinue
    if ($allAdapters) {
        $targetAdapter = $allAdapters | Where-Object {
            $_.Description -notmatch $VirtualRegex -and 
            $_.DefaultIPGateway -ne $null -and 
            $_.DefaultIPGateway.Count -gt 0
        } | Select-Object -First 1

        if (-not $targetAdapter) {
            $targetAdapter = $allAdapters | Where-Object {
                $_.Description -notmatch $VirtualRegex -and
                (@($_.IPAddress) | Where-Object { $_ -like "$SubnetFilter*" })
            } | Select-Object -First 1
        }

        if (-not $targetAdapter) {
            $targetAdapter = $allAdapters | Where-Object { $_.Description -notmatch $VirtualRegex } | Select-Object -First 1
        }

        if ($targetAdapter) {
            $LanAdapterName = $targetAdapter.Description
            $validIps = @($targetAdapter.IPAddress | Where-Object {
                $_ -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$' -and 
                $_ -notmatch '^127\.' -and 
                $_ -notmatch '^169\.254\.' -and 
                $_ -notmatch '^100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.'
            })
            
            $prefIp = $validIps | Where-Object { $_ -like "$SubnetFilter*" } | Select-Object -First 1
            $PrimaryIp = if ($prefIp) { $prefIp } elseif ($validIps.Count -gt 0) { $validIps[0] } else { "" }

            foreach ($ip in $validIps) {
                if ($ActiveIpv4List -notcontains $ip) { $ActiveIpv4List += $ip }
            }

            if ($targetAdapter.DefaultIPGateway) { $GatewayAddress = $targetAdapter.DefaultIPGateway[0] }
            if ($targetAdapter.DNSServerSearchOrder) {
                foreach ($dns in $targetAdapter.DNSServerSearchOrder) {
                    if ($dns -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$' -and $DnsServerList -notcontains $dns) {
                        $DnsServerList += $dns
                    }
                }
            }
        }
    }
} catch {}

if ([string]::IsNullOrWhiteSpace($PrimaryIp)) { $PrimaryIp = "127.0.0.1" }
if ($ActiveIpv4List.Count -eq 0) { $ActiveIpv4List += $PrimaryIp }

$DnsServersJoined = $DnsServerList -join ", "

Write-Host " Host:     $Hostname" -ForegroundColor Gray
Write-Host " Uzytkownik: $LoggedUser" -ForegroundColor Cyan
$rebootColor = if ($PendingReboot) { 'Yellow' } else { 'Gray' }
Write-Host " Ostatni HotFix: $LastUpdateInfo (Restart: $(if ($PendingReboot) { 'WYMAGANY' } else { 'Nie' }))" -ForegroundColor $rebootColor
Write-Host " Domena:   $(if ($DomainName) { $DomainName } else { '(Grupa robocza)' })" -ForegroundColor Gray
Write-Host " System:   $OsName ($OsVersion)" -ForegroundColor Gray
Write-Host " IP LAN:   $PrimaryIp (Karta: $LanAdapterName)" -ForegroundColor Green
Write-Host " Brama:    $GatewayAddress" -ForegroundColor Gray
Write-Host " DNS:      $DnsServersJoined" -ForegroundColor Gray

# 4. Wykonywanie testów diagnostycznych
Write-Host "`n[2/3] Wykonywanie testow infrastruktury..." -ForegroundColor Yellow
$Tests = [System.Collections.Generic.List[PSObject]]::new()
$NowUtc = [DateTime]::UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ")

# --- A. Interfejs sieciowy ---
$sw = [System.Diagnostics.Stopwatch]::StartNew()
if ($PrimaryIp -ne "127.0.0.1" -and $ActiveIpv4List.Count -gt 0) {
    $sw.Stop()
    $Tests.Add([PSCustomObject]@{
        key         = "net_interface"
        name        = "Interfejs sieciowy LAN"
        status      = "OK"
        message     = "Aktywny interfejs fizyczny ($LanAdapterName, IP: $PrimaryIp)"
        duration_ms = [int]$sw.ElapsedMilliseconds
        checked_at  = $NowUtc
    })
} else {
    $sw.Stop()
    $Tests.Add([PSCustomObject]@{
        key         = "net_interface"
        name        = "Interfejs sieciowy LAN"
        status      = "ERROR"
        message     = "Brak aktywnego fizycznego interfejsu sieciowego w podsieci urzedu"
        duration_ms = [int]$sw.ElapsedMilliseconds
        checked_at  = $NowUtc
    })
}

# --- B. Brama domyślna ---
if ([string]::IsNullOrWhiteSpace($GatewayAddress)) {
    $Tests.Add([PSCustomObject]@{
        key         = "gateway"
        name        = "Brama domyslna"
        status      = "ERROR"
        message     = "Brak skonfigurowanej bramy domyslnej w podsieci LAN"
        duration_ms = $null
        checked_at  = $NowUtc
    })
} else {
    $gwPing = Test-IcmpPing -Address $GatewayAddress -TimeoutMs $PingTimeout
    if ($gwPing.Success) {
        $Tests.Add([PSCustomObject]@{
            key         = "gateway"
            name        = "Brama domyslna"
            status      = "OK"
            message     = "Brama $GatewayAddress odpowiada na ICMP (czas: $($gwPing.Rtt) ms)"
            duration_ms = $gwPing.Rtt
            checked_at  = $NowUtc
        })
    } else {
        $Tests.Add([PSCustomObject]@{
            key         = "gateway"
            name        = "Brama domyslna"
            status      = "WARNING"
            message     = "Brama $GatewayAddress nie odpowiada na ping ICMP ($($gwPing.Error))."
            duration_ms = $null
            checked_at  = $NowUtc
        })
    }
}

# --- C. Test DNS ---
$dnsTarget = if ($Config.Dns -and $Config.Dns.LookupHost) { $Config.Dns.LookupHost } else { "gov.pl" }
$sw = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $resolved = [System.Net.Dns]::GetHostAddresses($dnsTarget)
    $sw.Stop()
    if ($resolved -and $resolved.Count -gt 0) {
        $resolvedIps = ($resolved | Select-Object -ExpandProperty IPAddressToString) -join ", "
        $Tests.Add([PSCustomObject]@{
            key         = "dns"
            name        = "Serwer DNS (Rozwiazywanie nazw)"
            status      = "OK"
            message     = "Poprawnie rozwiazano ${dnsTarget} -> ${resolvedIps}"
            duration_ms = [int]$sw.ElapsedMilliseconds
            checked_at  = $NowUtc
        })
    } else {
        $Tests.Add([PSCustomObject]@{
            key         = "dns"
            name        = "Serwer DNS (Rozwiazywanie nazw)"
            status      = "ERROR"
            message     = "Serwer DNS nie zwrocil zadnych adresow IP dla ${dnsTarget}"
            duration_ms = [int]$sw.ElapsedMilliseconds
            checked_at  = $NowUtc
        })
    }
} catch {
    $sw.Stop()
    $Tests.Add([PSCustomObject]@{
        key         = "dns"
        name        = "Serwer DNS (Rozwiazywanie nazw)"
        status      = "ERROR"
        message     = "Nie udalo sie rozwiazac nazwy ${dnsTarget}: $($_.Exception.Message)"
        duration_ms = [int]$sw.ElapsedMilliseconds
        checked_at  = $NowUtc
    })
}

# --- D. Kontroler Domeny (Active Directory) - Auto-wykrywanie! ---
$dcHost = ""
if ($Config.DomainController -and -not [string]::IsNullOrWhiteSpace($Config.DomainController.Host)) {
    $dcHost = $Config.DomainController.Host
} elseif (-not [string]::IsNullOrWhiteSpace($env:LOGONSERVER)) {
    $dcHost = $env:LOGONSERVER.TrimStart('\')
} elseif (-not [string]::IsNullOrWhiteSpace($env:USERDNSDOMAIN)) {
    $dcHost = $env:USERDNSDOMAIN
}

if (-not [string]::IsNullOrWhiteSpace($dcHost) -and $dcHost -ne $Hostname) {
    $dcPort = 389
    $dcTcp = Test-TcpPort -HostName $dcHost -Port $dcPort -TimeoutMs $TcpTimeout
    if ($dcTcp.Success) {
        $Tests.Add([PSCustomObject]@{
            key         = "domain_controller"
            name        = "Kontroler Domeny (AD LDAP: $dcHost)"
            status      = "OK"
            message     = "Port LDAP 389 na kontrolerze ${dcHost} jest aktywny (${dcTcp.DurationMs} ms)"
            duration_ms = $dcTcp.DurationMs
            checked_at  = $NowUtc
        })
    } else {
        $dcPing = Test-IcmpPing -Address $dcHost -TimeoutMs $PingTimeout
        if ($dcPing.Success) {
            $Tests.Add([PSCustomObject]@{
                key         = "domain_controller"
                name        = "Kontroler Domeny (AD LDAP: $dcHost)"
                status      = "WARNING"
                message     = "Kontroler ${dcHost} odpowiada na ICMP (${dcPing.Rtt} ms), lecz port LDAP 389 nie odpowiedzial."
                duration_ms = $dcPing.Rtt
                checked_at  = $NowUtc
            })
        } else {
            $Tests.Add([PSCustomObject]@{
                key         = "domain_controller"
                name        = "Kontroler Domeny (AD LDAP: $dcHost)"
                status      = "ERROR"
                message     = "Brak lacznosci z kontrolerem domeny ${dcHost}"
                duration_ms = $null
                checked_at  = $NowUtc
            })
        }
    }
} else {
    $Tests.Add([PSCustomObject]@{
        key         = "domain_controller"
        name        = "Kontroler Domeny (AD LDAP)"
        status      = "SKIPPED"
        message     = "Stacja robocza nie nalezy do domeny lub brak aktywnego logonservera"
        duration_ms = $null
        checked_at  = $NowUtc
    })
}

# --- E. Łączność z Internetem (HTTPS 443) ---
$inetHost = "www.gov.pl"
$inetTcp = Test-TcpPort -HostName $inetHost -Port 443 -TimeoutMs $TcpTimeout
if ($inetTcp.Success) {
    $Tests.Add([PSCustomObject]@{
        key         = "internet"
        name        = "Lacznosc z Internetem (HTTPS)"
        status      = "OK"
        message     = "Polaczenie TCP do ${inetHost}:443 nawiazane pomyslnie (${inetTcp.DurationMs} ms)"
        duration_ms = $inetTcp.DurationMs
        checked_at  = $NowUtc
    })
} else {
    $Tests.Add([PSCustomObject]@{
        key         = "internet"
        name        = "Lacznosc z Internetem (HTTPS)"
        status      = "ERROR"
        message     = "Brak polaczenia TCP do ${inetHost}:443 ($($inetTcp.Error))"
        duration_ms = $inetTcp.DurationMs
        checked_at  = $NowUtc
    })
}

# --- F1. Drukarka Korytarzowa 1 (Ricoh IM C3010 - 192.168.15.60) ---
$ricohIp = "192.168.15.60"
$rTcp = Test-TcpPort -HostName $ricohIp -Port 9100 -TimeoutMs $TcpTimeout
if ($rTcp.Success) {
    $Tests.Add([PSCustomObject]@{
        key         = "printer_korytarz_ricoh"
        name        = "Korytarz: Ricoh ($ricohIp)"
        status      = "OK"
        message     = "Port druku 9100 na $ricohIp jest dostepny (${rTcp.DurationMs} ms)"
        duration_ms = $rTcp.DurationMs
        checked_at  = $NowUtc
    })
} else {
    $rPing = Test-IcmpPing -Address $ricohIp -TimeoutMs $PingTimeout
    $rStatus = if ($rPing.Success) { "WARNING" } else { "ERROR" }
    $rMsg = if ($rPing.Success) { "Odpowiada na ping, port 9100 zamkniety" } else { "Brak odpowiedzi w sieci" }
    $Tests.Add([PSCustomObject]@{
        key         = "printer_korytarz_ricoh"
        name        = "Korytarz: Ricoh ($ricohIp)"
        status      = $rStatus
        message     = $rMsg
        duration_ms = $rPing.Rtt
        checked_at  = $NowUtc
    })
}

# --- F2. Drukarka Korytarzowa 2 (Konica Minolta C257i - 192.168.15.50) ---
$konicaIp = "192.168.15.50"
$kTcp = Test-TcpPort -HostName $konicaIp -Port 9100 -TimeoutMs $TcpTimeout
if ($kTcp.Success) {
    $Tests.Add([PSCustomObject]@{
        key         = "printer_korytarz_konica"
        name        = "Korytarz: Konica ($konicaIp)"
        status      = "OK"
        message     = "Port druku 9100 na $konicaIp jest dostepny (${kTcp.DurationMs} ms)"
        duration_ms = $kTcp.DurationMs
        checked_at  = $NowUtc
    })
} else {
    $kPing = Test-IcmpPing -Address $konicaIp -TimeoutMs $PingTimeout
    $kStatus = if ($kPing.Success) { "WARNING" } else { "ERROR" }
    $kMsg = if ($kPing.Success) { "Odpowiada na ping, port 9100 zamkniety" } else { "Brak odpowiedzi w sieci" }
    $Tests.Add([PSCustomObject]@{
        key         = "printer_korytarz_konica"
        name        = "Korytarz: Konica ($konicaIp)"
        status      = $kStatus
        message     = $kMsg
        duration_ms = $kPing.Rtt
        checked_at  = $NowUtc
    })
}

# --- F3. Drukarka w pokoju (Automatycznie wykrywana w podsieciach 15.x / 115.x lub kolejka) ---
$roomPrnDetected = $false
try {
    $installedPrinters = Get-CimInstance Win32_Printer -ErrorAction SilentlyContinue
    
    # Szukaj domyślnej drukarki lub sieciowej, która nie jest żadną z korytarzowych (15.50 / 15.60)
    $candidatePrinters = $installedPrinters | Where-Object { 
        $_.Name -notmatch 'PDF|OneNote|XPS|Fax'
    }

    $roomPrn = $candidatePrinters | Where-Object { 
        $search = "$($_.PortName) $($_.Name) $($_.DeviceID)"
        # Filtrujemy drukarki pokojowe w podsieci 15.x lub 115.x, wykluczając korytarzowe 15.50 i 15.60
        $search -match '192\.168\.(?:15|115)\.(?!50\b|60\b)\d+'
    } | Select-Object -First 1

    # Jeśli nie ma specyficznej w 15.x/115.x, sprawdź domyślną użytkownika (inną niż 15.50/15.60)
    if (-not $roomPrn) {
        $roomPrn = $candidatePrinters | Where-Object { 
            $_.Default -eq $true -and $_.PortName -notmatch '15\.50|15\.60' -and $_.Name -notmatch 'RICOH IM C3010|C257i'
        } | Select-Object -First 1
    }

    if ($roomPrn) {
        $roomPrnName = $roomPrn.Name.TrimStart('\')
        $searchStr = "$($roomPrn.PortName) $($roomPrn.Name) $($roomPrn.DeviceID)"
        $roomIp = ""
        if ($searchStr -match '(?:\d{1,3}\.){3}\d{1,3}') {
            $roomIp = $matches[0]
        }

        if (-not [string]::IsNullOrWhiteSpace($roomIp)) {
            $roomPrnDetected = $true
            $pTcp = Test-TcpPort -HostName $roomIp -Port 9100 -TimeoutMs $TcpTimeout
            if ($pTcp.Success) {
                $Tests.Add([PSCustomObject]@{
                    key         = "printer_room"
                    name        = "Drukarka pokoj ($roomIp)"
                    status      = "OK"
                    message     = "Drukarka pokojowa $roomPrnName ($roomIp) aktywna (${pTcp.DurationMs} ms)"
                    duration_ms = $pTcp.DurationMs
                    checked_at  = $NowUtc
                })
            } else {
                $pPing = Test-IcmpPing -Address $roomIp -TimeoutMs $PingTimeout
                $pStatus = if ($pPing.Success) { "OK" } else { "ERROR" }
                $pMsg = if ($pPing.Success) { "Drukarka $roomIp odpowiada na ICMP" } else { "Brak odpowiedzi od drukarki $roomIp (wylaczona/brak sieci)" }
                $Tests.Add([PSCustomObject]@{
                    key         = "printer_room"
                    name        = "Drukarka pokoj ($roomIp)"
                    status      = $pStatus
                    message     = $pMsg
                    duration_ms = $pPing.Rtt
                    checked_at  = $NowUtc
                })
            }
        }
    }
} catch {}

if (-not $roomPrnDetected) {
    $Tests.Add([PSCustomObject]@{
        key         = "printer_room"
        name        = "Drukarka w pokoju"
        status      = "SKIPPED"
        message     = "Brak dodatkowej drukarki pokojowej w podsieci 15/115 na tej stacji"
        duration_ms = $null
        checked_at  = $NowUtc
    })
}

# --- H. Aktualizacje Windows (Windows Update & Pending Reboot) ---
if ($PendingReboot) {
    $Tests.Add([PSCustomObject]@{
        key         = "windows_updates"
        name        = "Aktualizacje Windows"
        status      = "WARNING"
        message     = "Wymagany restart stacji po instalacji aktualizacji (Ostatnia: $LastUpdateInfo)"
        duration_ms = $null
        checked_at  = $NowUtc
    })
} elseif ($DaysSinceUpdate -gt 60 -and $DaysSinceUpdate -lt 1000) {
    $Tests.Add([PSCustomObject]@{
        key         = "windows_updates"
        name        = "Aktualizacje Windows"
        status      = "WARNING"
        message     = "Brak nowych aktualizacji od $DaysSinceUpdate dni (Ostatnia: $LastUpdateInfo)"
        duration_ms = $null
        checked_at  = $NowUtc
    })
} else {
    $Tests.Add([PSCustomObject]@{
        key         = "windows_updates"
        name        = "Aktualizacje Windows"
        status      = "OK"
        message     = "System zaktualizowany (Ostatnia: $LastUpdateInfo)"
        duration_ms = $null
        checked_at  = $NowUtc
    })
}

# --- G. Serwer plików / NAS ---
$nasHost = if ($Config.Nas -and $Config.Nas.Host) { $Config.Nas.Host } else { "VM-AD1" }
if (-not [string]::IsNullOrWhiteSpace($nasHost)) {
    $nasTcp = Test-TcpPort -HostName $nasHost -Port 445 -TimeoutMs $TcpTimeout
    if ($nasTcp.Success) {
        $Tests.Add([PSCustomObject]@{
            key         = "nas"
            name        = "Serwer plikow SMB ($nasHost)"
            status      = "OK"
            message     = "Port SMB 445 ($nasHost) jest aktywny (${nasTcp.DurationMs} ms)"
            duration_ms = $nasTcp.DurationMs
            checked_at  = $NowUtc
        })
    } else {
        $Tests.Add([PSCustomObject]@{
            key         = "nas"
            name        = "Serwer plikow SMB ($nasHost)"
            status      = "ERROR"
            message     = "Brak dostepu do uslugi plikow na serwerze $nasHost"
            duration_ms = $null
            checked_at  = $NowUtc
        })
    }
} else {
    $Tests.Add([PSCustomObject]@{
        key         = "nas"
        name        = "Serwer plikow SMB"
        status      = "SKIPPED"
        message     = "Brak skonfigurowanego serwera plikow"
        duration_ms = $null
        checked_at  = $NowUtc
    })
}

# Tabela wyników w konsoli
Write-Host ""
$fmt = "{0,-34} | {1,-9} | {2,-8} | {3}"
Write-Host ($fmt -f "NAZWA TESTU", "STATUS", "CZAS", "KOMUNIKAT") -ForegroundColor Cyan
Write-Host ("-" * 85) -ForegroundColor DarkGray

foreach ($t in $Tests) {
    $dur = if ($t.duration_ms) { "$($t.duration_ms) ms" } else { "-" }
    $color = switch ($t.status) {
        "OK"      { "Green" }
        "WARNING" { "Yellow" }
        "ERROR"   { "Red" }
        default   { "DarkGray" }
    }
    Write-Host ($fmt -f $t.name, $t.status, $dur, $t.message) -ForegroundColor $color
}
Write-Host ("-" * 85) -ForegroundColor DarkGray

# 5. Przygotowanie raportu i wysyłanie do API
Write-Host "`n[3/3] Wysylanie raportu do API: $ApiUrl..." -ForegroundColor Yellow

$PayloadObj = @{
    agent_version = $AgentVer
    timestamp     = $NowUtc
    token         = $DeviceToken
    computer      = @{
        hostname          = $Hostname
        domain            = $DomainName
        os_name           = $OsName
        os_version        = $OsVersion
        ip_address        = $PrimaryIp
        gateway           = $GatewayAddress
        dns_servers       = $DnsServersJoined
        logged_user       = $LoggedUser
        room_number       = $Config.RoomNumber
        updates_status    = if ($PendingReboot) { "PENDING_REBOOT" } else { "OK" }
        last_update_date  = $LastUpdateInfo
        pending_reboot    = if ($PendingReboot) { 1 } else { 0 }
        office_version    = $OfficeVersion
        serial_number      = $ComputerSerialNumber
        monitors           = $MonitorsList
        antivirus          = $AntivirusInfo
        installed_software = $InstalledSoftwareList
    }
    tests         = $Tests
}

$JsonPayload = $PayloadObj | ConvertTo-Json -Depth 5 -Compress
$JsonBytes = [System.Text.Encoding]::UTF8.GetBytes($JsonPayload)

$Headers = @{
    "X-Device-Token" = $DeviceToken
}

try {
    $response = Invoke-RestMethod -Uri $ApiUrl `
        -Method Post `
        -Headers $Headers `
        -ContentType "application/json; charset=utf-8" `
        -Body $JsonBytes `
        -TimeoutSec $HttpTimeout `
        -ErrorAction Stop

    if ($response -and $response.success) {
        Write-Host "`n[SUKCES] Raport przyjety przez API Gmina IT Monitor!" -ForegroundColor Green
        Write-Host " Host:               $Hostname ($PrimaryIp)" -ForegroundColor Gray
        Write-Host " Numer seryjny PC:   $ComputerSerialNumber" -ForegroundColor Gray
        if ($MonitorsList.Count -gt 0) {
            $monStr = ($MonitorsList | ForEach-Object { "$($_.Model) (SN: $($_.SerialNumber))" }) -join ", "
            Write-Host " Monitory:           $monStr" -ForegroundColor Gray
        }
        Write-Host " Antywirus:          $AntivirusInfo" -ForegroundColor Gray
        Write-Host " Oprogramowanie:     $($InstalledSoftwareList.Count) aplikacji" -ForegroundColor Gray
        Write-Host " Przetworzone testy: $($response.data.processed_checks)" -ForegroundColor Gray
        $errColor = if ($response.data.errors -gt 0) { "Red" } else { "Green" }
        $warnColor = if ($response.data.warnings -gt 0) { "Yellow" } else { "Green" }
        Write-Host " Wykryte bledy:      $($response.data.errors)" -ForegroundColor $errColor
        Write-Host " Ostrzezenia:        $($response.data.warnings)" -ForegroundColor $warnColor
        Write-Host " Ogolny stan:        $($response.data.overall_status)" -ForegroundColor Cyan
        exit 0
    } else {
        $errMsg = if ($response.error) { $response.error } else { "Nieznany blad serwera API" }
        Write-Host "`n[BLAD API] $errMsg" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "`n[BLAD POLACZENIA] Nie udalo sie wyslac raportu: $_" -ForegroundColor Red
    exit 1
}
