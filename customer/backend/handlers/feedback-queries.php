<?php
/**
 * FitPal Feedback Queries
 *
 * Feature file for the `feedback` and `rating` tables. It owns every
 * SQL statement the review flow needs.
 *
 * ---------------------------------------------------------------------
 * PER-SUBJECT COMMENTS
 * ---------------------------------------------------------------------
 * The `feedback` table has one feedback_content column per order.
 * Per-subject comments are stored inside that one column as JSON:
 *
 *     {"comments": {
 *         "product:45":   "The salmon was fresh.",
 *         "restaurant:7": "Fast prep.",
 *         "rider:3":      "Very polite."
 *     }}
 *
 * A subject that was rated but not commented gets no key. An
 * envelope with no comments at all stores NULL.
 *
 * createFeedback() writes that JSON verbatim. getFeedbackRatings()
 * returns the ratings AND a parallel `comments` map decoded from the
 * envelope, so a caller that reads a stored review does not have to
 * decode the envelope itself.
 *
 * ---------------------------------------------------------------------
 * TRANSACTION CONTRACT
 * ---------------------------------------------------------------------
 * createFeedback() and createRating() are individual row writers.
 * They do not open, commit, or roll back a transaction. The caller
 * (feedback-handler.php) opens a transaction, calls them in sequence,
 * and commits once all rows are written.
 *
 * @package FitPal
 * @version 2.0 — Adds JSON comment decoding to getFeedbackRatings()
 *                and documents the per-subject comment envelope
 *                shape on createFeedback().
 *
 *                (1.0: initial feedback query layer.)
 */

declare(strict_types=1);

/* =============================================================
 * READS — RATABLE SUBJECTS
 * ============================================================= */

function getOrderRatableBranches(PDO $db, int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DISTINCT
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            rb.branch_code,
            r.restaurant_id,
            r.business_name AS restaurant_name
         FROM queue_item qi
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE qi.order_id = :order_id
         ORDER BY r.business_name ASC, rb.branch_name ASC"
    );
    $stmt->execute([':order_id' => $orderId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'branch_id'       => (int)$row['branch_id'],
            'branch_name'     => (string)$row['branch_name'],
            'branch_code'     => (string)$row['branch_code'],
            'restaurant_id'   => (int)$row['restaurant_id'],
            'restaurant_name' => (string)$row['restaurant_name'],
        ];
    }
    return $out;
}

function getOrderRatableRider(PDO $db, int $orderId): array|false
{
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id AS rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            drp.vehicle_type,
            drp.profile_picture
         FROM orders o
         JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE o.order_id = :order_id
           AND o.delivery_rider_id IS NOT NULL
         LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $parts = array_filter([
        (string)($row['first_name']  ?? ''),
        (string)($row['middle_name'] ?? ''),
        (string)($row['last_name']   ?? ''),
    ]);
    $displayName = trim(implode(' ', $parts));
    if ($displayName === '') {
        $displayName = 'Rider';
    }

    return [
        'rider_id'        => (int)$row['rider_id'],
        'first_name'      => (string)($row['first_name']  ?? ''),
        'middle_name'     => $row['middle_name'] !== null
            ? (string)$row['middle_name']
            : null,
        'last_name'       => (string)($row['last_name']   ?? ''),
        'display_name'    => $displayName,
        'vehicle_type'    => $row['vehicle_type'] !== null
            ? (string)$row['vehicle_type']
            : null,
        'profile_picture' => $row['profile_picture'] !== null
            ? (string)$row['profile_picture']
            : null,
    ];
}

