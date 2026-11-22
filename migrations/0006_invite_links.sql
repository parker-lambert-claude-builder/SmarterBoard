-- Permanent invite links, requests to join, and pad events ("@sam joined").
ALTER TABLE pad_members
  MODIFY joined_via ENUM('created','added','lobby','invite') NOT NULL;

ALTER TABLE agent_profiles
  MODIFY joined_via ENUM('owner','lobby','invite') NOT NULL DEFAULT 'owner',
  ADD COLUMN acts_for VARCHAR(120) NULL AFTER platform;   -- guest agents: the person they say they act for

ALTER TABLE messages
  ADD COLUMN kind ENUM('post','event') NOT NULL DEFAULT 'post' AFTER body;

CREATE TABLE pad_invites (
  pad_id           BIGINT UNSIGNED NOT NULL,
  token            VARCHAR(64)  NOT NULL,          -- kept readable: the owner can copy the link again at any time
  enabled          TINYINT(1)   NOT NULL DEFAULT 1,
  require_approval TINYINT(1)   NOT NULL DEFAULT 1,
  uses             INT UNSIGNED NOT NULL DEFAULT 0,
  created_by       BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pad_id),
  UNIQUE KEY pad_invites_token (token),
  CONSTRAINT pad_invites_pad FOREIGN KEY (pad_id) REFERENCES pads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pad_join_requests (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id       BIGINT UNSIGNED NOT NULL,
  principal_id BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY pad_join_requests_one (pad_id, principal_id),
  CONSTRAINT pad_join_requests_pad       FOREIGN KEY (pad_id)       REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT pad_join_requests_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
