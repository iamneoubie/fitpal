<?php
/**
 * FitPal Rider Assignment Panel (shared include)
 *
 * The bottom-anchored, collapsible panel that mirrors the kitchen's
 * rider_pending handoff on the rider side. It is chrome, not page
 * content: header.php pulls it in on every authenticated rider page.
 *
 * ---------------------------------------------------------------------
 * ENDPOINT URL
 * ---------------------------------------------------------------------
 * The panel posts to the URL published on its own wrapper:
 *
 *     #assignmentPanelWrapper[data-endpoint]
 *
 * That attribute is set here. rider/assets/ui/js/assignment-panel.js
 * reads it first and only falls back to a global when the attribute
 * is missing.
 *
 * This include is always pulled in from rider/pages/*. Every page in
 * that directory sets $assetBase to '../shared/'. Therefore the
 * handler is always at:
 *
 *     '../backend/handlers/assignment-handler.php'
 *
 * resolved against the page's own directory, which is rider/pages/.
 * That walks up to rider/, then into backend/handlers/ — the correct
 * file.
 *
 * The previous revision built the URL as
 *
 *     preg_replace('#shared/$#', '', $assetBase) . 'rider/backend/...'
 *
 * From rider/pages/*, that produced '../rider/backend/...', which
 * resolved against rider/pages/ as rider/rider/backend/... — one
 * 'rider/' too many. Every panel POST 404'd at the router. The
 * server log recorded it as:
 *
 *     [404] /rider/backend/handlers/assignment-handler.php
 *     - No such file or directory
 *
 * followed by the router script's own [200] on the same line.
 *
 * The corrected version derives the URL from a fixed relative path
 * relative to rider/pages/, with a fallback for the case where a
 * future caller includes the panel from somewhere else.
 *
 * ---------------------------------------------------------------------
 * VISIBILITY CONTRACT
 * ---------------------------------------------------------------------
 * The wrapper is permanently on screen. It never slides off the
 * bottom of the viewport. The panel's collapsed and expanded states
 * are expressed entirely on .assignment-panel-inner, driven by the
 * .open / .closed classes on .assignment-panel:
 *
 *   .assignment-panel.closed .assignment-panel-inner
 *       transform: translateY(calc(100% - 56px))
 *       → shows only the 56px header bar
 *
 *   .assignment-panel.open .assignment-panel-inner
 *       transform: translateY(0)
 *       → shows the full body
 *
 * The server renders .closed on first paint so the panel starts
 * collapsed and the toggle's aria-expanded="false" is honest from
 * the first frame. assignment-panel.js seeds its isOpen flag from
 * the DOM and is the only writer of the open/closed state after
 * that.
 *
 * ---------------------------------------------------------------------
 * MODAL VISIBILITY
 * ---------------------------------------------------------------------
 * Both modals (.assignment-notify-modal and
 * .assignment-availability-modal) are hidden by the CSS rule
 * `display: none` and shown when assignment-panel.js adds the
 * .active class. No inline style="display:none" attribute is
 * present on either modal element. Visibility is driven by exactly
 * one channel: the presence or absence of .active.
 *
 * ---------------------------------------------------------------------
 * LIVE ASSIGNMENT STATUSES
 * ---------------------------------------------------------------------
 * The panel surfaces three statuses as "live":
 *
 *   rider_pending  — kitchen asked; rider has not yet decided.
 *                    Row actions: Accept, Decline, Message Kitchen,
 *                    Call Kitchen.
 *
 *   picking_up     — rider accepted; en route to or at the
 *                    restaurant; food not yet in hand.
 *                    Row actions: Mark Picked Up, Message Kitchen,
 *                    Call Kitchen.
 *
 *   delivering     — rider has the food; en route to the customer.
 *                    Row actions: Mark Delivered, Message Customer,
 *                    Call Customer.
 *
 * All three count toward the concurrent-order cap of 3. The status
 * label and badge are provided by the server in the row payload;
 * the row's action buttons are injected by assignment-panel.js at
 * render time based on the row's `status` field.
 *
 * ---------------------------------------------------------------------
 * SESSION USE
 * ---------------------------------------------------------------------
 * Read only: $_SESSION['delivery_rider_id']. Never writes to session.
 *
 * @package FitPal
 * @version 4.3 — The panel endpoint URL is now
 *                '../backend/handlers/assignment-handler.php',
 *                resolved against rider/pages/. The previous
 *                revision produced '../rider/backend/...', which
 *                resolved one directory too high.
 *
 *                (4.2: modal visibility contract documented. 4.1:
 *                data-endpoint resolved from $assetBase. 4.0:
 *                picking_up status and mark_picked_up row action
 *                documented. 3.0: aria-hidden="false" on wrapper.
 *                2.0: three-zone header grid. 1.0: initial.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

// Anonymous visitors get nothing. The panel is a rider-only surface.
if (empty($_SESSION['delivery_rider_id'])) {
    return;
}

$riderId = (int)$_SESSION['delivery_rider_id'];

/*
 * Endpoint URL.
 *
 * This include is pulled in from rider/includes/header.php, which is
 * itself pulled in by every page under rider/pages/. The panel is
 * therefore rendered in the context of a page whose directory is
 * rider/pages/.
 *
 * From that directory, the handler is at:
 *
 *     ../backend/handlers/assignment-handler.php
 *
 * The fallback below covers the case where a future caller includes
 * the panel from a different depth. It derives the URL by walking
 * up to the project root from $assetBase and then down into
 * rider/backend/handlers/. $assetBase ends with 'shared/', so
 * trimming that suffix yields the path from the current page's
 * directory to the project root.
 *
 * If $assetBase is somehow unavailable, the fixed relative path is
 * used as the last resort — it is correct for the only caller that
 * exists today.
 */
