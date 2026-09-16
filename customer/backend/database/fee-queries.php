<?php
/**
 * FitPal Fee Schedule
 *
 * Single source of truth for delivery, service, and VAT charges.
 * Both the checkout page (display) and createOrderFromCart()
 * (persistence) call these helpers so the number the customer sees
 * is the number that gets stored.
 *
 * VAT is ADDITIVE: it is charged on top of the subtotal and included
 * in the order total. If your business rule is inclusive VAT, change
 * the `$vat` return to 0 and adjust the display in checkout.php.
 *
 * @package FitPal
 * @version 1.1 — Adds VAT.
 */

declare(strict_types=1);

const FITPAL_DELIVERY_BASE_FEE         = 50.00;
const FITPAL_DELIVERY_EXTRA_PER_BRANCH = 30.00;
const FITPAL_SERVICE_FEE               = 5.00;

/**
 * VAT rate applied to the subtotal.
 * 12% is the standard Philippine VAT rate.
 */
const FITPAL_VAT_RATE = 0.12;

/**
 * Calculate all order-level fees from the number of distinct branches
 * in the cart and the cart subtotal.
 *
 * @param int   $branchCount  Distinct restaurant_branch_id count in the cart.
 *                            A value of 0 is treated as 1.
 * @param float $subtotal     Cart subtotal (sum of item price × quantity).
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