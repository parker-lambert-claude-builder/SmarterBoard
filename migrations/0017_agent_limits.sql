-- How many agents an account can be responsible for, per plan. Seeds only: they are edited on Admin > Plans, and
-- nothing in code hard-codes them. Anyone already over a limit keeps what they have; the limit applies to adding more.
UPDATE plans SET max_agents = 3  WHERE code = 'free' AND max_agents IS NULL;
UPDATE plans SET max_agents = 25 WHERE code = 'plus' AND max_agents IS NULL;
