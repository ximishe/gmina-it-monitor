/**
 * Gmina IT Monitor - Dashboard JavaScript
 */

let currentDevices = [];
let activeModalDeviceId = null;
let currentHistoryDeviceId = null;
let historyCurrentPage = 1;
let refreshTimer = null;

let deviceFilters = {
    search: "",
    department: "",
    room: "",
    status: ""
};

document.addEventListener("DOMContentLoaded", () => {
    loadDashboard();
    loadIncidents();
    initSystemUpdate();

    refreshTimer = setInterval(() => {
        loadDashboard(true);
        loadIncidents();
    initSystemUpdate();
    }, 10000);

    const btnRefresh = document.getElementById("btn-manual-refresh");
    if (btnRefresh) {
        btnRefresh.addEventListener("click", () => {
            const icon = btnRefresh.querySelector("i");
            if (icon) icon.classList.add("spin-once");
            loadDashboard();
            loadIncidents();
    initSystemUpdate();
            setTimeout(() => { if (icon) icon.classList.remove("spin-once"); }, 600);
        });
    }

    // Filtry tabeli stacji roboczych
    const searchInput = document.getElementById("filter-device-search");
    if (searchInput) {
        searchInput.addEventListener("input", (e) => {
            deviceFilters.search = e.target.value.toLowerCase().trim();
            applyDeviceFilters();
        });
    }

    const deptSelect = document.getElementById("filter-device-dept");
    if (deptSelect) {
        deptSelect.addEventListener("change", (e) => {
            deviceFilters.department = e.target.value;
            applyDeviceFilters();
        });
    }

    const roomInput = document.getElementById("filter-device-room");
    if (roomInput) {
        roomInput.addEventListener("input", (e) => {
            deviceFilters.room = e.target.value.toLowerCase().trim();
            applyDeviceFilters();
        });
    }

    const statusSelect = document.getElementById("filter-device-status");
    if (statusSelect) {
        statusSelect.addEventListener("change", (e) => {
            deviceFilters.status = e.target.value;
            applyDeviceFilters();
        });
    }

    const btnResetDeviceFilter = document.getElementById("btn-device-filter-reset");
    if (btnResetDeviceFilter) {
        btnResetDeviceFilter.addEventListener("click", () => {
            deviceFilters = { search: "", department: "", room: "", status: "" };
            if (searchInput) searchInput.value = "";
            if (deptSelect) deptSelect.value = "";
            if (roomInput) roomInput.value = "";
            if (statusSelect) statusSelect.value = "";
            applyDeviceFilters();
        });
    }

    // Formularz edycji stacji (Referat i Pokój)
    const editForm = document.getElementById("edit-device-form");
    if (editForm) {
        editForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            const devId = parseInt(document.getElementById("edit-device-id").value, 10);
            const deptVal = document.getElementById("edit-department").value.trim();
            const roomVal = document.getElementById("edit-room-number").value.trim();
            const btnSubmit = document.getElementById("btn-save-device-details");

            if (btnSubmit) btnSubmit.disabled = true;

            try {
                const res = await fetch("api/update_device.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        device_id: devId,
                        department: deptVal,
                        room_number: roomVal
                    })
                });
                const data = await res.json();
                if (data.success) {
                    const modalEl = document.getElementById("editDeviceModal");
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    await loadDashboard();
                } else {
                    alert("Błąd: " + (data.error || "Nie udało się zapisać zmian"));
                }
            } catch (err) {
                alert("Błąd połączenia z serwerem.");
            } finally {
                if (btnSubmit) btnSubmit.disabled = false;
            }
        });
    }

    // Filtry historii
    const historyFilterForm = document.getElementById("history-filter-form");
    if (historyFilterForm) {
        historyFilterForm.addEventListener("submit", (e) => {
            e.preventDefault();
            historyCurrentPage = 1;
            loadHistory(currentHistoryDeviceId, 1);
        });
    }

    const btnHistoryReset = document.getElementById("btn-history-reset");
    if (btnHistoryReset) {
        btnHistoryReset.addEventListener("click", () => {
            document.getElementById("filter-check-key").value = "";
            document.getElementById("filter-status").value = "";
            document.getElementById("filter-date-from").value = "";
            document.getElementById("filter-date-to").value = "";
            historyCurrentPage = 1;
            loadHistory(currentHistoryDeviceId, 1);
        });
    }
});

/**
 * Sprawdza stan API
 */
async function checkApiHealth() {
    const pill = document.getElementById("api-status-badge");
    try {
        const res = await fetch("api/health.php", { cache: "no-store" });
        const data = await res.json();
        if (pill) {
            if (data.success && data.status === "OK") {
                pill.className = "badge bg-success-subtle text-success-emphasis border border-success-subtle px-2 py-1";
                pill.innerHTML = `<span class="status-dot dot-online"></span>API Aktywne (${data.database.driver || 'SQLite'})`;
            } else {
                pill.className = "badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1";
                pill.innerHTML = `<span class="status-dot dot-warning"></span>API Ograniczone`;
            }
        }
    } catch (e) {
        if (pill) {
            pill.className = "badge bg-danger-subtle text-danger-emphasis border border-danger-subtle px-2 py-1";
            pill.innerHTML = `<span class="status-dot dot-error"></span>API Niedostępne`;
        }
    }
}

/**
 * Aktualizuje karty liczników podsumowania
 */
function updateSummaryCards(summary) {
    const elTotal = document.getElementById("stat-total");
    const elOnline = document.getElementById("stat-online");
    const elIssues = document.getElementById("stat-issues");
    const elOffline = document.getElementById("stat-offline");

    if (elTotal) elTotal.textContent = summary.total ?? 0;
    if (elOnline) elOnline.textContent = summary.online ?? 0;
    if (elIssues) elIssues.textContent = summary.issues ?? 0;
    if (elOffline) elOffline.textContent = summary.offline ?? 0;
}

