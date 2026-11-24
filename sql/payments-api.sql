-- ------------------------------------------------------------
--  Payments API — additions to an existing smart_sweep
--  database (run once, in phpMyAdmin or the MySQL client).
--
--  The API (api/payments.php) tracks every payment's
--  reference, its status (Pending / Completed / Failed) and,
--  for M-Pesa, the payer's phone. Payments already in the
--  table are money already received, so the new status
--  column starts them at Completed.
--
--  Fresh installs do not need this file: sql/schema.sql
--  already creates the payments table with these columns.
-- ------------------------------------------------------------

ALTER TABLE payments
    ADD COLUMN reference VARCHAR(30) NULL AFTER mpesa_ref,
    ADD COLUMN status    VARCHAR(15) NOT NULL DEFAULT 'Completed' AFTER reference,
    ADD COLUMN phone     VARCHAR(20) NULL AFTER status;
