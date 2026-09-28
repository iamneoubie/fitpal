/**
 * FitPal Admin — Moderation Button Renderer
 *
 * Single source of truth for the state→button mapping used by the
 * admin moderation modals:
 *
 *   - Rider verification      (riders.php)
 *   - Restaurant verification (restaurants.php)
 *   - Customer account toggle (customers.php)
 *
 * Why this exists:
 *   The button set that should be visible in a moderation modal is a
 *   pure function of the entity's current state. Rendering that set in
 *   PHP means the mapping is duplicated across three pages, and any
 *   future AJAX transition (verify without a reload) would need a
 *   second copy in JS. This module owns the mapping once, reads the
 *   entity's state from a data attribute on the modal, and renders the
 *   correct set of buttons.
 *
 * Contract with the page:
 *   Each modal that wants dynamic footer buttons must render:
 *
 *     <div class="admin-modal" data-moderation-modal>
 *       ...
 *       <div class="admin-modal-footer-actions"
 *            data-moderation-actions
 *            data-entity="rider|restaurant|customer"
 *            data-entity-id="123"
 *            data-state="pending|verified|denied|suspended|active|inactive"
 *            data-action-url="../backend/handlers/admin-handler.php"
 *            data-csrf-token="...">
 *       </div>
 *     </div>
 *
 *   The module finds every [data-moderation-actions] on the page,
 *   reads the four data attributes, and replaces the contents of the
 *   container with the correct <form> buttons.
 *
 * Button styling:
 *   Per the project's button rules (§7):
 *     Confirm / positive  → .btn-primary   (white text)
 *     Neutral             → .btn-black     (white text)
 *     Negative            → .btn-danger    (white text)
 *
 *   Verify        → primary
 *   Approve       → primary
 *   Deny          → danger
 *   Suspend       → danger
 *   Remove Suspension → neutral (returns to pending; not a positive
 *                       confirmation, not a destructive action)
 *   Activate      → primary
 *   Deactivate    → danger
 *
 * All buttons are <button type="submit"> inside their own <form>,
 * so each posts to the handler with the correct action + status.
 * The handler validates CSRF and redirects, exactly as before.
 *
 * @package FitPal
 * @version 1.0
 */
(function () {
  "use strict";

  // ============================================================
  // STATE → BUTTON MAPS
  // ============================================================
  //
  // Each entry is a button descriptor:
  //   { label, value, style, action }
  //
  //   label  — visible button text
  //   value  — the `status` (verification) or `activate`
  //            (customer) value POSTed to the handler
  //   style  — "primary" | "danger" | "neutral"
  //   action — the `action` POST field sent to the handler
  //            (only needed when it differs from the container's
  //             default; containers set data-action so we can
  //             override per button if needed)

  var MODERATION_MAP = {
    rider: {
      pending: [
        { label: "Verify",  value: "verified",  style: "primary", action: "set_rider_verification" },
        { label: "Deny",    value: "denied",    style: "danger",  action: "set_rider_verification" },
      ],
      verified: [
        { label: "Suspend", value: "suspended", style: "danger",  action: "set_rider_verification" },
      ],
      denied: [
        { label: "Verify",  value: "verified",  style: "primary", action: "set_rider_verification" },
        { label: "Suspend", value: "suspended", style: "danger",  action: "set_rider_verification" },
      ],
      suspended: [
        { label: "Remove Suspension", value: "pending", style: "neutral", action: "set_rider_verification" },
      ],
    },

    restaurant: {
      pending: [
        { label: "Verify",  value: "verified",  style: "primary", action: "set_restaurant_verification" },
        { label: "Deny",    value: "denied",    style: "danger",  action: "set_restaurant_verification" },
      ],
      verified: [
        { label: "Suspend", value: "suspended", style: "danger",  action: "set_restaurant_verification" },
      ],
      denied: [
        { label: "Verify",  value: "verified",  style: "primary", action: "set_restaurant_verification" },
        { label: "Suspend", value: "suspended", style: "danger",  action: "set_restaurant_verification" },
      ],
      suspended: [
        { label: "Remove Suspension", value: "pending", style: "neutral", action: "set_restaurant_verification" },
      ],
    },

    customer: {
      active: [
        { label: "Deactivate", value: "0", style: "danger",  action: "toggle_customer" },
      ],
      inactive: [
        { label: "Activate",   value: "1", style: "primary", action: "toggle_customer" },
      ],
    },
  };

  // ============================================================
  // STYLE → CLASS
  // ============================================================

  function styleClass(style) {
    switch (style) {
      case "primary": return "btn btn-primary btn-sm";
      case "danger":  return "btn btn-danger btn-sm";
      case "neutral": return "btn btn-neutral btn-sm";
      default:        return "btn btn-outline btn-sm";
    }
  }

  // ============================================================
  // VALUE FIELD NAME
  // ============================================================
  //
  // Riders and restaurants POST `status`; customers POST `activate`.
  // The map above stores the value, and the entity type decides the
  // field name.

  function valueFieldName(entity) {
    return entity === "customer" ? "activate" : "status";
  }

  // ============================================================
  // ENTITY ID FIELD NAME
  // ============================================================

  function idFieldName(entity) {
    switch (entity) {
      case "rider":      return "rider_id";
      case "restaurant": return "restaurant_id";
      case "customer":   return "customer_id";
      default:           return "id";
    }
  }

  // ============================================================
  // ESCAPE
  // ============================================================

  function escapeAttr(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  // ============================================================
  // RENDER
  // ============================================================

  function renderButtons(container) {
    var entity   = container.getAttribute("data-entity");
    var entityId = container.getAttribute("data-entity-id");
    var state    = container.getAttribute("data-state");
    var url      = container.getAttribute("data-action-url");
    var csrf     = container.getAttribute("data-csrf-token");
    var redirect = container.getAttribute("data-redirect-to") || "";

    if (!entity || !entityId || !state || !url || !csrf) {
      return;
    }

    var map = MODERATION_MAP[entity];
    if (!map) return;

    var buttons = map[state];
    if (!buttons) return;

    var idField = idFieldName(entity);
    var valField = valueFieldName(entity);

    var html = "";

    buttons.forEach(function (btn) {
      html += '<form method="POST" action="' + escapeAttr(url) + '">';
      html += '<input type="hidden" name="csrf_token" value="' + escapeAttr(csrf) + '">';
      html += '<input type="hidden" name="action" value="' + escapeAttr(btn.action) + '">';
      html += '<input type="hidden" name="' + escapeAttr(idField) + '" value="' + escapeAttr(entityId) + '">';
      html += '<input type="hidden" name="' + escapeAttr(valField) + '" value="' + escapeAttr(btn.value) + '">';
      if (redirect) {
        html += '<input type="hidden" name="redirect_to" value="' + escapeAttr(redirect) + '">';
      }
      html += '<button type="submit" class="' + styleClass(btn.style) + '">';
      html += escapeAttr(btn.label);
      html += "</button>";
      html += "</form>";
    });

    container.innerHTML = html;
  }

  // ============================================================
  // INIT
  // ============================================================

  document.addEventListener("DOMContentLoaded", function () {
    var containers = document.querySelectorAll("[data-moderation-actions]");
    for (var i = 0; i < containers.length; i++) {
      renderButtons(containers[i]);
    }
  });
})();