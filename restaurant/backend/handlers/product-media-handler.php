<?php
/**
 * FitPal Restaurant Product Media Handler
 *
 * Owns the disk side of product image storage.
 *
 * ---------------------------------------------------------------------
 * FILENAME SCHEME — FIVE ADDRESSABLE SLOTS
 * ---------------------------------------------------------------------
 * The current layout writes image-1.<ext> through image-5.<ext>
 * into
 *
 *     shared/uploads/restaurant/products/<product_id>/
 *
 * This matches the read order of getProductImageFilenames() in
 * customer/backend/database/product-queries.php.
 *
 * ---------------------------------------------------------------------
 * READ SOURCES — TWO, IN ORDER
 * ---------------------------------------------------------------------
 * A product's images can come from either of two sources:
 *
 *   1. The writer's folder
 *          shared/uploads/restaurant/products/<product_id>/
 *
 *   2. The column value in dietary_information.images, resolved
 *      through the same candidate roots the customer-facing
 *      storefront uses. This is where seed files live.
 *
 * productMediaSlotList() reads (1) first and falls back to (2).
 *
 * productMediaSlotBasePath() returns the project-root-relative
 * folder that the winning source used, so a caller building a URL
 * knows where the file actually is. The page builds the preview
 * URL from this base path, not from a hard-coded writer path.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * storeProductMediaSlots(int $productId, array $slots): array
 * productMediaSlotFilename(int $productId, int $slot): string
 * productMediaSlotMap(int $productId): array
 * productMediaSlotBasePath(int $productId): string
 * productMediaRelativeFolder(int $productId): string
 * productMediaAbsoluteFolder(int $productId): string
 * productMediaCandidateFolders(int $productId): array
 * productMediaResolveFolder(int $productId): string
 * productMediaListImageFiles(string $folder): array
 * productMediaSlotList(int $productId): array
 * productMediaSlotListFromFolder(string $folder): array
 * productMediaColumnValue(int $productId): string
 * deleteProductMediaSlot(int $productId, int $slot): bool
 * deleteProductMediaFolder(int $productId): bool
 *
 * @package FitPal
 * @version 3.1 — Adds productMediaSlotBasePath(). The page uses it
 *                to build preview URLs so a seed product's image
 *                renders in the editor instead of pointing at an
 *                empty writer folder and 404ing.
 *
 *                (3.0: two-source slot list. 2.2: candidate
 *                folders. 2.1: legacy fallback. 2.0: slot layout.
 *                1.0: MM_DD_YYYY_<n> naming.)
 */

declare(strict_types=1);

if (!defined('FITPAL_PRODUCT_MEDIA_MAX_SLOTS')) {
    define('FITPAL_PRODUCT_MEDIA_MAX_SLOTS', 5);
}

if (!defined('FITPAL_PRODUCT_MEDIA_MAX_BYTES')) {
    define('FITPAL_PRODUCT_MEDIA_MAX_BYTES', 5 * 1024 * 1024);
}

if (!defined('FITPAL_PRODUCT_MEDIA_ALLOWED_MIME')) {
    define('FITPAL_PRODUCT_MEDIA_ALLOWED_MIME', [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ]);
}

require_once __DIR__ . '/../../../customer/backend/database/product-queries.php';

/* =============================================================
 * PATH HELPERS
 * ============================================================= */

if (!function_exists('productMediaAbsoluteFolder')) {
    function productMediaAbsoluteFolder(int $productId): string
    {
        $projectRoot = dirname(__DIR__, 3);
        return $projectRoot . '/shared/uploads/restaurant/products/' . $productId;
    }
}

if (!function_exists('productMediaRelativeFolder')) {
    function productMediaRelativeFolder(int $productId): string
    {
        return 'shared/uploads/restaurant/products/' . $productId . '/';
    }
}

if (!function_exists('productMediaCandidateFolders')) {
    /**
     * @return array<int, string>
     */
    function productMediaCandidateFolders(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $projectRoot = dirname(__DIR__, 3);
        $uploadsRoot = $projectRoot . '/shared/uploads';

        return [
            $uploadsRoot . '/restaurant/products/' . $productId,
            $uploadsRoot . '/restaurant/' . $productId,
            $uploadsRoot . '/products/' . $productId,
        ];
    }
}

