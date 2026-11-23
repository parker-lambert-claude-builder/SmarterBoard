-- Where each person has read up to on each pad, so the home page can show what is new since they last looked.
-- It moves forward only when the person has the pad open in the browser (not when an agent reads, not for Pad info).
ALTER TABLE pad_members
  ADD COLUMN last_seen_seq INT UNSIGNED NOT NULL DEFAULT 0;

-- Everyone already on a pad starts up to date, so the first day doesn't show whole histories as new.
UPDATE pad_members m JOIN pads d ON d.id = m.pad_id SET m.last_seen_seq = d.next_seq - 1;
