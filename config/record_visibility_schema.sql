-- Additive personal archive/delete storage. Source records are never deleted.
CREATE TABLE IF NOT EXISTS tbl_record_user_visibility (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(1000) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_record_visibility (user_id, entity_type, entity_id),
    KEY idx_record_visibility_entity (entity_type, entity_id),
    CONSTRAINT fk_record_visibility_user FOREIGN KEY (user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_record_visibility_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NOT NULL,
    actor_name VARCHAR(255) NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    record_label VARCHAR(255) NOT NULL,
    action VARCHAR(20) NOT NULL,
    previous_state VARCHAR(20) NOT NULL,
    new_state VARCHAR(20) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    request_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_record_visibility_request (request_key),
    KEY idx_record_visibility_history (entity_type, entity_id, id),
    KEY idx_record_visibility_actor (actor_user_id, id),
    CONSTRAINT fk_record_visibility_action_actor FOREIGN KEY (actor_user_id) REFERENCES tbl_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