if (!function_exists('productMediaResolveFolder')) {
    function productMediaResolveFolder(int $productId): string
    {
        foreach (productMediaCandidateFolders($productId) as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('productMediaListImageFiles')) {
    /**
     * @return array<int, string>
     */
    function productMediaListImageFiles(string $folder): array
    {
        if ($folder === '' || !is_dir($folder)) {
            return [];
        }

        $entries = @scandir($folder);
        if ($entries === false) {
            return [];
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $images = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $folder . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $images[] = $entry;
        }

        sort($images, SORT_STRING);
        return $images;
    }
}

/* =============================================================
 * COLUMN VALUE
 * ============================================================= */

if (!function_exists('productMediaColumnValue')) {
    function productMediaColumnValue(int $productId): string
    {
        static $cache = [];

        if ($productId <= 0) {
            return '';
        }

        if (array_key_exists($productId, $cache)) {
            return $cache[$productId];
        }

        $value = '';

        if (
            isset($GLOBALS['database_connection']) &&
            $GLOBALS['database_connection'] instanceof PDO
        ) {
            try {
                $stmt = $GLOBALS['database_connection']->prepare(
                    "SELECT COALESCE(di.images, '') AS images
                       FROM product p
                       LEFT JOIN dietary_information di
                              ON p.dietary_information_id = di.dietary_information_id
                      WHERE p.product_id = :product_id
                      LIMIT 1"
                );
                $stmt->execute([':product_id' => $productId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $value = (string)($row['images'] ?? '');
                }
            } catch (PDOException $e) {
                error_log('productMediaColumnValue failed: ' . $e->getMessage());
                $value = '';
            }
        }

        $cache[$productId] = $value;
        return $value;
    }
}

/* =============================================================
 * SLOT LIST — TWO-SOURCE RESOLVER
 * ============================================================= */

if (!function_exists('productMediaSlotListFromFolder')) {
    /**
     * @return array<int, string>
     */
    function productMediaSlotListFromFolder(string $folder): array
    {
        if ($folder === '' || !is_dir($folder)) {
            return [];
        }

        $all = productMediaListImageFiles($folder);
        if (empty($all)) {
            return [];
        }

        $bySlot = [];

        // Rule 1 — image-<n>.<ext>
        foreach ($all as $file) {
            if (!preg_match('/^image-(\d+)\.[A-Za-z0-9]+$/', $file, $m)) {
                continue;
            }
            $slot = (int)$m[1];
            if ($slot < 1 || $slot > FITPAL_PRODUCT_MEDIA_MAX_SLOTS) {
                continue;
            }
            if (isset($bySlot[$slot])) {
                continue;
            }
            $bySlot[$slot] = $file;
        }

        // Rule 2 — MM_DD_YYYY_<n>.<ext>
        $legacy = [];
        foreach ($all as $file) {
            if (!preg_match('/^\d{2}_\d{2}_\d{4}_(\d+)\.[A-Za-z0-9]+$/', $file, $m)) {
                continue;
            }
            $legacy[(int)$m[1]] = $file;
        }
        if (!empty($legacy)) {
            ksort($legacy, SORT_NUMERIC);
            $legacyOrdered = array_values($legacy);

            $cursor = 0;
            for ($slot = 1; $slot <= FITPAL_PRODUCT_MEDIA_MAX_SLOTS; $slot++) {
                if (isset($bySlot[$slot])) {
                    continue;
                }
                if (!isset($legacyOrdered[$cursor])) {
                    break;
                }
                $bySlot[$slot] = $legacyOrdered[$cursor];
                $cursor++;
            }
        }

        // Rule 3 — any other image, lexicographic order.
        $used = array_flip(array_values($bySlot));
        $others = [];
        foreach ($all as $file) {
            if (isset($used[$file])) {
                continue;
            }
            $others[] = $file;
        }

        $cursor = 0;
        for ($slot = 1; $slot <= FITPAL_PRODUCT_MEDIA_MAX_SLOTS; $slot++) {
            if (isset($bySlot[$slot])) {
                continue;
            }
            if (!isset($others[$cursor])) {
                break;
            }
            $bySlot[$slot] = $others[$cursor];
            $cursor++;
        }

        ksort($bySlot, SORT_NUMERIC);

        return array_values($bySlot);
    }
}

if (!function_exists('productMediaResolveSourceFolder')) {
    /**
     * Return the absolute folder the slot list will read from, or ''
     * when neither source has images. Used by productMediaSlotList()
     * and by productMediaSlotBasePath() so they always agree on
     * which folder won.
     */
    function productMediaResolveSourceFolder(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        // Source 1 — canonical writer folder.
        $writerFolder = productMediaAbsoluteFolder($productId);
        if (
            is_dir($writerFolder) &&
            !empty(productMediaListImageFiles($writerFolder))
        ) {
            return $writerFolder;
        }

        // Source 1b — defensive writer folders.
        foreach (productMediaCandidateFolders($productId) as $candidate) {
            if ($candidate === $writerFolder) {
                continue;
            }
            if (
                is_dir($candidate) &&
                !empty(productMediaListImageFiles($candidate))
            ) {
                return $candidate;
            }
        }

        // Source 2 — the column value resolved the same way the
        // storefront resolves it.
        $columnValue = productMediaColumnValue($productId);
        if ($columnValue !== '') {
            $resolved = resolveProductImageDir($columnValue);
            if (
                $resolved !== '' &&
                !empty(productMediaListImageFiles($resolved))
            ) {
                return $resolved;
            }
        }

        return '';
    }
}

if (!function_exists('productMediaSlotList')) {
    /**
     * @return array<int, string>
     */
    function productMediaSlotList(int $productId): array
    {
        $source = productMediaResolveSourceFolder($productId);
        if ($source === '') {
            return [];
        }
        return productMediaSlotListFromFolder($source);
    }
}

if (!function_exists('productMediaSlotBasePath')) {
    /**
     * Return the project-root-relative folder that the slot list
     * came from, ending with a slash, or '' when there is no
     * source.
     *
     * A caller building a browser URL concatenates:
     *
     *     $projectRootUrl . productMediaSlotBasePath($productId) . $filename
     *
     * The returned path always begins with "shared/" and always ends
     * with "/". It is either the writer's folder path or the
     * resolved column folder path, whichever the reader used.
     *
     * This is the helper the editor page uses to build preview URLs
     * so a seed product's image renders from the manifest folder
     * and an upload renders from the writer folder.
     */
    function productMediaSlotBasePath(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        // Preferred source — writer's folder, if it has files.
        $writerFolder = productMediaAbsoluteFolder($productId);
        if (
            is_dir($writerFolder) &&
            !empty(productMediaListImageFiles($writerFolder))
        ) {
            return productMediaRelativeFolder($productId);
        }

        // Defensive writer folders.
        foreach (productMediaCandidateFolders($productId) as $candidate) {
            if ($candidate === $writerFolder) {
                continue;
            }
            if (
                is_dir($candidate) &&
                !empty(productMediaListImageFiles($candidate))
            ) {
                $projectRoot = dirname(__DIR__, 3);
                $normalized  = str_replace('\\', '/', $candidate);
                $root        = str_replace('\\', '/', $projectRoot);
                if (stripos($normalized, $root) === 0) {
                    $normalized = substr($normalized, strlen($root));
                }
                $normalized = ltrim($normalized, '/');
                return $normalized !== '' ? rtrim($normalized, '/') . '/' : '';
            }
        }

        // Column value source.
        $columnValue = productMediaColumnValue($productId);
        if ($columnValue !== '') {
            $base = getProductImageBasePath($columnValue);
            if ($base !== '') {
                return $base;
            }
        }

        return '';
    }
}

if (!function_exists('productMediaSlotFilename')) {
    function productMediaSlotFilename(int $productId, int $slot): string
    {
        if (
            $productId <= 0 ||
            $slot < 1 ||
            $slot > FITPAL_PRODUCT_MEDIA_MAX_SLOTS
        ) {
            return '';
        }

        $list = productMediaSlotList($productId);
        $index = $slot - 1;

        if (!isset($list[$index])) {
            return '';
        }

        return $list[$index];
    }
}

if (!function_exists('productMediaSlotMap')) {
    /**
     * @return array<int, string|null>
     */
    function productMediaSlotMap(int $productId): array
    {
        $out = [];
        for ($slot = 1; $slot <= FITPAL_PRODUCT_MEDIA_MAX_SLOTS; $slot++) {
            $name = productMediaSlotFilename($productId, $slot);
            $out[$slot] = $name !== '' ? $name : null;
        }
        return $out;
    }
}

/* =============================================================
 * DELETE
 * ============================================================= */

if (!function_exists('deleteProductMediaSlot')) {
    function deleteProductMediaSlot(int $productId, int $slot): bool
    {
        if (
            $productId <= 0 ||
            $slot < 1 ||
            $slot > FITPAL_PRODUCT_MEDIA_MAX_SLOTS
        ) {
            return false;
        }

        $filename = productMediaSlotFilename($productId, $slot);
        if ($filename === '') {
            return false;
        }

        $candidates = productMediaCandidateFolders($productId);

        $columnValue = productMediaColumnValue($productId);
        if ($columnValue !== '') {
            $resolved = resolveProductImageDir($columnValue);
            if ($resolved !== '') {
                $candidates[] = $resolved;
            }
        }

        foreach ($candidates as $folder) {
            if (!is_dir($folder)) continue;
            $path = $folder . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                return @unlink($path);
            }
        }

        return false;
    }
}

if (!function_exists('deleteProductMediaFolder')) {
    function deleteProductMediaFolder(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $removed = false;

        foreach (productMediaCandidateFolders($productId) as $folder) {
            if (!is_dir($folder)) {
                continue;
            }

            $entries = @scandir($folder);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = $folder . DIRECTORY_SEPARATOR . $entry;
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }

            if (@rmdir($folder)) {
                $removed = true;
            }
        }

        return $removed;
    }
}

/* =============================================================
 * VALIDATION
 * ============================================================= */

if (!function_exists('validateProductMediaFile')) {
    /**
     * @throws RuntimeException
     */
    function validateProductMediaFile(array $file): string
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid file payload.');
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Empty slot.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'Upload failed (code ' . $file['error'] . ').'
            );
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ($size > FITPAL_PRODUCT_MEDIA_MAX_BYTES) {
            throw new RuntimeException(
                'Each image must be under '
                . round(FITPAL_PRODUCT_MEDIA_MAX_BYTES / 1048576, 1)
                . ' MB.'
            );
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException(
                'Uploaded file is not a valid upload.'
            );
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Could not inspect file.');
        }
        $mime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);

        $allowed = FITPAL_PRODUCT_MEDIA_ALLOWED_MIME;
        if (!isset($allowed[$mime])) {
            throw new RuntimeException(
                'Unsupported image type. Use JPG, PNG, WEBP, or GIF.'
            );
        }

        return $allowed[$mime];
    }
}

