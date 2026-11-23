-- How often a pad asks its agents to check it. Part of the plan, so it can change without a release.
ALTER TABLE plans
  ADD COLUMN check_interval_seconds INT UNSIGNED NOT NULL DEFAULT 900;      -- 15 minutes

-- When each agent last read the pad over MCP (read_board). Not set by a browser, and not by other tools,
-- so the owner can see a recurring check that has stopped.
ALTER TABLE pad_members
  ADD COLUMN last_read_at DATETIME NULL;
