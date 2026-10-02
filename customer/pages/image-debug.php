<?php
/**
 * FitPal Customer Image Debug Page
 *
 * Diagnostic-only. Not part of the customer ordering flow.
 *
 * This revision stops hard-coding any folder path. It walks the
 * manifest products directory on disk, prints every folder it finds
 * up to four levels deep, prints every image-*.* file inside each
 * folder, and then runs the reader's own helpers against every
 * candidate stored value derived from those folders.
 *
 * The output tells you three things:
 *
 *   1. What folders actually exist under the manifest products
 *      directory, at every depth.
 *   2. Which folders contain image-*.* files, and how many.
 *   3. Which stored value in dietary_information.images would make
 *      the reader return a non-empty filename list — i.e. which
 *      prefix needs to precede the folder name the seed data uses.
 *
 * Sections:
 *
 *   1. Environment
 *   2. The manifest products directory — every folder, every file
 *   3. Reader probe — every candidate stored value, reader output
 *   4. Every product — stored value, reader output, closest on-disk
 *      match
 *   5. Every image file — resolved as a browser URL and rendered
 *
 * ---------------------------------------------------------------------
 * SECURITY
 * ---------------------------------------------------------------------
 * This page prints filesystem paths. Delete it before deploying.
 *
 * @package FitPal
 * @version 3.0 — Walks the disk instead of hard-coding a folder.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/product-queries.php';

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------
$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$dirPath    = dirname($scriptPath);
$segments   = array_filter(explode('/', $dirPath));
$depth      = count($segments);
$assetBase  = $depth <= 0 ? './shared/' : str_repeat('../', $depth) . 'shared/';

$projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
if (!is_string($projectRootUrl)) {
    $projectRootUrl = '';
}

$pageDir           = __DIR__;
$productQueriesDir = realpath(__DIR__ . '/../backend/database') ?: (__DIR__ . '/../backend/database');
$projectDir        = dirname($productQueriesDir, 3);

// ---------------------------------------------------------------------
// The manifest products root on disk
// ---------------------------------------------------------------------
$manifestProductsDir = $projectDir . '/shared/assets/images/manifest/products';

/**
 * Walk a directory recursively and return every directory under it,
 * as an array of absolute paths. Depth-limited so a deep tree does
 * not blow up the page.
 *
 * @return array<int, string>
 */
function debugWalkDirs(string $root, int $maxDepth = 4): array
{
    $out = [];

    if (!is_dir($root)) {
        return $out;
    }

    $queue = [['path' => $root, 'depth' => 0]];

    while (!empty($queue)) {
        $item  = array_shift($queue);
        $path  = $item['path'];
        $level = $item['depth'];

        $out[] = $path;

        if ($level >= $maxDepth) {
            continue;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $queue[] = ['path' => $child, 'depth' => $level + 1];
            }
        }
    }

    return $out;
}

/**
 * Return the image-*.* files inside a directory, in the order the
 * reader's glob would find them.
 *
 * @return array<int, string>  Bare filenames.
 */
function debugImageFiles(string $dir): array
{
    $out = [];

    for ($i = 1; $i <= 5; $i++) {
        $hits = glob($dir . DIRECTORY_SEPARATOR . 'image-' . $i . '.*');
        if (empty($hits)) {
            continue;
        }
        foreach ($hits as $hit) {
            $out[] = basename($hit);
        }
    }

    return $out;
}

// Walk the manifest products dir once.
$allDirs = debugWalkDirs($manifestProductsDir, 4);

// Filter to only dirs that contain at least one image-*.* file.
$dirsWithImages = [];
foreach ($allDirs as $dir) {
    $imgs = debugImageFiles($dir);
    if (!empty($imgs)) {
        $dirsWithImages[$dir] = $imgs;
    }
}

