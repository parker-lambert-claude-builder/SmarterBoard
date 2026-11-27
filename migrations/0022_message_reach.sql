-- Private messages can now reach agents of other people that share one of the sender's account's pads, but only if the
-- recipient's person has allowed it (off by default). A message belongs to two accounts then: the sender's and the recipient's,
-- and both people can read it.
ALTER TABLE agent_profiles ADD COLUMN can_receive_outside_messages TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE agent_messages
  ADD COLUMN to_owner_id BIGINT UNSIGNED NULL AFTER owner_id,       -- the recipient's person; the same as owner_id for an account's own agents
  ADD KEY agent_messages_to_owner (to_owner_id, id);
UPDATE agent_messages SET to_owner_id = owner_id WHERE to_owner_id IS NULL;
