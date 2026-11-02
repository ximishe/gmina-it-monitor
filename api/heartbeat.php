<?php
/**
 * Gmina IT Monitor - API Endpoint: Heartbeat
 * Przyjmuje raporty stanu od agenta PowerShell
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    jsonError('Dozwolona jest wyłącznie metoda POST.', 405);
}

try {
    $config = getAppConfig();
    $maxBytes = $config['app']['max_payload_bytes'] ?? 524288;

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        jsonError('Przekroczono maksymalny dopuszczalny rozmiar raportu (512 KB).', 413);
    }

    $pdo = getDb();

    // Weryfikacja tokenu uwierzytelniającego
    $token = extractRequestToken();
    $rawInput = file_get_contents('php://input');

    if (strlen($rawInput) > $maxBytes) {
        jsonError('Przekroczono maksymalny dopuszczalny rozmiar raportu.', 413);
    }

    $payload = json_decode($rawInput, true);

    if (empty($token) && is_array($payload) && !empty($payload['token']) && is_string($payload['token'])) {
        $token = trim($payload['token']);
    }

    if (empty($token)) {
        jsonError('Brak wymaganego tokenu uwierzytelniającego (nagłówek X-Device-Token lub pole token w JSON).', 401);
    }

    $reportingHost = !empty($payload['computer']['hostname']) ? trim($payload['computer']['hostname']) : null;
    $device = authenticateDevice($pdo, $token, $reportingHost);
    if (!$device) {
        jsonError('Nieprawidłowy lub odwołany token urządzenia.', 403);
    }

    // Walidacja struktury JSON
    if (!is_array($payload)) {
        jsonError('Niepoprawny format danych JSON.', 400);
    }

    // Przetworzenie danych raportu w transakcji
    $result = processHeartbeat($pdo, $device, $payload);

    jsonResponse([
        'success' => true,
        'message' => 'Raport został pomyślnie przyjęty i przetworzony.',
        'data' => $result
    ], 200);

} catch (InvalidArgumentException $ie) {
    jsonError('Błąd walidacji danych: ' . $ie->getMessage(), 422);
} catch (Throwable $e) {
    error_log("Błąd w heartbeat.php: " . $e->getMessage() . " w " . $e->getFile() . ":" . $e->getLine());
    jsonError('Wystąpił wewnętrzny błąd serwera podczas przetwarzania raportu.', 500);
}
