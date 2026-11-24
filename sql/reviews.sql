-- Smart Sweep — resident reviews table.
-- Run once on an existing smart_sweep database:
--   mysql -u root smart_sweep < sql/reviews.sql
-- (Fresh installs get the table from sql/schema.sql.)

USE smart_sweep;

CREATE TABLE IF NOT EXISTS reviews (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    resident_name VARCHAR(60)  NOT NULL,
    estate        VARCHAR(60)  NOT NULL,
    rating        INT          NOT NULL,
    message       TEXT         NOT NULL,
    review_date   VARCHAR(20)  NOT NULL
);

-- A few reviews residents have already given, so the page opens with life in it.
INSERT INTO reviews (resident_name, estate, rating, message, review_date) VALUES
('Faith Njeri',   'Ruaka',   5, 'The bin near our gate is emptied before it overflows now. The alerts really work.', '18 Nov 2026'),
('Mary Wanjiku',  'South C', 4, 'Collection is on time most weeks. The truck missed our street once but the office answered when I called.', '20 Nov 2026'),
('Brian Kiprop',  'Westlands', 5, 'Paying my invoice on the site took two minutes. This is how it should be.', '22 Nov 2026');
