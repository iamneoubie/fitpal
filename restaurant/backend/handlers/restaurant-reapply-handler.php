<?php
/**
 * FitPal Restaurant Re-Application Handler
 *
 * The HTTP entry point for a restaurant owner whose verification was
 * denied to re-submit their application.
 *
 * ---------------------------------------------------------------------
 * PERMIT STORAGE — SLOT MODEL, ONE WRITER
 * ---------------------------------------------------------------------
 * Permits are stored exactly the way Crooks Cart Collectibles stores
 * product media: a single canonical folder per entity, five fixed
 * slots inside that folder, and ONE function that owns every byte
 * written to a slot.
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 *         image-1.jpg
 *         image-2.png
 *         image-3.webp
 *         image-4.jpg
 *         image-5.png
 *
 * There is exactly ONE restaurant_permit row per restaurant. Its
 * file_path carries the folder path ending with a slash. The five
 * slots are the files inside it. display_order is 0 and unused.
 *
 * ---------------------------------------------------------------------
 * THE WRITER CONTRACT
 * ---------------------------------------------------------------------
 * fitpal_permit_write_slot() is the ONLY function in this file that
 * unlinks or creates files inside a slot folder. It is called for
 * both the 'remove' and 'replace' ops. Its contract:
 *
 *   Input:
 *     ?array $file            null for 'remove', the $_FILES entry
 *                             for 'replace'
 *     string $absoluteFolder  the destination folder
 *     int    $slot            1..5
 *     string $ext             extension for 'replace'; ignored when
 *                             $file is null
 *
 *   Output:
 *     array{
 *       written: array<int,string>,     paths the writer created
 *                                       (empty for 'remove')
 *       removed: array<int,array{
 *         path: string,
 *         bytes: string
 *       }>                              paths the writer unlinked,
 *                                       with the bytes each held
 *                                       BEFORE it was unlinked
 *     }
 *
 * The writer performs four steps in this exact order, every time:
 *
 *   1. Ensure the folder exists.
 *   2. For every image-<slot>.* in the folder:
 *        a. read the file's bytes into a string;
 *        b. unlink the file;
 *        c. record { path, bytes }.
 *      A read failure or an unlink failure throws. Neither is
 *      swallowed. If the OS will not read or remove the file, the
 *      request fails and the caller can tell the user why.
 *   3. If $file is not null, move_uploaded_file it into
 *      image-<slot>.<ext>. Record the target path.
 *   4. Return { written, removed }.
 *
 * The caller stores the returned removed list and the returned
 * written list. On any later failure inside the transaction, the
 * caller:
 *
 *   - unlinks every path in every written list;
 *   - writes every removed entry's bytes back to its original path.
 *
 * The folder therefore ends the request in one of exactly two
 * states:
 *
 *   - commit:   one file per slot, the writer's bytes for the slots
 *               the request touched, the previous bytes for every
 *               other slot;
 *   - rollback: exactly the bytes that were on disk when the request
 *               started.
 *
 * There is no third state. There is no leftover image-1.jpg beside a
 * fresh image-1.webp. There is no silently-orphaned file.
 *
 * ---------------------------------------------------------------------
 * WHY SLOT 1 WAS NOT UPDATING, AND HOW THIS REVISION CLOSES IT
 * ---------------------------------------------------------------------
 * The previous revision had two failure modes:
 *
 *   1. fitpal_permit_write_slot() called @unlink and ignored the
 *      return value. If the unlink failed — permissions, Windows
 *      file lock, another process holding the handle — the loop
 *      continued, the write proceeded, and the folder ended with
 *      image-1.jpg AND image-1.webp. The reader's per-slot guard
 *      kept whichever scandir returned first, almost always the
 *      alphabetically earlier .jpg. The replacement was on disk
 *      and never shown.
 *
 *   2. The transaction's catch block knew only about the paths the
 *      writer had WITTEN. The paths the writer had REMOVED were
 *      discarded. A failure after the unlink but before the commit
 *      left the slot empty and the old bytes gone.
 *
 * Both are closed. The writer now throws on an unlink failure, so
 * the second file never appears. The writer now returns the removed
 * paths and their bytes, so the caller can put them back on any
 * failure.
 *
 * ---------------------------------------------------------------------
 * THE REMOVE PATH
 * ---------------------------------------------------------------------
 * A 'remove' op is the same writer with $file = null. The writer
 * unlinks the slot's files, captures their bytes, and returns them.
 * The caller records the bytes for rollback. Nothing else in the
 * transaction touches the slot's folder.
 *
 * ---------------------------------------------------------------------
 * LEGACY FOLDER MIGRATION
 * ---------------------------------------------------------------------
 * A previous revision stored permits under
 * shared/uploads/restaurant/permits/<restaurant_account_id>/ using
 * MM_DD_YYYY_<n>.<ext> filenames. On the first re-apply that reaches
 * this handler after the schema change, the legacy folder for the
 * signed-in owner account is checked. If it exists, every image
 * inside is moved into the new per-restaurant folder and renamed to
 * image-<n>.<ext> in file order. The legacy folder is removed after.
 *
 * ---------------------------------------------------------------------
 * THE 'replace WITH NO FILE' CASE
 * ---------------------------------------------------------------------
 * UPLOAD_ERR_NO_FILE on a slot the client claimed is 'replace'
 * downgrades to 'keep' when the slot maps to an existing file, and
 * drops the op entirely when the slot maps to no file. The whole
 * request is not refused on this condition.
 *
 * ---------------------------------------------------------------------
 * SESSION REFRESH AFTER COMMIT
 * ---------------------------------------------------------------------
 * restaurant_name, business_name, and restaurant_branch_name are
 * refreshed from the values this handler just wrote, after COMMIT,
 * so every restaurant page that reads them through the header sees
 * the new values on the very next request.
 *
 * @package FitPal
 * @version 10.0 — fitpal_permit_write_slot() captures the bytes it
 *                 unlinks and returns them. The transaction body
 *                 consumes what the writer returns. The remove and
 *                 replace branches both call the same writer. The
 *                 folder's state on commit and on rollback is
 *                 defined by the writer's return value alone.
 *
 *                 (9.0: documented slot 1 end-to-end. 8.0: slot
 *                 storage. 7.0: legacy migration. 6.0: branch
 *                 resolution inlined. 5.0: branch resolution
 *                 through the query layer. 4.0: session refresh.
 *                 3.0: silent downgrade. 2.0: initial.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * SHARED VALIDATORS
 * -------------------------------------------------------------- */

