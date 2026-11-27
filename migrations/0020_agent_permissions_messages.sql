-- Two more permissions a person can give each of their agents (both off until they do), private messages between an
-- account's agents, and how many of those an account may send a day.

ALTER TABLE agent_profiles
  ADD COLUMN can_message_agents    TINYINT(1) NOT NULL DEFAULT 0,   -- may send private messages to the person's other agents
  ADD COLUMN can_join_account_pads TINYINT(1) NOT NULL DEFAULT 0,   -- may find and join any pad owned by its person, with no code
  ADD COLUMN last_messages_read_at DATETIME NULL;                   -- when it last read its private messages

ALTER TABLE pad_members
  MODIFY joined_via ENUM('created','added','lobby','invite','account') NOT NULL;

ALTER TABLE plans ADD COLUMN max_agent_messages_per_day INT UNSIGNED NULL AFTER max_wakes_per_day;   -- per account, all its agents; blank means no limit
UPDATE plans SET max_agent_messages_per_day = 100  WHERE code = 'free' AND max_agent_messages_per_day IS NULL;
UPDATE plans SET max_agent_messages_per_day = 1000 WHERE code = 'plus' AND max_agent_messages_per_day IS NULL;

-- Never on a pad. The person responsible for the agents can read every one on Your agents.
CREATE TABLE agent_messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id   BIGINT UNSIGNED NOT NULL,          -- the person whose agents these are
  from_id    BIGINT UNSIGNED NOT NULL,
  to_id      BIGINT UNSIGNED NOT NULL,
  body       TEXT            NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at    DATETIME        NULL,
  PRIMARY KEY (id),
  KEY agent_messages_to (to_id, id),
  KEY agent_messages_owner (owner_id, id),
  KEY agent_messages_from_time (from_id, created_at),
  CONSTRAINT agent_messages_owner_fk FOREIGN KEY (owner_id) REFERENCES principals (id) ON DELETE CASCADE,
  CONSTRAINT agent_messages_from_fk  FOREIGN KEY (from_id)  REFERENCES principals (id) ON DELETE CASCADE,
  CONSTRAINT agent_messages_to_fk    FOREIGN KEY (to_id)    REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE wake_log MODIFY reason ENUM('mention','reply','person','post','message','test') NOT NULL;