// ---------------------------------------------------------------------
// Build a list of candidate stored values from the on-disk folders.
//
// For every folder that contains images, compute the project-root-
// relative path (strip the leading $projectDir . '/'). That is
// exactly the shape dietary_information.images is supposed to hold.
// Run the reader's own helpers against each one.
// ---------------------------------------------------------------------
$readerProbes = [];
foreach ($dirsWithImages as $dir => $imgs) {
    // Strip the leading project dir + separator.
    $prefix = rtrim($projectDir, '/\\') . DIRECTORY_SEPARATOR;
    $rel = $dir;
    if (stripos($rel, $prefix) === 0) {
        $rel = substr($rel, strlen($prefix));
    }
    $rel = str_replace('\\', '/', $rel);
    if (substr($rel, -1) !== '/') {
        $rel .= '/';
    }

    $readerProbes[$rel] = [
        'relative'  => $rel,
        'absolute'  => $dir,
        'files'     => $imgs,
        'readerFns' => getProductImageFilenames($rel),
        'readerPri' => getProductPrimaryFilename($rel),
    ];
}

// ---------------------------------------------------------------------
// Load every active product.
// ---------------------------------------------------------------------
$products  = [];
$loadError = null;

try {
    $stmt = $database_connection->prepare(
        "SELECT
            p.product_id,
            p.name AS product_name,
            r.business_name AS restaurant_name,
            COALESCE(di.images, '') AS images_folder
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di
                ON p.dietary_information_id = di.dietary_information_id
         WHERE p.is_active = 1
         ORDER BY r.business_name, p.name"
    );
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $loadError = $e->getMessage();
}

/**
 * Return the last non-empty path segment of a path, normalized to
 * forward slashes.
 */
function debugLastSegment(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    return (string)end($parts);
}

/**
 * Render one boolean as a coloured badge.
 */
function debugBadge(bool $ok, string $okText = 'FOUND', string $failText = 'NOT FOUND'): string
{
    $class = $ok ? 'ok' : 'fail';
    $text  = $ok ? $okText : $failText;
    return '<span class="badge ' . $class . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FitPal — Product Image Debug v3</title>
    <style>
    :root {
        --ok-bg: #d1fae5;
        --ok-fg: #065f46;
        --fail-bg: #fee2e2;
        --fail-fg: #991b1b;
        --warn-bg: #fef3c7;
        --warn-fg: #92400e;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-500: #6b7280;
        --gray-700: #374151;
        --gray-900: #111827;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 24px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 13px;
        line-height: 1.5;
        color: var(--gray-900);
        background: var(--gray-50);
    }

    h1 {
        margin: 0 0 4px 0;
        font-size: 18px;
    }

    h2 {
        margin: 32px 0 8px 0;
        font-size: 14px;
        color: var(--gray-700);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .lede {
        color: var(--gray-500);
        margin: 0 0 24px 0;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 13px;
    }

    .hint {
        background: var(--warn-bg);
        color: var(--warn-fg);
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 12px;
    }

    .hint strong {
        color: var(--warn-fg);
    }

    .global-info,
    .card {
        background: #fff;
        border: 1px solid var(--gray-200);
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 16px;
    }

    .kv {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 4px 16px;
        margin-bottom: 0;
    }

    .kv dt {
        color: var(--gray-500);
        font-size: 12px;
    }

    .kv dd {
        margin: 0;
        word-break: break-all;
    }

    .kv dd.empty {
        color: var(--gray-300);
        font-style: italic;
    }

    .badge {
        display: inline-block;
        padding: 1px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
    }

    .badge.ok {
        background: var(--ok-bg);
        color: var(--ok-fg);
    }

    .badge.fail {
        background: var(--fail-bg);
        color: var(--fail-fg);
    }

    .badge.warn {
        background: var(--warn-bg);
        color: var(--warn-fg);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
        font-size: 12px;
    }

    table th {
        text-align: left;
        padding: 6px 8px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-500);
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        white-space: nowrap;
    }

    table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--gray-100);
        word-break: break-all;
        vertical-align: top;
    }

    table tr:last-child td {
        border-bottom: none;
    }

    tr.is-winner {
        background: #ecfdf5;
    }

    tr.is-loser {
        opacity: 0.7;
    }

    .card-header {
        display: flex;
        align-items: baseline;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--gray-100);
    }

    .card-header .product-id {
        font-size: 11px;
        color: var(--gray-500);
        background: var(--gray-100);
        padding: 2px 8px;
        border-radius: 10px;
    }

    .card-header .product-name {
        font-size: 14px;
        font-weight: 600;
    }

    .card-header .restaurant-name {
        color: var(--gray-500);
        font-size: 12px;
    }

    .preview {
        width: 80px;
        height: 80px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--gray-200);
        background: var(--gray-100);
    }

    .preview-cell {
        width: 96px;
    }

    code {
        background: var(--gray-100);
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 12px;
    }

    .depth-0 {
        padding-left: 0;
    }

    .depth-1 {
        padding-left: 16px;
    }

    .depth-2 {
        padding-left: 32px;
    }

    .depth-3 {
        padding-left: 48px;
    }

    .depth-4 {
        padding-left: 64px;
    }
    </style>
