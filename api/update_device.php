<?php
/**
 * Gmina IT Monitor - API Endpoint: Update Device (pokój, referat / opis)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    jsonError('Dozwolona jest wyłącznie metoda POST.', 405);
}

try {
    $pdo = getDb();
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        jsonError('Niepoprawny format danych JSON.', 400);
    }

    $deviceId = isset($payload['device_id']) && is_numeric($payload['device_id']) ? (int)$payload['device_id'] : null;
    if (!$deviceId) {
        jsonError('Brak parametru device_id.', 400);
    }

    $roomNumber = isset($payload['room_number']) ? sanitizeString($payload['room_number'], 64) : null;
    $department = isset($payload['department']) ? sanitizeString($payload['department'], 128) : null;

    $stmt = $pdo->prepare("
        UPDATE devices 
        SET room_number = :room, 
            department = :dept,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([
        ':room' => $roomNumber,
        ':dept' => $department,
        ':id'   => $deviceId
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Lokalizacja i referat zostały pomyślnie zaktualizowane.',
        'data' => [
            'device_id'   => $deviceId,
            'room_number' => $roomNumber,
            'department'  => $department
        ]
    ]);

} catch (Throwable $e) {
    error_log("Błąd w update_device.php: " . $e->getMessage());
    jsonError('Wystąpił błąd podczas aktualizacji danych urządzenia.', 500);
}