<?php
/**
 * FitPal Shared Fee Schedule
 *
 * Single source of truth for delivery, service, and VAT charges, plus
 * the two rider grace windows that bracket a delivery, plus the
 * rider-responsibility math used by the accept-time liability
 * write and the failed-order settlement.
 *
 * Both the checkout page (display) and every order-creation path call
 * these helpers so the number the customer sees is the number that
 * gets stored, and the number the rider is debited matches the number
 * the customer actually paid.
 *
 * VAT is ADDITIVE: it is charged on top of the subtotal and included
 * in the order total.
 *
 * ---------------------------------------------------------------------
 * REVENUE RECOGNITION
 * ---------------------------------------------------------------------
 * No gross revenue is recognised until an order reaches 'delivered'.
 * Cancelled, refunded, and failed orders contribute zero to every
 * revenue aggregate, everywhere in the system.
 *
 * ---------------------------------------------------------------------
 * RIDER LIABILITY MODEL (v2.5.0)
 * ---------------------------------------------------------------------
 * The moment a rider accepts an assignment, they become financially
 * responsible for the order. The amount recorded is the full order
 * total:
 *
 *     rider_liability_amount = subtotal + delivery_fee
 *                            + service_fee + vat
 *
 * This is written by acceptOrder() (via recordRiderLiability() in
 * the shared order-transaction query layer) for EVERY payment
 * method, and it appears on the rider's earnings page as their
 * outstanding order liability.
 *
 * On successful delivery:
 *   - rider_liability_amount is cleared to NULL
 *   - the rider is credited the delivery fee (their earnings)
 *   - the restaurant is credited the subtotal (their food revenue)
 *   - the platform retains the service fee and the VAT
 *
 * On failure (auto-fail from sweepFailedDeliveries(), or an
 * explicit failure transition):
 *   - a `payment` transaction is written against the rider's
 *     account for rider_liability_amount
 *   - the rider's wallet is debited by the transaction triggers
 *   - rider_liability_amount is cleared to NULL
 *   - any COD cash-custody row in rider_collection is voided
 *
 * The rider's wallet is permitted to go negative on a failure. The
 * Insufficient balance guard in the transaction triggers exempts
 * any completed `payment` or `withdrawal` whose description begins
 * with 'Rider liability for order #', which is the prefix the
 * failure settlement writes.
 *
 * ---------------------------------------------------------------------
 * TWO GRACE WINDOWS (v2.5.0)
 * ---------------------------------------------------------------------
 * A delivery is bracketed by two independent timer-based windows.
 * They serve different purposes and their values are separate
 * policy choices:
 *
 *   FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS
 *     The window an order may remain in 'picking_up' or
 *     'delivering' without a status change before the sweep fails
 *     it. Measured from orders.updated_at, which resets on every
 *     status transition. A rider therefore has this many seconds
 *     to go from picking_up to delivering, and a fresh window of
 *     the same length to go from delivering to delivered. Total
 *     allowed time across both statuses is up to twice this value.
 *
 *   FITPAL_RIDER_MESSAGE_GRACE_SECONDS
 *     The window after a successful delivery during which the
 *     rider may still exchange messages with the customer and the
 *     kitchen about the order. Measured from orders.delivered_at.
 *     After it elapses, the conversation remains readable but no
 *     further sends are accepted on either channel.
 *
 * Changing one does not change the other. They currently happen to
 * share a numeric value of one hour only for the message window;
 * the failure window is now shorter than the message window
 * because the failure policy is more aggressive than the messaging
 * policy. Do not couple the two values in code.
 *
 * ---------------------------------------------------------------------
 * WHERE THIS FILE LIVES
 * ---------------------------------------------------------------------
 * This file lives under shared/ because every role depends on
 * exactly the same constants: the customer role renders the fee
 * breakdown on checkout; the restaurant role reconciles revenue;
 * the rider role is debited or credited by the numbers here; the
 * admin role reads platform revenue; the shared order-transaction
 * layer reads the grace windows. Keeping one copy under shared/
 * removes any cross-role duplication risk.
 *
 * @package FitPal
 * @version 3.0.0 — Adds FITPAL_RIDER_MESSAGE_GRACE_SECONDS as the
 *                  single source of truth for the post-delivery
 *                  messaging window. Shortens
 *                  FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS from
 *                  3600 to 2700 (45 minutes) to match the v2.5.0
 *                  failure policy. Documents both windows and
 *                  their independence.
 *
 *                  The three fee constants and the two fee
 *                  functions are unchanged so existing callers
 *                  keep working.
 *
 *                  (2.0: moved to shared/backend/database/. Adds
 *                  the rider responsibility window and the three
 *                  payout helpers. 1.1: added VAT. 1.0: initial
 *                  delivery + service fee schedule.)
 */

declare(strict_types=1);

/* =============================================================
 * ORDER-LEVEL FEES
 * ============================================================= */

