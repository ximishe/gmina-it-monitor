<?php
/**
 * Gmina IT Monitor - Inicjalizacja połączenia PDO i Auto-Migracje
 */

function getAppConfig(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/../config/config.php';
    if (!file_exists($configFile)) {
        $configFile = __DIR__ . '/../config/config.example.php';
    }

    if (!file_exists($configFile)) {
        throw new RuntimeException('Brak pliku konfiguracyjnego aplikacji.');
    }

    $config = require $configFile;
    
    // Ustawienie strefy czasowej
    if (!empty($config['app']['timezone'])) {
        date_default_timezone_set($config['app']['timezone']);
    }

    return $config;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = getAppConfig();
    $dbConfig = $config['db'] ?? [];
    $driver = strtolower($dbConfig['driver'] ?? 'sqlite');

    if ($driver === 'sqlite') {
        $dbPath = $dbConfig['sqlite_path'] ?? (__DIR__ . '/../database/it_monitor.sqlite');
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $isNewDb = !file_exists($dbPath) || filesize($dbPath) === 0;

        $dsn = 'sqlite:' . $dbPath;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Włączenie obsługi kluczy obcych i trybu WAL dla wydajności w SQLite
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');

        if ($isNewDb || !empty($config['app']['auto_migrate'])) {
            initDatabase($pdo, 'sqlite', $config);
        }
    } elseif ($driver === 'mysql') {
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 3306;
        $dbname = $dbConfig['dbname'] ?? 'it_monitor';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (!empty($config['app']['auto_migrate'])) {
            initDatabase($pdo, 'mysql', $config);
        }
    } else {
        throw new InvalidArgumentException("Nieobsługiwany sterownik bazy danych: {$driver}");
    }

    return $pdo;
}

/**
 * Automatyczna inicjalizacja tabel i początkowego urządzenia w SQLite / MySQL
 */
function initDatabase(PDO $pdo, string $driver, array $config): void {
    $tableExists = false;

    if ($driver === 'sqlite') {
        $res = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='devices'");
        if ($res && $res->fetch()) {
            $tableExists = true;
        }

        if (!$tableExists) {
            $schemaFile = __DIR__ . '/../database/schema.sqlite.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        }
    } else {
        $res = $pdo->query("SHOW TABLES LIKE 'devices'");
        if ($res && $res->fetch()) {
            $tableExists = true;
        }

        if (!$tableExists) {
            $schemaFile = __DIR__ . '/../database/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        }
    }

    // Zapewnienie tabeli migracji i wykonanie oczekujących migracji
    runMigrations($pdo, $driver);

    // Sprawdź czy istnieje zarejestrowany token
    $count = $pdo->query("SELECT COUNT(*) FROM device_tokens")->fetchColumn();
    if ($count == 0) {
        $defaultToken = $config['security']['default_admin_token'] ?? 'gmina-it-adm-token-2026-secret';
        $tokenHash = hash('sha256', $defaultToken);
        $tokenHint = substr($defaultToken, 0, 4) . '...' . substr($defaultToken, -4);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO devices (device_uuid, hostname, os_name, overall_status, is_active, first_seen)
                VALUES (:uuid, :hostname, :os_name, 'UNKNOWN', 1, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':uuid' => 'dev-admin-primary',
                ':hostname' => 'STACJA-ADMINISTRATORA',
                ':os_name' => 'Windows (oczekuje na pierwszy raport)'
            ]);
            $deviceId = $pdo->lastInsertId();

            $stmtToken = $pdo->prepare("
                INSERT INTO device_tokens (device_id, token_hash, token_hint, name)
                VALUES (:device_id, :token_hash, :token_hint, 'Stacja Administratora')
            ");
            $stmtToken->execute([
                ':device_id' => $deviceId,
                ':token_hash' => $tokenHash,
                ':token_hint' => $tokenHint
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("Błąd inicjalizacji bazy: " . $e->getMessage());
        }
    }
}

/**
 * Automatyczny mechanizm migracji bazy danych (SQLite / MySQL)
 * Pozwala rozbudowywać bazę przy kolejnych aktualizacjach projektu.
 */
function runMigrations(PDO $pdo, string $driver): array {
    // 1. Zapewnij tabelę rejestru migracji
    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL UNIQUE,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `version` VARCHAR(128) NOT NULL UNIQUE,
                `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 2. Pobierz już zaaplikowane migracje
    $stmt = $pdo->query("SELECT version FROM schema_migrations");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $appliedMap = array_flip($applied);

    $migrationsDir = __DIR__ . '/../database/migrations';
    if (!is_dir($migrationsDir)) {
        return [];
    }

    $files = scandir($migrationsDir);
    if (!$files) {
        return [];
    }

    sort($files, SORT_NATURAL);
    $executed = [];

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || strtolower($file) === 'readme.md') {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'sql' && $ext !== 'php') {
            continue;
        }

        // Filtrowanie dedykowanych sterowników np. 002_xxx.sqlite.sql lub 002_xxx.mysql.sql
        if (preg_match('/\.sqlite\.sql$/i', $file) && $driver !== 'sqlite') {
            continue;
        }
        if (preg_match('/\.mysql\.sql$/i', $file) && $driver !== 'mysql') {
            continue;
        }

        if (isset($appliedMap[$file])) {
            continue;
        }

        $filePath = $migrationsDir . DIRECTORY_SEPARATOR . $file;

        $pdo->beginTransaction();
        try {
            if ($ext === 'sql') {
                $sql = file_get_contents($filePath);
                if (trim($sql) !== '') {
                    $pdo->exec($sql);
                }
            } elseif ($ext === 'php') {
                $runner = require $filePath;
                if (is_callable($runner)) {
                    $runner($pdo, $driver);
                }
            }

            $stmtIns = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (:ver, CURRENT_TIMESTAMP)");
            $stmtIns->execute([':ver' => $file]);

            $pdo->commit();
            $appliedMap[$file] = true;
            $executed[] = $file;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("Błąd migracji bazy ({$file}): " . $e->getMessage());
            throw new RuntimeException("Błąd migracji bazy danych ({$file}): " . $e->getMessage(), 0, $e);
        }
    }

    return $executed;
}

/**
 * Pomocnik migracji sprawdzający czy kolumna istnieje w tabeli
 */
function dbColumnExists(PDO $pdo, string $table, string $column, string $driver = 'sqlite'): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info(" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . ")");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $c) {
            if (strcasecmp($c['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    } else {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "` LIKE :col");
        $stmt->execute([':col' => $column]);
        return (bool)$stmt->fetch();
    }
}
