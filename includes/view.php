<?php
/**
 * Escape dynamic text before rendering it into HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