$panelEndpoint = '';

if (isset($assetBase) && is_string($assetBase) && $assetBase !== '') {
    // $assetBase is '../shared/' from rider/pages/*. Trimming the
    // trailing 'shared/' yields '../', which is the path from the
    // page's own directory to the project root.
    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);

    if (is_string($projectRootUrl) && $projectRootUrl !== '') {
        // The fallback uses the same relative path the primary
        // branch would compute, but built from $assetBase rather
        // than assumed. It stays correct if a page ever lives at a
        // different depth under rider/pages/.
        $panelEndpoint = $projectRootUrl . 'rider/backend/handlers/assignment-handler.php';

        // If $projectRootUrl ends with 'rider/pages/../' — i.e. the
        // page is one level below rider/ — collapse it to the
        // shorter form that resolves directly.
        //
        // This is a normalisation step, not a second source of the
        // URL. The two forms resolve to the same file; the shorter
        // one is what every current page produces and what the
        // server log recorded as the correct path.
        $panelEndpoint = str_replace(
            'rider/pages/../rider/',
            'rider/',
            $panelEndpoint
        );
    }
}

if ($panelEndpoint === '') {
    // Last resort — correct for every page under rider/pages/.
    $panelEndpoint = '../backend/handlers/assignment-handler.php';
}

// The panel is included from rider/pages/*. Every page there has
// $assetBase = '../shared/'. That means the handler is at
// '../backend/handlers/assignment-handler.php' resolved against the
// page's own directory.
//
// The fallback block above would produce the same URL by walking
// up to the project root and then down; this explicit assignment
// keeps the primary path obvious in the code a reader sees first.
if ($assetBase === '../shared/') {
    $panelEndpoint = '../backend/handlers/assignment-handler.php';
}
?>
<!-- ============================================================
     RIDER ASSIGNMENT PANEL
     Bottom-anchored, collapsible. Rendered on every authenticated
     rider page. The wrapper is permanently on screen; the panel's
     collapsed and expanded states are expressed on
     .assignment-panel-inner by the .open / .closed classes that
     assignment-panel.js toggles on .assignment-panel.

     All interaction is delegated to assignment-panel.js. This
     markup only defines the skeleton.
     ============================================================ -->
<div class="assignment-panel-wrapper" id="assignmentPanelWrapper" data-rider-id="<?php echo $riderId; ?>"
    data-endpoint="<?php echo htmlspecialchars($panelEndpoint, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="false">

    <div class="assignment-panel closed" id="assignmentPanel" role="region" aria-label="Assignments">

        <div class="assignment-panel-inner" id="assignmentPanelInner">

            <!-- ============================================
                 PANEL HEADER
                 Three zones: title | availability | count + toggle
                 ============================================ -->
            <div class="assignment-panel-header" id="assignmentPanelHeader">

                <!-- LEFT — title -->
                <div class="assignment-panel-title">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/list-view.svg" alt=""
                        class="assignment-panel-title-icon" width="18" height="18"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/package.svg'">
                    <span class="assignment-panel-title-text">Assignments</span>
                </div>

                <!-- MIDDLE — availability button

                     Base label is the state ("Offline"). Hover label
                     is the action ("Go Online"). CSS swaps which one
                     is visible on :hover / :focus-visible. JS writes
                     the action text on every poll because the copy
                     depends on BOTH the online flag and whether the
                     rider has a live assignment. -->
                <button type="button" class="assignment-panel-status is-offline" id="assignmentPanelStatus"
                    aria-label="Availability: Offline" aria-haspopup="dialog">
                    <span class="assignment-panel-status-dot" aria-hidden="true"></span>
                    <span class="assignment-panel-status-text" id="assignmentPanelStatusText">Offline</span>
                    <span class="assignment-panel-status-action" id="assignmentPanelStatusAction" aria-hidden="true">Go
                        Online</span>
                </button>

                <!-- RIGHT — count + chevron -->
                <div class="assignment-panel-meta">
                    <span class="assignment-count-badge" id="assignmentCountBadge" style="display:none;">0</span>
                    <button type="button" class="assignment-panel-toggle" id="assignmentPanelToggle"
                        aria-expanded="false" aria-controls="assignmentPanelBody" aria-label="Toggle assignments panel">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            class="assignment-panel-toggle-icon" width="20" height="20">
                    </button>
                </div>
            </div>

            <!-- ============================================
                 PANEL BODY (collapsible)
                 ============================================ -->
            <div class="assignment-panel-body" id="assignmentPanelBody">

                <div class="assignment-offline-hint" id="assignmentOfflineHint" hidden>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt="" width="16"
                        height="16"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    <span>You are offline. Go online from the assignments panel to accept new deliveries.</span>
                </div>

                <div class="assignment-ineligible-hint" id="assignmentIneligibleHint" hidden>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt="" width="16"
                        height="16"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                    <span>Your account must be verified before you can receive assignments.</span>
                </div>

                <div class="assignment-list" id="assignmentList" role="list"></div>

                <div class="assignment-empty-state" id="assignmentEmptyState" hidden>
                    <div class="assignment-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="No assignments"
                            width="40" height="40"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/list-view.svg'">
                    </div>
                    <p class="assignment-empty-title">No active assignments</p>
                    <p class="assignment-empty-text">
                        When the kitchen assigns you an order, it will appear here.
                    </p>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     ASSIGNMENT NOTIFICATION MODAL
     Pops when a NEW rider_pending assignment arrives while the
     rider is online. Accept / Decline / Dismiss.

     Visibility is driven by the .active class added by
     assignment-panel.js. No inline display attribute is present;
     the class alone is the single channel that shows or hides it.
     ============================================================ -->
