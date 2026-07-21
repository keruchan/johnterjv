<?php
/**
 * One-time helper: creates the database `certreefy_db` and the
 * application tables used for users, notifications, and audit history.
 *
 * Usage:
 * - Run from the browser or CLI once, then delete this file.
 * - It uses default credentials matching config/config.php.
 */

require_once __DIR__ . '/permit_policy.php';

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dbName = 'certreefy_db';

$dsn = "mysql:host={$host};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Create the database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `{$dbName}` created or already exists.<br>\n";

    // Switch to the new database
    $pdo->exec("USE `{$dbName}`");

    // Create users table
    $createTable = <<<SQL
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
  `role` ENUM('superadmin','community','rps','ems') NOT NULL DEFAULT 'community',
  `status` ENUM('pending','active','suspended','disabled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($createTable);
    echo "Table `tbl_users` created or already exists.<br>\n";

    // Migrate any legacy `greenhouse` role values to `ems`. The EMS portal now
    // lives at pages/ems/; this only converts the stored role, then tightens the
    // ENUM so `greenhouse` is no longer an accepted role value.
    $pdo->exec(
        "ALTER TABLE `tbl_users`
         MODIFY `role` ENUM('superadmin','community','greenhouse','rps','ems') NOT NULL DEFAULT 'community'"
    );
    $pdo->exec("UPDATE `tbl_users` SET `role` = 'ems' WHERE `role` = 'greenhouse'");
    $pdo->exec(
        "ALTER TABLE `tbl_users`
         MODIFY `role` ENUM('superadmin','community','rps','ems') NOT NULL DEFAULT 'community'"
    );
    echo "Role values updated for CENRO Superadmin, RPS, Community, and EMS.<br>\n";

    $pdo->exec(
        "ALTER TABLE `tbl_users`
         MODIFY `status` ENUM('pending','active','suspended','disabled') NOT NULL DEFAULT 'pending'"
    );

    // Existing installations predate self-service email verification for
    // Community accounts. Add the columns individually so re-running this
    // script on an already-migrated database is a no-op.
    $userVerificationColumnDefinitions = [
        'email_verified_at' => "DATETIME DEFAULT NULL COMMENT 'Set when the user clicks the emailed verification link' AFTER `status`",
        'email_verify_token' => "VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of the pending verification token' AFTER `email_verified_at`",
        'email_verify_expires' => "DATETIME DEFAULT NULL AFTER `email_verify_token`",
    ];
    $userVerificationColumnLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_users'
           AND COLUMN_NAME = :column_name"
    );
    foreach ($userVerificationColumnDefinitions as $columnName => $definition) {
        $userVerificationColumnLookup->execute([
            ':schema_name' => $dbName,
            ':column_name' => $columnName,
        ]);
        if ((int) $userVerificationColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_users` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $userVerificationIndexLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_users'
           AND INDEX_NAME = 'idx_email_verify_token'"
    );
    $userVerificationIndexLookup->execute([':schema_name' => $dbName]);
    if ((int) $userVerificationIndexLookup->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `tbl_users` ADD KEY `idx_email_verify_token` (`email_verify_token`)');
    }
    echo "Email verification columns are ready.<br>\n";

    $createPermissionsTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_user_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `granted_by_user_id` INT UNSIGNED DEFAULT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permission` (`user_id`, `permission_key`),
  KEY `idx_user_permissions_active` (`permission_key`, `is_active`),
  KEY `idx_user_permissions_grantor` (`granted_by_user_id`),
  CONSTRAINT `fk_user_permission_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_permission_grantor` FOREIGN KEY (`granted_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($createPermissionsTable);

    $createAuditTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_user_management_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` INT UNSIGNED NOT NULL,
  `target_user_id` INT UNSIGNED NOT NULL,
  `action` ENUM('user_updated','status_changed') NOT NULL,
  `previous_role` ENUM('superadmin','community','rps','ems') DEFAULT NULL,
  `new_role` ENUM('superadmin','community','rps','ems') DEFAULT NULL,
  `previous_status` ENUM('pending','active','suspended','disabled') DEFAULT NULL,
  `new_status` ENUM('pending','active','suspended','disabled') DEFAULT NULL,
  `changed_fields` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_audit_actor` (`actor_user_id`),
  KEY `idx_user_audit_target` (`target_user_id`),
  CONSTRAINT `fk_user_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `tbl_users` (`id`),
  CONSTRAINT `fk_user_audit_target` FOREIGN KEY (`target_user_id`) REFERENCES `tbl_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($createAuditTable);
    echo "Account statuses and user-management audit history are ready.<br>\n";

    $createGeneralAuditTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_audit_trail` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` INT UNSIGNED NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_trail_actor` (`actor_user_id`),
  KEY `idx_audit_trail_category_action` (`category`, `action`),
  KEY `idx_audit_trail_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_trail_created` (`created_at`),
  CONSTRAINT `fk_audit_trail_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `tbl_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $createNotificationsTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_user_id` INT UNSIGNED NOT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `notification_type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` VARCHAR(1000) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` BIGINT UNSIGNED DEFAULT NULL,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_recipient_read` (`recipient_user_id`, `read_at`),
  KEY `idx_notifications_type` (`notification_type`),
  KEY `idx_notifications_entity` (`entity_type`, `entity_id`),
  KEY `idx_notifications_created` (`created_at`),
  CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_user_id`) REFERENCES `tbl_users` (`id`),
  CONSTRAINT `fk_notifications_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $createLoginAttemptsTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(150) NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_identifier` (`identifier`, `was_successful`, `created_at`),
  KEY `idx_login_attempts_ip` (`ip_address`, `was_successful`, `created_at`),
  KEY `idx_login_attempts_user` (`user_id`),
  CONSTRAINT `fk_login_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($createGeneralAuditTable);
    $pdo->exec($createNotificationsTable);
    $pdo->exec($createLoginAttemptsTable);
    echo "Reusable notification, general audit trail, and login-attempt tables are ready.<br>\n";

    $createPermitTables = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_permit_transaction_sequences` (
  `sequence_year` SMALLINT UNSIGNED NOT NULL,
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sequence_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_applications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` VARCHAR(20) DEFAULT NULL,
  `submission_key` VARCHAR(64) NOT NULL,
  `applicant_user_id` INT UNSIGNED NOT NULL,
  `applicant_name` VARCHAR(255) NOT NULL,
  `applicant_contact` VARCHAR(20) DEFAULT NULL,
  `applicant_address` VARCHAR(255) DEFAULT NULL,
  `applicant_type` VARCHAR(50) DEFAULT NULL,
  `organization_name` VARCHAR(255) DEFAULT NULL,
  `property_relationship` VARCHAR(50) DEFAULT NULL,
  `authorization_details` VARCHAR(1000) DEFAULT NULL,
  `property_classification` VARCHAR(100) DEFAULT NULL,
  `property_owner_name` VARCHAR(255) DEFAULT NULL,
  `property_address` VARCHAR(500) DEFAULT NULL,
  `lot_number` VARCHAR(100) DEFAULT NULL,
  `district` VARCHAR(100) DEFAULT NULL,
  `barangay` VARCHAR(100) DEFAULT NULL,
  `municipality` VARCHAR(100) DEFAULT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `cutting_purpose` VARCHAR(500) DEFAULT NULL,
  `application_notes` TEXT NULL,
  `application_status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `document_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `inspection_status` VARCHAR(50) NOT NULL DEFAULT 'pending_assessment',
  `decision_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `donation_status` VARCHAR(50) NOT NULL DEFAULT 'not_required',
  `release_status` VARCHAR(50) NOT NULL DEFAULT 'not_ready',
  `validity_status` VARCHAR(50) NOT NULL DEFAULT 'not_issued',
  `declaration_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_transaction_id` (`transaction_id`),
  UNIQUE KEY `uq_permit_applicant_submission` (`applicant_user_id`, `submission_key`),
  KEY `idx_permit_applicant_status` (`applicant_user_id`, `application_status`),
  KEY `idx_permit_processing_status` (`application_status`, `decision_status`, `release_status`),
  CONSTRAINT `fk_permit_application_applicant` FOREIGN KEY (`applicant_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_trees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `common_name` VARCHAR(150) NOT NULL,
  `scientific_name` VARCHAR(150) DEFAULT NULL,
  `quantity` SMALLINT UNSIGNED NOT NULL,
  `diameter_cm` DECIMAL(8,2) DEFAULT NULL,
  `estimated_height_m` DECIMAL(8,2) DEFAULT NULL,
  `condition_notes` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_tree_application_id` (`application_id`, `id`),
  KEY `idx_permit_trees_application` (`application_id`),
  CONSTRAINT `fk_permit_tree_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `status_domain` VARCHAR(50) NOT NULL,
  `previous_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `changed_by_user_id` INT UNSIGNED NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_permit_history_application` (`application_id`, `created_at`),
  KEY `idx_permit_history_domain` (`status_domain`, `new_status`),
  KEY `idx_permit_history_actor` (`changed_by_user_id`),
  CONSTRAINT `fk_permit_history_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_history_actor` FOREIGN KEY (`changed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `document_type` VARCHAR(100) NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size_bytes` BIGINT UNSIGNED NOT NULL,
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `replaces_document_id` BIGINT UNSIGNED DEFAULT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  `verification_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `verified_by_user_id` INT UNSIGNED DEFAULT NULL,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `verification_notes` VARCHAR(1000) DEFAULT NULL,
  `archived_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_document_application_id` (`application_id`, `id`),
  KEY `idx_permit_documents_application` (`application_id`, `document_type`),
  KEY `idx_permit_documents_current` (`application_id`, `document_type`, `is_current`),
  KEY `idx_permit_documents_uploader` (`uploaded_by_user_id`),
  KEY `idx_permit_documents_verifier` (`verified_by_user_id`),
  CONSTRAINT `fk_permit_document_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_document_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_document_verifier` FOREIGN KEY (`verified_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_document_replacement` FOREIGN KEY (`application_id`, `replaces_document_id`) REFERENCES `tbl_permit_documents` (`application_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_document_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED DEFAULT NULL,
  `document_type` VARCHAR(100) DEFAULT NULL,
  `review_scope` VARCHAR(30) NOT NULL,
  `review_status` VARCHAR(50) NOT NULL,
  `previous_review_id` BIGINT UNSIGNED DEFAULT NULL,
  `original_received` TINYINT(1) DEFAULT NULL,
  `original_received_on` DATE DEFAULT NULL,
  `received_by_user_id` INT UNSIGNED DEFAULT NULL,
  `wet_ink_required` TINYINT(1) DEFAULT NULL,
  `wet_ink_verified` TINYINT(1) DEFAULT NULL,
  `scan_compared_with_original` TINYINT(1) DEFAULT NULL,
  `reviewed_by_user_id` INT UNSIGNED NOT NULL,
  `review_notes` VARCHAR(1000) DEFAULT NULL,
  `reviewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_review_application_id` (`application_id`, `id`),
  KEY `idx_permit_reviews_application` (`application_id`, `review_scope`, `reviewed_at`),
  KEY `idx_permit_reviews_document` (`application_id`, `document_id`),
  KEY `idx_permit_reviews_original` (`application_id`, `review_scope`, `document_type`, `reviewed_at`),
  KEY `idx_permit_reviews_actor` (`reviewed_by_user_id`),
  KEY `idx_permit_reviews_receiver` (`received_by_user_id`),
  CONSTRAINT `fk_permit_review_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_review_document` FOREIGN KEY (`application_id`, `document_id`) REFERENCES `tbl_permit_documents` (`application_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_review_previous` FOREIGN KEY (`application_id`, `previous_review_id`) REFERENCES `tbl_permit_document_reviews` (`application_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_review_receiver` FOREIGN KEY (`received_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_review_actor` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_inspections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `previous_inspection_id` BIGINT UNSIGNED DEFAULT NULL,
  `follow_up_of_inspection_id` BIGINT UNSIGNED DEFAULT NULL,
  `inspector_user_id` INT UNSIGNED DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `completed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `inspection_status` VARCHAR(50) NOT NULL,
  `scheduled_at` DATETIME DEFAULT NULL,
  `inspection_location` VARCHAR(500) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `inspected_at` DATETIME DEFAULT NULL,
  `property_location_confirmed` TINYINT(1) DEFAULT NULL,
  `ownership_authorization_confirmed` TINYINT(1) DEFAULT NULL,
  `findings` TEXT NULL,
  `recommendation` VARCHAR(100) DEFAULT NULL,
  `follow_up_required` TINYINT(1) NOT NULL DEFAULT 0,
  `inspection_notes` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_inspection_application_id` (`application_id`, `id`),
  KEY `idx_permit_inspections_application` (`application_id`, `inspection_status`),
  KEY `idx_permit_inspections_actor` (`inspector_user_id`),
  KEY `idx_permit_inspections_creator` (`created_by_user_id`),
  KEY `idx_permit_inspections_completer` (`completed_by_user_id`),
  CONSTRAINT `fk_permit_inspection_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_inspection_actor` FOREIGN KEY (`inspector_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_inspection_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_inspection_completer` FOREIGN KEY (`completed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_inspection_previous` FOREIGN KEY (`previous_inspection_id`) REFERENCES `tbl_permit_inspections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_inspection_follow_up` FOREIGN KEY (`follow_up_of_inspection_id`) REFERENCES `tbl_permit_inspections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_inspection_tree_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `inspection_id` BIGINT UNSIGNED NOT NULL,
  `tree_id` BIGINT UNSIGNED NOT NULL,
  `species_confirmed` TINYINT(1) NOT NULL,
  `quantity_confirmed` TINYINT(1) NOT NULL,
  `measurements_confirmed` TINYINT(1) DEFAULT NULL,
  `verified_common_name` VARCHAR(150) NOT NULL,
  `verified_scientific_name` VARCHAR(150) DEFAULT NULL,
  `verified_quantity` SMALLINT UNSIGNED NOT NULL,
  `verified_diameter_cm` DECIMAL(8,2) DEFAULT NULL,
  `verified_height_m` DECIMAL(8,2) DEFAULT NULL,
  `measurement_notes` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_tree_verification` (`inspection_id`, `tree_id`),
  KEY `idx_inspection_tree_application` (`application_id`, `inspection_id`),
  KEY `idx_inspection_tree_record` (`application_id`, `tree_id`),
  CONSTRAINT `fk_inspection_tree_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inspection_tree_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `tbl_permit_inspections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inspection_tree_record` FOREIGN KEY (`tree_id`) REFERENCES `tbl_permit_trees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_inspection_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `inspection_id` BIGINT UNSIGNED NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size_bytes` BIGINT UNSIGNED NOT NULL,
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inspection_photos_inspection` (`application_id`, `inspection_id`),
  KEY `idx_inspection_photos_uploader` (`uploaded_by_user_id`),
  CONSTRAINT `fk_inspection_photo_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inspection_photo_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `tbl_permit_inspections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_inspection_photo_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_decisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `previous_decision_id` BIGINT UNSIGNED DEFAULT NULL,
  `decided_by_user_id` INT UNSIGNED NOT NULL,
  `decision` VARCHAR(50) NOT NULL,
  `decision_notes` VARCHAR(1000) DEFAULT NULL,
  `decision_conditions` VARCHAR(2000) DEFAULT NULL,
  `approved_tree_count` INT UNSIGNED DEFAULT NULL,
  `property_classification` VARCHAR(100) DEFAULT NULL,
  `donation_seedling_count` INT UNSIGNED DEFAULT NULL,
  `decided_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_decision_application_id` (`application_id`, `id`),
  KEY `idx_permit_decisions_application` (`application_id`, `decided_at`),
  KEY `idx_permit_decisions_actor` (`decided_by_user_id`),
  CONSTRAINT `fk_permit_decision_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_decision_actor` FOREIGN KEY (`decided_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_decision_previous` FOREIGN KEY (`previous_decision_id`) REFERENCES `tbl_permit_decisions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_donation_requirements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `approval_decision_id` BIGINT UNSIGNED NOT NULL,
  `property_classification` VARCHAR(100) NOT NULL,
  `policy_code` VARCHAR(100) NOT NULL,
  `policy_version` VARCHAR(50) NOT NULL,
  `required_seedling_count` INT UNSIGNED NOT NULL,
  `received_seedling_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `requirement_basis` VARCHAR(500) NOT NULL,
  `applicant_instructions` VARCHAR(1000) NOT NULL,
  `imposed_by_user_id` INT UNSIGNED NOT NULL,
  `current_status` VARCHAR(50) NOT NULL DEFAULT 'required',
  `imposed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_donation_application` (`application_id`),
  KEY `idx_permit_donation_approval` (`application_id`, `approval_decision_id`),
  KEY `idx_permit_donation_actor` (`imposed_by_user_id`),
  CONSTRAINT `fk_permit_donation_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_actor` FOREIGN KEY (`imposed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_approval` FOREIGN KEY (`application_id`, `approval_decision_id`) REFERENCES `tbl_permit_decisions` (`application_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_donation_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `donation_requirement_id` BIGINT UNSIGNED NOT NULL,
  `previous_verification_id` BIGINT UNSIGNED DEFAULT NULL,
  `receipt_group_key` CHAR(64) NOT NULL,
  `action_key` CHAR(64) NOT NULL,
  `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `received_by_user_id` INT UNSIGNED NOT NULL,
  `verified_by_user_id` INT UNSIGNED NOT NULL,
  `verification_status` VARCHAR(50) NOT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  `is_finalized` TINYINT(1) NOT NULL DEFAULT 0,
  `seedlings_received` INT UNSIGNED NOT NULL,
  `receipt_reference` VARCHAR(100) DEFAULT NULL,
  `received_at` DATETIME NOT NULL,
  `verification_notes` VARCHAR(1000) DEFAULT NULL,
  `finalized_at` TIMESTAMP NULL DEFAULT NULL,
  `verified_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_donation_action_key` (`action_key`),
  UNIQUE KEY `uq_permit_donation_receipt_version` (`donation_requirement_id`, `receipt_group_key`, `version_number`),
  KEY `idx_permit_donation_verification` (`donation_requirement_id`, `verified_at`),
  KEY `idx_permit_donation_verification_current` (`donation_requirement_id`, `is_current`, `is_finalized`),
  KEY `idx_permit_donation_receiver` (`received_by_user_id`),
  KEY `idx_permit_donation_verifier` (`verified_by_user_id`),
  CONSTRAINT `fk_permit_donation_verification_requirement` FOREIGN KEY (`donation_requirement_id`) REFERENCES `tbl_permit_donation_requirements` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_verification_previous` FOREIGN KEY (`previous_verification_id`) REFERENCES `tbl_permit_donation_verifications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_verification_receiver` FOREIGN KEY (`received_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_verification_actor` FOREIGN KEY (`verified_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_donation_verification_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `donation_verification_id` BIGINT UNSIGNED NOT NULL,
  `seedling_type` VARCHAR(150) NOT NULL,
  `inventory_id` BIGINT UNSIGNED DEFAULT NULL,
  `quantity_received` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_donation_item_type` (`donation_verification_id`, `seedling_type`),
  KEY `idx_permit_donation_item_verification` (`donation_verification_id`),
  KEY `idx_permit_donation_item_inventory` (`inventory_id`),
  CONSTRAINT `fk_permit_donation_item_verification` FOREIGN KEY (`donation_verification_id`) REFERENCES `tbl_permit_donation_verifications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_donation_item_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_seedling_inventory` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `decision_id` BIGINT UNSIGNED NOT NULL,
  `permit_number` VARCHAR(50) DEFAULT NULL,
  `prepared_by_user_id` INT UNSIGNED NOT NULL,
  `released_by_user_id` INT UNSIGNED DEFAULT NULL,
  `prepared_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` TIMESTAMP NULL DEFAULT NULL,
  `valid_from` DATE DEFAULT NULL,
  `valid_until` DATE DEFAULT NULL,
  `permit_file_path` VARCHAR(500) DEFAULT NULL,
  `release_notes` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_application` (`application_id`),
  UNIQUE KEY `uq_permit_number` (`permit_number`),
  KEY `idx_permit_decision` (`decision_id`),
  KEY `idx_permit_preparer` (`prepared_by_user_id`),
  KEY `idx_permit_releaser` (`released_by_user_id`),
  KEY `idx_permit_validity` (`valid_from`, `valid_until`),
  CONSTRAINT `fk_permit_record_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_record_decision` FOREIGN KEY (`decision_id`) REFERENCES `tbl_permit_decisions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_record_decision_application` FOREIGN KEY (`application_id`, `decision_id`) REFERENCES `tbl_permit_decisions` (`application_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_record_preparer` FOREIGN KEY (`prepared_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_record_releaser` FOREIGN KEY (`released_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_cutting_completions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `permit_id` BIGINT UNSIGNED NOT NULL,
  `completion_status` VARCHAR(50) NOT NULL,
  `trees_cut_count` INT UNSIGNED NOT NULL,
  `completed_on` DATE NOT NULL,
  `verified_by_user_id` INT UNSIGNED NOT NULL,
  `recorded_by_user_id` INT UNSIGNED NOT NULL,
  `remarks` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_completion_application` (`application_id`),
  KEY `idx_permit_completion_permit` (`permit_id`),
  KEY `idx_permit_completion_verifier` (`verified_by_user_id`),
  KEY `idx_permit_completion_recorder` (`recorded_by_user_id`),
  CONSTRAINT `fk_permit_completion_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_completion_permit` FOREIGN KEY (`permit_id`) REFERENCES `tbl_permits` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_completion_verifier` FOREIGN KEY (`verified_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_permit_completion_recorder` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_permit_cutting_completion_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `completion_id` BIGINT UNSIGNED NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size_bytes` BIGINT UNSIGNED NOT NULL,
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_completion_evidence_completion` (`application_id`, `completion_id`),
  KEY `idx_completion_evidence_uploader` (`uploaded_by_user_id`),
  CONSTRAINT `fk_completion_evidence_application` FOREIGN KEY (`application_id`) REFERENCES `tbl_permit_applications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_completion_evidence_completion` FOREIGN KEY (`completion_id`) REFERENCES `tbl_permit_cutting_completions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_completion_evidence_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $permitStatements = preg_split('/;\s*(?:\r\n|\n|\r|$)/', trim($createPermitTables)) ?: [];
    foreach ($permitStatements as $permitStatement) {
        if (trim($permitStatement) !== '') {
            $pdo->exec($permitStatement);
        }
    }

    // Existing installations predate permit validity/expiration and cutting
    // completion. Add the new permit-record columns individually so released
    // permits keep their existing validity data.
    $permitRecordColumnDefinitions = [
        'approved_duration_days' => "SMALLINT UNSIGNED DEFAULT NULL AFTER `valid_until`",
        'validity_start_basis' => "VARCHAR(30) DEFAULT NULL AFTER `approved_duration_days`",
        'expiry_warning_notified_at' => "TIMESTAMP NULL DEFAULT NULL AFTER `validity_start_basis`",
        'expired_notified_at' => "TIMESTAMP NULL DEFAULT NULL AFTER `expiry_warning_notified_at`",
        'completed_at' => "TIMESTAMP NULL DEFAULT NULL AFTER `expired_notified_at`",
        'permit_file_path' => "VARCHAR(500) DEFAULT NULL AFTER `completed_at`",
        'permit_file_original_name' => "VARCHAR(255) DEFAULT NULL AFTER `permit_file_path`",
        'permit_file_mime_type' => "VARCHAR(100) DEFAULT NULL AFTER `permit_file_original_name`",
        'permit_file_size_bytes' => "INT UNSIGNED DEFAULT NULL AFTER `permit_file_mime_type`",
        'permit_file_uploaded_by_user_id' => "INT UNSIGNED DEFAULT NULL AFTER `permit_file_size_bytes`",
        'permit_file_uploaded_at' => "TIMESTAMP NULL DEFAULT NULL AFTER `permit_file_uploaded_by_user_id`",
        'signed_on' => "DATE DEFAULT NULL AFTER `permit_file_uploaded_at`",
        'signed_by_name' => "VARCHAR(150) DEFAULT NULL AFTER `signed_on`",
        'released_to_recipient' => "VARCHAR(150) DEFAULT NULL AFTER `signed_by_name`",
    ];
    $permitRecordColumnLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_permits'
           AND COLUMN_NAME = :column_name"
    );
    foreach ($permitRecordColumnDefinitions as $columnName => $definition) {
        $permitRecordColumnLookup->execute([
            ':schema_name' => $dbName,
            ':column_name' => $columnName,
        ]);
        if ((int) $permitRecordColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permits` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }

    // Existing installations predate Community draft saving. Add the draft
    // fields individually, then relax only the columns that must be incomplete
    // before final submission. Submitted rows retain their existing values.
    $permitApplicationColumnDefinitions = [
        'applicant_type' => "VARCHAR(50) DEFAULT NULL AFTER `applicant_address`",
        'organization_name' => "VARCHAR(255) DEFAULT NULL AFTER `applicant_type`",
        'property_relationship' => "VARCHAR(50) DEFAULT NULL AFTER `organization_name`",
        'authorization_details' => "VARCHAR(1000) DEFAULT NULL AFTER `property_relationship`",
        'district' => "VARCHAR(100) DEFAULT NULL AFTER `lot_number`",
        'declaration_confirmed_at' => "TIMESTAMP NULL DEFAULT NULL AFTER `validity_status`",
    ];
    $permitApplicationColumnLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_permit_applications'
           AND COLUMN_NAME = :column_name"
    );
    foreach ($permitApplicationColumnDefinitions as $columnName => $definition) {
        $permitApplicationColumnLookup->execute([
            ':schema_name' => $dbName,
            ':column_name' => $columnName,
        ]);
        if ((int) $permitApplicationColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_applications` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }

    $pdo->exec(
        "ALTER TABLE `tbl_permit_applications`
         MODIFY `transaction_id` VARCHAR(20) NULL DEFAULT NULL,
         MODIFY `property_classification` VARCHAR(100) NULL DEFAULT NULL,
         MODIFY `property_owner_name` VARCHAR(255) NULL DEFAULT NULL,
         MODIFY `property_address` VARCHAR(500) NULL DEFAULT NULL,
         MODIFY `barangay` VARCHAR(100) NULL DEFAULT NULL,
         MODIFY `municipality` VARCHAR(100) NULL DEFAULT NULL,
         MODIFY `province` VARCHAR(100) NULL DEFAULT NULL,
         MODIFY `cutting_purpose` VARCHAR(500) NULL DEFAULT NULL,
         MODIFY `application_status` VARCHAR(50) NOT NULL DEFAULT 'draft',
         MODIFY `submitted_at` TIMESTAMP NULL DEFAULT NULL"
    );

    // Add versioned scan metadata for existing permit installations.
    $permitDocumentColumnDefinitions = [
        'replaces_document_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `uploaded_by_user_id`',
        'is_current' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `replaces_document_id`',
        'archived_at' => 'TIMESTAMP NULL DEFAULT NULL AFTER `verification_notes`',
    ];
    $permitColumnLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    foreach ($permitDocumentColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_documents',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_documents` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $permitReviewColumnDefinitions = [
        'document_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `application_id`',
        'document_type' => 'VARCHAR(100) DEFAULT NULL AFTER `document_id`',
        'previous_review_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `review_status`',
        'original_received' => 'TINYINT(1) DEFAULT NULL AFTER `previous_review_id`',
        'original_received_on' => 'DATE DEFAULT NULL AFTER `original_received`',
        'received_by_user_id' => 'INT UNSIGNED DEFAULT NULL AFTER `original_received_on`',
        'wet_ink_required' => 'TINYINT(1) DEFAULT NULL AFTER `received_by_user_id`',
        'wet_ink_verified' => 'TINYINT(1) DEFAULT NULL AFTER `wet_ink_required`',
        'scan_compared_with_original' => 'TINYINT(1) DEFAULT NULL AFTER `wet_ink_verified`',
    ];
    foreach ($permitReviewColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_document_reviews',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_document_reviews` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $permitInspectionColumnDefinitions = [
        'previous_inspection_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `application_id`',
        'follow_up_of_inspection_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `previous_inspection_id`',
        'created_by_user_id' => 'INT UNSIGNED DEFAULT NULL AFTER `inspector_user_id`',
        'completed_by_user_id' => 'INT UNSIGNED DEFAULT NULL AFTER `created_by_user_id`',
        'inspection_location' => 'VARCHAR(500) DEFAULT NULL AFTER `scheduled_at`',
        'latitude' => 'DECIMAL(10,7) DEFAULT NULL AFTER `inspection_location`',
        'longitude' => 'DECIMAL(10,7) DEFAULT NULL AFTER `latitude`',
        'property_location_confirmed' => 'TINYINT(1) DEFAULT NULL AFTER `inspected_at`',
        'ownership_authorization_confirmed' => 'TINYINT(1) DEFAULT NULL AFTER `property_location_confirmed`',
        'follow_up_required' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `recommendation`',
    ];
    foreach ($permitInspectionColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_inspections',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_inspections` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $pdo->exec(
        'UPDATE `tbl_permit_inspections`
         SET `created_by_user_id` = `inspector_user_id`
         WHERE `created_by_user_id` IS NULL'
    );
    $pdo->exec(
        'ALTER TABLE `tbl_permit_inspections`
         MODIFY `inspector_user_id` INT UNSIGNED NULL DEFAULT NULL,
         MODIFY `created_by_user_id` INT UNSIGNED NOT NULL'
    );
    $permitDecisionColumnDefinitions = [
        'previous_decision_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `application_id`',
        'decision_conditions' => 'VARCHAR(2000) DEFAULT NULL AFTER `decision_notes`',
        'approved_tree_count' => 'INT UNSIGNED DEFAULT NULL AFTER `decision_conditions`',
        'property_classification' => 'VARCHAR(100) DEFAULT NULL AFTER `approved_tree_count`',
        'donation_seedling_count' => 'INT UNSIGNED DEFAULT NULL AFTER `property_classification`',
    ];
    foreach ($permitDecisionColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_decisions',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_decisions` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $permitDonationColumnDefinitions = [
        'approval_decision_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `application_id`',
        'property_classification' => 'VARCHAR(100) DEFAULT NULL AFTER `approval_decision_id`',
        'policy_code' => 'VARCHAR(100) DEFAULT NULL AFTER `property_classification`',
        'policy_version' => 'VARCHAR(50) DEFAULT NULL AFTER `policy_code`',
        'received_seedling_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `required_seedling_count`',
        'applicant_instructions' => 'VARCHAR(1000) DEFAULT NULL AFTER `requirement_basis`',
    ];
    foreach ($permitDonationColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_donation_requirements',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_donation_requirements` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $permitDonationVerificationColumnDefinitions = [
        'previous_verification_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `donation_requirement_id`',
        'receipt_group_key' => 'CHAR(64) DEFAULT NULL AFTER `previous_verification_id`',
        'action_key' => 'CHAR(64) DEFAULT NULL AFTER `receipt_group_key`',
        'version_number' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `action_key`',
        'received_by_user_id' => 'INT UNSIGNED DEFAULT NULL AFTER `version_number`',
        'is_current' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `verification_status`',
        'is_finalized' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_current`',
        'finalized_at' => 'TIMESTAMP NULL DEFAULT NULL AFTER `verification_notes`',
    ];
    foreach ($permitDonationVerificationColumnDefinitions as $columnName => $definition) {
        $permitColumnLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => 'tbl_permit_donation_verifications',
            ':column_name' => $columnName,
        ]);
        if ((int) $permitColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_permit_donation_verifications` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    $pdo->exec(
        'UPDATE `tbl_permit_donation_verifications`
         SET receipt_group_key = COALESCE(
                 receipt_group_key,
                 SHA2(CONCAT(\'legacy-receipt:\', id, \':\', verified_at), 256)
             ),
             action_key = COALESCE(
                 action_key,
                 SHA2(CONCAT(\'legacy-action:\', id, \':\', verified_at), 256)
             ),
             received_by_user_id = COALESCE(received_by_user_id, verified_by_user_id),
             finalized_at = COALESCE(finalized_at, verified_at)'
    );
    $pdo->exec(
        'ALTER TABLE `tbl_permit_donation_verifications`
         MODIFY `receipt_group_key` CHAR(64) NOT NULL,
         MODIFY `action_key` CHAR(64) NOT NULL,
         MODIFY `received_by_user_id` INT UNSIGNED NOT NULL,
         MODIFY `is_finalized` TINYINT(1) NOT NULL DEFAULT 0'
    );
    $donationBackfill = $pdo->prepare(
        'UPDATE `tbl_permit_donation_requirements` r
         INNER JOIN `tbl_permit_applications` a ON a.id = r.application_id
         SET r.approval_decision_id = COALESCE(
                 r.approval_decision_id,
                 (SELECT d.id
                  FROM `tbl_permit_decisions` d
                  WHERE d.application_id = r.application_id
                    AND d.decision = \'approved\'
                  ORDER BY d.id DESC
                  LIMIT 1)
             ),
             r.property_classification = COALESCE(
                 r.property_classification,
                 (SELECT d.property_classification
                  FROM `tbl_permit_decisions` d
                  WHERE d.application_id = r.application_id
                    AND d.decision = \'approved\'
                  ORDER BY d.id DESC
                  LIMIT 1),
                 a.property_classification
             ),
             r.policy_code = COALESCE(
                 r.policy_code,
                 CASE COALESCE(r.property_classification, a.property_classification)
                     WHEN \'public_domain\' THEN \'property_public_domain\'
                     WHEN \'private_property\' THEN \'property_private_property\'
                     ELSE \'legacy_unknown\'
                 END
             ),
             r.policy_version = COALESCE(r.policy_version, \'legacy\'),
             r.received_seedling_count = GREATEST(
                 r.received_seedling_count,
                 COALESCE((
                      SELECT SUM(v.seedlings_received)
                      FROM `tbl_permit_donation_verifications` v
                      WHERE v.donation_requirement_id = r.id
                        AND v.is_current = 1
                        AND v.is_finalized = 1
                        AND v.verification_status IN (\'partially_received\', \'ems_verified\', \'verified\')
                  ), 0)
             ),
             r.applicant_instructions = COALESCE(r.applicant_instructions, :instructions)'
    );
    $donationBackfill->execute([':instructions' => PERMIT_DONATION_INSTRUCTIONS]);
    $invalidDonationSnapshotCount = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM `tbl_permit_donation_requirements`
         WHERE approval_decision_id IS NULL
            OR property_classification IS NULL
            OR policy_code IS NULL
            OR policy_version IS NULL
            OR applicant_instructions IS NULL'
    )->fetchColumn();
    if ($invalidDonationSnapshotCount > 0) {
        throw new RuntimeException(
            'Existing donation requirements could not be linked to an approval decision and policy snapshot.'
        );
    }
    $pdo->exec(
        'ALTER TABLE `tbl_permit_donation_requirements`
         MODIFY `approval_decision_id` BIGINT UNSIGNED NOT NULL,
         MODIFY `property_classification` VARCHAR(100) NOT NULL,
         MODIFY `policy_code` VARCHAR(100) NOT NULL,
         MODIFY `policy_version` VARCHAR(50) NOT NULL,
         MODIFY `applicant_instructions` VARCHAR(1000) NOT NULL'
    );
    $pdo->exec(
        'UPDATE `tbl_permit_document_reviews` r
         INNER JOIN `tbl_permit_documents` d
                 ON d.application_id = r.application_id AND d.id = r.document_id
         SET r.document_type = d.document_type
         WHERE r.document_type IS NULL'
    );
    $pdo->exec(
        'ALTER TABLE `tbl_permit_documents`
         MODIFY `verification_notes` VARCHAR(1000) NULL DEFAULT NULL'
    );

    $permitIndexLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name"
    );
    $permitIndexes = [
        ['tbl_permit_trees', 'uq_permit_tree_application_id',
            'ALTER TABLE `tbl_permit_trees` ADD UNIQUE KEY `uq_permit_tree_application_id` (`application_id`, `id`)'],
        ['tbl_permit_inspections', 'uq_permit_inspection_application_id',
            'ALTER TABLE `tbl_permit_inspections` ADD UNIQUE KEY `uq_permit_inspection_application_id` (`application_id`, `id`)'],
        ['tbl_permit_inspections', 'idx_permit_inspections_creator',
            'ALTER TABLE `tbl_permit_inspections` ADD KEY `idx_permit_inspections_creator` (`created_by_user_id`)'],
        ['tbl_permit_inspections', 'idx_permit_inspections_completer',
            'ALTER TABLE `tbl_permit_inspections` ADD KEY `idx_permit_inspections_completer` (`completed_by_user_id`)'],
        ['tbl_permit_documents', 'uq_permit_document_application_id',
            'ALTER TABLE `tbl_permit_documents` ADD UNIQUE KEY `uq_permit_document_application_id` (`application_id`, `id`)'],
        ['tbl_permit_documents', 'idx_permit_documents_current',
            'ALTER TABLE `tbl_permit_documents` ADD KEY `idx_permit_documents_current` (`application_id`, `document_type`, `is_current`)'],
        ['tbl_permit_document_reviews', 'idx_permit_reviews_document',
            'ALTER TABLE `tbl_permit_document_reviews` ADD KEY `idx_permit_reviews_document` (`application_id`, `document_id`)'],
        ['tbl_permit_document_reviews', 'uq_permit_review_application_id',
            'ALTER TABLE `tbl_permit_document_reviews` ADD UNIQUE KEY `uq_permit_review_application_id` (`application_id`, `id`)'],
        ['tbl_permit_document_reviews', 'idx_permit_reviews_original',
            'ALTER TABLE `tbl_permit_document_reviews` ADD KEY `idx_permit_reviews_original` (`application_id`, `review_scope`, `document_type`, `reviewed_at`)'],
        ['tbl_permit_document_reviews', 'idx_permit_reviews_receiver',
            'ALTER TABLE `tbl_permit_document_reviews` ADD KEY `idx_permit_reviews_receiver` (`received_by_user_id`)'],
        ['tbl_permit_donation_requirements', 'idx_permit_donation_approval',
            'ALTER TABLE `tbl_permit_donation_requirements` ADD KEY `idx_permit_donation_approval` (`application_id`, `approval_decision_id`)'],
        ['tbl_permit_donation_verifications', 'uq_permit_donation_action_key',
            'ALTER TABLE `tbl_permit_donation_verifications` ADD UNIQUE KEY `uq_permit_donation_action_key` (`action_key`)'],
        ['tbl_permit_donation_verifications', 'uq_permit_donation_receipt_version',
            'ALTER TABLE `tbl_permit_donation_verifications` ADD UNIQUE KEY `uq_permit_donation_receipt_version` (`donation_requirement_id`, `receipt_group_key`, `version_number`)'],
        ['tbl_permit_donation_verifications', 'idx_permit_donation_verification_current',
            'ALTER TABLE `tbl_permit_donation_verifications` ADD KEY `idx_permit_donation_verification_current` (`donation_requirement_id`, `is_current`, `is_finalized`)'],
        ['tbl_permit_donation_verifications', 'idx_permit_donation_receiver',
            'ALTER TABLE `tbl_permit_donation_verifications` ADD KEY `idx_permit_donation_receiver` (`received_by_user_id`)'],
        ['tbl_permits', 'idx_permit_file_uploader',
            'ALTER TABLE `tbl_permits` ADD KEY `idx_permit_file_uploader` (`permit_file_uploaded_by_user_id`)'],
    ];
    foreach ($permitIndexes as [$tableName, $indexName, $indexSql]) {
        $permitIndexLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => $tableName,
            ':index_name' => $indexName,
        ]);
        if ((int) $permitIndexLookup->fetchColumn() === 0) {
            $pdo->exec($indexSql);
        }
    }

    $permitConstraintLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :schema_name
           AND TABLE_NAME = :table_name
           AND CONSTRAINT_NAME = :constraint_name"
    );
    $permitDocumentConstraints = [
        ['tbl_permit_decisions', 'fk_permit_decision_previous',
            'ALTER TABLE `tbl_permit_decisions`
             ADD CONSTRAINT `fk_permit_decision_previous`
             FOREIGN KEY (`previous_decision_id`) REFERENCES `tbl_permit_decisions` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_donation_requirements', 'fk_permit_donation_approval',
            'ALTER TABLE `tbl_permit_donation_requirements`
             ADD CONSTRAINT `fk_permit_donation_approval`
             FOREIGN KEY (`application_id`, `approval_decision_id`)
             REFERENCES `tbl_permit_decisions` (`application_id`, `id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_donation_verifications', 'fk_permit_donation_verification_previous',
            'ALTER TABLE `tbl_permit_donation_verifications`
             ADD CONSTRAINT `fk_permit_donation_verification_previous`
             FOREIGN KEY (`previous_verification_id`) REFERENCES `tbl_permit_donation_verifications` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_donation_verifications', 'fk_permit_donation_verification_receiver',
            'ALTER TABLE `tbl_permit_donation_verifications`
             ADD CONSTRAINT `fk_permit_donation_verification_receiver`
             FOREIGN KEY (`received_by_user_id`) REFERENCES `tbl_users` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_inspections', 'fk_permit_inspection_creator',
            'ALTER TABLE `tbl_permit_inspections`
             ADD CONSTRAINT `fk_permit_inspection_creator`
             FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_inspections', 'fk_permit_inspection_completer',
            'ALTER TABLE `tbl_permit_inspections`
             ADD CONSTRAINT `fk_permit_inspection_completer`
             FOREIGN KEY (`completed_by_user_id`) REFERENCES `tbl_users` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_inspections', 'fk_permit_inspection_previous',
            'ALTER TABLE `tbl_permit_inspections`
             ADD CONSTRAINT `fk_permit_inspection_previous`
             FOREIGN KEY (`previous_inspection_id`) REFERENCES `tbl_permit_inspections` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_inspections', 'fk_permit_inspection_follow_up',
            'ALTER TABLE `tbl_permit_inspections`
             ADD CONSTRAINT `fk_permit_inspection_follow_up`
             FOREIGN KEY (`follow_up_of_inspection_id`) REFERENCES `tbl_permit_inspections` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_documents', 'fk_permit_document_replacement',
            'ALTER TABLE `tbl_permit_documents`
             ADD CONSTRAINT `fk_permit_document_replacement`
             FOREIGN KEY (`application_id`, `replaces_document_id`)
             REFERENCES `tbl_permit_documents` (`application_id`, `id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_document_reviews', 'fk_permit_review_document',
            'ALTER TABLE `tbl_permit_document_reviews`
             ADD CONSTRAINT `fk_permit_review_document`
             FOREIGN KEY (`application_id`, `document_id`)
             REFERENCES `tbl_permit_documents` (`application_id`, `id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_document_reviews', 'fk_permit_review_previous',
            'ALTER TABLE `tbl_permit_document_reviews`
             ADD CONSTRAINT `fk_permit_review_previous`
             FOREIGN KEY (`application_id`, `previous_review_id`)
             REFERENCES `tbl_permit_document_reviews` (`application_id`, `id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permit_document_reviews', 'fk_permit_review_receiver',
            'ALTER TABLE `tbl_permit_document_reviews`
             ADD CONSTRAINT `fk_permit_review_receiver`
             FOREIGN KEY (`received_by_user_id`)
             REFERENCES `tbl_users` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
        ['tbl_permits', 'fk_permit_record_file_uploader',
            'ALTER TABLE `tbl_permits`
             ADD CONSTRAINT `fk_permit_record_file_uploader`
             FOREIGN KEY (`permit_file_uploaded_by_user_id`)
             REFERENCES `tbl_users` (`id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'],
    ];
    foreach ($permitDocumentConstraints as [$tableName, $constraintName, $constraintSql]) {
        $permitConstraintLookup->execute([
            ':schema_name' => $dbName,
            ':table_name' => $tableName,
            ':constraint_name' => $constraintName,
        ]);
        if ((int) $permitConstraintLookup->fetchColumn() === 0) {
            $pdo->exec($constraintSql);
        }
    }

    $decisionApplicationIndex = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_permit_decisions'
           AND INDEX_NAME = 'uq_permit_decision_application_id'"
    );
    $decisionApplicationIndex->execute([':schema_name' => $dbName]);
    if ((int) $decisionApplicationIndex->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE `tbl_permit_decisions`
             ADD UNIQUE KEY `uq_permit_decision_application_id` (`application_id`, `id`)'
        );
    }

    $permitDecisionConstraint = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_permits'
           AND CONSTRAINT_NAME = 'fk_permit_record_decision_application'"
    );
    $permitDecisionConstraint->execute([':schema_name' => $dbName]);
    if ((int) $permitDecisionConstraint->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE `tbl_permits`
             ADD CONSTRAINT `fk_permit_record_decision_application`
             FOREIGN KEY (`application_id`, `decision_id`)
             REFERENCES `tbl_permit_decisions` (`application_id`, `id`)
             ON DELETE RESTRICT ON UPDATE RESTRICT'
        );
    }
    echo "Tree Cutting Permit workflow foundation tables are ready.<br>\n";

    // Seedling inventory and the public seedling-request program. Independent of
    // the permit workflow: permit donations flow Community -> EMS, while these
    // requests flow EMS -> Community.
    $createSeedlingTables = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_seedling_request_sequences` (
  `sequence_year` SMALLINT UNSIGNED NOT NULL,
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sequence_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_seedling_inventory` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `common_name` VARCHAR(150) NOT NULL,
  `scientific_name` VARCHAR(150) DEFAULT NULL,
  `available_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
  `low_stock_threshold` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seedling_species` (`common_name`),
  KEY `idx_seedling_inventory_active` (`is_active`, `common_name`),
  KEY `idx_seedling_inventory_creator` (`created_by_user_id`),
  CONSTRAINT `fk_seedling_inventory_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_seedling_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_reference` VARCHAR(20) DEFAULT NULL,
  `submission_key` VARCHAR(64) NOT NULL,
  `requester_user_id` INT UNSIGNED NOT NULL,
  `requester_name` VARCHAR(255) NOT NULL,
  `requester_contact` VARCHAR(20) DEFAULT NULL,
  `planting_purpose` VARCHAR(500) NOT NULL,
  `planting_location` VARCHAR(500) NOT NULL,
  `preferred_pickup_date` DATE DEFAULT NULL,
  `current_status` VARCHAR(50) NOT NULL DEFAULT 'submitted',
  `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `review_remarks` VARCHAR(1000) DEFAULT NULL,
  `fulfilled_by_user_id` INT UNSIGNED DEFAULT NULL,
  `fulfilled_at` TIMESTAMP NULL DEFAULT NULL,
  `claimed_by_name` VARCHAR(150) DEFAULT NULL,
  `released_by_user_id` INT UNSIGNED DEFAULT NULL,
  `claimed_on` DATE DEFAULT NULL,
  `claim_remarks` VARCHAR(1000) DEFAULT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seedling_request_reference` (`request_reference`),
  UNIQUE KEY `uq_seedling_requester_submission` (`requester_user_id`, `submission_key`),
  KEY `idx_seedling_requests_status` (`current_status`, `submitted_at`),
  KEY `idx_seedling_requests_requester` (`requester_user_id`, `current_status`),
  KEY `idx_seedling_requests_reviewer` (`reviewed_by_user_id`),
  KEY `idx_seedling_requests_fulfiller` (`fulfilled_by_user_id`),
  KEY `idx_seedling_requests_releaser` (`released_by_user_id`),
  CONSTRAINT `fk_seedling_request_requester` FOREIGN KEY (`requester_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_request_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_request_fulfiller` FOREIGN KEY (`fulfilled_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_request_releaser` FOREIGN KEY (`released_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_seedling_request_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `inventory_id` BIGINT UNSIGNED NOT NULL,
  `common_name` VARCHAR(150) NOT NULL,
  `quantity_requested` INT UNSIGNED NOT NULL,
  `quantity_approved` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seedling_request_item_species` (`request_id`, `inventory_id`),
  KEY `idx_seedling_request_items_request` (`request_id`),
  KEY `idx_seedling_request_items_inventory` (`inventory_id`),
  CONSTRAINT `fk_seedling_request_item_request` FOREIGN KEY (`request_id`) REFERENCES `tbl_seedling_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_request_item_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_seedling_inventory` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_seedling_stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` BIGINT UNSIGNED NOT NULL,
  `request_id` BIGINT UNSIGNED DEFAULT NULL,
  `movement_type` VARCHAR(30) NOT NULL,
  `quantity_delta` INT NOT NULL,
  `quantity_after` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) DEFAULT NULL,
  `recorded_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seedling_movements_inventory` (`inventory_id`, `created_at`),
  KEY `idx_seedling_movements_type` (`movement_type`, `created_at`),
  KEY `idx_seedling_movements_request` (`request_id`),
  KEY `idx_seedling_movements_actor` (`recorded_by_user_id`),
  CONSTRAINT `fk_seedling_movement_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_seedling_inventory` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_movement_request` FOREIGN KEY (`request_id`) REFERENCES `tbl_seedling_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_movement_actor` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_seedling_request_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `previous_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `changed_by_user_id` INT UNSIGNED NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seedling_history_request` (`request_id`, `created_at`),
  KEY `idx_seedling_history_actor` (`changed_by_user_id`),
  CONSTRAINT `fk_seedling_history_request` FOREIGN KEY (`request_id`) REFERENCES `tbl_seedling_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_seedling_history_actor` FOREIGN KEY (`changed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $seedlingStatements = preg_split('/;\s*(?:\r\n|\n|\r|$)/', trim($createSeedlingTables)) ?: [];
    foreach ($seedlingStatements as $seedlingStatement) {
        if (trim($seedlingStatement) !== '') {
            $pdo->exec($seedlingStatement);
        }
    }
    echo "Seedling inventory and request tables are ready.<br>\n";

    // Illegal logging incident reports. Independent of the permit and seedling
    // workflows: Community reports a suspected incident, CENRO enforcement
    // (active RPS or a specifically permitted Superadmin) dispatches a field
    // verification, and records a resolution outcome.
    $createIllegalLoggingTables = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_illegal_logging_report_sequences` (
  `sequence_year` SMALLINT UNSIGNED NOT NULL,
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sequence_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_illegal_logging_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_reference` VARCHAR(20) DEFAULT NULL,
  `submission_key` VARCHAR(64) NOT NULL,
  `reporter_user_id` INT UNSIGNED NOT NULL,
  `reporter_name` VARCHAR(255) NOT NULL,
  `reporter_contact` VARCHAR(20) DEFAULT NULL,
  `incident_location` VARCHAR(500) NOT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `incident_description` TEXT NOT NULL,
  `observed_on` DATE DEFAULT NULL,
  `current_status` VARCHAR(50) NOT NULL DEFAULT 'submitted',
  `assigned_to_user_id` INT UNSIGNED DEFAULT NULL,
  `assigned_by_user_id` INT UNSIGNED DEFAULT NULL,
  `assigned_at` TIMESTAMP NULL DEFAULT NULL,
  `field_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `field_findings` VARCHAR(2000) DEFAULT NULL,
  `resolution_outcome` VARCHAR(50) DEFAULT NULL,
  `resolution_notes` VARCHAR(2000) DEFAULT NULL,
  `resolved_by_user_id` INT UNSIGNED DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_illegal_logging_report_reference` (`report_reference`),
  UNIQUE KEY `uq_illegal_logging_reporter_submission` (`reporter_user_id`, `submission_key`),
  KEY `idx_illegal_logging_status` (`current_status`, `submitted_at`),
  KEY `idx_illegal_logging_reporter` (`reporter_user_id`, `current_status`),
  KEY `idx_illegal_logging_assignee` (`assigned_to_user_id`),
  KEY `idx_illegal_logging_assigner` (`assigned_by_user_id`),
  KEY `idx_illegal_logging_resolver` (`resolved_by_user_id`),
  CONSTRAINT `fk_illegal_logging_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_illegal_logging_assignee` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_illegal_logging_assigner` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_illegal_logging_resolver` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_illegal_logging_report_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size_bytes` BIGINT UNSIGNED NOT NULL,
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_illegal_logging_photos_report` (`report_id`),
  KEY `idx_illegal_logging_photos_uploader` (`uploaded_by_user_id`),
  CONSTRAINT `fk_illegal_logging_photo_report` FOREIGN KEY (`report_id`) REFERENCES `tbl_illegal_logging_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_illegal_logging_photo_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_illegal_logging_report_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `previous_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `changed_by_user_id` INT UNSIGNED NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_illegal_logging_history_report` (`report_id`, `created_at`),
  KEY `idx_illegal_logging_history_actor` (`changed_by_user_id`),
  CONSTRAINT `fk_illegal_logging_history_report` FOREIGN KEY (`report_id`) REFERENCES `tbl_illegal_logging_reports` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_illegal_logging_history_actor` FOREIGN KEY (`changed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $illegalLoggingStatements = preg_split('/;\s*(?:\r\n|\n|\r|$)/', trim($createIllegalLoggingTables)) ?: [];
    foreach ($illegalLoggingStatements as $illegalLoggingStatement) {
        if (trim($illegalLoggingStatement) !== '') {
            $pdo->exec($illegalLoggingStatement);
        }
    }
    echo "Illegal logging report tables are ready.<br>\n";

    // Area Management: a CENRO-internal reference registry of named geographic
    // zones classified as allowed, restricted, or protected. Purely
    // informational; no link to the Tree Cutting Permit workflow.
    $createAreaZoneTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_area_zones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `zone_name` VARCHAR(150) NOT NULL,
  `classification` VARCHAR(50) NOT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `municipality` VARCHAR(100) DEFAULT NULL,
  `barangay` VARCHAR(100) DEFAULT NULL,
  `district` VARCHAR(100) DEFAULT NULL,
  `coverage_description` VARCHAR(1000) DEFAULT NULL,
  `notes` VARCHAR(1000) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_area_zone_name` (`zone_name`),
  KEY `idx_area_zone_classification` (`classification`, `is_active`),
  KEY `idx_area_zone_creator` (`created_by_user_id`),
  KEY `idx_area_zone_updater` (`updated_by_user_id`),
  CONSTRAINT `fk_area_zone_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_zone_updater` FOREIGN KEY (`updated_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($createAreaZoneTable);

    // Existing installations predate zone geomapping. Add the drawn-boundary
    // and map-center columns individually so existing zones keep their data.
    $areaZoneColumnDefinitions = [
        'boundary_geojson' => 'MEDIUMTEXT DEFAULT NULL AFTER `coverage_description`',
        'center_lat' => 'DECIMAL(10,7) DEFAULT NULL AFTER `boundary_geojson`',
        'center_lng' => 'DECIMAL(10,7) DEFAULT NULL AFTER `center_lat`',
    ];
    $areaZoneColumnLookup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = 'tbl_area_zones'
           AND COLUMN_NAME = :column_name"
    );
    foreach ($areaZoneColumnDefinitions as $columnName => $definition) {
        $areaZoneColumnLookup->execute([
            ':schema_name' => $dbName,
            ':column_name' => $columnName,
        ]);
        if ((int) $areaZoneColumnLookup->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE `tbl_area_zones` ADD COLUMN `'
                . $columnName . '` ' . $definition
            );
        }
    }
    echo "Area Management zone table is ready.<br>\n";

    // Planting sites: an EMS-maintained advisory registry of recommended
    // seedling planting locations. Each record carries editable environmental
    // attributes (soil, moisture, season) whose initial values may be seeded
    // from free public datasets (ISRIC SoilGrids, Open-Meteo, PAGASA normals)
    // and are always overridable by EMS field knowledge. Read-only for
    // Community users on the seedling-request page; no link to permit flow.
    $createPlantingSiteTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_planting_sites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_name` VARCHAR(150) NOT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `municipality` VARCHAR(100) DEFAULT NULL,
  `barangay` VARCHAR(100) DEFAULT NULL,
  `boundary_geojson` MEDIUMTEXT DEFAULT NULL,
  `center_lat` DECIMAL(10,7) DEFAULT NULL,
  `center_lng` DECIMAL(10,7) DEFAULT NULL,
  `soil_type` VARCHAR(100) DEFAULT NULL,
  `soil_ph` VARCHAR(50) DEFAULT NULL,
  `moisture_level` VARCHAR(50) DEFAULT NULL,
  `recommended_season` VARCHAR(150) DEFAULT NULL,
  `suitable_species` VARCHAR(500) DEFAULT NULL,
  `rationale` VARCHAR(1000) DEFAULT NULL,
  `data_source` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planting_site_name` (`site_name`),
  KEY `idx_planting_site_active` (`is_active`, `municipality`),
  KEY `idx_planting_site_creator` (`created_by_user_id`),
  KEY `idx_planting_site_updater` (`updated_by_user_id`),
  CONSTRAINT `fk_planting_site_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_planting_site_updater` FOREIGN KEY (`updated_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($createPlantingSiteTable);
    echo "Planting site table is ready.<br>\n";

    // Public Advisories: CENRO-authored notices shown to logged-in Community
    // users once published. Independent of the permit workflow. Lifecycle:
    // draft -> published -> archived, with a direct draft -> archived discard.
    $createAdvisoriesTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_advisories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `current_status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `event_at` DATETIME NULL DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `image_original_name` VARCHAR(255) DEFAULT NULL,
  `image_mime_type` VARCHAR(100) DEFAULT NULL,
  `image_size_bytes` INT UNSIGNED DEFAULT NULL,
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `archived_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_advisory_status` (`current_status`, `published_at`),
  KEY `idx_advisory_creator` (`created_by_user_id`),
  KEY `idx_advisory_updater` (`updated_by_user_id`),
  CONSTRAINT `fk_advisory_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_advisory_updater` FOREIGN KEY (`updated_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($createAdvisoriesTable);
    echo "Advisories table is ready.<br>\n";

    $createAdvisoryHistoryTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_advisory_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `advisory_id` BIGINT UNSIGNED NOT NULL,
  `previous_status` VARCHAR(20) DEFAULT NULL,
  `new_status` VARCHAR(20) NOT NULL,
  `changed_by_user_id` INT UNSIGNED NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_advisory_history_advisory` (`advisory_id`, `created_at`),
  KEY `idx_advisory_history_actor` (`changed_by_user_id`),
  CONSTRAINT `fk_advisory_history_advisory` FOREIGN KEY (`advisory_id`) REFERENCES `tbl_advisories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_advisory_history_actor` FOREIGN KEY (`changed_by_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($createAdvisoryHistoryTable);
    echo "Advisory status history table is ready.<br>\n";

    echo "<strong>Done.</strong> Please delete this file (`create_database_and_tables.php`) after successful execution.";

} catch (PDOException $e) {
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit(1);
}

?>
