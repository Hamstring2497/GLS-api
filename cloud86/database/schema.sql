CREATE TABLE IF NOT EXISTS deliveries (
    exact_entry_id VARCHAR(64) PRIMARY KEY,
    delivery_number VARCHAR(64) NOT NULL,
    order_number VARCHAR(64) NOT NULL,
    ship_type CHAR(1) NOT NULL DEFAULT 'P',
    shipment_reference VARCHAR(64) NULL,
    address_json JSON NOT NULL,
    shipping_date DATE NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    tracking_numbers_json JSON NOT NULL,
    gls_request_json JSON NULL,
    gls_response_json JSON NULL,
    label_path VARCHAR(255) NULL,
    exact_tracking_synced_at DATETIME NULL,
    last_gls_state VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parcels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exact_entry_id VARCHAR(64) NOT NULL,
    position INT UNSIGNED NOT NULL,
    weight_kg DECIMAL(8,3) NOT NULL,
    UNIQUE KEY parcels_delivery_position (exact_entry_id, position),
    CONSTRAINT parcels_delivery_fk FOREIGN KEY (exact_entry_id)
        REFERENCES deliveries(exact_entry_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key CHAR(64) NOT NULL UNIQUE,
    parcel_no VARCHAR(64) NOT NULL DEFAULT '',
    payload_json JSON NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX webhook_parcel_no (parcel_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exact_tokens (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    division INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'warehouse') NOT NULL DEFAULT 'warehouse',
    active TINYINT(1) NOT NULL DEFAULT 1,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