/* =============================================================
 * STORE — SLOT-AWARE
 * ============================================================= */

if (!function_exists('storeProductMediaSlots')) {
    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array{
     *     status:    string,
     *     message:   string,
     *     folder:    string,
     *     filenames: array<int, string|null>
     * }
     */
    function storeProductMediaSlots(int $productId, array $slots): array
    {
        $fail = static function (string $message): array {
            return [
                'status'    => 'error',
                'message'   => $message,
                'folder'    => '',
                'filenames' => [],
            ];
        };

        if ($productId <= 0) {
            return $fail('Invalid product id.');
        }

        $normalized = [];

        foreach ($slots as $slotIndex => $op) {
            $slotIndex = (int)$slotIndex;
            if (
                $slotIndex < 1 ||
                $slotIndex > FITPAL_PRODUCT_MEDIA_MAX_SLOTS
            ) {
                continue;
            }
            if (!is_array($op)) {
                continue;
            }

            $mode = (string)($op['mode'] ?? 'keep');
            if (!in_array($mode, ['keep', 'remove', 'replace'], true)) {
                continue;
            }

            if ($mode === 'replace') {
                $file = $op['file'] ?? null;
                if (!is_array($file)) {
                    return $fail("Slot {$slotIndex} is missing its file.");
                }
                try {
                    $ext = validateProductMediaFile($file);
                } catch (RuntimeException $e) {
                    return $fail("Slot {$slotIndex}: " . $e->getMessage());
                }
                $normalized[$slotIndex] = [
                    'mode' => 'replace',
                    'file' => $file,
                    'ext'  => $ext,
                ];
                continue;
            }

            $normalized[$slotIndex] = ['mode' => $mode];
        }

        if (empty($normalized)) {
            return [
                'status'    => 'success',
                'message'   => 'No changes.',
                'folder'    => productMediaRelativeFolder($productId),
                'filenames' => productMediaSlotMap($productId),
            ];
        }

        $absoluteFolder = productMediaAbsoluteFolder($productId);
        $readFolder     = productMediaResolveSourceFolder($productId);
        $beforeSlotMap  = productMediaSlotMap($productId);

        if (!is_dir($absoluteFolder)) {
            if (
                !mkdir($absoluteFolder, 0755, true) &&
                !is_dir($absoluteFolder)
            ) {
                return $fail('Could not create the product image folder.');
            }
        }

        $written = [];
        $removed = [];
        $applied = [];

        try {
            foreach ($normalized as $slotIndex => $op) {

                if ($op['mode'] === 'keep') {
                    continue;
                }

                if ($op['mode'] === 'remove') {
                    $existingFilename = $beforeSlotMap[$slotIndex] ?? null;
                    if ($existingFilename !== null && $readFolder !== '') {
                        $path = $readFolder . DIRECTORY_SEPARATOR . $existingFilename;
                        if (is_file($path)) {
                            $previous = @file_get_contents($path);
                            if ($previous !== false) {
                                $removed[$path] = $previous;
                            }
                            if (!@unlink($path)) {
                                throw new RuntimeException(
                                    "Could not remove slot {$slotIndex}."
                                );
                            }
                        }
                    }

                    $dupes = glob(
                        $absoluteFolder . '/image-' . $slotIndex . '.*'
                    );
                    if (!empty($dupes)) {
                        foreach ($dupes as $path) {
                            if (!is_file($path)) continue;
                            $previous = @file_get_contents($path);
                            if ($previous !== false && !isset($removed[$path])) {
                                $removed[$path] = $previous;
                            }
                            if (!@unlink($path)) {
                                throw new RuntimeException(
                                    "Could not remove slot {$slotIndex}."
                                );
                            }
                        }
                    }

                    $applied[] = "slot {$slotIndex} removed";
                    continue;
                }

                // mode === 'replace'
                $ext       = $op['ext'];
                $targetNew = $absoluteFolder
                           . '/image-' . $slotIndex . '.' . $ext;

                $existingFilename = $beforeSlotMap[$slotIndex] ?? null;
                if ($existingFilename !== null && $readFolder !== '') {
                    $path = $readFolder . DIRECTORY_SEPARATOR . $existingFilename;
                    if ($path !== $targetNew && is_file($path)) {
                        $previous = @file_get_contents($path);
                        if ($previous !== false) {
                            $removed[$path] = $previous;
                        }
                        if (!@unlink($path)) {
                            throw new RuntimeException(
                                "Could not replace slot {$slotIndex}."
                            );
                        }
                    }
                }

                $existingAtSlot = glob(
                    $absoluteFolder . '/image-' . $slotIndex . '.*'
                );
                if (!empty($existingAtSlot)) {
                    foreach ($existingAtSlot as $path) {
                        if ($path === $targetNew) continue;
                        if (!is_file($path)) continue;
                        $previous = @file_get_contents($path);
                        if ($previous !== false && !isset($removed[$path])) {
                            $removed[$path] = $previous;
                        }
                        if (!@unlink($path)) {
                            throw new RuntimeException(
                                "Could not replace slot {$slotIndex}."
                            );
                        }
                    }
                }

                if (!move_uploaded_file($op['file']['tmp_name'], $targetNew)) {
                    throw new RuntimeException(
                        "Could not save slot {$slotIndex}."
                    );
                }

                @chmod($targetNew, 0644);
                $written[$targetNew] = true;
                $applied[] = "slot {$slotIndex} written as "
                           . basename($targetNew);
            }
        } catch (Throwable $e) {
            foreach (array_keys($written) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            foreach ($removed as $path => $contents) {
                @file_put_contents($path, $contents);
            }
            return $fail($e->getMessage());
        }

        return [
            'status'    => 'success',
            'message'   => implode('; ', $applied),
            'folder'    => productMediaRelativeFolder($productId),
            'filenames' => productMediaSlotMap($productId),
        ];
    }
}