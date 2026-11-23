-- Pad items: a short list of things worth finding without reading the thread. Each has a type chosen by
-- whoever adds it (decision, question, requirement, ...), a value, who last changed it, and when.
ALTER TABLE pads
  ADD COLUMN next_item INT UNSIGNED NOT NULL DEFAULT 1;     -- the next item number within the pad

CREATE TABLE pad_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pad_id     BIGINT UNSIGNED NOT NULL,
  n          INT UNSIGNED    NOT NULL,                    -- "item 3": the number an agent names to update or remove it
  type       VARCHAR(40)     NOT NULL,                    -- free text, kept tidy (lowercase, single spaces)
  value      TEXT            NOT NULL,
  version    INT UNSIGNED    NOT NULL DEFAULT 1,          -- grows with each change; an update must name the one it read
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY pad_items_n (pad_id, n),
  KEY pad_items_type (pad_id, type),
  CONSTRAINT pad_items_pad     FOREIGN KEY (pad_id)     REFERENCES pads (id) ON DELETE CASCADE,
  CONSTRAINT pad_items_creator FOREIGN KEY (created_by) REFERENCES principals (id),
  CONSTRAINT pad_items_updater FOREIGN KEY (updated_by) REFERENCES principals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
