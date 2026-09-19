<?php
/**
 * FitPal Admin Database Connection (role wrapper)
 *
 * Admin pages should require THIS file instead of reaching into
 * shared/backend/database/database-connect.php directly.
 *
 * This does NOT open a second database connection - FitPal's
 * architecture requires one shared PDO singleton. This file exists
 * so the admin role has its own include point, consistent with the
 * rest of admin/backend/database/.
 *
 * Usage:
 *   require_once __DIR__ . '/../../backend/database/admin-connect.php';
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// $database_connection is now available - the same singleton every role uses.