/**
 * Aktualizuje timestamp ostatniego odświeżenia
 */
function updateRefreshTimestamp(serverTime) {
    const el = document.getElementById("last-refresh-time");
    if (el) {
        const now = new Date();
        const timeStr = now.toLocaleTimeString("pl-PL");
        el.textContent = `Odświeżono: ${timeStr}`;
    }
}

/**
 * Pobiera dane urządzeń
 */
async function loadDashboard(isAutoRefresh = false) {
    try {
        checkApiHealth();
        const res = await fetch("api/devices.php", { cache: "no-store" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.success) {
            currentDevices = data.devices || [];
            updateSummaryCards(data.summary || {});
            updateDepartmentDropdownOptions(currentDevices);
            applyDeviceFilters();
            updateRefreshTimestamp(data.timestamp);

            if (activeModalDeviceId) {
                const activeDev = currentDevices.find(d => d.id === activeModalDeviceId);
                if (activeDev) {
                    renderModalContent(activeDev);
                }
            }
        }
    } catch (err) {
        console.error("Błąd podczas pobierania danych urządzeń:", err);
    }
}

function updateDepartmentDropdownOptions(devices) {
    const deptSelect = document.getElementById("filter-device-dept");
    if (!deptSelect) return;

    const currentVal = deptSelect.value;
    const departments = new Set();
    for (const d of devices) {
        if (d.department && d.department.trim()) {
            departments.add(d.department.trim());
        }
    }

    const sortedDepts = Array.from(departments).sort();
    let optionsHtml = `<option value="">Wszystkie referaty / opisy</option>`;
    for (const dept of sortedDepts) {
        const isSelected = dept === currentVal ? "selected" : "";
        optionsHtml += `<option value="${escapeHtml(dept)}" ${isSelected}>${escapeHtml(dept)}</option>`;
    }
    deptSelect.innerHTML = optionsHtml;
}

function applyDeviceFilters() {
    let filtered = currentDevices.slice();

    if (deviceFilters.search) {
        const term = deviceFilters.search;
        filtered = filtered.filter(d => {
            const host = (d.hostname || "").toLowerCase();
            const ip = (d.ip_address || "").toLowerCase();
            const user = (d.logged_user || "").toLowerCase();
            const dept = (d.department || "").toLowerCase();
            const room = (d.room_number || "").toLowerCase();
            const sn = (d.serial_number || "").toLowerCase();
            const av = (d.antivirus || "").toLowerCase();
            const office = (d.office_version || "").toLowerCase();
            const monitorsMatch = Array.isArray(d.monitors) && d.monitors.some(m => ((m.SerialNumber || "") + " " + (m.Model || "") + " " + (m.Manufacturer || "")).toLowerCase().includes(term));
            const swMatch = Array.isArray(d.installed_software) && d.installed_software.some(s => ((s.Name || "") + " " + (s.Publisher || "")).toLowerCase().includes(term));
            return host.includes(term) || ip.includes(term) || user.includes(term) || dept.includes(term) || room.includes(term) || office.includes(term) || sn.includes(term) || av.includes(term) || monitorsMatch || swMatch;
        });
    }

    if (deviceFilters.department) {
        filtered = filtered.filter(d => (d.department || "").trim() === deviceFilters.department);
    }

    if (deviceFilters.room) {
        const roomTerm = deviceFilters.room;
        filtered = filtered.filter(d => (d.room_number || "").toLowerCase().includes(roomTerm));
    }

    if (deviceFilters.status) {
        if (deviceFilters.status === "ONLINE") {
            filtered = filtered.filter(d => d.computed_status === "ONLINE");
        } else if (deviceFilters.status === "OFFLINE") {
            filtered = filtered.filter(d => d.computed_status === "OFFLINE");
        } else if (deviceFilters.status === "ISSUES") {
            filtered = filtered.filter(d => parseInt(d.open_incidents_count || 0, 10) > 0 || d.overall_status === "ERROR" || d.pending_reboot == 1);
        }
    }

    const counter = document.getElementById("filtered-devices-count");
    if (counter) {
        if (filtered.length !== currentDevices.length) {
            counter.textContent = `Widoczne: ${filtered.length} z ${currentDevices.length}`;
        } else {
            counter.textContent = `Łącznie: ${currentDevices.length}`;
        }
    }

    renderDevicesTable(filtered);
}

function renderDevicesTable(devices) {
    const tbody = document.getElementById("devices-table-body");
    if (!tbody) return;

    if (!devices || devices.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5">
                    <div class="text-muted">
                        <i class="bi bi-display fs-1 d-block mb-2 text-secondary"></i>
                        <h6 class="fw-semibold mb-1">Brak komputerów spełniających kryteria</h6>
                        <p class="small mb-2">Zmień filtry wyszukiwania lub wyczyść je przyciskiem obok.</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }

    let rowsHtml = "";
    for (const dev of devices) {
        const statusBadge = getStatusBadge(dev.computed_status);
        const issuesCount = parseInt(dev.open_incidents_count || 0, 10);
        const issuesBadge = issuesCount > 0 
            ? `<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-exclamation-triangle me-1"></i>${issuesCount} ${issuesCount === 1 ? 'awaria' : 'awarie'}</span>`
            : `<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check me-1"></i>Brak problemów</span>`;

        const osText = escapeHtml(dev.os_name || 'Nieznany');
        let osVerHtml = '';
        if (dev.os_version && dev.os_version.trim()) {
            const cleanVer = dev.os_version.trim();
            if (!osText.includes(cleanVer)) {
                const verText = (cleanVer.startsWith('(') && cleanVer.endsWith(')')) ? cleanVer : `(${cleanVer})`;
                osVerHtml = `<div class="text-secondary fw-normal font-monospace mt-1" style="font-size: 0.75rem;" title="Wersja aktualizacji i kompilacja">${escapeHtml(verText)}</div>`;
            }
        }
        const ipText = escapeHtml(dev.ip_address || '-');
        const hostnameText = escapeHtml(dev.hostname || 'Nieznany');
        const relativeLastSeen = escapeHtml(dev.last_seen_relative || 'Nigdy');
        const fullLastSeen = dev.last_seen ? escapeHtml(dev.last_seen) : '';

        const userText = dev.logged_user ? escapeHtml(dev.logged_user) : 'Brak sesji';
        const deptText = dev.department ? escapeHtml(dev.department) : 'Nie przypisano referatu';
        const roomText = dev.room_number ? escapeHtml(dev.room_number) : 'Brak pokoju';

        let updatesBadge = `<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" title="Ostatnia: ${escapeHtml(dev.last_update_date || '-')}"><i class="bi bi-shield-check me-1"></i>Aktualny</span>`;
        if (dev.pending_reboot == 1 || dev.updates_status === 'PENDING_REBOOT') {
            updatesBadge = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Wymagany restart stacji. Ostatnia: ${escapeHtml(dev.last_update_date || '-')}"><i class="bi bi-arrow-repeat me-1"></i>Wymaga restartu</span>`;
        }

        let officeBadge = '';
        if (dev.office_version && dev.office_version !== 'Brak Office') {
            officeBadge = `<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Zainstalowany pakiet Office"><i class="bi bi-file-earmark-word me-1"></i>${escapeHtml(dev.office_version)}</span>`;
        } else {
            officeBadge = `<span class="badge bg-light text-muted border"><i class="bi bi-file-earmark-x me-1"></i>Brak Office</span>`;
        }

        // Sprzęt i Numery Seryjne
        const devSn = (dev.serial_number && dev.serial_number.trim()) ? dev.serial_number.trim() : 'Brak SN';
        const monitors = Array.isArray(dev.monitors) ? dev.monitors : (typeof dev.monitors === 'string' ? JSON.parse(dev.monitors || '[]') : []);
        let monitorsHtml = "";
        if (monitors.length > 0) {
            monitorsHtml = '<div class="d-flex flex-wrap gap-1 mt-1">';
            for (const m of monitors) {
                const mModel = escapeHtml(m.Model || 'Monitor');
                const mSn = escapeHtml(m.SerialNumber || 'Brak SN');
                const mManuf = escapeHtml(m.Manufacturer || '');
                monitorsHtml += `<span class="badge bg-secondary-subtle text-secondary-emphasis border py-1 px-2" title="Producent: ${mManuf}, Model: ${mModel}"><i class="bi bi-display me-1 text-info"></i>${mModel} <strong class="ms-1">SN:</strong> ${mSn}</span>`;
            }
            monitorsHtml += '</div>';
        } else {
            monitorsHtml = '<div class="text-muted small mt-1" style="font-size: 0.75rem;"><i class="bi bi-display me-1 text-secondary"></i>Brak monitora w WMI</div>';
        }

        const snHtml = `
            <div>
                <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-dark-subtle text-dark border font-monospace py-1 px-2" title="Numer seryjny BIOS komputera">
                        <i class="bi bi-pc me-1 text-primary"></i>${escapeHtml(devSn)}
                    </span>
                    ${devSn !== 'Brak SN' ? `<button class="btn btn-sm btn-outline-secondary p-0 px-1 border-0" onclick="copySnToClipboard('${escapeHtml(devSn)}', event)" title="Kopiuj numer seryjny"><i class="bi bi-clipboard"></i></button>` : ''}
                </div>
                ${monitorsHtml}
            </div>
        `;

        // Antywirus
        let avBadge = '';
        if (dev.antivirus) {
            const isAvActive = !dev.antivirus.includes('Nieaktywny');
            const avClass = isAvActive ? 'bg-success-subtle text-success-emphasis border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
            const avIcon = isAvActive ? 'bi-shield-check' : 'bi-shield-exclamation';
            avBadge = `<div class="mt-1"><span class="badge ${avClass} border py-1" title="Zainstalowany antywirus w systemie"><i class="bi ${avIcon} me-1"></i>${escapeHtml(dev.antivirus)}</span></div>`;
        } else {
            avBadge = `<div class="mt-1"><span class="badge bg-light text-muted border py-1"><i class="bi bi-shield me-1"></i>Brak danych AV</span></div>`;
        }

        // Oprogramowanie
        const swList = Array.isArray(dev.installed_software) ? dev.installed_software : (typeof dev.installed_software === 'string' ? JSON.parse(dev.installed_software || '[]') : []);
        const swCount = swList.length;
        const swBtn = swCount > 0 
            ? `<div class="mt-1"><button class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 0.75rem;" onclick="openSoftwareModal(${dev.id}, event)" title="Pokaż ${swCount} aplikacji"><i class="bi bi-box-seam me-1"></i>${swCount} programów</button></div>`
            : '';

        const printerTests = (dev.latest_tests || []).filter(t => t.check_key && t.check_key.startsWith('printer'));
        let printersBadgesHtml = "";

        if (printerTests.length > 0) {
            printersBadgesHtml = `<div class="d-flex flex-wrap gap-1">`;
            for (const pt of printerTests) {
                let badgeClass = "badge bg-secondary-subtle text-secondary-emphasis border";
                let iconClass = "bi-printer";
                if (pt.status === "OK") {
                    badgeClass = "badge bg-success-subtle text-success-emphasis border border-success-subtle";
                    iconClass = "bi-check-circle";
                } else if (pt.status === "ERROR") {
                    badgeClass = "badge bg-danger-subtle text-danger-emphasis border border-danger-subtle";
                    iconClass = "bi-x-circle";
                } else if (pt.status === "WARNING") {
                    badgeClass = "badge bg-warning-subtle text-warning-emphasis border border-warning-subtle";
                    iconClass = "bi-exclamation-triangle";
                }

                let shortLabel = pt.check_name || pt.check_key || 'Drukarka';
                if (pt.check_key === 'printer_korytarz_ricoh') shortLabel = 'Ricoh (15.60)';
                else if (pt.check_key === 'printer_korytarz_konica') shortLabel = 'Konica (15.50)';
                else if (pt.check_key === 'printer_room') shortLabel = (pt.check_name ? pt.check_name.replace('Drukarka w pokoju', 'Pokój').replace('Drukarka pokoj', 'Pokój') : 'Pokój');

                printersBadgesHtml += `
                    <span class="${badgeClass} py-1 px-2" title="${escapeHtml(pt.message || '')}">
                        <i class="bi ${iconClass} me-1"></i><strong>${escapeHtml(shortLabel)}:</strong> ${escapeHtml(pt.status)}
                    </span>
                `;
            }
            printersBadgesHtml += `</div>`;
        } else {
            printersBadgesHtml = `<div class="text-muted small"><i class="bi bi-printer me-1"></i>Brak zarejestrowanych drukarek</div>`;
        }

        rowsHtml += `
            <tr id="device-row-${dev.id}">
                <td>
                    <div class="d-flex align-items-center">
                        <div class="bg-light p-2 rounded me-2 border text-primary">
                            <i class="bi bi-pc-display fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-dark fs-6">${hostnameText}</div>
                            <div class="mt-1">
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle py-1" title="Zalogowane konto">
                                    <i class="bi bi-person-fill me-1"></i>${userText}
                                </span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="fw-semibold text-dark small">${deptText}</div>
                            <div class="text-muted small mt-1">
                                <i class="bi bi-door-open me-1 text-secondary"></i>${roomText}
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary p-1 ms-2" onclick="openEditDeviceModal(${dev.id})" title="Zmień referat i pokój">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                    </div>
                </td>
                <td>
                    ${snHtml}
                </td>
                <td>
                    <span class="font-monospace text-dark fw-bold">${ipText}</span>
                </td>
                <td>
                    ${printersBadgesHtml}
                </td>
                <td>
                    <div class="text-dark small fw-semibold">${osText}</div>
                    ${osVerHtml}
                    ${avBadge}
                    <div class="mt-1">${officeBadge}</div>
                    <div class="mt-1">${updatesBadge}</div>
                    ${swBtn}
                </td>
                <td>
                    <div class="text-dark small">${relativeLastSeen}</div>
                    <div class="text-muted small" style="font-size: 0.75rem;">${fullLastSeen}</div>
                </td>
                <td>${statusBadge}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openDeviceModal(${dev.id})">
                        <i class="bi bi-sliders me-1"></i>Testy
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="openHistorySection(${dev.id}, '${hostnameText}')">
                        <i class="bi bi-clock-history me-1"></i>Historia
                    </button>
                </td>
            </tr>
        `;
    }
    tbody.innerHTML = rowsHtml;
}

function openEditDeviceModal(deviceId) {
    const dev = currentDevices.find(d => d.id === deviceId);
    if (!dev) return;

    document.getElementById("edit-device-id").value = dev.id;
    document.getElementById("edit-modal-hostname").textContent = `${dev.hostname} (${dev.ip_address || '-'})`;
    document.getElementById("edit-department").value = dev.department || "";
    document.getElementById("edit-room-number").value = dev.room_number || "";

    const modalEl = document.getElementById("editDeviceModal");
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function getStatusBadge(status) {
    switch (status) {
        case "ONLINE":
            return `<span class="badge badge-status-online px-2 py-1"><span class="status-dot dot-online"></span>ONLINE</span>`;
        case "OFFLINE":
            return `<span class="badge badge-status-offline px-2 py-1"><span class="status-dot dot-offline"></span>OFFLINE</span>`;
        default:
            return `<span class="badge badge-status-unknown px-2 py-1"><span class="status-dot dot-unknown"></span>NIEZNANY</span>`;
    }
}

function getTestStatusBadge(status) {
    switch (status) {
        case "OK":
            return `<span class="badge badge-status-ok"><i class="bi bi-check-circle me-1"></i>OK</span>`;
        case "ERROR":
            return `<span class="badge badge-status-error"><i class="bi bi-x-circle me-1"></i>BŁĄD</span>`;
        case "WARNING":
            return `<span class="badge badge-status-warning"><i class="bi bi-exclamation-triangle me-1"></i>OSTRZEŻENIE</span>`;
        case "SKIPPED":
            return `<span class="badge badge-status-skipped"><i class="bi bi-dash-circle me-1"></i>POMINIÄTY</span>`;
        default:
            return `<span class="badge badge-status-unknown">${escapeHtml(status)}</span>`;
    }
}

function openDeviceModal(deviceId) {
    activeModalDeviceId = deviceId;
    const dev = currentDevices.find(d => d.id === deviceId);
    if (!dev) return;

    renderModalContent(dev);

    const modalEl = document.getElementById("deviceDetailModal");
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    modalEl.addEventListener("hidden.bs.modal", () => {
        if (activeModalDeviceId === deviceId) {
            activeModalDeviceId = null;
        }
    }, { once: true });
}

function renderModalContent(dev) {
    document.getElementById("modal-device-hostname").textContent = dev.hostname || "Szczegóły stacji";
    document.getElementById("modal-host-name").textContent = `${dev.hostname || '-'} [Użytkownik: ${dev.logged_user || 'Brak sesji'}]`;
    document.getElementById("modal-ip").textContent = dev.ip_address || "-";
    document.getElementById("modal-gateway").textContent = dev.gateway || "-";
    document.getElementById("modal-dns").textContent = dev.dns_servers || "-";
    
    let updatesInfo = "Aktualny";
    if (dev.pending_reboot == 1) {
        updatesInfo = "Wymagany restart stacji!";
    }
    if (dev.last_update_date) {
        updatesInfo += ` (${dev.last_update_date})`;
    }

    const deptAndRoom = [dev.department, dev.room_number ? `Pokój: ${dev.room_number}` : ''].filter(Boolean).join(' | ');

    document.getElementById("modal-os").textContent = `${dev.os_name || '-'} (${dev.os_version || '-'}) | WU: ${updatesInfo} | Office: ${dev.office_version || "Brak"}`;
    document.getElementById("modal-agent").textContent = `${dev.agent_version || '1.1.0'} | Lokalizacja: ${deptAndRoom || 'Nie przypisano'}`;
    document.getElementById("modal-last-seen").textContent = `${dev.last_seen_relative || 'Nigdy'} (${dev.last_seen || '-'})`;

    const statusPill = document.getElementById("modal-status-pill");
    if (statusPill) {
        statusPill.innerHTML = getStatusBadge(dev.computed_status);
    }

    // Sprzęt i Numery Seryjne w modalu
    const devSn = (dev.serial_number && dev.serial_number.trim()) ? dev.serial_number.trim() : 'Brak danych';
    const snEl = document.getElementById("modal-comp-sn");
    if (snEl) snEl.textContent = devSn;

    const copyBtn = document.getElementById("btn-copy-modal-sn");
    if (copyBtn) {
        copyBtn.onclick = (e) => copySnToClipboard(devSn, e);
    }

    const snBadgeEl = document.getElementById("modal-sn-badge");
    if (snBadgeEl) {
        snBadgeEl.innerHTML = `<span class="badge bg-primary-subtle text-primary border font-monospace"><i class="bi bi-hash me-1"></i>${escapeHtml(devSn)}</span>`;
    }

    // Antywirus w modalu
    const avContainer = document.getElementById("modal-antivirus-container");
    if (avContainer) {
        if (dev.antivirus) {
            const isAvActive = !dev.antivirus.includes("Nieaktywny");
            const avClass = isAvActive ? "bg-success-subtle text-success-emphasis border-success-subtle" : "bg-warning-subtle text-warning-emphasis border-warning-subtle";
            const avIcon = isAvActive ? "bi-shield-check" : "bi-shield-exclamation";
            avContainer.innerHTML = `<span class="badge ${avClass} border py-1 px-2"><i class="bi ${avIcon} me-1"></i>${escapeHtml(dev.antivirus)}</span>`;
        } else {
            avContainer.innerHTML = `<span class="badge bg-light text-muted border py-1 px-2"><i class="bi bi-shield me-1"></i>Brak danych</span>`;
        }
    }

    // Monitory w modalu
    const monContainer = document.getElementById("modal-monitors-container");
    if (monContainer) {
        const monitors = Array.isArray(dev.monitors) ? dev.monitors : (typeof dev.monitors === 'string' ? JSON.parse(dev.monitors || '[]') : []);
        if (monitors.length > 0) {
            let monHtml = `
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-bordered bg-white mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Producent</th>
                                <th>Model monitora</th>
                                <th>Numer seryjny (SN)</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            for (const m of monitors) {
                const mSn = escapeHtml(m.SerialNumber || "Brak SN");
                monHtml += `
                    <tr>
                        <td class="fw-semibold">${escapeHtml(m.Manufacturer || "Nieznany")}</td>
                        <td><i class="bi bi-display me-1 text-primary"></i>${escapeHtml(m.Model || "Monitor")}</td>
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <span class="font-monospace text-dark fw-bold">${mSn}</span>
                                ${mSn !== "Brak SN" ? `<button class="btn btn-xs btn-outline-secondary p-0 px-1 border-0" onclick="copySnToClipboard('${mSn}', event)" title="Kopiuj"><i class="bi bi-clipboard"></i></button>` : ""}
                            </div>
                        </td>
                    </tr>
                `;
            }
            monHtml += `</tbody></table></div>`;
            monContainer.innerHTML = monHtml;
        } else {
            monContainer.innerHTML = `<span class="text-muted small"><i class="bi bi-display me-1"></i>Brak wykrytych monitorów w rejestrze WMI</span>`;
        }
    }

    // Oprogramowanie w modalu
    const officeEl = document.getElementById("modal-office-val");
    if (officeEl) officeEl.textContent = dev.office_version || "Brak pakietu Office";

    const wuEl = document.getElementById("modal-wu-val");
    if (wuEl) wuEl.textContent = updatesInfo;

    const swList = Array.isArray(dev.installed_software) ? dev.installed_software : (typeof dev.installed_software === 'string' ? JSON.parse(dev.installed_software || '[]') : []);
    const swCount = swList.length;
    const swCountEl = document.getElementById("modal-sw-count-val");
    if (swCountEl) swCountEl.textContent = `${swCount} aplikacji`;

    const openSwBtn = document.getElementById("btn-open-software-modal");
    if (openSwBtn) {
        openSwBtn.onclick = () => openSoftwareModal(dev.id);
    }

    const testsContainer = document.getElementById("modal-tests-container");
    if (!testsContainer) return;

    const tests = dev.latest_tests || [];
    if (tests.length === 0) {
        testsContainer.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="bi bi-hourglass-split fs-2 d-block mb-2"></i>
                Brak zarejestrowanych testów dla tego komputera. Wykonaj raport w PowerShell.
            </div>
        `;
        return;
    }

    let testsHtml = `
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 28%;">Nazwa testu</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 12%;">Czas</th>
                        <th style="width: 30%;">Opis wyniku</th>
                        <th style="width: 15%;">Sprawdzono</th>
                    </tr>
                </thead>
                <tbody>
    `;

    for (const t of tests) {
        const badge = getTestStatusBadge(t.status);
        const dur = t.duration_ms !== null && t.duration_ms !== undefined ? `${t.duration_ms} ms` : '-';
        const checkedAt = escapeHtml(t.checked_at || '-');
        const msg = escapeHtml(t.message || '-');

        testsHtml += `
            <tr>
                <td class="fw-semibold text-dark">${escapeHtml(t.check_name || t.check_key)}</td>
                <td>${badge}</td>
                <td class="font-monospace small">${dur}</td>
                <td class="small text-secondary">${msg}</td>
                <td class="small text-muted">${checkedAt}</td>
            </tr>
        `;
    }

    testsHtml += `</tbody></table></div>`;
    testsContainer.innerHTML = testsHtml;
}

async function loadIncidents() {
    const container = document.getElementById("incidents-container");
    if (!container) return;

    try {
        const res = await fetch("api/incidents.php?limit=10", { cache: "no-store" });
        if (!res.ok) return;
        const data = await res.json();

        if (data.success && data.data) {
            const list = data.data;
            if (list.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4 text-muted small">
                        <i class="bi bi-shield-check text-success fs-3 d-block mb-1"></i>
                        Brak zarejestrowanych incydentów. Wszystkie usługi działają prawidłowo.
                    </div>
                `;
                return;
            }

            let html = `<ul class="list-group list-group-flush">`;
            for (const inc of list) {
                const isOpen = inc.status === "OPEN";
                const badge = isOpen 
                    ? `<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-exclamation-triangle me-1"></i>AWARIA</span>`
                    : `<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check-circle me-1"></i>ROZWIĄZANO</span>`;

                const timeText = isOpen 
                    ? `Wykryto: ${escapeHtml(inc.opened_at_relative || inc.opened_at)}`
                    : `Rozwiązano: ${escapeHtml(inc.resolved_at_relative || inc.resolved_at)}`;

                html += `
                    <li class="list-group-item d-flex justify-content-between align-items-start py-3 px-3">
                        <div class="ms-2 me-auto">
                            <div class="d-flex align-items-center mb-1">
                                <span class="fw-semibold text-dark me-2">${escapeHtml(inc.title)}</span>
                                ${badge}
                            </div>
                            <div class="small text-muted mb-1">Komputer: <strong>${escapeHtml(inc.hostname)}</strong> | Usługa: <strong>${escapeHtml(inc.check_name)}</strong></div>
                            <div class="small text-secondary">${escapeHtml(inc.description || '')}</div>
                        </div>
                        <span class="text-muted small">${timeText}</span>
                    </li>
                `;
            }
            html += `</ul>`;
            container.innerHTML = html;
        }
    } catch (e) {
        console.error("Błąd ładowania incydentów:", e);
    }
}

function openHistorySection(deviceId, hostname) {
    currentHistoryDeviceId = deviceId;
    historyCurrentPage = 1;

    document.getElementById("history-hostname-title").textContent = hostname;
    document.getElementById("history-section").scrollIntoView({ behavior: "smooth" });

    loadHistory(deviceId, 1);
}

async function loadHistory(deviceId, page = 1) {
    if (!deviceId) return;
    const tbody = document.getElementById("history-table-body");
    const pagination = document.getElementById("history-pagination");
    if (!tbody) return;

    tbody.innerHTML = `
        <tr>
            <td colspan="5" class="text-center py-4 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Ładowanie historii testów...
            </td>
        </tr>
    `;

    const checkKey = document.getElementById("filter-check-key").value;
    const status = document.getElementById("filter-status").value;
    const dateFrom = document.getElementById("filter-date-from").value;
    const dateTo = document.getElementById("filter-date-to").value;

    let url = `api/history.php?device_id=${encodeURIComponent(deviceId)}&page=${page}&limit=15`;
    if (checkKey) url += `&check_key=${encodeURIComponent(checkKey)}`;
    if (status) url += `&status=${encodeURIComponent(status)}`;
    if (dateFrom) url += `&date_from=${encodeURIComponent(dateFrom)}`;
    if (dateTo) url += `&date_to=${encodeURIComponent(dateTo)}`;

    try {
        const res = await fetch(url, { cache: "no-store" });
        const data = await res.json();

        if (data.success) {
            updateAvailableChecksDropdown(data.filters?.available_checks || []);

            const rows = data.data || [];
            if (rows.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">
                            Brak wyników spełniających kryteria wyszukiwania.
                        </td>
                    </tr>
                `;
                pagination.innerHTML = "";
                return;
            }

            let html = "";
            for (const r of rows) {
                const badge = getTestStatusBadge(r.status);
                const dur = r.duration_ms !== null ? `${r.duration_ms} ms` : '-';
                html += `
                    <tr>
                        <td class="fw-semibold text-dark">${escapeHtml(r.check_name || r.check_key)}</td>
                        <td>${badge}</td>
                        <td class="font-monospace small">${dur}</td>
                        <td class="small text-secondary">${escapeHtml(r.message || '-')}</td>
                        <td class="small text-muted">${escapeHtml(r.checked_at)}</td>
                    </tr>
                `;
            }
            tbody.innerHTML = html;
            renderPagination(data.page, data.pages_count, deviceId);
        }
    } catch (e) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-4 text-danger">Błąd pobierania historii.</td>
            </tr>
        `;
    }
}

function updateAvailableChecksDropdown(checks) {
    const select = document.getElementById("filter-check-key");
    if (!select || select.options.length > 1) return;

    for (const c of checks) {
        const opt = document.createElement("option");
        opt.value = c.check_key;
        opt.textContent = c.check_name;
        select.appendChild(opt);
    }
}

function renderPagination(currentPage, totalPages, deviceId) {
    const el = document.getElementById("history-pagination");
    if (!el || totalPages <= 1) {
        if (el) el.innerHTML = "";
        return;
    }

    let html = `<ul class="pagination pagination-sm mb-0">`;
    html += `
        <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
            <button class="page-link" onclick="loadHistory(${deviceId}, ${currentPage - 1})">Poprzednia</button>
        </li>
    `;

    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= currentPage - 2 && p <= currentPage + 2)) {
            html += `
                <li class="page-item ${p === currentPage ? 'active' : ''}">
                    <button class="page-link" onclick="loadHistory(${deviceId}, ${p})">${p}</button>
                </li>
            `;
        } else if (p === currentPage - 3 || p === currentPage + 3) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }

    html += `
        <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
            <button class="page-link" onclick="loadHistory(${deviceId}, ${currentPage + 1})">Następna</button>
        </li>
    `;
    html += `</ul>`;
    el.innerHTML = html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


let currentModalSoftware = [];

function copySnToClipboard(text, event) {
    if (event) {
        event.stopPropagation();
    }
    if (!text || text === "Brak SN" || text === "Brak danych") return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showCopyToast(text);
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand("copy");
        showCopyToast(text);
    } catch (e) {}
    document.body.removeChild(ta);
}

function showCopyToast(text) {
    let toast = document.getElementById("copy-toast-notification");
    if (!toast) {
        toast = document.createElement("div");
        toast.id = "copy-toast-notification";
        toast.className = "position-fixed bottom-0 end-0 p-3";
        toast.style.zIndex = "11000";
        toast.innerHTML = `
            <div class="toast align-items-center text-white bg-dark border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body small">
                        <i class="bi bi-clipboard-check text-success me-2"></i>Skopiowano do schowka: <strong id="copy-toast-text"></strong>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        document.body.appendChild(toast);
    }
    const txtEl = document.getElementById("copy-toast-text");
    if (txtEl) txtEl.textContent = text;
    toast.style.display = "block";
    setTimeout(() => {
        if (toast) toast.style.display = "none";
    }, 2500);
}

function openSoftwareModal(deviceId, event) {
    if (event) {
        event.stopPropagation();
    }
    const dev = currentDevices.find(d => d.id === deviceId);
    if (!dev) return;

    const hostnameEl = document.getElementById("sw-modal-hostname");
    if (hostnameEl) hostnameEl.textContent = dev.hostname || "Stacja";

    const swRaw = dev.installed_software;
    currentModalSoftware = Array.isArray(swRaw) ? swRaw : (typeof swRaw === "string" ? JSON.parse(swRaw || "[]") : []);
    
    const countEl = document.getElementById("sw-modal-count");
    if (countEl) countEl.textContent = `Zarejestrowano ${currentModalSoftware.length} aplikacji w systemie`;

    const searchInput = document.getElementById("sw-search-input");
    if (searchInput) {
        searchInput.value = "";
        searchInput.oninput = (e) => {
            renderSoftwareTable(currentModalSoftware, e.target.value);
        };
    }

    renderSoftwareTable(currentModalSoftware, "");

    const modalEl = document.getElementById("installedSoftwareModal");
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

function renderSoftwareTable(softwareList, filterQuery) {
    const tbody = document.getElementById("sw-modal-table-body");
    if (!tbody) return;

    let filtered = softwareList;
    if (filterQuery && filterQuery.trim()) {
        const q = filterQuery.toLowerCase().trim();
        filtered = softwareList.filter(s => 
            (s.Name || "").toLowerCase().includes(q) ||
            (s.Version || "").toLowerCase().includes(q) ||
            (s.Publisher || "").toLowerCase().includes(q)
        );
    }

    if (!filtered || filtered.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="3" class="text-center py-4 text-muted">
                    <i class="bi bi-search me-1"></i>Brak programów odpowiadających filtrowi
                </td>
            </tr>
        `;
        return;
    }

    let rows = "";
    for (const app of filtered) {
        const name = escapeHtml(app.Name || "Aplikacja");
        const ver = escapeHtml(app.Version || "-");
        const pub = escapeHtml(app.Publisher || "-");
        rows += `
            <tr>
                <td class="fw-semibold text-dark"><i class="bi bi-box-seam me-2 text-primary opacity-75"></i>${name}</td>
                <td><span class="badge bg-light text-secondary border font-monospace">${ver}</span></td>
                <td class="small text-muted">${pub}</td>
            </tr>
        `;
    }
    tbody.innerHTML = rows;
}


// ==============================================================================
// MODUŁ AKTUALIZACJI SYSTEMU (GitHub Self-Updater)
// ==============================================================================

function initSystemUpdate() {
    const updateModalEl = document.getElementById("systemUpdateModal");
    const btnCheck = document.getElementById("btn-check-git-updates");
    const btnExecute = document.getElementById("btn-execute-git-update");

    if (updateModalEl) {
        updateModalEl.addEventListener("show.bs.modal", () => {
            fetchSystemUpdateStatus("status");
        });
    }

    if (btnCheck) {
        btnCheck.addEventListener("click", () => {
            fetchSystemUpdateStatus("check");
        });
    }

    if (btnExecute) {
        btnExecute.addEventListener("click", () => {
            if (confirm("Czy na pewno chcesz pobrać najnowszą wersję kodu z GitHuba i zaktualizować aplikację na serwerze?")) {
                executeSystemUpdate();
            }
        });
    }
}

async function fetchSystemUpdateStatus(action = "status") {
    const statusAlert = document.getElementById("update-status-alert");
    const statusText = document.getElementById("update-status-text");
    const spinner = document.getElementById("update-status-spinner");
    const consoleLog = document.getElementById("update-console-log");
    const btnExecute = document.getElementById("btn-execute-git-update");
    const btnCheck = document.getElementById("btn-check-git-updates");

    const commitEl = document.getElementById("update-cur-commit");
    const branchEl = document.getElementById("update-cur-branch");
    const dateEl = document.getElementById("update-cur-date");
    const msgEl = document.getElementById("update-cur-msg");
    const updateBadge = document.getElementById("update-badge");

    if (spinner) spinner.classList.remove("d-none");
    if (btnCheck) btnCheck.disabled = true;

    try {
        const res = await fetch(`api/system_update.php?action=${action}`);
        const data = await res.json();

        if (spinner) spinner.classList.add("d-none");
        if (btnCheck) btnCheck.disabled = false;

        if (!data.success && data.git_installed === false) {
            if (statusAlert) statusAlert.className = "alert alert-warning py-2 px-3 small mb-3";
            if (statusText) statusText.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Git nie jest zainstalowany w systemie. Zainstaluj pakiet <code>git</code> na serwerze.`;
            if (btnExecute) btnExecute.disabled = true;
            return;
        }

        if (data.is_git_repo === false) {
            if (statusAlert) statusAlert.className = "alert alert-info py-2 px-3 small mb-3";
            if (statusText) statusText.innerHTML = `<i class="bi bi-info-circle me-1"></i> ${data.message} <br><small class="text-muted mt-1 d-block">${data.hint || ""}</small>`;
            if (btnExecute) btnExecute.disabled = true;
            return;
        }

        const info = data.info || {};
        if (commitEl) commitEl.textContent = info.commit || "-";
        if (branchEl) branchEl.textContent = info.branch || "-";
        if (dateEl) dateEl.textContent = info.date || "-";
        if (msgEl) msgEl.textContent = info.message || "-";

        if (action === "check") {
            const updates = data.updates || {};
            if (consoleLog && updates.log) {
                consoleLog.textContent = updates.log;
            }

            if (updates.has_updates) {
                if (statusAlert) statusAlert.className = "alert alert-success py-2 px-3 small mb-3";
                if (statusText) statusText.innerHTML = `<i class="bi bi-arrow-down-circle-fill text-success me-1"></i> <strong>Dostępna nowa wersja!</strong> Liczba nowych zatwierdzeń na GitHubie: <strong>${updates.behind_count}</strong>.`;
                if (btnExecute) btnExecute.disabled = false;
                if (updateBadge) updateBadge.classList.remove("d-none");
            } else {
                if (statusAlert) statusAlert.className = "alert alert-secondary py-2 px-3 small mb-3";
                if (statusText) statusText.innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i> System jest aktualny (masz najnowszą wersję kodu z gałęzi <code>${info.branch}</code>).`;
                if (btnExecute) btnExecute.disabled = false;
                if (updateBadge) updateBadge.classList.add("d-none");
            }
        } else {
            if (btnExecute) btnExecute.disabled = false;
        }
    } catch (err) {
        if (spinner) spinner.classList.add("d-none");
        if (btnCheck) btnCheck.disabled = false;
        if (statusAlert) statusAlert.className = "alert alert-danger py-2 px-3 small mb-3";
        if (statusText) statusText.innerHTML = `<i class="bi bi-x-circle me-1"></i> Błąd połączenia z API aktualizacji: ${escapeHtml(err.message)}`;
    }
}

async function executeSystemUpdate() {
    const statusAlert = document.getElementById("update-status-alert");
    const statusText = document.getElementById("update-status-text");
    const spinner = document.getElementById("update-status-spinner");
    const consoleLog = document.getElementById("update-console-log");
    const btnExecute = document.getElementById("btn-execute-git-update");
    const btnCheck = document.getElementById("btn-check-git-updates");

    if (spinner) spinner.classList.remove("d-none");
    if (btnExecute) btnExecute.disabled = true;
    if (btnCheck) btnCheck.disabled = true;

    if (statusAlert) statusAlert.className = "alert alert-info py-2 px-3 small mb-3";
    if (statusText) statusText.innerHTML = `<i class="bi bi-cloud-arrow-down me-1"></i> Pobieranie najnowszego kodu i aktualizacja repozytorium...`;
    if (consoleLog) consoleLog.textContent = "Wykonywanie: git fetch && git reset --hard origin/main ...";

    try {
        const res = await fetch("api/system_update.php?action=update", {
            method: "POST"
        });
        const data = await res.json();

        if (spinner) spinner.classList.add("d-none");
        if (btnCheck) btnCheck.disabled = false;

        if (consoleLog && data.output) {
            consoleLog.textContent = data.output;
        }

        if (data.success) {
            if (statusAlert) statusAlert.className = "alert alert-success py-2 px-3 small mb-3";
            if (statusText) statusText.innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i> <strong>Sukces!</strong> ${data.message} (Nowy commit: ${data.new_commit}). Przeładuj stronę, aby zobaczyć zmiany.`;
            setTimeout(() => {
                window.location.reload();
            }, 2500);
        } else {
            if (statusAlert) statusAlert.className = "alert alert-danger py-2 px-3 small mb-3";
            if (statusText) statusText.innerHTML = `<i class="bi bi-x-circle me-1"></i> Błąd: ${escapeHtml(data.error || "Niepowodzenie aktualizacji")}`;
            if (btnExecute) btnExecute.disabled = false;
        }
    } catch (err) {
        if (spinner) spinner.classList.add("d-none");
        if (btnCheck) btnCheck.disabled = false;
        if (btnExecute) btnExecute.disabled = false;
        if (statusAlert) statusAlert.className = "alert alert-danger py-2 px-3 small mb-3";
        if (statusText) statusText.innerHTML = `<i class="bi bi-x-circle me-1"></i> Błąd żądania: ${escapeHtml(err.message)}`;
    }
}