function getCustomerFeedbackForOrder(PDO $db, int $orderId, int $customerId): array|false
{
    if ($orderId <= 0 || $customerId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            f.feedback_id,
            f.feedback_content,
            f.date_posted,
            (
                SELECT COUNT(*)
                  FROM rating r
                 WHERE r.feedback_id = f.feedback_id
            ) AS rating_count
         FROM feedback f
         WHERE f.order_id = :order_id
           AND f.feedback_from_type = 'customer'
           AND f.feedback_from_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $rawContent = $row['feedback_content'] !== null
        ? (string)$row['feedback_content']
        : null;

    return [
        'feedback_id'      => (int)$row['feedback_id'],
        'feedback_content' => $rawContent,
        'comments'         => feedbackDecodeComments($rawContent),
        'date_posted'      => (string)$row['date_posted'],
        'rating_count'     => (int)$row['rating_count'],
    ];
}

/**
 * Decode the per-subject comments out of a feedback_content
 * string. Returns an empty array when the column is NULL, the
 * string is empty, or the JSON does not carry a `comments` map.
 *
 * @return array<string, string>  Keyed by "type:id".
 */
function feedbackDecodeComments(?string $content): array
{
    if ($content === null || trim($content) === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [];
    }

    $comments = $decoded['comments'] ?? null;
    if (!is_array($comments)) {
        return [];
    }

    $out = [];
    foreach ($comments as $key => $text) {
        if (!is_string($key) || !is_string($text)) {
            continue;
        }
        $text = trim($text);
        if ($text === '') {
            continue;
        }
        $out[$key] = $text;
    }
    return $out;
}

/**
 * Ratings left on an envelope, grouped by type. Each entry carries
 * the subject id so a caller can look up the matching comment in
 * the envelope's decoded `comments` map.
 *
 * @return array{
 *     product:    array<int, array{subject_id:int, score:int, subject_label:string}>,
 *     restaurant: array<int, array{subject_id:int, score:int, subject_label:string}>,
 *     rider:      array<int, array{subject_id:int, score:int, subject_label:string}>
 * }
 */
function getFeedbackRatings(PDO $db, int $feedbackId): array
{
    $grouped = ['product' => [], 'restaurant' => [], 'rider' => []];

    if ($feedbackId <= 0) {
        return $grouped;
    }

    $stmt = $db->prepare(
        "SELECT
            r.rating_id,
            r.rating_type,
            r.score,
            r.queue_item_id,
            r.branch_id,
            r.rider_id,
            p.name AS product_name,
            rb.branch_name,
            r2.business_name AS restaurant_name,
            CONCAT_WS(' ',
                dr.first_name,
                NULLIF(dr.middle_name, ''),
                dr.last_name
            ) AS rider_name
         FROM rating r
         LEFT JOIN queue_item qi ON r.queue_item_id = qi.queue_item_id
         LEFT JOIN product p     ON qi.product_id = p.product_id
         LEFT JOIN restaurant_branch rb ON r.branch_id = rb.restaurant_branch_id
         LEFT JOIN restaurant r2 ON rb.restaurant_id = r2.restaurant_id
         LEFT JOIN delivery_rider dr ON r.rider_id = dr.delivery_rider_id
         WHERE r.feedback_id = :feedback_id
         ORDER BY r.rating_id ASC"
    );
    $stmt->execute([':feedback_id' => $feedbackId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $type = (string)$row['rating_type'];
        if (!isset($grouped[$type])) {
            continue;
        }

        $subjectId = 0;
        $label     = '';

        if ($type === 'product') {
            $subjectId = (int)($row['queue_item_id'] ?? 0);
            $label     = (string)($row['product_name'] ?? 'Product');
        } elseif ($type === 'restaurant') {
            $subjectId = (int)($row['branch_id'] ?? 0);
            $branch    = (string)($row['branch_name'] ?? '');
            $resto     = (string)($row['restaurant_name'] ?? '');
            $label     = trim($resto . ($branch !== '' ? ' • ' . $branch : ''));
            if ($label === '') {
                $label = 'Restaurant';
            }
        } elseif ($type === 'rider') {
            $subjectId = (int)($row['rider_id'] ?? 0);
            $label     = trim((string)($row['rider_name'] ?? ''));
            if ($label === '') {
                $label = 'Rider';
            }
        }

        $grouped[$type][] = [
            'subject_id'    => $subjectId,
            'score'         => (int)$row['score'],
            'subject_label' => $label,
        ];
    }

    return $grouped;
}

/* =============================================================
 * WRITES
 * ============================================================= */

/**
 * Insert one feedback envelope for an order.
 *
 * The caller has already verified that no envelope exists for this
 * (order_id, customer) pair.
 *
 * `$content` is expected to be the JSON envelope for the per-subject
 * comments, shaped as {"comments":{"type:id":"text"}}. The function
 * passes it through verbatim: it does not parse or re-encode. A
 * value of NULL or an empty string stores NULL. A caller that has
 * no comments to write must pass NULL.
 *
 * The `feedback_from_type` and `feedback_from_id` arguments are
 * caller-supplied so the same query layer can serve any author.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $orderId
 * @param string $fromType
 * @param int $fromId
 * @param string|null $content  JSON envelope or NULL.
 * @return int The new feedback_id.
 */
function createFeedback(
    PDO $db,
    int $orderId,
    string $fromType,
    int $fromId,
    ?string $content
): int {
    $normalized = ($content !== null && trim($content) !== '')
        ? $content
        : null;

    $stmt = $db->prepare(
        "INSERT INTO feedback
            (order_id, feedback_from_type, feedback_from_id,
             feedback_content, date_posted)
         VALUES
            (:order_id, :from_type, :from_id,
             :content, NOW())"
    );
    $stmt->execute([
        ':order_id'  => $orderId,
        ':from_type' => $fromType,
        ':from_id'   => $fromId,
        ':content'   => $normalized,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Insert one rating row anchored to a feedback envelope.
 *
 * Exactly one of the three subject columns must be non-null; the
 * choice is driven by `$ratingType`. The table's own CHECK
 * constraint enforces the same rule at the database level.
 *
 * Requires: caller-owned transaction.
 *
 * @param PDO $db
 * @param int $feedbackId
 * @param string $ratingType  'product' | 'restaurant' | 'rider'
 * @param int $subjectId
 * @param int $score          1..5.
 * @return int The new rating_id.
 */
function createRating(
    PDO $db,
    int $feedbackId,
    string $ratingType,
    int $subjectId,
    int $score
): int {
    $queueItemId = null;
    $branchId    = null;
    $riderId     = null;

    if ($ratingType === 'product') {
        $queueItemId = $subjectId;
    } elseif ($ratingType === 'restaurant') {
        $branchId = $subjectId;
    } elseif ($ratingType === 'rider') {
        $riderId = $subjectId;
    }

    $stmt = $db->prepare(
        "INSERT INTO rating
            (feedback_id, rating_type,
             queue_item_id, branch_id, rider_id,
             score, created_at)
         VALUES
            (:feedback_id, :rating_type,
             :queue_item_id, :branch_id, :rider_id,
             :score, NOW())"
    );
    $stmt->execute([
        ':feedback_id'   => $feedbackId,
        ':rating_type'   => $ratingType,
        ':queue_item_id' => $queueItemId,
        ':branch_id'     => $branchId,
        ':rider_id'      => $riderId,
        ':score'         => $score,
    ]);

    return (int)$db->lastInsertId();
}