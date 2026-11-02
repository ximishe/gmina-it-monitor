<?php
/**
 * Gmina IT Monitor - API Endpoint: System Update
 * Umożliwia sprawdzenie statusu wersji oraz samoczynną aktualizację z publicznego GitHuba
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$config = getAppConfig();
$projectRoot = dirname(__DIR__);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

/**
 * Bezpieczne wywołanie polecenia w katalogu projektu
 */
function runGitCommand(string $command, string $cwd): array {
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd, null);
    if (!is_resource($process)) {
        return ['code' => -1, 'output' => 'Nie udało się uruchomić procesu proc_open.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $output = trim($stdout . ($stderr ? "\n" . $stderr : ''));

    return [
        'code' => $exitCode,
        'output' => $output
    ];
}

/**
 * Sprawdza czy Git jest zainstalowany w systemie
 */
function isGitInstalled(): bool {
    $res = runGitCommand('git --version', __DIR__);
    return $res['code'] === 0;
}

/**
 * Pobiera informacje o stanie repozytorium Git
 */
function getGitRepoInfo(string $projectRoot): array {
    $gitDir = $projectRoot . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($gitDir)) {
        return [
            'is_git_repo' => false,
            'message' => 'Katalog projektu nie został sklonowany przez git (brak folderu .git).'
        ];
    }

    $commitRes = runGitCommand('git rev-parse --short HEAD', $projectRoot);
    $commitFullRes = runGitCommand('git rev-parse HEAD', $projectRoot);
    $branchRes = runGitCommand('git rev-parse --abbrev-ref HEAD', $projectRoot);
    $dateRes = runGitCommand('git log -1 --format=%cd --date=format:"%Y-%m-%d %H:%M:%S"', $projectRoot);
    $msgRes = runGitCommand('git log -1 --format=%s', $projectRoot);
    $originRes = runGitCommand('git config --get remote.origin.url', $projectRoot);

    return [
        'is_git_repo' => true,
        'commit' => ($commitRes['code'] === 0) ? $commitRes['output'] : 'Nieznany',
        'commit_full' => ($commitFullRes['code'] === 0) ? $commitFullRes['output'] : '',
        'branch' => ($branchRes['code'] === 0) ? $branchRes['output'] : 'main',
        'date' => ($dateRes['code'] === 0) ? $dateRes['output'] : 'Nieznana',
        'message' => ($msgRes['code'] === 0) ? $msgRes['output'] : '',
        'remote_url' => ($originRes['code'] === 0) ? $originRes['output'] : ''
    ];
}

if (!isGitInstalled()) {
    jsonResponse([
        'success' => false,
        'error' => 'Git nie jest zainstalowany na serwerze (brak polecenia git w PATH).',
        'git_installed' => false
    ], 200);
}

$repoInfo = getGitRepoInfo($projectRoot);

if (!$repoInfo['is_git_repo']) {
    jsonResponse([
        'success' => true,
        'git_installed' => true,
        'is_git_repo' => false,
        'message' => $repoInfo['message'],
        'hint' => 'Na serwerze Ubuntu sklonuj projekt poleceniem: git clone <URL_GITHUB> /var/www/html/it-monitor'
    ], 200);
}

// Obsługa akcji: status / check
if ($action === 'status' || $action === 'check') {
    $hasUpdates = false;
    $behindCount = 0;
    $checkLog = '';

    if ($action === 'check') {
        $branch = $repoInfo['branch'] ?: 'main';
        $fetchRes = runGitCommand('git fetch origin ' . escapeshellarg($branch) . ' 2>&1', $projectRoot);
        $checkLog = $fetchRes['output'];

        if ($fetchRes['code'] === 0) {
            $countRes = runGitCommand('git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count', $projectRoot);
            if ($countRes['code'] === 0) {
                $behindCount = (int)trim($countRes['output']);
                $hasUpdates = ($behindCount > 0);
            }
        }
    }

    jsonResponse([
        'success' => true,
        'git_installed' => true,
        'is_git_repo' => true,
        'info' => $repoInfo,
        'updates' => [
            'checked' => ($action === 'check'),
            'has_updates' => $hasUpdates,
            'behind_count' => $behindCount,
            'log' => $checkLog
        ]
    ]);
}

// Obsługa akcji: update (wykonanie aktualizacji kodu z GitHuba)
if ($action === 'update') {
    $branch = $repoInfo['branch'] ?: 'main';

    // Bezpieczne pobranie i twarde przestawienie na stan origin (z zachowaniem plików w .gitignore)
    $cmd = 'git fetch origin ' . escapeshellarg($branch) . ' 2>&1 && git reset --hard origin/' . escapeshellarg($branch) . ' 2>&1';
    $updateRes = runGitCommand($cmd, $projectRoot);

    $newRepoInfo = getGitRepoInfo($projectRoot);

    if ($updateRes['code'] === 0) {
        $migrated = [];
        try {
            $pdo = getDb();
            $driver = $config['db']['driver'] ?? 'sqlite';
            $migrated = runMigrations($pdo, $driver);
        } catch (Throwable $e) {
            error_log("Błąd migracji po aktualizacji: " . $e->getMessage());
        }

        $migMsg = !empty($migrated) 
            ? " (Zaaplikowano nowe migracje bazy: " . implode(", ", $migrated) . ")" 
            : "";

        jsonResponse([
            'success' => true,
            'message' => 'Aplikacja oraz baza danych zostały pomyślnie zaktualizowane!' . $migMsg,
            'previous_commit' => $repoInfo['commit'],
            'new_commit' => $newRepoInfo['commit'],
            'new_message' => $newRepoInfo['message'],
            'migrations_applied' => $migrated,
            'output' => $updateRes['output']
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'error' => 'Wystąpił błąd podczas wykonywania git fetch/reset.',
            'output' => $updateRes['output'],
            'hint' => 'Upewnij się, że użytkownik www-data ma uprawnienia zapisu do folderu projektu (sudo chown -R www-data:www-data /var/www/html/it-monitor).'
        ], 500);
    }
}

jsonError('Nieznana akcja: ' . htmlspecialchars($action), 400);

