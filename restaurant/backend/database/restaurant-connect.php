<?php
/**
 * FitPal Restaurant Database Connection (role wrapper)
 *
 * Restaurant pages should require THIS file instead of reaching into
 * shared/backend/database/database-connect.php directly.
 *
 * This does NOT open a second database connection - FitPal's
 * architecture requires one shared PDO singleton. This file exists
 * so the restaurant role has its own include point, consistent with
 * the rest of restaurant/backend/database/.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// $database_connection is now available - the same singleton every role uses.