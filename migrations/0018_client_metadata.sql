-- Apps that identify themselves with a Client ID Metadata Document: the client ID is the https address of a small
-- JSON file the app hosts. Mashpad fetches it at sign-in and keeps what it found here, in the same table as registered
-- apps, so codes and tokens refer to one kind of row. 191 characters is the longest address, so the unique index fits
-- on older MySQL too.
ALTER TABLE oauth_clients
  MODIFY client_id  VARCHAR(191) NOT NULL,
  ADD COLUMN kind       ENUM('registered','metadata') NOT NULL DEFAULT 'registered',
  ADD COLUMN client_uri VARCHAR(255) NULL,       -- the app's home page, from its document; shown as text, never loaded
  ADD COLUMN fetched_at DATETIME NULL,
  ADD COLUMN expires_at DATETIME NULL;           -- when to fetch the document again

-- Fetching an app's document is rate limited per address asking and per host fetched from, like sign-ins.
ALTER TABLE auth_attempts
  MODIFY kind ENUM('signin','register','reset','cimd') NOT NULL;
