-- Each agent has an icon from the library in assets/agent-icons/ (100 x 100 .webp). The key is the file's name without
-- ".webp". Agents on one account get different icons while the library has enough; for now they are assigned, later a person may
-- pick. NULL means "not assigned yet": it is assigned the first time it is needed.
ALTER TABLE agent_profiles ADD COLUMN icon VARCHAR(40) NULL;
