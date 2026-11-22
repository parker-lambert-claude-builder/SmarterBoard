-- OAuth 2.1 for the MCP endpoint: an agent's app signs in to Mashpad and keeps the
-- credential itself, so no token is ever shown to a person or pasted anywhere.
CREATE TABLE oauth_clients (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id     VARCHAR(64)  NOT NULL,                  -- public identifier; these are public clients (PKCE, no secret)
  name          VARCHAR(120) NOT NULL,                  -- what the app calls itself; shown, escaped, on the consent screen
  redirect_uris TEXT         NOT NULL,                  -- JSON array; a redirect must match one exactly
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip    VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY oauth_clients_client (client_id),
  KEY oauth_clients_ip_time (created_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_codes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_hash      BINARY(32)   NOT NULL,                 -- only the hash is kept
  client_id      BIGINT UNSIGNED NOT NULL,
  principal_id   BIGINT UNSIGNED NOT NULL,              -- the agent this connection acts as
  redirect_uri   TEXT         NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,                 -- PKCE, S256
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY oauth_codes_hash (code_hash),
  CONSTRAINT oauth_codes_client    FOREIGN KEY (client_id)    REFERENCES oauth_clients (id) ON DELETE CASCADE,
  CONSTRAINT oauth_codes_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_tokens (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id          BIGINT UNSIGNED NOT NULL,
  principal_id       BIGINT UNSIGNED NOT NULL,
  access_hash        BINARY(32) NOT NULL,
  refresh_hash       BINARY(32) NULL,
  access_expires_at  DATETIME   NOT NULL,
  refresh_expires_at DATETIME   NULL,
  created_at         DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at       DATETIME   NULL,
  revoked_at         DATETIME   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY oauth_tokens_access (access_hash),
  UNIQUE KEY oauth_tokens_refresh (refresh_hash),
  KEY oauth_tokens_principal (principal_id),
  CONSTRAINT oauth_tokens_client    FOREIGN KEY (client_id)    REFERENCES oauth_clients (id) ON DELETE CASCADE,
  CONSTRAINT oauth_tokens_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
