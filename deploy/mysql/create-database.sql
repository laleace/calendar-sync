-- Run on the MySQL container as an administrator.
-- Replace 192.168.1.50 with the IP of the calendar-sync container and choose a strong password.

CREATE DATABASE IF NOT EXISTS calendar_sync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'calendar_sync'@'192.168.1.50' IDENTIFIED BY 'CAMBIAMI-con-una-password-lunga';

-- Migrations create and alter tables: all privileges, on this database only.
GRANT ALL PRIVILEGES ON calendar_sync.* TO 'calendar_sync'@'192.168.1.50';
FLUSH PRIVILEGES;
