<?php
/**
 * FitPal Customer Database Connection (role wrapper)
 *
 * Customer pages should require THIS file instead of reaching into
 * shared/backend/database/database_connect.php directly.
 *
 * This does NOT open a second database connection - FitPal's architecture
 * requires one shared PDO singleton (instructions.md Section 10). This file
 * exists only so the customer role has its own include point, consistent
 * with the rest of customer/backend/database/ (e.g. customer_queries.php).
 *
 * Usage:
 *   require_once __DIR__ . '/../../backend/database/customer_connect.php';
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// $database_connection is now available - the same singleton every role uses.