</head>

<body>

    <h1>Product Image Debug — v3</h1>
    <p class="lede">
        Walks the manifest products directory on disk, prints every folder it finds, prints every image-*.* file inside
        each folder, and runs the reader's own helpers against every project-root-relative path derived from those
        folders. No folder is hard-coded.
    </p>

    <div class="hint">
        <strong>Delete this file before deploying.</strong> It prints filesystem paths.
    </div>

    <!-- ============================================================ -->
    <!-- 1. ENVIRONMENT -->
    <!-- ============================================================ -->
    <section class="global-info">
        <h2 style="margin-top:0;">1. Environment</h2>
        <dl class="kv">
            <dt>PHP version</dt>
            <dd><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></dd>

            <dt>SCRIPT_NAME</dt>
            <dd><?php echo htmlspecialchars($scriptPath, ENT_QUOTES, 'UTF-8'); ?></dd>

            <dt>This file's __DIR__</dt>
            <dd><code><?php echo htmlspecialchars($pageDir, ENT_QUOTES, 'UTF-8'); ?></code></dd>

            <dt>product-queries.php's directory</dt>
            <dd><code><?php echo htmlspecialchars((string)$productQueriesDir, ENT_QUOTES, 'UTF-8'); ?></code></dd>

            <dt>Project dir (dirname(__DIR__, 3) from product-queries.php)</dt>
            <dd><code><?php echo htmlspecialchars($projectDir, ENT_QUOTES, 'UTF-8'); ?></code></dd>

            <dt>manifest/products directory on disk</dt>
            <dd><code><?php echo htmlspecialchars($manifestProductsDir, ENT_QUOTES, 'UTF-8'); ?></code></dd>

            <dt>Does manifest/products exist?</dt>
            <dd><?php echo debugBadge(is_dir($manifestProductsDir)); ?></dd>

            <dt>$assetBase (from SCRIPT_NAME)</dt>
            <dd><code><?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?></code></dd>

            <dt>$projectRootUrl (for browser)</dt>
            <dd><code><?php echo htmlspecialchars($projectRootUrl, ENT_QUOTES, 'UTF-8'); ?></code></dd>
        </dl>
    </section>

    <!-- ============================================================ -->
    <!-- 2. DIRECTORY WALK -->
    <!-- ============================================================ -->
    <section class="global-info">
        <h2 style="margin-top:0;">2. Every folder under manifest/products (depth ≤ 4)</h2>
        <p class="lede" style="margin:0 0 12px 0;">
            <strong><?php echo count($allDirs); ?></strong> folders found.
            <strong><?php echo count($dirsWithImages); ?></strong> of them contain at least one image-*.* file.
        </p>

        <?php if (empty($allDirs)): ?>
        <div class="hint">
            <strong>The walk returned nothing.</strong> The directory
            <code><?php echo htmlspecialchars($manifestProductsDir, ENT_QUOTES, 'UTF-8'); ?></code>
            does not exist or is not readable. Check the path.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Folder (indent = depth)</th>
                    <th>image-*.* files inside</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allDirs as $dir):
                    $rel = $dir;
                    $prefix = rtrim($projectDir, '/\\') . DIRECTORY_SEPARATOR;
                    if (stripos($rel, $prefix) === 0) {
                        $rel = substr($rel, strlen($prefix));
                    }
                    $rel = str_replace('\\', '/', $rel);
                    $depthHere = substr_count(rtrim($rel, '/'), '/');
                    $imgsHere = $dirsWithImages[$dir] ?? [];
                ?>
                <tr class="<?php echo !empty($imgsHere) ? 'is-winner' : 'is-loser'; ?>">
                    <td class="depth-<?php echo min(4, $depthHere); ?>">
                        <code><?php echo htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'); ?></code>
                    </td>
                    <td>
                        <?php if (empty($imgsHere)): ?>
                        <span style="color: var(--gray-300); font-style: italic;">—</span>
                        <?php else: ?>
                        <strong><?php echo count($imgsHere); ?></strong>:
                        <?php echo htmlspecialchars(implode(', ', $imgsHere), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- ============================================================ -->
    <!-- 3. READER PROBES -->
    <!-- ============================================================ -->
    <section class="global-info">
        <h2 style="margin-top:0;">3. Reader probe against every folder that has images</h2>
        <p class="lede" style="margin:0 0 12px 0;">
            For each folder that contains image-*.* files, this table shows the project-root-relative path the
            folder would need to have in <code>dietary_information.images</code> for the reader to find it, and what
            the reader's own two helpers return when given that value. If <code>getProductImageFilenames()</code>
            returns a list here, this is the exact value the column needs to hold.
        </p>

        <?php if (empty($readerProbes)): ?>
        <div class="hint">
            <strong>No folders on disk contain image-*.* files.</strong>
            The walk found <?php echo count($allDirs); ?> folders, and none of them
            contained a file named image-1.*, image-2.*, image-3.*, image-4.*, or image-5.*.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Value the column would need to hold</th>
                    <th>Files on disk</th>
                    <th>getProductImageFilenames() returns</th>
                    <th>getProductPrimaryFilename() returns</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($readerProbes as $rel => $probe):
                    $readerCount = count($probe['readerFns']);
                    $diskCount   = count($probe['files']);
                    $allMatch    = ($readerCount === $diskCount);
                ?>
                <tr class="<?php echo $allMatch ? 'is-winner' : 'is-loser'; ?>">
                    <td><code><?php echo htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo $diskCount; ?></td>
                    <td>
                        <?php if (empty($probe['readerFns'])): ?>
                        <span style="color: var(--gray-300); font-style: italic;">(empty)</span>
                        <?php else: ?>
                        <?php echo debugBadge(true, count($probe['readerFns']) . ' found'); ?>
                        <?php echo htmlspecialchars(implode(', ', $probe['readerFns']), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $probe['readerPri'] === ''
                            ? '<span style="color: var(--gray-300); font-style: italic;">(empty)</span>'
                            : '<code>' . htmlspecialchars($probe['readerPri'], ENT_QUOTES, 'UTF-8') . '</code>'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- ============================================================ -->
    <!-- 4. PRODUCTS -->
    <!-- ============================================================ -->
    <?php if ($loadError !== null): ?>
    <div class="hint">
        <strong>Query failed:</strong> <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
    <h2>4. Products (<?php echo count($products); ?>)</h2>
    <p class="lede">
        For each product, the stored value in <code>dietary_information.images</code>, the reader's output for that
        value, and — if the product's slug appears in any folder name on disk — the folder that actually exists.
    </p>

    <?php foreach ($products as $p):
        $pid          = (int)$p['product_id'];
        $productName  = (string)$p['product_name'];
        $restoName    = (string)$p['restaurant_name'];
        $folder       = (string)$p['images_folder'];

        $readerFns = getProductImageFilenames($folder);
        $readerPri = getProductPrimaryFilename($folder);

        // Best-effort match: find any folder on disk whose basename
        // matches the last segment of the stored value.
        $storedLast = debugLastSegment($folder);
        $diskMatches = [];
        if ($storedLast !== '') {
            foreach ($dirsWithImages as $dir => $imgs) {
                if (debugLastSegment($dir) === $storedLast) {
                    $rel = $dir;
                    $prefix = rtrim($projectDir, '/\\') . DIRECTORY_SEPARATOR;
                    if (stripos($rel, $prefix) === 0) {
                        $rel = substr($rel, strlen($prefix));
                    }
                    $diskMatches[] = [
                        'relative' => str_replace('\\', '/', $rel) . '/',
                        'absolute' => $dir,
                        'files'    => $imgs,
                    ];
                }
            }
        }

        $browserUrl = ($readerPri !== '' && $folder !== '' && $projectRootUrl !== '')
            ? $projectRootUrl . $folder . $readerPri
            : '';
    ?>

    <div class="card">
        <div class="card-header">
            <span class="product-id">#<?php echo $pid; ?></span>
            <span class="product-name"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="restaurant-name"><?php echo htmlspecialchars($restoName, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <dl class="kv">
            <dt>dietary_information.images holds</dt>
            <dd class="<?php echo $folder === '' ? 'empty' : ''; ?>">
                <?php echo $folder === ''
                    ? '(empty or NULL)'
                    : '<code>' . htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') . '</code>'; ?>
            </dd>

            <dt>getProductImageFilenames() returned</dt>
            <dd class="<?php echo empty($readerFns) ? 'empty' : ''; ?>">
                <?php echo empty($readerFns)
                    ? '(none)'
                    : htmlspecialchars(implode(', ', $readerFns), ENT_QUOTES, 'UTF-8'); ?>
            </dd>

            <dt>getProductPrimaryFilename() returned</dt>
            <dd class="<?php echo $readerPri === '' ? 'empty' : ''; ?>">
                <?php echo $readerPri === ''
                    ? '(empty)'
                    : htmlspecialchars($readerPri, ENT_QUOTES, 'UTF-8'); ?>
            </dd>

            <dt>Browser URL the render pages would emit</dt>
            <dd class="<?php echo $browserUrl === '' ? 'empty' : ''; ?>">
                <?php echo $browserUrl === ''
                    ? '(empty — falls back to restaurant icon)'
                    : '<code>' . htmlspecialchars($browserUrl, ENT_QUOTES, 'UTF-8') . '</code>'; ?>
            </dd>

            <dt>Folders on disk whose basename matches the stored slug</dt>
            <dd>
                <?php if (empty($diskMatches)): ?>
                <span style="color: var(--gray-300); font-style: italic;">(none)</span>
                <?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($diskMatches as $m): ?>
                    <li>
                        <code><?php echo htmlspecialchars($m['relative'], ENT_QUOTES, 'UTF-8'); ?></code>
                        — <?php echo count($m['files']); ?> image file(s):
                        <?php echo htmlspecialchars(implode(', ', $m['files']), ENT_QUOTES, 'UTF-8'); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- 5. EVERY IMAGE, RENDERED -->
    <!-- ============================================================ -->
    <?php if (!empty($readerProbes)): ?>
    <h2>5. Every image file, rendered</h2>
    <p class="lede">
        One row per folder that contains images. Every image-*.* file in the folder is rendered from the browser URL
        that the folder's relative path would produce. If the browser URL 404s, the preview will show a red background.
    </p>

    <?php foreach ($readerProbes as $rel => $probe): ?>
    <div class="card">
        <div class="card-header">
            <span class="product-name"><?php echo htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="restaurant-name"><?php echo count($probe['files']); ?> file(s)</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Preview</th>
                    <th>Filename</th>
                    <th>Browser URL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($probe['files'] as $filename):
                    $browser = $projectRootUrl . $rel . $filename;
                ?>
                <tr>
                    <td class="preview-cell">
                        <img class="preview" src="<?php echo htmlspecialchars($browser, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                            onerror="this.onerror=null; this.style.background='#fee2e2'; this.alt='broken';">
                    </td>
                    <td><?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><code><?php echo htmlspecialchars($browser, ENT_QUOTES, 'UTF-8'); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

</body>

</html>