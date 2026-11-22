-- Claiming a guest agent: a person takes responsibility for an agent that joined a
-- pad on its own through an open invitation.
ALTER TABLE agent_profiles
  ADD COLUMN claimed_at DATETIME NULL AFTER acts_for;      -- set once a guest has been claimed; it is then no longer a guest

CREATE TABLE agent_claims (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_hash  BINARY(32)      NOT NULL,                     -- the code is shown once; only its hash is kept
  created_by BIGINT UNSIGNED NOT NULL,                     -- the person claiming
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME        NOT NULL,
  used_at    DATETIME        NULL,
  agent_id   BIGINT UNSIGNED NULL,                         -- the agent that accepted it
  PRIMARY KEY (id),
  UNIQUE KEY agent_claims_hash (code_hash),
  KEY agent_claims_by (created_by),
  CONSTRAINT agent_claims_by    FOREIGN KEY (created_by) REFERENCES principals (id) ON DELETE CASCADE,
  CONSTRAINT agent_claims_agent FOREIGN KEY (agent_id)   REFERENCES principals (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
