<?php
/**
 * Gmina IT Monitor - API Endpoint: Incidents / Zdarzenia
 * Zwraca listę ostatnich incydentów (otwartych i rozwiązanych)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    jsonError('Dozwolona jest wyłącznie metoda GET.', 405);
}

try {
    $pdo = getDb();
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 15)));

    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.device_id,
            d.hostname,
            i.check_key,
            i.check_name,
            i.severity,
            i.title,
            i.description,
            i.opened_at,
            i.resolved_at,
            i.status
        FROM incidents i
        INNER JOIN devices d ON i.device_id = d.id
        ORDER BY 
            CASE WHEN i.status = 'OPEN' THEN 0 ELSE 1 END,
            COALESCE(i.resolved_at, i.opened_at) DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $incidents = $stmt->fetchAll();

    $formatted = [];
    foreach ($incidents as $inc) {
        $inc['opened_at_relative'] = formatRelativeTime($inc['opened_at']);
        $inc['resolved_at_relative'] = !empty($inc['resolved_at']) ? formatRelativeTime($inc['resolved_at']) : null;
        $formatted[] = $inc;
    }

    jsonResponse([
        'success' => true,
        'count' => count($formatted),
        'data' => $formatted
    ]);

} catch (Throwable $e) {
    error_log("Błąd w incidents.php: " . $e->getMessage());
    jsonError('Wystąpił błąd podczas pobierania zdarzeń.', 500);
}