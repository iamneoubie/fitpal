<?php
/**
 * FitPal Rider Product Queries
 *
 * Data-access and presentation helpers for the rider role. This file
 * provides:
 *
 *   - The image-folder resolution helpers that turn a raw
 *     dietary_information.images value into a browser-loadable URL,
 *     using the same three-candidate pattern the customer and
 *     restaurant roles use.
 *
 *   - getRiderOrderItemsWithDetails(), the query that returns the
 *     item list for one order with each item's product image URL,
 *     its customization rows, and the order's special instructions.
 *     The assignment panel reads this to render its per-order card.
 *
 * ---------------------------------------------------------------------
 * MULTI-IMAGE MODEL
 * ---------------------------------------------------------------------
 * dietary_information.images stores a project-root-relative FOLDER
 * path. On this deployment the column value omits the literal
 * `restaurant/` segment that the manifest folder actually carries:
 *
 *     column value:  shared/assets/images/manifest/products/<resto>/<slug>/
 *     disk folder:   shared/assets/images/manifest/products/restaurant/<resto>/<slug>/
 *
 * resolveProductImageDir() tries a list of candidate roots and
 * returns the first one that exists on disk. getProductImageBasePath()
 * returns that directory as a project-root-relative path; the caller
 * prepends its own project-root URL and appends a bare filename to
 * build the browser URL.
 *
 * This file mirrors the resolution logic the customer and restaurant
 * roles carry in their own product-queries.php. The three files are
 * deliberately duplicated rather than shared, because sharing them
 * would couple the three roles' include paths and make a role-local
 * change impossible.
 *
 * ---------------------------------------------------------------------
 * PROJECT-ROOT URL
 * ---------------------------------------------------------------------
 * getRiderProjectRootUrl() derives the project-root URL prefix from
 * $_SERVER['SCRIPT_NAME']. For a page at /rider/pages/ it returns
 * '../../'. For a handler at /rider/backend/handlers/ it returns
 * '../../../'. Both are correct relative to their own requests, and
 * both produce a URL the browser can resolve.
 *
 * The helper is exported so the handler and the page can use the
 * same derivation instead of each computing its own.
 *
 * ---------------------------------------------------------------------
 * SPECIAL INSTRUCTIONS
 * ---------------------------------------------------------------------
 * queue_item.custom_instructions carries the customer's free-text
 * notes for one order item. When the order has any notes at all,
 * getRiderOrderItemsWithDetails() returns them on each affected item
 * as `custom_instructions`. The assignment panel collects every
 * non-empty value across the order's items and renders them once,
 * rather than repeating the same note under every item row.
 *
 * ---------------------------------------------------------------------
 * WHERE THIS FILE IS LOADED FROM
 * ---------------------------------------------------------------------
 * Both the rider assignment handler and any rider page that renders
 * an order card can require this file. It declares functions only;
 * it does not run a request dispatch, so it is safe to include from
 * any context.
 *
 * @package FitPal
 * @version 2.0 — Adds getRiderProjectRootUrl() and rebuilds
 *                getRiderOrderItemsWithDetails() around the
 *                three-candidate resolution so every rider-facing
 *                order card can render a product thumbnail through
 *                the same helper the customer and restaurant roles
 *                use.
 *
 *                (1.0: initial file with the resolution helpers and
 *                the item query.)
 */

declare(strict_types=1);

/* =============================================================
 * IMAGE FOLDER RESOLUTION
 * ============================================================= */

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

        // rider/backend/database → three levels up reaches
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

/* =============================================================
 * PROJECT-ROOT URL
 * ============================================================= */

if (!function_exists('getRiderProjectRootUrl')) {
    /**
     * Derive the project-root URL prefix for the current request.
     *
     * For a page at /rider/pages/dashboard.php this returns '../../'.
     * For a handler at /rider/backend/handlers/assignment-handler.php
     * this returns '../../../'. Both are correct relative to their
     * own requests.
     *
     * @return string
     */
    function getRiderProjectRootUrl(): string
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';

        if ($scriptPath === '') {
            return '';
        }

        $dirPath  = dirname($scriptPath);
        $segments = array_filter(explode('/', $dirPath));
        $depth    = count($segments);

        if ($depth <= 0) {
            return './';
        }

        return str_repeat('../', $depth);
    }
}

