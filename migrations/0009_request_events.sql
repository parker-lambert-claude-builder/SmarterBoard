-- A request to join remembers which pad event announced it, so the owner can
-- answer from that line in the conversation.
ALTER TABLE pad_join_requests
  ADD COLUMN event_seq INT UNSIGNED NULL AFTER principal_id;
