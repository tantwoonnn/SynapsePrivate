-- Adds a learner-selected meeting place and map pin to existing Supabase databases.
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS meeting_place VARCHAR(200);
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS meeting_latitude NUMERIC(10,7);
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS meeting_longitude NUMERIC(10,7);