if (!function_exists('resolveRiderProductImageUrl')) {
    /**
     * Build a browser-loadable image URL for one product from its
     * raw dietary_information.images value.
     *
     * Returns '' when the folder cannot be resolved or contains no
     * image file. Callers fall back to the shared restaurant icon
     * in that case.
     *
     * @param string $imageFolder  Raw dietary_information.images value.
     * @param string $projectRootUrl  Project-root URL prefix, from
     *                                getRiderProjectRootUrl().
     * @return string
     */
    function resolveRiderProductImageUrl(
        string $imageFolder,
        string $projectRootUrl
    ): string {
        if ($imageFolder === '' || $projectRootUrl === '') {
            return '';
        }

        $imageBase    = getProductImageBasePath($imageFolder);
        $primaryImage = getProductPrimaryFilename($imageFolder);

        if ($imageBase === '' || $primaryImage === '') {
            return '';
        }

        return $projectRootUrl . $imageBase . $primaryImage;
    }
}

/* =============================================================
 * ORDER ITEMS
 * ============================================================= */

if (!function_exists('getRiderOrderItemsWithDetails')) {
    /**
     * Fetch every item on an order with its product image URL, its
     * customization rows, and its special instructions.
     *
     * Each returned item carries:
     *
     *   queue_item_id        int
     *   product_id           int
     *   quantity             int
     *   unit_price           float
     *   final_price          float|null
     *   is_customized        bool
     *   custom_instructions  string|null   the customer's notes
     *   product_name         string
     *   product_image        string        raw column value
     *   product_image_url    string        browser-loadable, or ''
     *   customizations       array         display rows, one per
     *                                      ingredient modification
     *
     * The `customizations` array entries each carry:
     *
     *   ingredient_id    int
     *   ingredient_name  string
     *   quantity         int
     *   price_at_time    float
     *   calories_at_time int
     *   is_removed       bool
     *   custom_text      string|null
     *
     * The caller is free to render or ignore any of these fields.
     *
     * @param PDO $db
     * @param int $orderId
     * @return array<int, array<string, mixed>>
     */
    function getRiderOrderItemsWithDetails(PDO $db, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            "SELECT
                qi.queue_item_id,
                qi.product_id,
                qi.queue_quantity AS quantity,
                qi.unit_price,
                qi.final_price,
                qi.is_customized,
                qi.custom_instructions,
                p.name AS product_name,
                COALESCE(di.images, '') AS product_image
             FROM queue_item qi
             JOIN product p ON qi.product_id = p.product_id
             LEFT JOIN dietary_information di
                    ON p.dietary_information_id = di.dietary_information_id
             WHERE qi.order_id = :order_id
             ORDER BY qi.queue_item_id ASC"
        );
        $stmt->execute([':order_id' => $orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return [];
        }

        $queueItemIds = array_column($items, 'queue_item_id');
        $placeholders = implode(
            ',',
            array_fill(0, count($queueItemIds), '?')
        );

        $custStmt = $db->prepare(
            "SELECT
                ci.queue_item_id,
                ci.ingredient_id,
                ci.quantity,
                ci.price_at_time,
                ci.calories_at_time,
                ci.is_removed,
                ci.custom_text,
                i.name AS ingredient_name
             FROM customization_instance ci
             LEFT JOIN ingredient i
                    ON ci.ingredient_id = i.ingredient_id
             WHERE ci.queue_item_id IN ($placeholders)
             ORDER BY ci.queue_item_id ASC, ci.instance_id ASC"
        );
        $custStmt->execute($queueItemIds);

        $custByItem = [];
        while ($row = $custStmt->fetch(PDO::FETCH_ASSOC)) {
            $custByItem[(int)$row['queue_item_id']][] = [
                'ingredient_id'    => (int)$row['ingredient_id'],
                'ingredient_name'  => (string)($row['ingredient_name'] ?? ''),
                'quantity'         => (int)$row['quantity'],
                'price_at_time'    => (float)$row['price_at_time'],
                'calories_at_time' => (int)($row['calories_at_time'] ?? 0),
                'is_removed'       => (int)($row['is_removed'] ?? 0) === 1,
                'custom_text'      => $row['custom_text'] !== null
                    ? (string)$row['custom_text']
                    : null,
            ];
        }

        $projectRootUrl = getRiderProjectRootUrl();

        foreach ($items as &$item) {
            $qiId = (int)$item['queue_item_id'];

            $item['quantity']          = (int)$item['quantity'];
            $item['unit_price']        = (float)$item['unit_price'];
            $item['final_price']       = $item['final_price'] !== null
                ? (float)$item['final_price']
                : null;
            $item['is_customized']     = (int)($item['is_customized'] ?? 0) === 1;
            $item['product_name']      = (string)($item['product_name'] ?? '');
            $item['product_image']     = (string)($item['product_image'] ?? '');
            $item['custom_instructions'] = $item['custom_instructions'] !== null
                ? (string)$item['custom_instructions']
                : null;

            $item['product_image_url'] = resolveRiderProductImageUrl(
                $item['product_image'],
                $projectRootUrl
            );

            $item['customizations'] = $custByItem[$qiId] ?? [];
        }
        unset($item);

        return $items;
    }
}