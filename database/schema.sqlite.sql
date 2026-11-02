PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_uuid TEXT NOT NULL UNIQUE,
    hostname TEXT NOT NULL,
    department TEXT DEFAULT NULL,
    room_number TEXT DEFAULT NULL,
    logged_user TEXT DEFAULT NULL,
    updates_status TEXT DEFAULT 'OK',
    last_update_date TEXT DEFAULT NULL,
    pending_reboot INTEGER DEFAULT 0,
    os_name TEXT,
    os_version TEXT,
    ip_address TEXT,
    gateway TEXT,
    dns_servers TEXT,
    agent_version TEXT,
    first_seen DATETIME,
    last_seen DATETIME,
    overall_status TEXT DEFAULT 'UNKNOWN',
    is_active INTEGER DEFAULT 1,
    office_version TEXT DEFAULT NULL,
    serial_number TEXT DEFAULT NULL,
    monitors TEXT DEFAULT NULL,
    antivirus TEXT DEFAULT NULL,
    installed_software TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    token_hint TEXT,
    name TEXT DEFAULT 'Główny token agenta',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS check_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL,
    check_key TEXT NOT NULL,
    check_name TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT,
    duration_ms INTEGER,
    checked_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS incidents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL,
    check_key TEXT NOT NULL,
    check_name TEXT NOT NULL,
    severity TEXT DEFAULT 'ERROR',
    title TEXT NOT NULL,
    description TEXT,
    opened_at DATETIME NOT NULL,
    resolved_at DATETIME DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'OPEN',
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_devices_hostname ON devices(hostname);
CREATE INDEX IF NOT EXISTS idx_devices_last_seen ON devices(last_seen);
CREATE INDEX IF NOT EXISTS idx_device_tokens_hash ON device_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_check_results_device_checked ON check_results(device_id, checked_at DESC);
CREATE INDEX IF NOT EXISTS idx_check_results_key ON check_results(device_id, check_key, checked_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents(device_id, status, opened_at DESC);

