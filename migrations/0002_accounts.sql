-- Human sign-in: unique human handles (race-proof) and attempt tracking for rate limits.

ALTER TABLE principals
  ADD COLUMN human_handle VARCHAR(40) GENERATED ALWAYS AS (IF(kind = 'human', handle, NULL)) STORED,
  ADD UNIQUE KEY principals_human_handle (human_handle);

CREATE TABLE auth_attempts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind             ENUM('signin','register') NOT NULL,
  ip               VARCHAR(45)  NOT NULL,
  email_normalized VARCHAR(254) NULL,
  success          TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY attempts_ip (kind, ip, at),
  KEY attempts_email (kind, email_normalized, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
