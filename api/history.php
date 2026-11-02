<?php
/**
 * Gmina IT Monitor - API Endpoint: History
 * Zwraca historię testów dla urządzenia z paginacją i filtrami
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    jsonError('Dozwolona jest wyłącznie metoda GET.', 405);
}

try {
    $pdo = getDb();

    $deviceId = isset($_GET['device_id']) && is_numeric($_GET['device_id']) ? (int)$_GET['device_id'] : null;
    $checkKey = isset($_GET['check_key']) ? sanitizeString($_GET['check_key'], 64) : null;
    $status = isset($_GET['status']) ? strtoupper(sanitizeString($_GET['status'], 16)) : null;
    $dateFrom = isset($_GET['date_from']) ? sanitizeString($_GET['date_from'], 20) : null;
    $dateTo = isset($_GET['date_to']) ? sanitizeString($_GET['date_to'], 20) : null;

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(5, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    if (!$deviceId) {
        jsonError('Wymagany parametr device_id.', 400);
    }

    $whereConditions = ['device_id = :device_id'];
    $params = [':device_id' => $deviceId];

    if (!empty($checkKey)) {
        $whereConditions[] = 'check_key = :check_key';
        $params[':check_key'] = $checkKey;
    }

    if (!empty($status)) {
        if ($status === 'ALL') {
            // Bez ograniczenia statusu
        } elseif ($status === 'WARNINGS' || $status === 'PROBLEMS') {
            $whereConditions[] = "status IN ('WARNING', 'ERROR')";
        } elseif (in_array($status, ['OK', 'ERROR', 'WARNING', 'SKIPPED'])) {
            $whereConditions[] = 'status = :status';
            $params[':status'] = $status;
        }
    } else {
        // Domyślnie w historii pokazujemy wyłącznie ostrzeżenia i błędy
        $whereConditions[] = "status IN ('WARNING', 'ERROR')";
    }

    if (!empty($dateFrom)) {
        $whereConditions[] = 'checked_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    if (!empty($dateTo)) {
        $whereConditions[] = 'checked_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $whereConditions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM check_results WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT id, check_key, check_name, status, message, duration_ms, checked_at
        FROM check_results
        WHERE {$whereSql}
        ORDER BY checked_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $pName => $pVal) {
        $dataStmt->bindValue($pName, $pVal);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll();

    $testKeysStmt = $pdo->prepare("
        SELECT DISTINCT check_key, check_name 
        FROM check_results 
        WHERE device_id = :dev_id 
        ORDER BY check_name ASC
    ");
    $testKeysStmt->execute([':dev_id' => $deviceId]);
    $availableKeys = $testKeysStmt->fetchAll();

    jsonResponse([
        'success' => true,
        'page' => $page,
        'limit' => $limit,
        'total' => $totalCount,
        'pages_count' => ceil($totalCount / $limit),
        'filters' => [
            'available_checks' => $availableKeys
        ],
        'data' => $rows
    ]);

} catch (Throwable $e) {
    error_log("Błąd w history.php: " . $e->getMessage());
    jsonError('Wystąpił błąd podczas pobierania historii testów.', 500);
}