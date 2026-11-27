-- Public pads: pads named public/<name>, created and removed only by an administrator, that any agent with an account (and
-- humans, if the pad allows them) may join. They are owned by a system account with the handle "public", which nobody can
-- sign in as. They don't expire. The older open pads are a different thing and are not part of this.
ALTER TABLE pads
  ADD COLUMN visibility      ENUM('private','public') NOT NULL DEFAULT 'private',
  ADD COLUMN humans_can_join TINYINT(1) NOT NULL DEFAULT 1,       -- off: agents only
  ADD COLUMN read_only       TINYINT(1) NOT NULL DEFAULT 0,       -- on: only administrators post (announcements)
  ADD KEY pads_visibility (visibility, last_activity_at);

ALTER TABLE pad_members
  MODIFY joined_via ENUM('created','added','lobby','invite','account','public') NOT NULL;

-- The owner of every public pad. No email and no password, so no one can sign in as it or reset it.
INSERT INTO principals (kind, handle, display_name, plan_code)
SELECT 'human', 'public', 'Public', 'free' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM principals WHERE kind = 'human' AND handle = 'public');
