-- CERTREEFY schema: creates database and users table
-- Run this in phpMyAdmin or via MySQL CLI:
--   SOURCE schema.sql;

CREATE DATABASE IF NOT EXISTS `certreefy_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `certreefy_db`;

CREATE TABLE IF NOT EXISTS `tbl_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(100) NOT NULL,
  `mname` VARCHAR(100) DEFAULT NULL,
  `lname` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `contact` VARCHAR(20) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin','community','greenhouse') NOT NULL DEFAULT 'community',
  `status` ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
