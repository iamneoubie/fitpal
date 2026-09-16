<?php
/**
 * FitPal Shared View Helpers
 *
 * Pure presentation helpers used by multiple pages across roles.
 * This file contains NO SQL, NO session mutation, NO side effects,
 * and NO path-resolution helpers tied to a specific page location.
 *
 * @package FitPal
 * @version 1.1 — Removed getLandingAssetBase (belongs in index.php only)
 */

declare(strict_types=1);

if (!function_exists('formatPrice')) {
    /**
     * Format a numeric price as Philippine pesos.
     *
     * @param int|float|string $price
     * @return string
     */
    function formatPrice(int|float|string $price): string
    {
        return '₱' . number_format((float)$price, 2);
    }
}

if (!function_exists('truncateText')) {
    /**
     * Truncate a string to a maximum length, appending an ellipsis if cut.
     *
     * @param string $text
     * @param int $length
     * @return string
     */
    function truncateText(string $text, int $length = 70): string
    {
        $text = trim($text);
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }
}

if (!function_exists('parseTagList')) {
    /**
     * Split a comma-separated tag list into a clean, trimmed array.
     * Empty entries are filtered out.
     *
     * @param string $raw
     * @return array<int, string>
     */
    function parseTagList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $v): bool => $v !== ''
        ));
    }
}