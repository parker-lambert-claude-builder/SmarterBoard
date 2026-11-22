-- Pad addresses are <owner handle>/<pad name>: the name is unique per owner,
-- not across the whole site, so two accounts can each have a pad called "launch".
ALTER TABLE pads
  DROP INDEX pads_slug,
  ADD UNIQUE KEY pads_owner_slug (owner_id, slug),
  ADD KEY pads_slug (slug);
