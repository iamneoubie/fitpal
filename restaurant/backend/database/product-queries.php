<?php
/**
 * FitPal Restaurant Product Queries
 *
 * Read-only data-access and presentation helpers for the product
 * image folder resolution that the restaurant kitchen page needs.
 * The restaurant role does not manage products from the kitchen
 * page, so this file is deliberately smaller than the customer
 * role's product-queries.php: it only surfaces the helpers that
 * turn a raw `dietary_information.images` value into a browser-
 * loadable URL.
 *
 * ---------------------------------------------------------------------
 * MULTI-IMAGE MODEL (mirrors the customer role)
 * ---------------------------------------------------------------------
 * dietary_information.images stores a project-root-relative FOLDER
 * path. On this deployment the column value omits the literal
 * `restaurant/` segment that the manifest folder actually carries:
 *
 *     column value:  shared/assets/images/manifest/products/<resto>/<slug>/
 *     disk folder:   shared/assets/images/manifest/products/restaurant/<resto>/<slug>/
 *
 * The reader resolves the column value against a candidate list of
 * roots and returns the first candidate that is a directory. The
 * absolute directory it found is exposed as the resolved folder
 * path; the caller appends a filename and prepends its own
 * project-root URL.
 *
 * The customer role's product-queries.php performs exactly this
 * resolution. This file carries the same logic so the restaurant
 * role does not have to reach across into the customer role's
 * directory. The two files are deliberately duplicated rather than
 * shared, because sharing them would couple the two roles' include
 * paths and make a role-local change impossible.
 *
 * ---------------------------------------------------------------------
 * CANDIDATE ORDER
 * ---------------------------------------------------------------------
 * resolveProductImageDir() tries, in order:
 *
 *   1. $projectRoot . '/' . $folder
 *         The value as stored. Correct when the column already
 *         names a real on-disk folder.
 *
 *   2. $projectRoot . '/shared/assets/images/manifest/products/restaurant/' . $tail
 *         The manifest root that actually exists. $tail is the last
 *         two path segments of $folder.
 *
 *   3. $projectRoot . '/shared/uploads/restaurant/' . $tail
 *         The future runtime upload root.
 *
 * @package FitPal
 * @version 1.0 — First version of this file. Provides the image
 *                resolution helpers the restaurant kitchen page
 *                needs to render product thumbnails on kitchen
 *                order cards.
 */

declare(strict_types=1);

if (!function_exists('getProductImageCandidateDirs')) {
    /**
     * Return the ordered list of candidate absolute directories for
     * a given dietary_information.images value.
     *
     * @param string $folderPath
     * @return array<int, string>
     */
    function getProductImageCandidateDirs(string $folderPath): array
    {
        $folderPath = trim($folderPath);
        if ($folderPath === '') {
            return [];
        }

        $folderPath = rtrim(str_replace('\\', '/', $folderPath), '/') . '/';

        // restaurant/backend/database → three levels up reaches
        // the project root.
        $projectRoot = dirname(__DIR__, 3);

        $candidates = [];

        // Candidate 1: the value as stored.
        $candidates[] = $projectRoot . '/' . ltrim($folderPath, '/');

        // Extract the trailing two path segments.
        $trimmed = trim($folderPath, '/');
        $parts   = explode('/', $trimmed);
        $parts   = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if (count($parts) >= 2) {
            $tail = $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1] . '/';

            // Candidate 2: manifest layout with the extra
            // `restaurant/` segment.
            $candidates[] = $projectRoot
                . '/shared/assets/images/manifest/products/restaurant/'
                . $tail;

            // Candidate 3: runtime upload root.
            $candidates[] = $projectRoot . '/shared/uploads/restaurant/' . $tail;
        }

        return $candidates;
    }
}

if (!function_exists('resolveProductImageDir')) {
    /**
     * Return the first candidate directory that exists on disk, as
     * an absolute filesystem path, or '' when none exist.
     *
     * @param string $folderPath
     * @return string
     */
    function resolveProductImageDir(string $folderPath): string
    {
        foreach (getProductImageCandidateDirs($folderPath) as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('getProductImageBasePath')) {
    /**
     * Return the project-root-relative folder path the reader
     * resolved for a column value, or '' when nothing matched.
     *
     * The returned path always begins with `shared/` and always ends
     * with `/`. A caller prepends its own project-root URL and
     * appends a bare filename to build the browser URL.
     *
     * @param string $folderPath
     * @return string
     */
    function getProductImageBasePath(string $folderPath): string
    {
        $abs = resolveProductImageDir($folderPath);
        if ($abs === '') {
            return '';
        }

        $projectRoot = dirname(__DIR__, 3);

        $normalized = str_replace('\\', '/', $abs);
        $root       = str_replace('\\', '/', $projectRoot);

        if (stripos($normalized, $root) === 0) {
            $normalized = substr($normalized, strlen($root));
        }

        $normalized = ltrim($normalized, '/');
        if ($normalized === '') {
            return '';
        }

        return rtrim($normalized, '/') . '/';
    }
}

if (!function_exists('getProductImageFilenames')) {
    /**
     * Return the ordered list of image-*.{ext} filenames inside the
     * folder the reader resolved for a column value.
     *
     * @param string $folderPath
     * @return array<int, string>
     */
    function getProductImageFilenames(string $folderPath): array
    {
        $dir = resolveProductImageDir($folderPath);
        if ($dir === '') {
            return [];
        }

        $filenames = [];

        for ($index = 1; $index <= 5; $index++) {
            $matches = glob($dir . '/image-' . $index . '.*');
            if (empty($matches)) {
                continue;
            }
            $filenames[] = basename($matches[0]);
        }

        return $filenames;
    }
}

if (!function_exists('getProductPrimaryFilename')) {
    /**
     * Return only the FIRST image-*.{ext} filename inside the folder
     * the reader resolved for a column value, or '' when nothing
     * matched.
     *
     * @param string $folderPath
     * @return string
     */
    function getProductPrimaryFilename(string $folderPath): string
    {
        $dir = resolveProductImageDir($folderPath);
        if ($dir === '') {
            return '';
        }

        $matches = glob($dir . '/image-1.*');
        if (empty($matches)) {
            return '';
        }

        return basename($matches[0]);
    }
}