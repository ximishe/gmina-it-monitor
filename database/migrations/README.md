# Automatyczne migracje bazy danych (Auto-Migrations)

System automatycznie wykrywa i aplikuje nowe pliki migracji przy:
1. Uruchomieniu aplikacji (`getDb()`) jeśli `auto_migrate` = true (domyślnie).
2. Wykonaniu aktualizacji przez panel WWW (`api/system_update.php`).
3. Zwykłym `git pull` na serwerze Linux.

## Jak dodać zmianę w bazie danych przy rozwijaniu projektu?

Wystarczy dodać nowy plik do tego katalogu z numerem w nazwie, np.:

### Przykład 1: Prosty plik SQL (`002_dodaj_ram_i_dysk.sql`)
```sql
-- Dodanie nowych kolumn do tabeli devices
ALTER TABLE devices ADD COLUMN ram_gb INTEGER DEFAULT NULL;
ALTER TABLE devices ADD COLUMN disk_free_gb INTEGER DEFAULT NULL;
```

### Przykład 2: Bezpieczny skrypt PHP (`003_zaawansowana_migracja.php`)
```php
<?php
return function(PDO $pdo, string $driver) {
    if (!dbColumnExists($pdo, 'devices', 'ip_external', $driver)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN ip_external TEXT DEFAULT NULL");
    }
};
```

Historia zaaplikowanych wersji jest trwale zapisywana w tabeli `schema_migrations`.
Żadna migracja nie zostanie wykonana powtórnie, a istniejące dane w bazie SQLite na maszynie produkcyjnej pozostaną nienaruszone.
