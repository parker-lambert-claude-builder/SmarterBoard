-- Waking agents: Mashpad sends a web request to an address a person set up for their agent, so the agent can be
-- started when a pad needs it. The person sets the address (and any token), and chooses per pad what wakes the agent.

-- How many times a day an agent may be woken, per plan; edited on Admin > Plans. Blank means no limit.
ALTER TABLE plans ADD COLUMN max_wakes_per_day INT UNSIGNED NULL AFTER max_agents;
UPDATE plans SET max_wakes_per_day = 20  WHERE code = 'free' AND max_wakes_per_day IS NULL;
UPDATE plans SET max_wakes_per_day = 200 WHERE code = 'plus' AND max_wakes_per_day IS NULL;

-- Per pad, per agent: what wakes it. "people" is mentions, replies to its posts, and posts by a person.
ALTER TABLE pad_members
  ADD COLUMN wake_mode    ENUM('off','mentions','people','everything') NOT NULL DEFAULT 'people',
  ADD COLUMN last_wake_at DATETIME NULL;

-- Where an agent is woken. Tokens and secrets are sealed with the site's key, never stored in the clear.
CREATE TABLE agent_wake_targets (
  principal_id    BIGINT UNSIGNED NOT NULL,
  kind            ENUM('routine','webhook') NOT NULL,   -- a Claude Code routine's trigger, or a signed webhook
  url             VARCHAR(500) NOT NULL,
  token_sealed    VARCHAR(700) NULL,                    -- the bearer token a routine needs
  secret_sealed   VARCHAR(300) NULL,                    -- the secret a webhook's requests are signed with
  failures        INT UNSIGNED NOT NULL DEFAULT 0,      -- wake-ups in a row that could not be delivered
  paused_at       DATETIME NULL,
  pause_reason    VARCHAR(200) NULL,
  last_attempt_at DATETIME NULL,
  last_ok_at      DATETIME NULL,
  last_result     VARCHAR(200) NULL,
  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (principal_id),
  CONSTRAINT wake_targets_agent FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every wake-up, from queued to delivered or failed. It holds who, where and why: never any pad text.
CREATE TABLE wake_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id    BIGINT UNSIGNED NOT NULL,
  pad_id      BIGINT UNSIGNED NULL,
  post_seq    INT UNSIGNED NULL,
  from_id     BIGINT UNSIGNED NULL,
  reason      ENUM('mention','reply','person','post','test') NOT NULL,
  status      ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  http_status SMALLINT UNSIGNED NULL,
  note        VARCHAR(200) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  next_try_at DATETIME NULL,
  sent_at     DATETIME NULL,
  locked_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY wake_due (status, next_try_at),
  KEY wake_agent_time (agent_id, created_at),
  KEY wake_pad_time (pad_id, created_at),
  CONSTRAINT wake_log_agent FOREIGN KEY (agent_id) REFERENCES principals (id) ON DELETE CASCADE,
  CONSTRAINT wake_log_pad   FOREIGN KEY (pad_id)   REFERENCES pads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
