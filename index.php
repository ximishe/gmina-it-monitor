<?php
header('Content-Type: text/html; charset=utf-8');
/**
 * Gmina IT Monitor - Główny panel administracyjny (Dashboard)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$config = getAppConfig();
$appTitle = $config['app']['title'] ?? 'Gmina IT Monitor';
$appSubtitle = $config['app']['subtitle'] ?? 'Lokalny monitoring infrastruktury Windows';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appTitle) ?> — Panel Administracyjny</title>
    
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Własny arkusz stylów -->
    <link rel="stylesheet" href="assets/css/style.css?v=2.0">
</head>
<body>

    <!-- Górny pasek nawigacyjny -->
    <header class="navbar navbar-expand-lg border-bottom bg-white sticky-top py-2 px-3 shadow-sm">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white p-2 rounded me-3 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="bi bi-shield-check fs-5"></i>
                </div>
                <div>
                    <h1 class="h5 mb-0 fw-bold text-dark"><?= htmlspecialchars($appTitle) ?></h1>
                    <small class="text-muted"><?= htmlspecialchars($appSubtitle) ?></small>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#gpoModal">
                    <i class="bi bi-diagram-3"></i>
                    <span>Wdrożenie GPO</span>
                </button>
                <button class="btn btn-sm btn-outline-dark d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#systemUpdateModal" id="btn-open-update-modal">
                    <i class="bi bi-cloud-arrow-down"></i>
                    <span>Aktualizacja</span>
                    <span id="update-badge" class="badge bg-danger rounded-pill d-none">!</span>
                </button>
                <span id="api-status-badge" class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-2 py-1">
                    <span class="status-dot dot-unknown"></span>Sprawdzanie API...
                </span>
                <span id="last-refresh-time" class="text-muted small">Odświeżanie...</span>
                <button id="btn-manual-refresh" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
                    <i class="bi bi-arrow-clockwise"></i>
                    <span>Odśwież</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Główna zawartość -->
    <main class="container-fluid py-4 px-4 flex-grow-1">
        
        <!-- Karty podsumowania -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Wszystkie stacje</div>
                            <div id="stat-total" class="stat-number text-dark mt-1">0</div>
                        </div>
                        <div class="stat-icon bg-light text-primary border">
                            <i class="bi bi-pc-display"></i>
                        </div>
                    </div>
                    <div class="text-muted small mt-2">Zarejestrowane w systemie</div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Online</div>
                            <div id="stat-online" class="stat-number text-success mt-1">0</div>
                        </div>
                        <div class="stat-icon bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="text-muted small mt-2">Raport w ciągu ostatnich 15 min</div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Problemy</div>
                            <div id="stat-issues" class="stat-number text-danger mt-1">0</div>
                        </div>
                        <div class="stat-icon bg-danger-subtle text-danger border border-danger-subtle">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="text-muted small mt-2">Aktywne awarie i błędy testów</div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Offline / Brak raportu</div>
                            <div id="stat-offline" class="stat-number text-secondary mt-1">0</div>
                        </div>
                        <div class="stat-icon bg-secondary-subtle text-secondary border border-secondary-subtle">
                            <i class="bi bi-clock"></i>
                        </div>
                    </div>
                    <div class="text-muted small mt-2">Brak raportu powyżej 15 min</div>
                </div>
            </div>
        </div>

        <!-- Sekcja: Monitorowane komputery -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <i class="bi bi-display me-2 text-primary"></i>
                    <h2 class="h6 mb-0 fw-bold text-dark">Monitorowane komputery</h2>
                    <span id="filtered-devices-count" class="badge bg-light text-muted border ms-2"></span>
                </div>
                <div class="small text-muted">
                    Automatyczne odświeżanie co 10 sekund
                </div>
            </div>

            <!-- Pasek filtrów tabeli stacji roboczych -->
            <div class="card-body border-bottom bg-light py-2">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" id="filter-device-search" class="form-control" placeholder="Szukaj: host, IP, użytkownik...">
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <select id="filter-device-dept" class="form-select form-select-sm">
                            <option value="">Wszystkie referaty / opisy</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <input type="text" id="filter-device-room" class="form-control form-control-sm" placeholder="Filtruj po pokoju...">
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <select id="filter-device-status" class="form-select form-select-sm">
                            <option value="">Wszystkie statusy</option>
                            <option value="ONLINE">ONLINE</option>
                            <option value="OFFLINE">OFFLINE</option>
                            <option value="ISSUES">Tylko z problemami</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-1 d-flex">
                        <button type="button" id="btn-device-filter-reset" class="btn btn-sm btn-outline-secondary w-100" title="Wyczyść filtry">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle main-devices-table">
                    <thead>
                        <tr>
                            <th style="width: 13%;">Komputer / Użytkownik</th>
                            <th style="width: 11%;">Lokalizacja (Referat / Pokój)</th>
                            <th style="width: 16%;">Sprzęt / Numery seryjne</th>
                            <th style="width: 9%;">Adres IP LAN</th>
                            <th style="width: 13%;">Drukarki (Korytarz / Pokój)</th>
                            <th style="width: 18%;">System / Oprogramowanie</th>
                            <th style="width: 9%;">Ostatni kontakt</th>
                            <th style="width: 5%;">Status</th>
                            <th style="width: 6%;" class="text-end">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="devices-table-body">
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Ładowanie stanu infrastruktury...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Sekcja: Ostatnie zdarzenia -->
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-activity me-2 text-danger"></i>
                            <h3 class="h6 mb-0 fw-bold text-dark">Ostatnie zdarzenia (Incydenty)</h3>
                        </div>
                        <span class="badge bg-light text-muted border">Dziennik zdarzeń</span>
                    </div>
                    <div id="incidents-container" class="card-body p-0" style="max-height: 380px; overflow-y: auto;">
                        <div class="text-center py-4 text-muted small">
                            Ładowanie zdarzeń...
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sekcja: Szybka pomoc / Auto-Patcher -->
            <div class="col-12 col-lg-6" id="instructions-section">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-robot me-2 text-primary"></i>
                            <h3 class="h6 mb-0 fw-bold text-dark">Dystrybucja i Auto-Aktualizacja (Auto-Patcher)</h3>
                        </div>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active Directory</span>
                    </div>
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Dzięki modułowi <strong>Bootstrap</strong> nie musisz ręcznie kopiować plików na stacje robocze. Komputery w domenie pobierają najnowszy skrypt bezpośrednio z serwera WWW:
                        </p>
                        <div class="bg-light p-2 rounded border font-monospace small mb-3 text-break">
                            powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -Command "iex (New-Object Net.WebClient).DownloadString('http://adminmonitor.gminaleszno.local/agent/bootstrap.ps1')"
                        </div>
                        <ul class="small text-muted ps-3 mb-0">
                            <li><strong>Auto-wykrywanie drukarek:</strong> Drukarki korytarzowe Ricoh (15.60) i Konica (15.50) + automatycznie drukarki w pokojach (15.x/115.x).</li>
                            <li><strong>Użytkownik i pokój:</strong> Komputer sam raportuje aktywnego użytkownika, a pokój i referat możesz przypisać bezpośrednio z tabeli.</li>
                            <li><strong>Centralne aktualizacje:</strong> Zmiana kodu na serwerze WWW wchodzi natychmiast u wszystkich bez odwiedzania stanowisk.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sekcja: Historia testów dla wybranego komputera -->
        <div class="card" id="history-section">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <i class="bi bi-clock-history me-2 text-primary"></i>
                    <h3 class="h6 mb-0 fw-bold text-dark">Historia testów: <span id="history-hostname-title" class="text-primary">Wybierz komputer z listy powyżej</span></h3>
                </div>
                <div id="history-pagination"></div>
            </div>
            
            <div class="card-body border-bottom bg-light py-2">
                <form id="history-filter-form" class="row g-2 align-items-center">
                    <div class="col-12 col-sm-3">
                        <select id="filter-check-key" class="form-select form-select-sm">
                            <option value="">Wszystkie rodzaje testów</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-2">
                        <select id="filter-status" class="form-select form-select-sm">
                            <option value="WARNINGS" selected>Tylko ostrzeżenia i błędy</option>
                            <option value="ALL">Wszystkie zarejestrowane</option>
                            <option value="WARNING">Tylko OSTRZEŻENIE</option>
                            <option value="ERROR">Tylko BŁĄD</option>
                            <option value="OK">Tylko OK</option>
                            <option value="SKIPPED">POMINIĘTY</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-2">
                        <input type="date" id="filter-date-from" class="form-control form-control-sm" placeholder="Data od">
                    </div>
                    <div class="col-12 col-sm-2">
                        <input type="date" id="filter-date-to" class="form-control form-control-sm" placeholder="Data do">
                    </div>
                    <div class="col-12 col-sm-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>Filtruj
                        </button>
                        <button type="button" id="btn-history-reset" class="btn btn-sm btn-outline-secondary">
                            Wyczyść
                        </button>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle main-devices-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Test</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 12%;">Czas odpowiedzi</th>
                            <th style="width: 33%;">Komunikat</th>
                            <th style="width: 15%;">Data wykonania</th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body">
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                Kliknij przycisk <strong>„Historia”</strong> przy komputerze na liście, aby wyświetlić szczegółowy rejestr testów.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal: Edycja lokalizacji i referatu stacji -->
    <div class="modal fade" id="editDeviceModal" tabindex="-1" aria-labelledby="editDeviceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom bg-light py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-pencil-square fs-5 text-primary"></i>
                        <h4 class="modal-title h6 fw-bold mb-0" id="editDeviceModalLabel">
                            Przypisz lokalizację: <span id="edit-modal-hostname" class="text-primary">-</span>
                        </h4>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <form id="edit-device-form">
                    <input type="hidden" id="edit-device-id">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="edit-department" class="form-label small fw-bold text-dark">Referat / Wydział / Opis stacji:</label>
                            <input type="text" id="edit-department" class="form-control" placeholder="np. Referat IT, Księgowość, Sekretariat, USC..." required>
                            <div class="form-text small">Po tej wartości możesz filtrować stacje na dashboardzie.</div>
                        </div>
                        <div class="mb-3">
                            <label for="edit-room-number" class="form-label small fw-bold text-dark">Numer pokoju:</label>
                            <input type="text" id="edit-room-number" class="form-control" placeholder="np. 101, Pokój 12, Parter..." required>
                        </div>
                    </div>
                    <div class="modal-footer border-top py-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" id="btn-save-device-details" class="btn btn-sm btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Zapisz zmiany
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Wdrożenie Active Directory (GPO) -->
    <div class="modal fade" id="gpoModal" tabindex="-1" aria-labelledby="gpoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom bg-light py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3 fs-4 text-primary"></i>
                        <h4 class="modal-title h6 fw-bold mb-0" id="gpoModalLabel">
                            Wdrożenie przez Zasady Grup (Active Directory GPO)
                        </h4>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body p-4">
                    <h6 class="fw-bold text-dark mb-2">1. Harmonogram zadań przez GPO (Zalecane)</h6>
                    <p class="small text-secondary mb-2">
                        W konsoli <code>gpmc.msc</code> przejdź do: <strong>Konfiguracja komputera → Preferencje → Ustawienia panelu sterowania → Zaplanowane zadania</strong> i dodaj zadanie z wyzwalaczem co 15 minut:
                    </p>
                    <div class="bg-light p-3 rounded border font-monospace small mb-3 text-break user-select-all">
                        powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -Command "iex (New-Object Net.WebClient).DownloadString('http://adminmonitor.gminaleszno.local/agent/bootstrap.ps1')"
                    </div>

                    <h6 class="fw-bold text-dark mb-2">2. Alternatywa: Skrypt startowy komputera (Startup Script)</h6>
                    <p class="small text-secondary mb-2">
                        Możesz również dodać skrypt <code>agent/bootstrap.ps1</code> do zasad startowych: <strong>Konfiguracja komputera → Zasady → Ustawienia systemu Windows → Skrypty → Uruchamianie</strong>.
                    </p>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Rozumiem</button>
                </div>
            </div>
        </div>
    </div>

    
    <!-- Modal: Aktualizacja systemu z GitHuba -->
    <div class="modal fade" id="systemUpdateModal" tabindex="-1" aria-labelledby="systemUpdateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom bg-light py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-cloud-arrow-down fs-4 text-primary"></i>
                        <div>
                            <h4 class="modal-title h6 fw-bold mb-0" id="systemUpdateModalLabel">
                                Aktualizacja systemu (GitHub Self-Updater)
                            </h4>
                            <small class="text-muted">Pobieraj i aktualizuj kod z publicznego repozytorium GitHub</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Bieżący stan wersji -->
                    <div class="card mb-3 bg-light border">
                        <div class="card-body py-3">
                            <div class="row g-2 small">
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Bieżący Commit:</span>
                                    <strong id="update-cur-commit" class="font-monospace text-dark">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Gałąź (Branch):</span>
                                    <strong id="update-cur-branch" class="text-dark font-monospace">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Data wydania:</span>
                                    <strong id="update-cur-date" class="text-dark">-</strong>
                                </div>
                                <div class="col-12">
                                    <span class="text-muted d-block">Ostatnia zmiana w repozytorium:</span>
                                    <span id="update-cur-msg" class="text-secondary fst-italic">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status aktualizacji / Komunikat -->
                    <div id="update-status-alert" class="alert alert-secondary py-2 px-3 small d-flex align-items-center justify-content-between mb-3">
                        <span id="update-status-text"><i class="bi bi-info-circle me-1"></i> Kliknij „Sprawdź na GitHubie”, aby zweryfikować dostępność nowych wydań.</span>
                        <span id="update-status-spinner" class="spinner-border spinner-border-sm text-primary d-none" role="status"></span>
                    </div>

                    <!-- Konsola wyjściowa z operacji Git -->
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-muted mb-1">Dziennik operacji Git:</label>
                        <pre id="update-console-log" class="bg-dark text-light p-3 rounded font-monospace small mb-0" style="max-height: 160px; overflow-y: auto; font-size: 0.82rem;">Oczekiwanie na akcję...</pre>
                    </div>

                    <div class="alert alert-warning-subtle text-warning-emphasis border border-warning-subtle py-2 px-3 small mb-0 mt-3">
                        <i class="bi bi-shield-lock-fill me-1"></i>
                        <strong>Bezpieczeństwo danych:</strong> Plik bazy danych SQLite (<code>database/it_monitor.sqlite</code>) oraz plik konfiguracyjny (<code>config/config.php</code>) są wykluczone z repozytorium i nie zostaną zmodyfikowane ani usunięte podczas aktualizacji.
                    </div>
                </div>
                <div class="modal-footer border-top py-2 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
                    <div class="d-flex gap-2">
                        <button type="button" id="btn-check-git-updates" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1">
                            <i class="bi bi-arrow-repeat"></i>
                            <span>Sprawdź na GitHubie</span>
                        </button>
                        <button type="button" id="btn-execute-git-update" class="btn btn-sm btn-success d-flex align-items-center gap-1" disabled>
                            <i class="bi bi-download"></i>
                            <span>Pobierz i zaktualizuj</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal ze szczegółami testów komputera -->
    <div class="modal fade" id="deviceDetailModal" tabindex="-1" aria-labelledby="deviceDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom bg-light py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-pc-display fs-4 text-primary"></i>
                        <div>
                            <h4 class="modal-title h6 fw-bold mb-0" id="deviceDetailModalLabel">
                                Stacja: <span id="modal-device-hostname" class="text-primary">-</span>
                            </h4>
                            <small class="text-muted">Szczegółowe wyniki ostatniego raportu diagnostycznego</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div id="modal-status-pill"></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                </div>

                <div class="modal-body p-4">
                    <div class="card mb-4 bg-light border">
                        <div class="card-body py-3">
                            <div class="row g-3 small">
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Nazwa komputera:</span>
                                    <strong id="modal-host-name" class="text-dark">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Adres IP LAN:</span>
                                    <strong id="modal-ip" class="text-dark font-monospace">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Brama domyślna:</span>
                                    <strong id="modal-gateway" class="text-dark font-monospace">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Serwery DNS:</span>
                                    <strong id="modal-dns" class="text-dark font-monospace">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">System operacyjny:</span>
                                    <strong id="modal-os" class="text-dark">-</strong>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted d-block">Wersja Agenta / Lokalizacja:</span>
                                    <strong id="modal-agent" class="text-dark">-</strong> | <span id="modal-last-seen" class="text-muted">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="h6 fw-bold text-dark mb-3">Wyniki testów infrastruktury</h5>
                    <div id="modal-tests-container"></div>
                </div>

                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Pełna lista zainstalowanego oprogramowania -->
    <div class="modal fade" id="installedSoftwareModal" tabindex="-1" aria-labelledby="installedSoftwareModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom bg-light py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-box-seam fs-4 text-primary"></i>
                        <div>
                            <h4 class="modal-title h6 fw-bold mb-0" id="installedSoftwareModalLabel">
                                Zainstalowane oprogramowanie: <span id="sw-modal-hostname" class="text-primary">-</span>
                            </h4>
                            <small id="sw-modal-count" class="text-muted">Lista aplikacji zarejestrowanych w systemie</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="sw-search-input" class="form-control" placeholder="Szukaj programu, wersji, producenta...">
                    </div>
                    <div id="sw-modal-container" style="max-height: 480px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Nazwa programu</th>
                                    <th style="width: 25%;">Wersja</th>
                                    <th style="width: 30%;">Wydawca / Producent</th>
                                </tr>
                            </thead>
                            <tbody id="sw-modal-table-body">
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">Brak danych o oprogramowaniu</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stopka -->
    <footer class="py-3 px-4 text-center text-muted border-top bg-white">
        <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><?= htmlspecialchars($appTitle) ?></strong> — Lokalny monitoring infrastruktury Windows (SQLite / XAMPP)
            </div>
            <div>
                PHP <?= PHP_VERSION ?> | PowerShell Agent 5.1+
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 Bundle JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Skrypt Dashboardu -->
    <script src="assets/js/dashboard.js?v=3.2"></script>
</body>
</html>

