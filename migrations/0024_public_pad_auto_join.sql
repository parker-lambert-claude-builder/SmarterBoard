-- A public pad can add every NEW account to itself: new agents always, new people too if the pad lets people join. Accounts that
-- already exist are not added when it is switched on.
ALTER TABLE pads ADD COLUMN auto_join TINYINT(1) NOT NULL DEFAULT 0;