const FITPAL_DELIVERY_BASE_FEE         = 50.00;
const FITPAL_DELIVERY_EXTRA_PER_BRANCH = 30.00;
const FITPAL_SERVICE_FEE               = 5.00;

/**
 * VAT rate applied to the subtotal.
 *
 * 12% is the standard Philippine VAT rate.
 */
const FITPAL_VAT_RATE = 0.12;

/* =============================================================
 * RIDER GRACE WINDOWS
 *
 * Two independent windows. See the file header for the full
 * description of each and why they are not coupled.
 * ============================================================= */

/**
 * Window, in seconds, that an order may remain in 'picking_up' or
 * 'delivering' without a status change before sweepFailedDeliveries()
 * fails it.
 *
 * 45 minutes = 2700 seconds. Measured from orders.updated_at, which
 * resets on every status transition. A rider therefore has 45
 * minutes to go from picking_up to delivering, and a fresh 45
 * minutes to go from delivering to delivered. Maximum allowed time
 * across both statuses is 90 minutes.
 *
 * When the sweep fires, the order is transitioned to 'failed' and
 * the rider's liability is settled against their account via
 * settleFailedRiderLiability().
 */
const FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS = 2700;

/**
 * Window, in seconds, after a successful delivery during which the
 * rider may still exchange messages with the customer and the
 * kitchen about the order.
 *
 * 1 hour = 3600 seconds. Measured from orders.delivered_at. After
 * the window elapses, the conversation remains readable on the
 * read path but no further sends are accepted on either channel.
 *
 * This window is independent of the failure window above. Its
 * value is a messaging policy choice, not a timing policy tied to
 * the failed-delivery sweep.
 */
const FITPAL_RIDER_MESSAGE_GRACE_SECONDS = 3600;

/* =============================================================
 * ORDER FEE CALCULATION
 * ============================================================= */

/**
 * Calculate all order-level fees from the number of distinct
 * branches in the cart and the cart subtotal.
 *
 * @param int   $branchCount  Distinct restaurant_branch_id count in
 *                            the cart. A value of 0 is treated as 1.
 * @param float $subtotal     Cart subtotal (sum of item price ×
 *                            quantity).
 *
 * @return array{
 *     branch_count:   int,
 *     extra_branches: int,
 *     delivery_fee:   float,
 *     service_fee:    float,
 *     vat_rate:       float,
 *     vat:            float,
 *     total_fees:     float
 * }
 */
function calculateOrderFees(int $branchCount, float $subtotal = 0.0): array
{
    $branchCount = max(1, $branchCount);
    $extra       = $branchCount - 1;

    $delivery = FITPAL_DELIVERY_BASE_FEE
              + ($extra * FITPAL_DELIVERY_EXTRA_PER_BRANCH);

    $service  = FITPAL_SERVICE_FEE;

    $vat = round($subtotal * FITPAL_VAT_RATE, 2);

    return [
        'branch_count'   => $branchCount,
        'extra_branches' => $extra,
        'delivery_fee'   => round($delivery, 2),
        'service_fee'    => round($service, 2),
        'vat_rate'       => FITPAL_VAT_RATE,
        'vat'            => $vat,
        'total_fees'     => round($delivery + $service + $vat, 2),
    ];
}

/**
 * Convenience: just the VAT amount for a given subtotal.
 *
 * @param float $subtotal
 * @return float
 */
function getVatAmount(float $subtotal): float
{
    return round($subtotal * FITPAL_VAT_RATE, 2);
}

/* =============================================================
 * RIDER LIABILITY MATH (v2.5.0)
 * ============================================================= */

/**
 * Amount recorded as the rider's liability when they accept an
 * assignment for an order.
 *
 * The liability is the order's total: subtotal plus delivery fee
 * plus service fee plus VAT. It applies to every payment method.
 * It is the figure written to orders.rider_liability_amount by
 * recordRiderLiability() and the figure the earnings page displays
 * as "Order liability."
 *
 * On successful delivery the liability is cleared and the rider is
 * credited the delivery fee. On failure the liability is settled
 * against the rider's account as a debit.
 *
 * @param float $subtotal
 * @param float $deliveryFee
 * @param float $serviceFee
 * @param float $vat
 * @return float
 */
function calculateRiderLiability(
    float $subtotal,
    float $deliveryFee,
    float $serviceFee,
    float $vat
): float {
    return round($subtotal + $deliveryFee + $serviceFee + $vat, 2);
}

/**
 * Amount credited to the rider's financial account on successful
 * delivery. The rider earns the delivery fee.
 *
 * @param float $deliveryFee
 * @return float
 */
function calculateRiderPayout(float $deliveryFee): float
{
    return round($deliveryFee, 2);
}

/**
 * Amount credited to the restaurant's financial account on successful
 * delivery. The restaurant earns the food subtotal.
 *
 * @param float $subtotal
 * @return float
 */
function calculateRestaurantPayout(float $subtotal): float
{
    return round($subtotal, 2);
}