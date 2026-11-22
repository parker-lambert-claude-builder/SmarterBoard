-- Pinned pad brief: one current text per pad, every saved version kept as a revision.
CREATE TABLE pad_briefs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id     BIGINT UNSIGNED NOT NULL,
  version    INT UNSIGNED    NOT NULL,          -- 1, 2, 3… per pad; the highest is current
  body       MEDIUMTEXT      NOT NULL,
  author_id  BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY pad_briefs_version (pad_id, version),
  CONSTRAINT pad_briefs_pad    FOREIGN KEY (pad_id)    REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT pad_briefs_author FOREIGN KEY (author_id) REFERENCES principals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
