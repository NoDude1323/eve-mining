CREATE TABLE IF NOT EXISTS eve_mining_state (
  state_key VARCHAR(64) NOT NULL PRIMARY KEY,
  state_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_market_cache (
  cache_key VARCHAR(191) NOT NULL PRIMARY KEY,
  source VARCHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_market_order_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  region_id INT NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  type_id INT NOT NULL,
  item_name VARCHAR(128) NOT NULL,
  region_best_buy DECIMAL(20,8) NULL,
  region_best_sell DECIMAL(20,8) NULL,
  station_best_buy DECIMAL(20,8) NULL,
  station_best_sell DECIMAL(20,8) NULL,
  region_buy_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  region_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  station_buy_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  station_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  region_buy_order_count INT UNSIGNED NOT NULL DEFAULT 0,
  region_sell_order_count INT UNSIGNED NOT NULL DEFAULT 0,
  station_buy_order_count INT UNSIGNED NOT NULL DEFAULT 0,
  station_sell_order_count INT UNSIGNED NOT NULL DEFAULT 0,
  source VARCHAR(64) NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_snapshot_lookup (region_id, station_id, type_id, fetched_at),
  KEY idx_snapshot_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_sso_states (
  state_key VARCHAR(128) NOT NULL PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_sso_characters (
  character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  character_name VARCHAR(128) NOT NULL,
  owner_hash VARCHAR(191) NULL,
  scopes_json TEXT NOT NULL,
  access_token TEXT NOT NULL,
  refresh_token TEXT NULL,
  token_expires_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