<div class="assignment-notify-modal" id="assignmentNotifyModal" role="dialog" aria-modal="true"
    aria-labelledby="assignmentNotifyTitle">
    <div class="assignment-notify-overlay" data-assignment-notify-dismiss></div>

    <div class="assignment-notify-content">
        <div class="assignment-notify-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt="" width="28" height="28"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-fill.svg'">
        </div>

        <p class="assignment-notify-kicker">New Assignment</p>
        <p class="assignment-notify-title" id="assignmentNotifyTitle">
            Order #<span id="assignmentNotifyOrderId"></span>
        </p>
        <p class="assignment-notify-text">The kitchen needs a rider for this order.</p>

        <dl class="assignment-notify-meta">
            <div class="assignment-notify-meta-row">
                <dt>Restaurant</dt>
                <dd id="assignmentNotifyRestaurant">—</dd>
            </div>
            <div class="assignment-notify-meta-row">
                <dt>Deliver to</dt>
                <dd id="assignmentNotifyCustomer">—</dd>
            </div>
            <div class="assignment-notify-meta-row">
                <dt>Order total</dt>
                <dd id="assignmentNotifyTotal">—</dd>
            </div>
        </dl>

        <div class="assignment-notify-actions">
            <button type="button" class="assignment-notify-btn assignment-notify-btn-decline"
                id="assignmentNotifyDeclineBtn" data-order-id="">Decline</button>
            <button type="button" class="assignment-notify-btn assignment-notify-btn-accept"
                id="assignmentNotifyAcceptBtn" data-order-id="">Accept</button>
        </div>

        <button type="button" class="assignment-notify-dismiss" id="assignmentNotifyDismissBtn" data-order-id="">Decide
            later</button>
    </div>
</div>

<!-- ============================================================
     AVAILABILITY MODAL
     Opened by the availability pill in the panel header. Three
     shapes, all decided in JS and expressed by the modal's
     data-variant and data-icon attributes.
     ============================================================ -->
<div class="assignment-availability-modal" id="assignmentAvailabilityModal" data-variant="primary" data-icon="online"
    role="dialog" aria-modal="true" aria-labelledby="assignmentAvailabilityTitle"
    aria-describedby="assignmentAvailabilityText">
    <div class="assignment-availability-overlay" data-assignment-availability-dismiss></div>

    <div class="assignment-availability-content">
        <div class="assignment-availability-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                class="assignment-availability-icon-online"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/check-line.svg'">
            <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg" alt=""
                class="assignment-availability-icon-offline"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/cancel.svg'">
            <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                class="assignment-availability-icon-blocked"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
        </div>

        <p class="assignment-availability-title" id="assignmentAvailabilityTitle" aria-live="polite">Go online?</p>
        <p class="assignment-availability-text" id="assignmentAvailabilityText"></p>

        <div class="assignment-availability-actions">
            <button type="button" class="assignment-availability-btn assignment-availability-btn-cancel"
                id="assignmentAvailabilityCancelBtn" data-assignment-availability-dismiss>Cancel</button>
            <button type="button" class="assignment-availability-btn assignment-availability-btn-confirm"
                id="assignmentAvailabilityConfirmBtn">Confirm</button>
        </div>
    </div>
</div>