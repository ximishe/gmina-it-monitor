<?php
/**
 * Gmina IT Monitor - Inicjalizacja połączenia PDO
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
            initSqliteDatabase($pdo, $config);
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
    } else {
        throw new InvalidArgumentException("Nieobsługiwany sterownik bazy danych: {$driver}");
    }

    return $pdo;
}

/**
 * Automatyczna inicjalizacja tabel i początkowego urządzenia w SQLite
 */
function initSqliteDatabase(PDO $pdo, array $config): void {
    // Sprawdź czy tabela devices istnieje
    $tableExists = false;
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

