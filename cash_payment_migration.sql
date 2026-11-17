-- Adds the in-person cash option to an existing Supabase payment setup.
ALTER TABLE appointment_payments ADD COLUMN IF NOT EXISTS payment_method VARCHAR(10) NOT NULL DEFAULT 'PAYMONGO';
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'appointment_payments_payment_method_check') THEN
        ALTER TABLE appointment_payments ADD CONSTRAINT appointment_payments_payment_method_check CHECK (payment_method IN ('PAYMONGO', 'CASH'));
    END IF;
END $$;
