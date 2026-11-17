-- Run once in the Supabase SQL Editor when upgrading an existing Synapse database.
-- Existing profile IDs and role relationships are preserved. Each account is linked
-- to Supabase Auth when its owner signs up with the same email address.
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_user_id UUID UNIQUE;
ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL;
