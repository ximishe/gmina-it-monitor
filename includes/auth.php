<?php
/**
 * Gmina IT Monitor - Moduł uwierzytelniania agentów
 */

require_once __DIR__ . '/functions.php';

/**
 * Pobiera token z nagłówków żądania HTTP lub z ciała żądania
 */
function extractRequestToken(): ?string {
    if (!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) {
        return trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $val) {
            $lowerKey = strtolower($key);
            if ($lowerKey === 'x-device-token') {
                return trim($val);
            }
            if ($lowerKey === 'authorization' && preg_match('/Bearer\s+(\S+)/i', $val, $matches)) {
                return trim($matches[1]);
            }
        }
    }

    return null;
}

/**
 * Weryfikuje token urządzenia w bazie danych.
 * Jeśli token jest poprawny, ale komputer o podanym hostname jeszcze nie istnieje (nowa stacja z GPO),
 * automatycznie rejestruje stację w bazie (Auto-Provisioning dla domeny Active Directory).
 */
function authenticateDevice(PDO $pdo, ?string $token, ?string $reportingHostname = null): ?array {
    if (empty($token) || strlen($token) < 8) {
        return null;
    }

    $tokenHash = hash('sha256', $token);

    // Sprawdź czy token jest zarejestrowany i aktywny
    $stmtToken = $pdo->prepare("
        SELECT id, device_id, name, revoked_at 
        FROM device_tokens 
        WHERE token_hash = :hash AND revoked_at IS NULL 
        LIMIT 1
    ");
    $stmtToken->execute([':hash' => $tokenHash]);
    $tokenRow = $stmtToken->fetch();

    if (!$tokenRow) {
        return null;
    }

    // Jeśli podano konkretną nazwę komputera wysyłającego raport
    if (!empty($reportingHostname)) {
        $cleanHost = sanitizeString($reportingHostname, 128);

        // Sprawdź czy stacja o tej nazwie już istnieje w bazie
        $stmtDev = $pdo->prepare("
            SELECT id AS device_id, device_uuid, hostname, is_active, last_seen 
            FROM devices 
            WHERE UPPER(hostname) = UPPER(:host) AND is_active = 1 
            LIMIT 1
        ");
        $stmtDev->execute([':host' => $cleanHost]);
        $existingDevice = $stmtDev->fetch();

        if ($existingDevice) {
            return array_merge($existingDevice, [
                'token_id' => $tokenRow['id'],
                'token_name' => $tokenRow['name']
            ]);
        }

        // Jeśli to zupełnie nowa stacja z domeny (Active Directory Auto-Provisioning)
        $newUuid = 'dev-' . strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $cleanHost)) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $insertStmt = $pdo->prepare("
            INSERT INTO devices (device_uuid, hostname, os_name, overall_status, is_active, first_seen, last_seen)
            VALUES (:uuid, :host, 'Nowa stacja z AD', 'UNKNOWN', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insertStmt->execute([
            ':uuid' => $newUuid,
            ':host' => $cleanHost
        ]);
        $newDevId = $pdo->lastInsertId();

        return [
            'device_id' => (int)$newDevId,
            'device_uuid' => $newUuid,
            'hostname' => $cleanHost,
            'is_active' => 1,
            'last_seen' => null,
            'token_id' => $tokenRow['id'],
            'token_name' => $tokenRow['name']
        ];
    }

    // Domyślne powiązanie z przypisanym urządzeniem
    $stmtFallback = $pdo->prepare("
        SELECT id AS device_id, device_uuid, hostname, is_active, last_seen 
        FROM devices 
        WHERE id = :dev_id AND is_active = 1
        LIMIT 1
    ");
    $stmtFallback->execute([':dev_id' => $tokenRow['device_id']]);
    $dev = $stmtFallback->fetch();

    return $dev ? array_merge($dev, ['token_id' => $tokenRow['id'], 'token_name' => $tokenRow['name']]) : null;
}