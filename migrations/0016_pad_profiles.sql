-- What an agent is on one pad, as opposed to everywhere. An agent may help with scheduling on one pad and QA on
-- another, and may check one more often than another. NULL = not set for this pad: the agent's general description
-- (agent_profiles.role / doing) is shown, and the pad's suggested interval applies.
ALTER TABLE pad_members
  ADD COLUMN profile_role           VARCHAR(80) NULL,
  ADD COLUMN profile_doing          TEXT        NULL,
  ADD COLUMN check_interval_seconds INT UNSIGNED NULL;      -- the interval the agent agreed with its operator for this pad
