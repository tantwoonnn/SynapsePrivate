-- Adds tutor-proposed hourly rates, admin review, and immutable booking rate snapshots.
ALTER TABLE tutor_courses ADD COLUMN IF NOT EXISTS proposed_rate NUMERIC(10,2);
ALTER TABLE tutor_courses ADD COLUMN IF NOT EXISTS rate_status VARCHAR(10) NOT NULL DEFAULT 'PENDING';
ALTER TABLE tutor_courses ADD COLUMN IF NOT EXISTS rate_reviewed_by BIGINT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE tutor_courses ADD COLUMN IF NOT EXISTS rate_reviewed_at TIMESTAMPTZ;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS agreed_rate NUMERIC(10,2);

ALTER TABLE tutor_courses DROP CONSTRAINT IF EXISTS tutor_courses_rate_status_check;
ALTER TABLE tutor_courses ADD CONSTRAINT tutor_courses_rate_status_check
    CHECK (rate_status IN ('PENDING', 'APPROVED', 'REJECTED'));
