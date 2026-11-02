@{
    # Adres API w lokalnym serwerze (zmien na wlasny IP lub domene serwera monitoringu)
    ApiUrl = "http://192.168.1.100/it-monitor/api/heartbeat.php"

    # Token przypisany do puli komputerow urzedu (wygeneruj wlasny unikalny token)
    DeviceToken = "TUTAJ_WPISZ_WLASNY_TAJNY_TOKEN"

    # Filtr preferowanej podsieci fizycznej urzedu
    SubnetFilter = "192.168."

    # Wersja agenta
    AgentVersion = "1.1.0"

    # Timeouty sieciowe (ms)
    PingTimeoutMs = 1500
    TcpTimeoutMs = 2000
    HttpTimeoutMs = 5000

    # Test DNS
    Dns = @{
        Enabled = $true
        LookupHost = "gov.pl"
    }

    # Kontroler Domeny Active Directory
    DomainController = @{
        Enabled = $true
        Host = "dc01.twojadomena.local"
        Port = 389
    }

    # Drukarka Glowna (Korytarzowa)
    MainPrinter = @{
        Enabled = $true
        Name = "Drukarka Glowna"
        Ip = "192.168.1.50"
        Port = 9100
    }

    # Serwer plikow / NAS / Zasoby AD
    Nas = @{
        Enabled = $true
        Host = "nas.twojadomena.local"
        Port = 445
    }
}
