-- ============================================================
--  Smart Sweep — role login migration
--
--  The users table was seeded with a password hash for the
--  administrator only; every other demo account had NULL.
--  Real role-based login needs every account to have a
--  password, so this file sets the same demo password for
--  all eight seeded accounts (including the administrator,
--  whose old hash is replaced because its password was
--  never recorded anywhere).
--
--  Demo password for every account: smart123
--  (shown on the login page; change it per account with
--  the administrator's user controls once logged in, or
--  UPDATE users SET password_hash = ... directly.)
--
--  The hash below is bcrypt('smart123'), made with PHP's
--  password_hash. It is a demo credential for a student
--  system, not a production secret.
--
--  Run once, on the MySQL database that holds the system:
--      mysql -u root smart_sweep < sql/role-login.sql
--  (or import it in phpMyAdmin).
-- ============================================================

UPDATE users
SET password_hash = '$2y$10$ugH0ljsDs/tui3H4ofZ6PupCk9/aaXbOe3MeVfD2IEECsYTJJpR2m'
WHERE username IN ('admin', 'dkamau', 'jmwangi', 'awambui',
                   'potieno', 'gachieng', 'fnjeri', 'jkiprop');