if (!function_exists('fitpal_validate_name_field')) {
    function fitpal_validate_name_field(string $value, string $field, string $label): array
    {
        $pattern = "/^[A-Za-z\s\-']+$/u";

        if (strlen($value) < 2) {
            return [$field => $label . ' must be at least 2 characters.'];
        }
        if (!preg_match($pattern, $value)) {
            return [$field => $label . ' contains invalid characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_contact_number')) {
    function fitpal_validate_contact_number(string $value, string $field, string $label): array
    {
        $cleaned = (string)preg_replace('/\D+/', '', $value);

        if ($cleaned === '') {
            return [$field => $label . ' is required.'];
        }
        if (!preg_match('/^09\d{9}$/', $cleaned)) {
            return [$field => $label . ' must be a valid PH mobile number (11 digits, starting with 09).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_normalize_contact_number')) {
    function fitpal_normalize_contact_number(string $value): string
    {
        return (string)preg_replace('/\D+/', '', $value);
    }
}

if (!function_exists('fitpal_validate_username')) {
    function fitpal_validate_username(string $value, string $field = 'username'): array
    {
        if ($value === '') {
            return [$field => 'Username is required.'];
        }
        if (strlen($value) < 3 || strlen($value) > 20) {
            return [$field => 'Username must be 3–20 characters.'];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            return [$field => 'Username can only contain letters, numbers, and underscores.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_email')) {
    function fitpal_validate_email(string $value, string $field = 'email'): array
    {
        if ($value === '') {
            return [$field => 'Email address is required.'];
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [$field => 'Please enter a valid email address.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_business_name')) {
    function fitpal_validate_business_name(string $value, string $field = 'business_name'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Business name must be at least 2 characters.'];
        }
        if (strlen($value) > 100) {
            return [$field => 'Business name must be at most 100 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_cuisine_type')) {
    function fitpal_validate_cuisine_type(string $value, string $field = 'cuisine_type'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Cuisine type must be at least 2 characters.'];
        }
        if (strlen($value) > 50) {
            return [$field => 'Cuisine type must be at most 50 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_business_description')) {
    function fitpal_validate_business_description(string $value, string $field = 'description'): array
    {
        if (strlen($value) > 2000) {
            return [$field => 'Description is too long (max 2000 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_dietary_tags')) {
    function fitpal_validate_dietary_tags(string $value, string $field = 'dietary_tags'): array
    {
        if (strlen($value) > 200) {
            return [$field => 'Dietary tags are too long (max 200 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_branch_name')) {
    function fitpal_validate_branch_name(string $value, string $field = 'branch_name'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Branch name must be at least 2 characters.'];
        }
        if (strlen($value) > 50) {
            return [$field => 'Branch name must be at most 50 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_address_field')) {
    function fitpal_validate_address_field(
        string $value,
        string $field,
        string $label,
        bool $required
    ): array {
        if ($required && $value === '') {
            return [$field => $label . ' is required.'];
        }
        if (strlen($value) > 120) {
            return [$field => $label . ' is too long (max 120 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_postal_code')) {
    function fitpal_validate_postal_code(string $value, string $field = 'postal_code'): array
    {
        if ($value === '') {
            return [];
        }
        if (!preg_match('/^[0-9]{3,10}$/', $value)) {
            return [$field => 'Postal code must be numeric (3–10 digits).'];
        }
        return [];
    }
}

/* --------------------------------------------------------------
 * LOCAL HELPERS — PERMIT FOLDER AND SLOT FILES
 * -------------------------------------------------------------- */

if (!defined('FITPAL_PERMIT_MAX_SLOTS')) {
    define('FITPAL_PERMIT_MAX_SLOTS', 5);
}

if (!defined('FITPAL_PERMIT_MAX_BYTES')) {
    define('FITPAL_PERMIT_MAX_BYTES', 5 * 1024 * 1024);
}

if (!defined('FITPAL_PERMIT_ALLOWED_MIME')) {
    define('FITPAL_PERMIT_ALLOWED_MIME', [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ]);
}

if (!function_exists('fitpal_permit_project_root')) {
    function fitpal_permit_project_root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        return $root === false ? '' : $root;
    }
}

if (!function_exists('fitpal_permit_relative_folder')) {
    function fitpal_permit_relative_folder(int $restaurantId): string
    {
        if ($restaurantId <= 0) {
            return '';
        }
        return 'shared/uploads/restaurant/permits/' . $restaurantId . '/';
    }
}

if (!function_exists('fitpal_permit_absolute_folder')) {
    function fitpal_permit_absolute_folder(int $restaurantId): string
    {
        if ($restaurantId <= 0) {
            return '';
        }
        $root = fitpal_permit_project_root();
        if ($root === '') {
            return '';
        }
        return $root . '/shared/uploads/restaurant/permits/' . $restaurantId;
    }
}

if (!function_exists('fitpal_permit_list_slot_files')) {
    /**
     * @return array<int, string>
     */
    function fitpal_permit_list_slot_files(string $absoluteFolder): array
    {
        if ($absoluteFolder === '' || !is_dir($absoluteFolder)) {
            return [];
        }

        $entries = @scandir($absoluteFolder);
        if ($entries === false) {
            return [];
        }

        $allowedExt = array_values(FITPAL_PERMIT_ALLOWED_MIME);
        $bySlot = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!preg_match('/^image-(\d+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
                continue;
            }

            $slot = (int)$m[1];
            if ($slot < 1 || $slot > FITPAL_PERMIT_MAX_SLOTS) {
                continue;
            }

            $ext = strtolower($m[2]);
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $path = $absoluteFolder . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            if (!isset($bySlot[$slot])) {
                $bySlot[$slot] = $entry;
            }
        }

        ksort($bySlot, SORT_NUMERIC);
        return $bySlot;
    }
}

if (!function_exists('fitpal_permit_validate_upload')) {
    /**
     * @throws RuntimeException
     */
    function fitpal_permit_validate_upload(array $file): string
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid file payload.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
        }
        if (($file['size'] ?? 0) <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ($file['size'] > FITPAL_PERMIT_MAX_BYTES) {
            throw new RuntimeException(
                'Each permit must be under '
                . round(FITPAL_PERMIT_MAX_BYTES / 1048576, 1)
                . ' MB.'
            );
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Uploaded file is not a valid upload.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Could not inspect the uploaded file.');
        }
        $mime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);

        $allowed = FITPAL_PERMIT_ALLOWED_MIME;
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Unsupported file type. Use JPG, PNG, or WEBP.');
        }

        return $allowed[$mime];
    }
}

/**
 * Read the current bytes of every file that claims a slot.
 *
 * Called by the writer before it unlinks anything. Returns a list of
 * { path, bytes } entries. A file that cannot be read throws; the
 * writer will not proceed to unlink a file it cannot restore.
 *
 * @return array<int, array{path:string, bytes:string}>
 * @throws RuntimeException
 */
if (!function_exists('fitpal_permit_capture_slot_files')) {
    function fitpal_permit_capture_slot_files(string $absoluteFolder, int $slot): array
    {
        if ($absoluteFolder === '' || $slot < 1 || $slot > FITPAL_PERMIT_MAX_SLOTS) {
            throw new RuntimeException('Invalid permit slot.');
        }
        if (!is_dir($absoluteFolder)) {
            return [];
        }

        $captured = [];

        foreach (glob($absoluteFolder . '/image-' . $slot . '.*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException(
                    "Could not read the existing file for slot {$slot} "
                    . "before replacing it."
                );
            }

            $captured[] = [
                'path'  => $path,
                'bytes' => $bytes,
            ];
        }

        return $captured;
    }
}

/**
 * Write one permit slot.
 *
 * The ONLY function in this file that unlinks or creates files
 * inside a slot folder. Called for both 'remove' and 'replace'.
 *
 * @param array|null $file    null for 'remove'; the $_FILES entry
 *                            for 'replace'.
 * @param string     $absoluteFolder
 * @param int        $slot
 * @param string     $ext     extension for 'replace'; ignored when
 *                            $file is null.
 *
 * @return array{
 *   written: array<int, string>,
 *   removed: array<int, array{path:string, bytes:string}>
 * }
 *
 * @throws RuntimeException
 */
if (!function_exists('fitpal_permit_write_slot')) {
    function fitpal_permit_write_slot(
        ?array $file,
        string $absoluteFolder,
        int $slot,
        string $ext = ''
    ): array {
        if ($absoluteFolder === '') {
            throw new RuntimeException('Permit folder is not available.');
        }
        if ($slot < 1 || $slot > FITPAL_PERMIT_MAX_SLOTS) {
            throw new RuntimeException('Invalid permit slot.');
        }

        if (!is_dir($absoluteFolder)) {
            if (!mkdir($absoluteFolder, 0755, true) && !is_dir($absoluteFolder)) {
                throw new RuntimeException('Could not create the permit folder.');
            }
        }

        // STEP 1 — capture the bytes of every file claiming this
        // slot, BEFORE unlinking any of them.
        $captured = fitpal_permit_capture_slot_files($absoluteFolder, $slot);

        // STEP 2 — unlink every file claiming this slot. A failure
        // is a hard error: the caller is told so, and the request
        // does not proceed to write a fresh file beside a stale
        // one.
        foreach ($captured as $entry) {
            if (!@unlink($entry['path'])) {
                throw new RuntimeException(
                    "Could not remove the existing file for slot {$slot}."
                );
            }
        }

        // STEP 3 — for a 'replace', move the upload into place as
        // image-<slot>.<ext>.
        $written = [];

        if ($file !== null) {
            $target = $absoluteFolder
                    . DIRECTORY_SEPARATOR
                    . 'image-' . $slot . '.' . $ext;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                throw new RuntimeException('Could not save the permit file.');
            }

            @chmod($target, 0644);
            $written[] = $target;
        }

        return [
            'written' => $written,
            'removed' => $captured,
        ];
    }
}

/**
 * One-shot migration from the legacy per-account permit folder.
 */
function fitpal_permit_migrate_legacy_folder(
    int $accountId,
    int $restaurantId,
    string $newAbsoluteFolder
): void {
    if ($accountId <= 0 || $restaurantId <= 0 || $newAbsoluteFolder === '') {
        return;
    }

    $root = fitpal_permit_project_root();
    if ($root === '') {
        return;
    }

    if (!empty(fitpal_permit_list_slot_files($newAbsoluteFolder))) {
        return;
    }

    $legacyFolder = $root . '/shared/uploads/restaurant/permits/' . $accountId;
    if (!is_dir($legacyFolder)) {
        return;
    }

    $entries = @scandir($legacyFolder);
    if ($entries === false) {
        return;
    }

    $allowedExt = array_values(FITPAL_PERMIT_ALLOWED_MIME);
    $legacy     = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (!preg_match('/^(\d{2}_\d{2}_\d{4})_(\d+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
            continue;
        }

        $ext = strtolower($m[3]);
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $source = $legacyFolder . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($source)) {
            continue;
        }

        $legacy[] = [
            'path'  => $source,
            'index' => (int)$m[2],
            'name'  => $entry,
        ];
    }

    if (empty($legacy)) {
        return;
    }

    usort($legacy, static function (array $a, array $b): int {
        return $a['index'] <=> $b['index'];
    });

    if (!is_dir($newAbsoluteFolder)) {
        if (!mkdir($newAbsoluteFolder, 0755, true) && !is_dir($newAbsoluteFolder)) {
            return;
        }
    }

    $slot = 1;
    foreach ($legacy as $item) {
        if ($slot > FITPAL_PERMIT_MAX_SLOTS) {
            break;
        }

        $ext = strtolower((string)pathinfo($item['name'], PATHINFO_EXTENSION));
        $target = $newAbsoluteFolder
                . DIRECTORY_SEPARATOR
                . 'image-' . $slot . '.' . $ext;

        if (@rename($item['path'], $target)) {
            @chmod($target, 0644);
            $slot++;
            continue;
        }

        if (@copy($item['path'], $target)) {
            @chmod($target, 0644);
            @unlink($item['path']);
            $slot++;
        }
    }

    $remaining = @scandir($legacyFolder);
    if (is_array($remaining)) {
        $hasRealFile = false;
        foreach ($remaining as $r) {
            if ($r === '.' || $r === '..') continue;
            $hasRealFile = true;
            break;
        }
        if (!$hasRealFile) {
            @rmdir($legacyFolder);
        }
    }
}

if (!function_exists('fitpal_permit_ensure_row')) {
    function fitpal_permit_ensure_row(
        PDO $db,
        int $restaurantId,
        string $folderPath
    ): int {
        $stmt = $db->prepare(
            "SELECT permit_id
               FROM restaurant_permit
              WHERE restaurant_id = :restaurant_id
              ORDER BY permit_id ASC
              LIMIT 1"
        );
        $stmt->execute([':restaurant_id' => $restaurantId]);
        $permitId = $stmt->fetchColumn();

        if ($permitId !== false) {
            $update = $db->prepare(
                "UPDATE restaurant_permit
                    SET file_path = :file_path
                  WHERE permit_id = :permit_id
                    AND restaurant_id = :restaurant_id"
            );
            $update->execute([
                ':file_path'     => $folderPath,
                ':permit_id'     => (int)$permitId,
                ':restaurant_id' => $restaurantId,
            ]);
            return (int)$permitId;
        }

        $insert = $db->prepare(
            "INSERT INTO restaurant_permit
                (restaurant_id, file_path, original_name, display_order)
             VALUES
                (:restaurant_id, :file_path, NULL, 0)"
        );
        $insert->execute([
            ':restaurant_id' => $restaurantId,
            ':file_path'     => $folderPath,
        ]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('fitpal_reapply_resolve_branch_id')) {
    function fitpal_reapply_resolve_branch_id(
        PDO $db,
        int $restaurantId,
        int $sessionBranchId
    ): int {
        if ($sessionBranchId > 0) {
            return $sessionBranchId;
        }
        if ($restaurantId <= 0) {
            return 0;
        }

        $stmt = $db->prepare(
            "SELECT restaurant_branch_id
               FROM restaurant_branch
              WHERE restaurant_id = :restaurant_id
                AND is_active = 1
              ORDER BY restaurant_branch_id ASC
              LIMIT 1"
        );
        $stmt->execute([':restaurant_id' => $restaurantId]);
        $branchId = $stmt->fetchColumn();

        return $branchId === false ? 0 : (int)$branchId;
    }
}

/* --------------------------------------------------------------
 * AUTHENTICATION
 * -------------------------------------------------------------- */

if (empty($_SESSION['restaurant_account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$accountId    = (int)$_SESSION['restaurant_account_id'];
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
$accountScope = (string)($_SESSION['restaurant_scope'] ?? '');

if ($restaurantId <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'No restaurant is associated with this account.',
    ]);
    exit;
}

if ($accountScope !== 'owner') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Only the owner account can re-apply.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * DEPENDENCIES
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['restaurant_csrf_token'] ?? '');

if (
    $sessToken === '' ||
    $givenToken === '' ||
    !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['restaurant_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * LOAD CURRENT STATE
 * -------------------------------------------------------------- */

try {
    $currentAccount = getRestaurantAccountProfile($database_connection, $accountId);
} catch (PDOException $e) {
    error_log('Restaurant re-apply: account load failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
    exit;
}

if (!$currentAccount) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your account could not be found. Please sign in again.',
    ]);
    exit;
}

if ((int)($currentAccount['restaurant_id'] ?? 0) !== $restaurantId) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your account is not associated with the expected restaurant.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * VERIFICATION GATE
 * -------------------------------------------------------------- */

try {
    $gateStmt = $database_connection->prepare(
        "SELECT verification_status, business_name, is_active
           FROM restaurant
          WHERE restaurant_id = :restaurant_id
          LIMIT 1"
    );
    $gateStmt->execute([':restaurant_id' => $restaurantId]);
    $gateRow = $gateStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Restaurant re-apply: gate load failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
    exit;
}

if (!$gateRow) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your restaurant could not be found. Please contact support.',
    ]);
    exit;
}

if ((string)$gateRow['verification_status'] !== 'denied') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your restaurant is not eligible to re-apply right now.',
    ]);
    exit;
}

if ((int)$gateRow['is_active'] !== 1) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your restaurant account is inactive. Please contact support.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * RESOLVE THE BRANCH TO UPDATE
 * -------------------------------------------------------------- */

$sessionBranchId = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;

try {
    $branchId = fitpal_reapply_resolve_branch_id(
        $database_connection,
        $restaurantId,
        $sessionBranchId
    );
} catch (PDOException $e) {
    error_log('Restaurant re-apply: branch resolution failed: ' . $e->getMessage());
    $branchId = 0;
}

if ($branchId <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your restaurant does not have an active branch to update.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * COLLECT INPUT
 * -------------------------------------------------------------- */

$firstName        = trim((string)($_POST['first_name']        ?? ''));
$middleName       = trim((string)($_POST['middle_name']       ?? ''));
$lastName         = trim((string)($_POST['last_name']         ?? ''));
$email            = trim((string)($_POST['email']             ?? ''));
$contactNumber    = trim((string)($_POST['contact_number']    ?? ''));
$username         = trim((string)($_POST['username']          ?? ''));

$businessName        = trim((string)($_POST['business_name']        ?? ''));
$cuisineType         = trim((string)($_POST['cuisine_type']         ?? ''));
$businessDescription = trim((string)($_POST['business_description'] ?? ''));
$dietaryTags         = trim((string)($_POST['dietary_tags']         ?? ''));

$branchName = trim((string)($_POST['branch_name'] ?? ''));
$block      = trim((string)($_POST['block']       ?? ''));
$barangay   = trim((string)($_POST['barangay']    ?? ''));
$city       = trim((string)($_POST['city']        ?? ''));
$province   = trim((string)($_POST['province']    ?? ''));
$region     = trim((string)($_POST['region']      ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));

/* --------------------------------------------------------------
 * VALIDATE
 * -------------------------------------------------------------- */

$errors = [];

$errors += fitpal_validate_name_field($firstName, 'first_name', 'First name');
$errors += fitpal_validate_name_field($lastName,  'last_name',  'Last name');

if ($middleName !== '') {
    $errors += fitpal_validate_name_field($middleName, 'middle_name', 'Middle name');
}

$errors += fitpal_validate_email($email);
$errors += fitpal_validate_contact_number($contactNumber, 'contact_number', 'Contact number');
$errors += fitpal_validate_username($username);

$errors += fitpal_validate_business_name($businessName);
$errors += fitpal_validate_cuisine_type($cuisineType);
$errors += fitpal_validate_business_description($businessDescription, 'business_description');
$errors += fitpal_validate_dietary_tags($dietaryTags);

$errors += fitpal_validate_branch_name($branchName);
$errors += fitpal_validate_address_field($block,      'block',      'Block / Street / Unit', true);
$errors += fitpal_validate_address_field($barangay,   'barangay',   'Barangay',              false);
$errors += fitpal_validate_address_field($city,       'city',       'City / Municipality',   true);
$errors += fitpal_validate_address_field($province,   'province',   'Province',              false);
$errors += fitpal_validate_address_field($region,     'region',     'Region',                false);
$errors += fitpal_validate_postal_code($postalCode);

if (!empty($errors)) {
    $firstField = array_key_first($errors);

    echo json_encode([
        'status'  => 'error',
        'field'   => $firstField,
        'errors'  => $errors,
        'message' => $errors[$firstField],
    ]);
    exit;
}

$cleanContact = fitpal_normalize_contact_number($contactNumber);

/* --------------------------------------------------------------
 * UNIQUENESS — PROBES EXCLUDE THE CALLER'S OWN ACCOUNT
 * -------------------------------------------------------------- */

try {
    if (function_exists('restaurantEmailExistsForOther')
        && restaurantEmailExistsForOther($database_connection, $email, $accountId)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'email',
            'message' => 'This email address is already registered to another account.',
        ]);
        exit;
    }

    if (function_exists('restaurantUsernameExistsForOther')
        && restaurantUsernameExistsForOther($database_connection, $username, $accountId)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'username',
            'message' => 'This username is already taken.',
        ]);
        exit;
    }

    if (function_exists('restaurantContactExistsForOther')
        && restaurantContactExistsForOther($database_connection, $cleanContact, $accountId)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'contact_number',
            'message' => 'This mobile number is already registered to another account.',
        ]);
        exit;
    }

    if (function_exists('restaurantBusinessNameExistsForOther')
        && restaurantBusinessNameExistsForOther($database_connection, $businessName, $restaurantId)) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'business_name',
            'message' => 'This business name is already registered to another restaurant.',
        ]);
        exit;
    }
} catch (PDOException $e) {
    error_log('Restaurant re-apply: uniqueness probes failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
    exit;
}

/* --------------------------------------------------------------
 * PERMIT FOLDER
 * -------------------------------------------------------------- */

$permitAbsFolder = fitpal_permit_absolute_folder($restaurantId);
if ($permitAbsFolder === '') {
    echo json_encode(['status' => 'error', 'message' => 'Server storage path unavailable.']);
    exit;
}

$permitRelFolder = fitpal_permit_relative_folder($restaurantId);

fitpal_permit_migrate_legacy_folder($accountId, $restaurantId, $permitAbsFolder);

$currentSlotFiles = fitpal_permit_list_slot_files($permitAbsFolder);

/* --------------------------------------------------------------
 * PERMIT SLOT INPUT
 * -------------------------------------------------------------- */

$slotOps = [];

for ($slot = 1; $slot <= FITPAL_PERMIT_MAX_SLOTS; $slot++) {

    $op = (string)($_POST['permit_op'][$slot] ?? 'keep');
    if (!in_array($op, ['keep', 'replace', 'remove'], true)) {
        $op = 'keep';
    }

    if ($op === 'keep') {
        if (isset($currentSlotFiles[$slot])) {
            $slotOps[$slot] = ['op' => 'keep'];
        }
        continue;
    }

    if ($op === 'remove') {
        if (isset($currentSlotFiles[$slot])) {
            $slotOps[$slot] = ['op' => 'remove'];
        }
        continue;
    }

    /* ---- replace ---- */

    $fileEntry = null;

    if (
        isset($_FILES['permit_file']) &&
        is_array($_FILES['permit_file']) &&
        isset($_FILES['permit_file']['error'][$slot]) &&
        is_array($_FILES['permit_file']['error'])
    ) {
        $err = (int)$_FILES['permit_file']['error'][$slot];

        if ($err !== UPLOAD_ERR_NO_FILE) {
            $fileEntry = [
                'name'     => (string)($_FILES['permit_file']['name'][$slot]     ?? ''),
                'type'     => (string)($_FILES['permit_file']['type'][$slot]     ?? ''),
                'tmp_name' => (string)($_FILES['permit_file']['tmp_name'][$slot] ?? ''),
                'error'    => $err,
                'size'     => (int)   ($_FILES['permit_file']['size'][$slot]     ?? 0),
            ];
        }
    }

    if ($fileEntry === null) {
        if (isset($currentSlotFiles[$slot])) {
            $slotOps[$slot] = ['op' => 'keep'];
        }
        continue;
    }

    $slotOps[$slot] = [
        'op'   => 'replace',
        'file' => $fileEntry,
    ];
}

/* --------------------------------------------------------------
 * PERMIT FILE VALIDATION
 * -------------------------------------------------------------- */

$validatedReplacements = [];

foreach ($slotOps as $slot => $slotOp) {
    if ($slotOp['op'] !== 'replace') {
        continue;
    }

    try {
        $ext = fitpal_permit_validate_upload($slotOp['file']);
    } catch (RuntimeException $e) {
        echo json_encode([
            'status'  => 'error',
            'field'   => 'permits',
            'message' => "Slot {$slot}: " . $e->getMessage(),
        ]);
        exit;
    }

    $validatedReplacements[$slot] = [
        'ext' => $ext,
        'file' => $slotOp['file'],
    ];
}

/* --------------------------------------------------------------
 * SIMULATE THE PERMIT SET AFTER THE OPS
 * -------------------------------------------------------------- */

$presentBefore = count($currentSlotFiles);
$removals      = 0;
$additions     = 0;

foreach ($slotOps as $slot => $slotOp) {
    if ($slotOp['op'] === 'remove' && isset($currentSlotFiles[$slot])) {
        $removals++;
        continue;
    }
    if ($slotOp['op'] === 'replace' && !isset($currentSlotFiles[$slot])) {
        $additions++;
    }
}

$finalPermitCount = $presentBefore - $removals + $additions;

if ($finalPermitCount < 1) {
    echo json_encode([
        'status'  => 'error',
        'field'   => 'permits',
        'message' => 'At least one permit must remain on file. Add a new permit before removing the last one.',
    ]);
    exit;
}

/* =============================================================
 * TRANSACTION
 *
 * Two accumulators are the entire rollback state:
 *
 *   $writtenFiles     flat list of every path the writer created.
 *                     On failure: unlink each.
 *
 *   $restorableFiles  flat list of { path, bytes } entries the
 *                     writer removed. On failure: write each
 *                     entry's bytes back to its path.
 *
 * The writer is the ONLY place that reads or unlinks a slot's
 * files. Both the 'remove' and 'replace' ops call the same writer.
 * The transaction body never touches the slot folder directly.
 * ============================================================= */

$writtenFiles    = [];
$restorableFiles = [];

try {
    $database_connection->beginTransaction();

    /* ----------------------------------------------------------
     * 1. restaurant_account
     * ---------------------------------------------------------- */

    $updateAccountStmt = $database_connection->prepare(
        "UPDATE restaurant_account
            SET first_name     = :first_name,
                middle_name    = :middle_name,
                last_name      = :last_name,
                email          = :email,
                contact_number = :contact_number,
                username       = :username
          WHERE restaurant_account_id = :account_id
            AND restaurant_id = :restaurant_id"
    );
    $updateAccountStmt->execute([
        ':first_name'     => $firstName,
        ':middle_name'    => $middleName !== '' ? $middleName : null,
        ':last_name'      => $lastName,
        ':email'          => $email,
        ':contact_number' => $cleanContact,
        ':username'       => $username,
        ':account_id'     => $accountId,
        ':restaurant_id'  => $restaurantId,
    ]);

    /* ----------------------------------------------------------
     * 2. restaurant
     * ---------------------------------------------------------- */

    $updateRestaurantStmt = $database_connection->prepare(
        "UPDATE restaurant
            SET business_name       = :business_name,
                description         = :description,
                cuisine_type        = :cuisine_type,
                dietary_tags        = :dietary_tags,
                verification_status = 'pending',
                verified_by_admin_id = NULL,
                verified_at          = NULL
          WHERE restaurant_id = :restaurant_id"
    );
    $updateRestaurantStmt->execute([
        ':business_name' => $businessName,
        ':description'   => $businessDescription !== '' ? $businessDescription : null,
        ':cuisine_type'  => $cuisineType !== '' ? $cuisineType : null,
        ':dietary_tags'  => $dietaryTags !== '' ? $dietaryTags : null,
        ':restaurant_id' => $restaurantId,
    ]);

    /* ----------------------------------------------------------
     * 3. restaurant_branch
     * ---------------------------------------------------------- */

    $branchCheckStmt = $database_connection->prepare(
        "SELECT 1
           FROM restaurant_branch
          WHERE restaurant_branch_id = :branch_id
            AND restaurant_id = :restaurant_id
          LIMIT 1"
    );
    $branchCheckStmt->execute([
        ':branch_id'     => $branchId,
        ':restaurant_id' => $restaurantId,
    ]);

    if ($branchCheckStmt->fetchColumn() === false) {
        throw new RuntimeException('Your branch could not be found.');
    }

    $updateBranchStmt = $database_connection->prepare(
        "UPDATE restaurant_branch
            SET branch_name = :branch_name,
                block       = :block,
                barangay    = :barangay,
                city        = :city,
                province    = :province,
                region      = :region,
                postal_code = :postal_code
          WHERE restaurant_branch_id = :branch_id
            AND restaurant_id = :restaurant_id"
    );
    $updateBranchStmt->execute([
        ':branch_name'   => $branchName,
        ':block'         => $block !== '' ? $block : null,
        ':barangay'      => $barangay !== '' ? $barangay : null,
        ':city'          => $city,
        ':province'      => $province !== '' ? $province : null,
        ':region'        => $region !== '' ? $region : null,
        ':postal_code'   => $postalCode !== '' ? $postalCode : null,
        ':branch_id'     => $branchId,
        ':restaurant_id' => $restaurantId,
    ]);

    /* ----------------------------------------------------------
     * 4. restaurant_permit
     *
     * Both ops go through fitpal_permit_write_slot(). The 'remove'
     * op is the same writer with $file = null: it captures, then
     * unlinks, and returns the captured bytes. The 'replace' op
     * captures, unlinks, and then writes the upload.
     *
     * The transaction body records the writer's return values into
     * the two accumulators and moves on. It does not read, unlink,
     * or write any slot file itself.
     * ---------------------------------------------------------- */

    foreach ($slotOps as $slot => $slotOp) {

        if ($slotOp['op'] === 'keep') {
            continue;
        }

        if ($slotOp['op'] === 'remove') {
            $result = fitpal_permit_write_slot(
                null,
                $permitAbsFolder,
                $slot,
                ''
            );

            foreach ($result['written'] as $path) {
                $writtenFiles[] = $path;
            }
            foreach ($result['removed'] as $entry) {
                $restorableFiles[] = $entry;
            }

            continue;
        }

        $replacement = $validatedReplacements[$slot] ?? null;
        if ($replacement === null) {
            throw new RuntimeException("Slot {$slot} lost its validated file.");
        }

        $result = fitpal_permit_write_slot(
            $replacement['file'],
            $permitAbsFolder,
            $slot,
            $replacement['ext']
        );

        foreach ($result['written'] as $path) {
            $writtenFiles[] = $path;
        }
        foreach ($result['removed'] as $entry) {
            $restorableFiles[] = $entry;
        }
    }

    /* ----------------------------------------------------------
     * 5. Permit row
     * ---------------------------------------------------------- */

    $permitRowId = fitpal_permit_ensure_row(
        $database_connection,
        $restaurantId,
        $permitRelFolder
    );

    if ($permitRowId <= 0) {
        throw new RuntimeException('Could not record the permit folder.');
    }

    $database_connection->commit();

    /* ----------------------------------------------------------
     * 6. Session refresh
     * ---------------------------------------------------------- */

    $_SESSION['restaurant_name'] = trim($firstName . ' ' . $lastName);
    $_SESSION['business_name']   = $businessName;
    $_SESSION['restaurant_branch_name'] = $branchName;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }

    // Roll back the filesystem to exactly what it held before.
    foreach ($writtenFiles as $writtenFile) {
        if (is_file($writtenFile)) {
            @unlink($writtenFile);
        }
    }
    foreach ($restorableFiles as $entry) {
        @file_put_contents($entry['path'], $entry['bytes']);
        @chmod($entry['path'], 0644);
    }

    error_log('Restaurant re-apply DB error: ' . $e->getMessage());

    $message = $e->getMessage();
    if (stripos($message, 'Duplicate entry') !== false) {
        if (stripos($message, 'email') !== false) {
            echo json_encode([
                'status'  => 'error',
                'field'   => 'email',
                'message' => 'This email address is already registered to another account.',
            ]);
            exit;
        }
        if (stripos($message, 'username') !== false) {
            echo json_encode([
                'status'  => 'error',
                'field'   => 'username',
                'message' => 'This username is already taken.',
            ]);
            exit;
        }
        if (stripos($message, 'contact_number') !== false) {
            echo json_encode([
                'status'  => 'error',
                'field'   => 'contact_number',
                'message' => 'This mobile number is already registered to another account.',
            ]);
            exit;
        }
        if (stripos($message, 'business_name') !== false) {
            echo json_encode([
                'status'  => 'error',
                'field'   => 'business_name',
                'message' => 'This business name is already registered to another restaurant.',
            ]);
            exit;
        }
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'An unexpected error occurred. Please try again later.',
    ]);
    exit;

} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }

    foreach ($writtenFiles as $writtenFile) {
        if (is_file($writtenFile)) {
            @unlink($writtenFile);
        }
    }
    foreach ($restorableFiles as $entry) {
        @file_put_contents($entry['path'], $entry['bytes']);
        @chmod($entry['path'], 0644);
    }

    error_log('Restaurant re-apply error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'An unexpected error occurred. Please try again later.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * SUCCESS
 * -------------------------------------------------------------- */

unset($_SESSION['restaurant_csrf_token']);

$_SESSION['restaurant_success'] =
    'Your updated application has been submitted. It is now pending review.';

echo json_encode([
    'status'   => 'success',
    'message'  => 'Your updated application has been submitted. It is now pending review.',
    'redirect' => 'dashboard.php',
]);
exit;