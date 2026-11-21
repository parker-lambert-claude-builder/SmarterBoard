-- Owned pads: membership, messages, agent identities and lobby codes.
-- Account-less pads stay in data/boards/*.json until cutover; nothing is migrated.

ALTER TABLE principals
  ADD UNIQUE KEY principals_agent_handle (owner_id, kind, handle);

CREATE TABLE agent_profiles (
  principal_id    BIGINT UNSIGNED NOT NULL,
  role            VARCHAR(80)  NULL,
  doing           TEXT         NULL,
  platform        VARCHAR(80)  NULL,
  webhook_url     VARCHAR(500) NULL,
  webhook_secret  VARCHAR(80)  NULL,
  can_create_pads TINYINT(1)   NOT NULL DEFAULT 0,
  joined_via      ENUM('owner','lobby') NOT NULL DEFAULT 'owner',
  self_described  TINYINT(1)   NOT NULL DEFAULT 0,
  last_active_at  DATETIME     NULL,
  PRIMARY KEY (principal_id),
  CONSTRAINT agent_profiles_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pads (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug             VARCHAR(64)  NOT NULL,
  name             VARCHAR(120) NOT NULL,
  owner_id         BIGINT UNSIGNED NOT NULL,
  created_by       BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  next_seq         INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY pads_slug (slug),
  KEY pads_owner (owner_id),
  KEY pads_activity (last_activity_at),
  CONSTRAINT pads_owner      FOREIGN KEY (owner_id)   REFERENCES principals (id),
  CONSTRAINT pads_created_by FOREIGN KEY (created_by) REFERENCES principals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lobby_codes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id     BIGINT UNSIGNED NOT NULL,
  code_hash  BINARY(32)   NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME     NOT NULL,
  revoked_at DATETIME     NULL,
  uses       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY lobby_codes_hash (code_hash),
  KEY lobby_codes_pad (pad_id),
  CONSTRAINT lobby_codes_pad FOREIGN KEY (pad_id) REFERENCES pads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pad_members (
  pad_id        BIGINT UNSIGNED NOT NULL,
  principal_id  BIGINT UNSIGNED NOT NULL,
  role          ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_via    ENUM('created','added','lobby') NOT NULL,
  lobby_code_id BIGINT UNSIGNED NULL,
  joined_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at    DATETIME NULL,
  PRIMARY KEY (pad_id, principal_id),
  KEY pad_members_principal (principal_id),
  CONSTRAINT pad_members_pad       FOREIGN KEY (pad_id)        REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT pad_members_principal FOREIGN KEY (principal_id)  REFERENCES principals (id) ON DELETE CASCADE,
  CONSTRAINT pad_members_lobby     FOREIGN KEY (lobby_code_id) REFERENCES lobby_codes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id     BIGINT UNSIGNED NOT NULL,
  seq        INT UNSIGNED    NOT NULL,          -- the #number people see, per pad
  author_id  BIGINT UNSIGNED NOT NULL,
  body       MEDIUMTEXT      NOT NULL,
  parent_id  BIGINT UNSIGNED NULL,              -- reply-to; surfaced on Plus later
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY messages_pad_seq (pad_id, seq),
  KEY messages_pad_author_time (pad_id, author_id, created_at),
  CONSTRAINT messages_pad    FOREIGN KEY (pad_id)    REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT messages_author FOREIGN KEY (author_id) REFERENCES principals (id),
  CONSTRAINT messages_parent FOREIGN KEY (parent_id) REFERENCES messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
