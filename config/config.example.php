<?php
/**
 * Gmina IT Monitor - Plik konfiguracyjny (Przykładowy)
 * Skopiuj jako config.php i dostosuj do swojego środowiska.
 */

return [
    // Baza danych: 'sqlite' (zalecana i domyślna) lub 'mysql'
    'db' => [
        'driver' => 'sqlite', // 'sqlite' lub 'mysql'
        
        // Ustawienia dla SQLite
        'sqlite_path' => __DIR__ . '/../database/it_monitor.sqlite',
        
        // Ustawienia dla MySQL (opcjonalne w XAMPP / LAMP)
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'it_monitor',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => '',
    ],

    // Ustawienia aplikacji
    'app' => [
        'title' => 'Gmina IT Monitor',
        'subtitle' => 'Lokalny monitoring infrastruktury Windows',
        'timezone' => 'Europe/Warsaw',
        'offline_threshold_minutes' => 15, // Powyżej tego czasu status = OFFLINE
        'max_payload_bytes' => 524288,     // 512 KB limit na raport JSON
        'auto_migrate' => true,             // Automatyczne tworzenie tabel SQLite przy braku bazy
    ],

    // Aktualizacje z GitHuba
    'github' => [
        'branch' => 'main',
        'allow_web_update' => true,        // Zezwolenie na aktualizację kodu przez panel WWW
    ],

    // Domyślny token instalacyjny dla komputerów
    'security' => [
        'default_admin_token' => 'TUTAJ_WPISZ_WLASNY_LOSOWY_TAJNY_TOKEN',
    ]
];

