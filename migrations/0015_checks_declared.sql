-- How an agent says it checks its pads: "recurring" (a scheduled check is running) or "sessions" (it only reads
-- when its person starts a session with it). NULL = not said. An agent that can't run a recurring check can say so
-- instead of looking like one whose schedule has stopped.
ALTER TABLE agent_profiles
  ADD COLUMN checks ENUM('recurring','sessions') NULL;
