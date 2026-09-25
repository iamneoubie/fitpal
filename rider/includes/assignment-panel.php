<?php
/**
 * FitPal Rider Assignment Panel (shared include)
 *
 * The bottom-anchored, collapsible panel that mirrors the kitchen's
 * rider_pending handoff on the rider side. It is chrome, not page
 * content: header.php pulls it in on every authenticated rider page.
 *
 * Visibility contract
 * -------------------
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
 * The collapsed state is therefore the resting state when the rider
 * has no live assignments.
 *
 * The server renders .closed on first paint so the panel starts
 * collapsed and the toggle's aria-expanded="false" is honest from
 * the first frame. assignment-panel.js seeds its isOpen flag from
 * the DOM and is the only writer of the open/closed state after
 * that.
 *
 * ARIA notes
 * ----------
 * The wrapper carries aria-hidden="false" because the wrapper is
 * always present and the header bar inside it is a focusable
 * control.
 *
 * The availability pill is now a real <button>. It is keyboard
 * focusable and fires on Enter / Space for free. It carries:
 *   - aria-label  the current state ("Online" / "Offline" /
 *                 "Inactive"), kept in sync by the JS
 *   - aria-haspopup="dialog"  because activating it opens the
 *                 availability modal
 *
 * Inside the button there are two label spans:
 *   - .assignment-panel-status-text    the base state label,
 *                                      always in the accessibility
 *                                      tree
 *   - .assignment-panel-status-action  the hover label ("Go
 *                                      Online" etc.), aria-hidden
 *                                      because it duplicates the
 *                                      action already announced by
 *                                      the modal
 *
 * CSS toggles which span is visible on :hover and :focus-visible.
 * JS owns the text inside the action span, because the copy
 * depends on BOTH the online flag and whether the rider has a live
 * assignment, and only the JS tracks both.
 *
 * What it renders
 * ---------------
 *   - A collapsed bar at the bottom of the viewport with:
 *       [ icon + "Assignments" ]  [ availability pill ]  [ count ] [ chevron ]
 *   - An expanded body listing every live assignment.
 *   - An empty state when the rider has no live assignments.
 *   - Inline offline and ineligible hints.
 *   - An assignment notification modal.
 *   - An availability modal (Go Online / Go Offline / Blocked).
 *
 * Session use
 * -----------
 * Read only: $_SESSION['delivery_rider_id']. Never writes to session.
 *
 * Asset use
 * ---------
 * $assetBase is required and provided by header.php, which is always
 * included before this file.
 *
 * @package FitPal
 * @version 4.0 — The availability pill is now a <button> that opens
 *                a new availability modal. The modal has three
 *                shapes, all decided in JS: Go Online (primary),
 *                Go Offline (danger), and Blocked when the rider
 *                has a live assignment (info-only, no state change).
 *                The pill's label swaps on hover and focus through
 *                CSS; the action text is written by JS because the
 *                copy depends on both the online flag and the
 *                rider's assignment state. aria-live moved off the
 *                pill and onto the modal, where the announcement
 *                belongs. No change to the panel body, the row
 *                skeleton, the empty state, or the assignment
 *                notification modal.
 *
 *                (3.0: aria-hidden="false" on the wrapper. 2.0:
 *                three-zone header grid. 1.0: initial.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anonymous visitors get nothing. The panel is a rider-only surface.
if (empty($_SESSION['delivery_rider_id'])) {
    return;
}

$riderId = (int)$_SESSION['delivery_rider_id'];
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
    data-endpoint="../../rider/backend/handlers/assignment-handler.php" aria-hidden="false">

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
                    <span>You are offline. Go online from the dashboard to accept new assignments.</span>
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
     ============================================================ -->
<div class="assignment-notify-modal" id="assignmentNotifyModal" style="display: none;" role="dialog" aria-modal="true"
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
     data-variant and data-icon attributes:

       data-variant="primary"  data-icon="online"   → Go Online
       data-variant="danger"   data-icon="offline"  → Go Offline
       data-variant="neutral"  data-icon="blocked"  → Blocked

     The three SVGs are pre-rendered inside the icon circle; CSS
     shows only the one whose class matches data-icon. The confirm
     button's colour follows data-variant. The Cancel button is
     hidden in the blocked shape because there is nothing to
     cancel — the rider is being told, not asked.

     aria-live="polite" on the title element so the copy that JS
     writes into it is announced when the modal opens. The pill
     itself no longer carries aria-live; the announcement belongs
     here.
     ============================================================ -->
<div class="assignment-availability-modal" id="assignmentAvailabilityModal" style="display: none;"
    data-variant="primary" data-icon="online" role="dialog" aria-modal="true"
    aria-labelledby="assignmentAvailabilityTitle" aria-describedby="assignmentAvailabilityText">
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