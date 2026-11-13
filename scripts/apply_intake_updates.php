<?php
/** CLI-only, additive intake migration; no existing records are removed or reclassified. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
$sql = file_get_contents(__DIR__ . '/../config/intake_updates_schema.sql');
foreach (explode(';', $sql) as $statement) {
    $statement = trim(preg_replace('/^--[^\r\n]*(?:\r?\n|$)/m', '', $statement));
    if ($statement === '') { continue; }
    // Check metadata instead of depending on vendor-specific ADD COLUMN IF NOT EXISTS.
    if (preg_match('/^ALTER TABLE (tbl_[a-z_]+) ADD COLUMN IF NOT EXISTS ([a-z_]+) /i', $statement, $match)) {
        $column = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $column->execute([$match[1], $match[2]]);
        if ((int) $column->fetchColumn() > 0) { continue; }
        $statement = str_replace('ADD COLUMN IF NOT EXISTS', 'ADD COLUMN', $statement);
    }
    $pdo->exec($statement);
}
echo "Intake schema updates applied. Existing records preserved." . PHP_EOL;
