-- Additive intake updates for MariaDB. For deployment use scripts/apply_intake_updates.php before updated pages serve traffic.
ALTER TABLE tbl_permit_applications ADD COLUMN IF NOT EXISTS intake_version TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE tbl_permit_applications ADD COLUMN IF NOT EXISTS completion_status VARCHAR(30) DEFAULT NULL;
ALTER TABLE tbl_permit_trees ADD COLUMN IF NOT EXISTS tree_origin VARCHAR(30) DEFAULT NULL;
ALTER TABLE tbl_seedling_inventory ADD COLUMN IF NOT EXISTS indigenous_status VARCHAR(20) NOT NULL DEFAULT 'unknown';
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_barangay VARCHAR(100) DEFAULT NULL;
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_municipality VARCHAR(100) DEFAULT NULL;
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_site_id BIGINT UNSIGNED DEFAULT NULL;
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_latitude DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_longitude DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE tbl_permit_donation_requirements MODIFY COLUMN property_classification VARCHAR(100) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS tbl_permit_tallies (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 uploaded_by_user_id INT UNSIGNED NOT NULL,
 original_filename VARCHAR(255) NOT NULL,
 storage_path VARCHAR(500) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 rows_json LONGTEXT NOT NULL,
 tree_count INT UNSIGNED NOT NULL,
 replacement_count INT UNSIGNED NOT NULL,
 natural_count INT UNSIGNED NOT NULL,
 planted_count INT UNSIGNED NOT NULL,
 volume_total DECIMAL(14,4) NOT NULL,
 is_current TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tally_content (application_id, sha256),
 KEY idx_tally_application (application_id, is_current),
 CONSTRAINT fk_tally_application FOREIGN KEY (application_id) REFERENCES tbl_permit_applications(id) ON DELETE RESTRICT,
 CONSTRAINT fk_tally_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_permit_completion_reports (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 reported_by_user_id INT UNSIGNED NOT NULL,
 description VARCHAR(2000) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_completion_report (application_id, id),
 CONSTRAINT fk_completion_report_application FOREIGN KEY (application_id) REFERENCES tbl_permit_applications(id) ON DELETE RESTRICT,
 CONSTRAINT fk_completion_report_user FOREIGN KEY (reported_by_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_permit_completion_verifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 report_id BIGINT UNSIGNED NOT NULL,
 verified_by_user_id INT UNSIGNED NOT NULL,
 all_approved_trees_cut TINYINT UNSIGNED NOT NULL,
 additional_trees_affected TINYINT UNSIGNED NOT NULL,
 description VARCHAR(2000) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_completion_verification (report_id, id),
 CONSTRAINT fk_completion_verification_report FOREIGN KEY (report_id) REFERENCES tbl_permit_completion_reports(id) ON DELETE RESTRICT,
 CONSTRAINT fk_completion_verification_user FOREIGN KEY (verified_by_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE tbl_permit_completion_reports ADD COLUMN IF NOT EXISTS completed_on DATE DEFAULT NULL;
ALTER TABLE tbl_permit_completion_verifications ADD COLUMN IF NOT EXISTS follow_up_resolved TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE tbl_permits ADD COLUMN IF NOT EXISTS tarpaulin_released_on DATE DEFAULT NULL;
ALTER TABLE tbl_permits ADD COLUMN IF NOT EXISTS tarpaulin_released_by_user_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_province VARCHAR(100) NOT NULL DEFAULT 'Laguna';
ALTER TABLE tbl_seedling_requests ADD COLUMN IF NOT EXISTS planting_location_source VARCHAR(1000) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS tbl_seedling_providers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 provider_name VARCHAR(200) NOT NULL,
 address VARCHAR(500) NOT NULL,
 municipality VARCHAR(100) NOT NULL,
 barangay VARCHAR(100) NOT NULL,
 latitude DECIMAL(10,7) NOT NULL,
 longitude DECIMAL(10,7) NOT NULL,
 contact_details VARCHAR(200) DEFAULT NULL,
 opening_hours VARCHAR(200) DEFAULT NULL,
 notes VARCHAR(1000) DEFAULT NULL,
 data_source VARCHAR(500) NOT NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_by_user_id INT UNSIGNED NOT NULL,
 updated_by_user_id INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_provider_active (is_active),
 CONSTRAINT fk_provider_creator FOREIGN KEY (created_by_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_provider_editor FOREIGN KEY (updated_by_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tbl_seedling_requests MODIFY COLUMN planting_location_source VARCHAR(1000) DEFAULT NULL;
