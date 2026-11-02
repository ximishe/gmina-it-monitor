<?php
/**
 * Gmina IT Monitor - Funkcje pomocnicze
 */

/**
 * Wysyła odpowiedź JSON i kończy skrypt
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Wysyła błąd JSON
 */
function jsonError(string $message, int $statusCode = 400, array $extra = []): void {
    jsonResponse(array_merge([
        'success' => false,
        'error' => $message,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ], $extra), $statusCode);
}

/**
 * Oczyszcza ciąg tekstowy
 */
function sanitizeString(?string $input, int $maxLength = 255): string {
    if ($input === null) {
        return '';
    }
    $trimmed = trim($input);
    $cleaned = strip_tags($trimmed);
    return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
}

/**
 * Oblicza status urządzenia: ONLINE, OFFLINE lub UNKNOWN
 */
function computeOnlineStatus(?string $lastSeen, int $thresholdMinutes = 15): string {
    if (empty($lastSeen)) {
        return 'UNKNOWN';
    }

    $lastSeenTs = strtotime($lastSeen);
    if ($lastSeenTs === false) {
        return 'UNKNOWN';
    }

    $diffSeconds = time() - $lastSeenTs;
    if ($diffSeconds <= ($thresholdMinutes * 60)) {
        return 'ONLINE';
    }

    return 'OFFLINE';
}

/**
 * Formatuje czas względny po polsku (np. "3 minuty temu")
 */
function formatRelativeTime(?string $datetime): string {
    if (empty($datetime)) {
        return 'Nigdy';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'Nieznany';
    }

    $diff = time() - $timestamp;

    if ($diff < 10) {
        return 'przed chwilą';
    }
    if ($diff < 60) {
        return $diff . ' s temu';
    }
    if ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min temu';
    }
    if ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' godz. temu';
    }
    $days = floor($diff / 86400);
    return $days . ' dni temu';
}

/**
 * Przetwarza raport heartbeat i aktualizuje incydenty
 */
