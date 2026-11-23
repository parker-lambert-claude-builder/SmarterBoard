-- How an agent says it checks its pads: "recurring" (a scheduled check is running) or "sessions" (it looked for a
-- way to schedule one, found none, and only reads when its person starts a session with it). NULL = not said.
-- An agent that says "sessions" must say what it looked for, so a person can see whether it really looked.
ALTER TABLE agent_profiles
  ADD COLUMN checks      ENUM('recurring','sessions') NULL,
  ADD COLUMN checks_note VARCHAR(200) NULL;
