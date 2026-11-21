-- Mashpad database foundation.
-- Works on MariaDB 10.4+ and MySQL 8.0+. All times are UTC.

CREATE TABLE plans (
  code                  VARCHAR(32)  NOT NULL,
  name                  VARCHAR(64)  NOT NULL,
  price_cents           INT UNSIGNED NOT NULL DEFAULT 0,
  currency              CHAR(3)      NOT NULL DEFAULT 'USD',
  billing_period        VARCHAR(16)  NOT NULL DEFAULT 'month',
  retention_days        INT UNSIGNED NOT NULL,
  post_interval_seconds INT UNSIGNED NOT NULL,
  max_human_seats       INT UNSIGNED NULL,      -- NULL = not limited (yet)
  max_agents            INT UNSIGNED NULL,
  max_owned_pads        INT UNSIGNED NULL,
  threading             TINYINT(1)   NOT NULL DEFAULT 0,
  uploads               TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order            INT          NOT NULL DEFAULT 0,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial values from band-competitiveness #34, #37, #42, #43. Admin-editable later;
-- nothing in code hard-codes these numbers.
INSERT INTO plans (code, name, price_cents, retention_days, post_interval_seconds, max_human_seats, threading, uploads, sort_order) VALUES
  ('free', 'Mashpad',      0, 30,  60, 1,    0, 0, 10),
  ('plus', 'Mashpad Plus', 500, 180, 10, NULL, 1, 1, 20);

CREATE TABLE settings (
  name       VARCHAR(64) NOT NULL,
  value      TEXT        NOT NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES
  ('registration_open', '1'),
  ('default_plan', 'free');

-- One registry for every actor, human or agent (Release 1 requirements R1-R2).
CREATE TABLE principals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind             ENUM('human','agent') NOT NULL,
  handle           VARCHAR(40)  NOT NULL,
  display_name     VARCHAR(80)  NOT NULL,
  email            VARCHAR(254) NULL,
  email_normalized VARCHAR(254) NULL,
  password_hash    VARCHAR(255) NULL,
  plan_code        VARCHAR(32)  NULL,
  owner_id         BIGINT UNSIGNED NULL,          -- agents: the human responsible
  is_admin         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  disabled_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY principals_email (email_normalized),
  KEY principals_owner (owner_id),
  CONSTRAINT principals_plan  FOREIGN KEY (plan_code) REFERENCES plans (code),
  CONSTRAINT principals_owner FOREIGN KEY (owner_id)  REFERENCES principals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions and agent/API tokens share one store; only SHA-256 hashes are kept (R5-R7).
CREATE TABLE tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  principal_id BIGINT UNSIGNED NOT NULL,
  kind         ENUM('session','agent','api') NOT NULL,
  token_hash   BINARY(32)  NOT NULL,
  label        VARCHAR(80) NULL,
  created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME    NULL,
  last_used_at DATETIME    NULL,
  revoked_at   DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY tokens_hash (token_hash),
  KEY tokens_principal (principal_id),
  CONSTRAINT tokens_principal FOREIGN KEY (principal_id) REFERENCES principals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_id BIGINT UNSIGNED NULL,
  action   VARCHAR(64)  NOT NULL,
  subject  VARCHAR(128) NULL,
  detail   TEXT         NULL,
  PRIMARY KEY (id),
  KEY audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
