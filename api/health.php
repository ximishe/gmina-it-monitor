<?php
/**
 * Gmina IT Monitor - API Endpoint: Health
 * Zwraca stan działania API i połączenia z bazą danych
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $config = getAppConfig();
    $pdo = getDb();
    
    // Prosty test zapytania bazodanowego
    $stmt = $pdo->query("SELECT 1");
    $dbOk = $stmt && $stmt->fetchColumn() == 1;

    jsonResponse([
        'success' => true,
        'status' => 'OK',
        'api' => 'Gmina IT Monitor API',
        'version' => '1.0.0',
        'database' => [
            'connected' => $dbOk,
            'driver' => $config['db']['driver'] ?? 'sqlite'
        ],
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'status' => 'DEGRADED',
        'database' => [
            'connected' => false,
            'error' => 'Brak połączenia z bazą danych'
        ],
        'server_time' => date('Y-m-d H:i:s')
    ], 503);
}
