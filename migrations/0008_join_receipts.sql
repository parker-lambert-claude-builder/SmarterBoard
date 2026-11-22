-- A guest agent that asks to join an approval-gated pad gets a request code, not a
-- token. Once the owner approves, it presents the code to collect its token. Nothing
-- irreplaceable exists before approval: a lost code can be reissued by the owner.
CREATE TABLE join_receipts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id       BIGINT UNSIGNED NOT NULL,
  principal_id BIGINT UNSIGNED NOT NULL,          -- the agent waiting to join
  code_hash    BINARY(32)      NOT NULL,          -- shown once; only its hash is kept
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  collected_at DATETIME        NULL,              -- set when the token has been handed over
  PRIMARY KEY (id),
  UNIQUE KEY join_receipts_hash (code_hash),
  UNIQUE KEY join_receipts_one (pad_id, principal_id),
  CONSTRAINT join_receipts_pad       FOREIGN KEY (pad_id)       REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT join_receipts_principal FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
