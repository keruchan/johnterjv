<?php
/**
 * Escape dynamic text before rendering it into HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Content-based versions prevent stale styles/scripts after a deployment. */
function certreefy_asset_version(string $relativePath): string
{
    static $versions = [];
    if (!isset($versions[$relativePath])) {
        $versions[$relativePath] = substr(hash_file('sha256', dirname(__DIR__) . '/' . $relativePath), 0, 12);
    }
    return $versions[$relativePath];
}

/** Visual placeholders only; no archive/delete endpoint or handler is attached. */
function render_record_action_placeholders(bool $includeArchive = true): void
{
    echo '<span class="d-inline-flex gap-1 record-action-placeholders">';
    if ($includeArchive) {
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Archive (not available yet)" aria-label="Archive (not available yet)"><i class="bi bi-archive" aria-hidden="true"></i></button>';
    }
    echo '<button type="button" class="btn btn-sm btn-outline-danger" disabled title="Delete (not available yet)" aria-label="Delete (not available yet)"><i class="bi bi-trash" aria-hidden="true"></i></button></span>';
}