function processHeartbeat(PDO $pdo, array $device, array $payload): array {
    $computer = $payload['computer'] ?? [];
    $tests = $payload['tests'] ?? [];
    $agentVersion = sanitizeString($payload['agent_version'] ?? '1.0.0', 32);
    $reportTime = !empty($payload['timestamp']) ? date('Y-m-d H:i:s', strtotime($payload['timestamp'])) : date('Y-m-d H:i:s');

    // Walidacja danych komputera
    $hostname = sanitizeString($computer['hostname'] ?? $device['hostname'], 128);
    $osName = sanitizeString($computer['os_name'] ?? '', 128);
    $osVersion = sanitizeString($computer['os_version'] ?? '', 64);
    $ipAddress = sanitizeString($computer['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 64);
    $gateway = sanitizeString($computer['gateway'] ?? '', 64);
    $dnsServers = sanitizeString($computer['dns_servers'] ?? '', 255);

    if (empty($hostname)) {
        throw new InvalidArgumentException('Brak poprawnej nazwy hosta komputera.');
    }

    if (!is_array($tests) || empty($tests)) {
        throw new InvalidArgumentException('Raport nie zawiera listy testów (tests).');
    }

    $deviceId = (int)$device['device_id'];

    $pdo->beginTransaction();
    try {
        $errorCount = 0;
        $warningCount = 0;
        $processedChecks = 0;

        $stmtFindLatestCheck = $pdo->prepare("
            SELECT id, status FROM check_results 
            WHERE device_id = :device_id AND check_key = :check_key 
            ORDER BY checked_at DESC, id DESC 
            LIMIT 1
        ");

        $stmtInsertCheck = $pdo->prepare("
            INSERT INTO check_results 
            (device_id, check_key, check_name, status, message, duration_ms, checked_at, created_at)
            VALUES 
            (:device_id, :check_key, :check_name, :status, :message, :duration_ms, :checked_at, CURRENT_TIMESTAMP)
        ");

        $stmtUpdateCheck = $pdo->prepare("
            UPDATE check_results 
            SET check_name = :check_name,
                status = :status,
                message = :message,
                duration_ms = :duration_ms,
                checked_at = :checked_at
            WHERE id = :id
        ");

        $stmtFindOpenIncident = $pdo->prepare("
            SELECT id FROM incidents 
            WHERE device_id = :device_id AND check_key = :check_key AND status = 'OPEN'
            LIMIT 1
        ");

        $stmtOpenIncident = $pdo->prepare("
            INSERT INTO incidents 
            (device_id, check_key, check_name, severity, title, description, opened_at, status)
            VALUES 
            (:device_id, :check_key, :check_name, :severity, :title, :description, :opened_at, 'OPEN')
        ");

        $stmtResolveIncident = $pdo->prepare("
            UPDATE incidents 
            SET status = 'RESOLVED', resolved_at = :resolved_at 
            WHERE device_id = :device_id AND check_key = :check_key AND status = 'OPEN'
        ");

        foreach ($tests as $t) {
            $key = sanitizeString($t['key'] ?? '', 64);
            $name = sanitizeString($t['name'] ?? $key, 128);
            $status = strtoupper(trim($t['status'] ?? 'UNKNOWN'));
            $message = sanitizeString($t['message'] ?? '', 1000);
            $durationMs = isset($t['duration_ms']) && is_numeric($t['duration_ms']) ? (int)$t['duration_ms'] : null;
            $checkedAt = !empty($t['checked_at']) ? date('Y-m-d H:i:s', strtotime($t['checked_at'])) : $reportTime;

            if (empty($key)) {
                continue;
            }

            if (!in_array($status, ['OK', 'ERROR', 'WARNING', 'SKIPPED', 'UNKNOWN'])) {
                $status = 'UNKNOWN';
            }

            if ($status === 'ERROR') {
                $errorCount++;
            } elseif ($status === 'WARNING') {
                $warningCount++;
            }

            // Pobierz poprzedni/najnowszy stan testu
            $stmtFindLatestCheck->execute([
                ':device_id' => $deviceId,
                ':check_key' => $key
            ]);
            $latestRow = $stmtFindLatestCheck->fetch();
            $prevStatus = $latestRow ? $latestRow['status'] : null;

            // Zapisujemy nowy rekord do historii tylko dla ostrzeżeń i błędów (anomalii),
            // lub gdy stan testu zmienia się z problemu na inny.
            // Dla powtarzających się pomyślnych testów (OK / SKIPPED) aktualizujemy istniejący wpis stanu bieżącego,
            // zapobiegając rozrastaniu się bazy o tysiące zbędnych rekordów OK.
            $isAnomaly = in_array($status, ['WARNING', 'ERROR']);
            $isStatusChangeFromProblem = ($prevStatus && in_array($prevStatus, ['WARNING', 'ERROR']) && $status !== $prevStatus);

            if ($isAnomaly || $isStatusChangeFromProblem) {
                $stmtInsertCheck->execute([
                    ':device_id' => $deviceId,
                    ':check_key' => $key,
                    ':check_name' => $name,
                    ':status' => $status,
                    ':message' => $message,
                    ':duration_ms' => $durationMs,
                    ':checked_at' => $checkedAt
                ]);
            } else {
                if ($latestRow) {
                    $stmtUpdateCheck->execute([
                        ':id' => $latestRow['id'],
                        ':check_name' => $name,
                        ':status' => $status,
                        ':message' => $message,
                        ':duration_ms' => $durationMs,
                        ':checked_at' => $checkedAt
                    ]);
                } else {
                    $stmtInsertCheck->execute([
                        ':device_id' => $deviceId,
                        ':check_key' => $key,
                        ':check_name' => $name,
                        ':status' => $status,
                        ':message' => $message,
                        ':duration_ms' => $durationMs,
                        ':checked_at' => $checkedAt
                    ]);
                }
            }

            // Zarządzanie incydentami
            if (in_array($status, ['ERROR', 'WARNING'])) {
                $stmtFindOpenIncident->execute([
                    ':device_id' => $deviceId,
                    ':check_key' => $key
                ]);
                $openInc = $stmtFindOpenIncident->fetch();

                if (!$openInc) {
                    $severity = ($status === 'ERROR') ? 'ERROR' : 'WARNING';
                    $title = "Awarie testu: {$name}";
                    $desc = !empty($message) ? $message : "Wykryto stan {$status} podczas monitorowania.";

                    $stmtOpenIncident->execute([
                        ':device_id' => $deviceId,
                        ':check_key' => $key,
                        ':check_name' => $name,
                        ':severity' => $severity,
                        ':title' => $title,
                        ':description' => $desc,
                        ':opened_at' => $checkedAt
                    ]);
                }
            } elseif ($status === 'OK') {
                $stmtResolveIncident->execute([
                    ':resolved_at' => $checkedAt,
                    ':device_id' => $deviceId,
                    ':check_key' => $key
                ]);
            }

            $processedChecks++;
        }

        $overallHealth = 'OK';
        if ($errorCount > 0) {
            $overallHealth = 'ERROR';
        } elseif ($warningCount > 0) {
            $overallHealth = 'WARNING';
        }

        $loggedUser = sanitizeString($computer['logged_user'] ?? '', 128);
        $roomNumber = !empty($computer['room_number']) ? sanitizeString($computer['room_number'], 64) : null;
        $updatesStatus = sanitizeString($computer['updates_status'] ?? 'OK', 32);
        $lastUpdateDate = sanitizeString($computer['last_update_date'] ?? '', 64);
        $pendingReboot = !empty($computer['pending_reboot']) ? 1 : 0;
        $officeVersion = !empty($computer['office_version']) ? sanitizeString($computer['office_version'], 128) : null;
        $serialNumber = !empty($computer['serial_number']) ? sanitizeString($computer['serial_number'], 128) : null;
        $monitors = !empty($computer['monitors']) ? (is_array($computer['monitors']) ? json_encode($computer['monitors'], JSON_UNESCAPED_UNICODE) : (string)$computer['monitors']) : null;
        $antivirus = !empty($computer['antivirus']) ? sanitizeString($computer['antivirus'], 255) : null;
        $installedSoftware = !empty($computer['installed_software']) ? (is_array($computer['installed_software']) ? json_encode($computer['installed_software'], JSON_UNESCAPED_UNICODE) : (string)$computer['installed_software']) : null;

        $stmtUpdateDev = $pdo->prepare("
            UPDATE devices SET
                hostname = :hostname,
                os_name = :os_name,
                os_version = :os_version,
                ip_address = :ip_address,
                gateway = :gateway,
                dns_servers = :dns_servers,
                agent_version = :agent_version,
                logged_user = :logged_user,
                room_number = COALESCE(:room_number, room_number),
                updates_status = :updates_status,
                last_update_date = :last_update_date,
                pending_reboot = :pending_reboot,
                office_version = COALESCE(:office_version, office_version),
                serial_number = COALESCE(:serial_number, serial_number),
                monitors = COALESCE(:monitors, monitors),
                antivirus = COALESCE(:antivirus, antivirus),
                installed_software = COALESCE(:installed_software, installed_software),
                last_seen = :last_seen_now,
                overall_status = :overall_status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $stmtUpdateDev->execute([
            ':hostname' => $hostname,
            ':os_name' => $osName,
            ':os_version' => $osVersion,
            ':ip_address' => $ipAddress,
            ':gateway' => $gateway,
            ':dns_servers' => $dnsServers,
            ':agent_version' => $agentVersion,
            ':logged_user' => $loggedUser,
            ':room_number' => $roomNumber,
            ':updates_status' => $updatesStatus,
            ':last_update_date' => $lastUpdateDate,
            ':pending_reboot' => $pendingReboot,
            ':office_version' => $officeVersion,
            ':serial_number' => $serialNumber,
            ':monitors' => $monitors,
            ':antivirus' => $antivirus,
            ':installed_software' => $installedSoftware,
            ':last_seen_now' => date('Y-m-d H:i:s'),
            ':overall_status' => $overallHealth,
            ':id' => $deviceId
        ]);

        $pdo->commit();

        return [
            'device_id' => $deviceId,
            'hostname' => $hostname,
            'processed_checks' => $processedChecks,
            'errors' => $errorCount,
            'warnings' => $warningCount,
            'overall_status' => $overallHealth,
            'received_at' => date('Y-m-d H:i:s')
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
