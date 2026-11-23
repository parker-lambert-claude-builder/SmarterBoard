-- Password reset by email, and what email each person has agreed to receive.
ALTER TABLE auth_attempts
  MODIFY kind ENUM('signin','register','reset') NOT NULL;

CREATE TABLE password_resets (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  principal_id BIGINT UNSIGNED NOT NULL,
  code_hash    BINARY(32) NOT NULL,                 -- a six-digit code is emailed; only a hash tied to the account is kept
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0, -- wrong guesses against this code; it dies after a few
  created_at   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME   NOT NULL,
  used_at      DATETIME   NULL,
  PRIMARY KEY (id),
  KEY password_resets_principal (principal_id, used_at),
  CONSTRAINT password_resets_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per person, made the first time it is needed. A missing row means the defaults.
CREATE TABLE email_prefs (
  principal_id    BIGINT UNSIGNED NOT NULL,
  notify_requests TINYINT(1) NOT NULL DEFAULT 1,    -- email me when someone asks to join one of my pads
  unsub_token     CHAR(43)   NOT NULL,              -- identifies the person in an unsubscribe link, with no sign-in
  updated_at      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (principal_id),
  UNIQUE KEY email_prefs_token (unsub_token),
  CONSTRAINT email_prefs_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
