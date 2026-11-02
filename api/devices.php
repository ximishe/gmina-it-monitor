<?php
/**
 * Gmina IT Monitor - API Endpoint: Devices
 * Zwraca listę zarejestrowanych komputerów i ich aktualny stan
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    jsonError('Dozwolona jest wyłącznie metoda GET.', 405);
}

try {
    $config = getAppConfig();
    $offlineThreshold = (int)($config['app']['offline_threshold_minutes'] ?? 15);
    $pdo = getDb();

    // Pobranie listy urządzeń
    $stmt = $pdo->query("
        SELECT 
            d.id,
            d.device_uuid,
            d.hostname,
            d.os_name,
            d.os_version,
            d.ip_address,
            d.gateway,
            d.dns_servers,
            d.agent_version,
            d.first_seen,
            d.last_seen,
            d.overall_status,
            d.logged_user,
            d.room_number,
            d.department,
            d.updates_status,
            d.last_update_date,
            d.pending_reboot,
            d.office_version,
            d.serial_number,
            d.monitors,
            d.antivirus,
            d.installed_software,
            d.is_active,
            (SELECT COUNT(*) FROM incidents i WHERE i.device_id = d.id AND i.status = 'OPEN') AS open_incidents_count
        FROM devices d
        WHERE d.is_active = 1
        ORDER BY d.hostname ASC
    ");
    $devices = $stmt->fetchAll();

    // Pobranie ostatnich wyników testów dla każdego urządzenia
    $stmtLatestTests = $pdo->prepare("
        SELECT c1.check_key, c1.check_name, c1.status, c1.message, c1.duration_ms, c1.checked_at
        FROM check_results c1
        INNER JOIN (
            SELECT check_key, MAX(id) as max_id
            FROM check_results
            WHERE device_id = :dev_id
            GROUP BY check_key
        ) c2 ON c1.id = c2.max_id
        ORDER BY c1.check_name ASC
    ");

    $summary = [
        'total' => count($devices),
        'online' => 0,
        'offline' => 0,
        'issues' => 0
    ];

    $resultDevices = [];
    foreach ($devices as $dev) {
        $computedOnline = computeOnlineStatus($dev['last_seen'], $offlineThreshold);
        $relativeTime = formatRelativeTime($dev['last_seen']);

        if ($computedOnline === 'ONLINE') {
            $summary['online']++;
        } else {
            $summary['offline']++;
        }

        if ((int)$dev['open_incidents_count'] > 0 || $dev['overall_status'] === 'ERROR') {
            $summary['issues']++;
        }

        // Pobierz ostatnie testy dla modala i podglądu
        $stmtLatestTests->execute([':dev_id' => $dev['id']]);
        $latestTests = $stmtLatestTests->fetchAll();

        $dev['computed_status'] = $computedOnline;
        $dev['last_seen_relative'] = $relativeTime;
        $dev['latest_tests'] = $latestTests;

        if (!empty($dev['monitors']) && is_string($dev['monitors'])) {
            $parsedMonitors = json_decode($dev['monitors'], true);
            $dev['monitors'] = is_array($parsedMonitors) ? $parsedMonitors : [];
        } else {
            $dev['monitors'] = is_array($dev['monitors']) ? $dev['monitors'] : [];
        }

        if (!empty($dev['installed_software']) && is_string($dev['installed_software'])) {
            $parsedSw = json_decode($dev['installed_software'], true);
            $dev['installed_software'] = is_array($parsedSw) ? $parsedSw : [];
        } else {
            $dev['installed_software'] = is_array($dev['installed_software']) ? $dev['installed_software'] : [];
        }

        $resultDevices[] = $dev;
    }

    jsonResponse([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'devices' => $resultDevices
    ]);

} catch (Throwable $e) {
    error_log("Błąd w devices.php: " . $e->getMessage());
    jsonError('Wystąpił błąd podczas pobierania listy urządzeń.', 500);
}
