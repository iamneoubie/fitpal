/**
 * FitPal Restaurant Logout Confirmation + Active-Order Guard
 *
 * Two responsibilities, both scoped to the restaurant role.
 *
 * 1. LOGOUT CONFIRMATION
 *    Intercepts clicks on [data-logout-trigger] elements and opens
 *    the #logoutModal rendered by restaurant/includes/header.php.
 *    The modal's confirm anchor navigates to sign-out-handler.php;
 *    Cancel closes the modal.
 *
 * 2. ACTIVE-ORDER GUARD
 *    Before opening the confirmation modal, this file asks the
 *    kitchen handler whether the branch still has live orders.
 *    A live order is anything in one of these statuses:
 *
 *        pending, preparing, rider_pending, picking_up, delivering
 *
 *    If the branch has at least one, the confirmation modal is NOT
 *    opened. Instead, a blocking modal is shown that names the
 *    count of active orders and refuses the sign-out. There is a
 *    single OK button; there is nothing to cancel.
 *
 * ---------------------------------------------------------------------
 * SCROLLBAR HIDING — HOW THE WHITE BAR AND THE PAGE SHIFT ARE FIXED
 * ---------------------------------------------------------------------
 * Two symptoms appeared while the modal was open:
 *
 *   - a tall white vertical strip on the right edge of the
 *     viewport, in the strip the browser reserves for the
 *     scrollbar gutter;
 *   - a visible horizontal shift of the page content behind the
 *     modal when the modal opened, and a matching shift back when
 *     it closed.
 *
 * Both came from the same place: the interaction between the
 * scrollbar-gutter reservation on <html> and the way the modal
 * removed the page's scrollbar.
 *
 * <html> carries `scrollbar-gutter: stable`. That reserves a strip
 * on the right edge of the viewport for the scrollbar, whether the
 * scrollbar is shown or hidden. Three consequences follow:
 *
 *   1. The layout box never changes width. Content does not shift
 *      when the scrollbar is hidden. That is the entire point of
 *      `scrollbar-gutter: stable`.
 *
 *   2. The reserved strip is painted by whatever <html>'s
 *      background is. If <html> has no background of its own, the
 *      browser falls back to its white canvas, which is the strip
 *      the user saw.
 *
 *   3. Adding `padding-right` to <body> on top of the reservation
 *      narrows <body>'s content box by the scrollbar width. A
 *      centered `.container` with `margin: 0 auto` then re-centers
 *      within the narrower box and shifts visibly to the left. On
 *      close, the padding is removed and the container shifts back.
 *      That is the "page moves weirdly" symptom.
 *
 * The correct fix has two parts and lives entirely in CSS:
 *
 *   - `html.modal-open { overflow: hidden !important }` hides the
 *     page scrollbar. The `scrollbar-gutter: stable` reservation
 *     keeps the layout box the same width, so nothing shifts.
 *
 *   - `html.modal-open { background: rgba(...) !important }` paints
 *     the reserved strip with the same tint the overlay carries.
 *     On a browser that honors the overflow rule there is no
 *     scrollbar to paint, so this declaration is inert. On a
 *     browser that does not, the strip is tinted, not white.
 *
 * This file therefore does NOT:
 *
 *   - measure the scrollbar width,
 *   - write padding-right on <body>,
 *   - restore padding-right on close.
 *
 * A prior revision did all three. The padding write was the direct
 * cause of the shift. The measurement and the restore existed only
 * to support the padding write, so they are removed with it.
 *
 * A single pair of helpers still owns the state change. Every open
 * path and every close path routes through them, so the
 * `modal-open` class cannot end up in the wrong state.
 *
 * ---------------------------------------------------------------------
 * FAIL-OPEN POLICY
 * ---------------------------------------------------------------------
 * If the server is unreachable, or returns a non-JSON body, or
 * returns a non-2xx status, or the endpoint is somehow not set,
 * the guard cannot decide. This file follows the rider and
 * customer precedent: do not block the user on an infrastructure
 * failure. The guard fires only when the server explicitly says
 * has_active is true.
 *
 * A null callback result — network error, non-JSON response, or an
 * unset helper — opens the confirmation modal as before.
 *
 * ---------------------------------------------------------------------
 * NO window.alert, window.confirm, OR window.prompt
 * ---------------------------------------------------------------------
 * Every message goes through a modal. Every failure is surfaced in
 * the UI, never through a browser dialog.
 *
 * @package FitPal
 * @version 5.0 — Body padding compensation removed. The
 *                  `modal-open` class on <html> and <body> is the
 *                  only state this file manages. CSS handles the
 *                  scrollbar hiding and the gutter tinting.
 *
 *                (4.0: modal-open class toggle. 3.3: removed
 *                --scrollbar-width write. 3.2: boot-time
 *                measurement. 3.1: guard on every branch-scoped
 *                page. 3.0: header globals, block modal,
 *                fallback.)
 */

(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var modal = document.getElementById("logoutModal");
    if (!modal) return;

    var confirmBtn = modal.querySelector(".logout-btn-confirm");
    var cancelNodes = modal.querySelectorAll("[data-logout-cancel]");
    var triggers = document.querySelectorAll("[data-logout-trigger]");

    /* ============================================================
           MODAL-OPEN CLASS TOGGLE
           ============================================================
           A single pair of helpers owns every state change to the
           `modal-open` class on <html> and <body>. No other code in
           this file writes either element's classes, style, or
           padding. CSS handles the rest. */

    function addModalOpen() {
      document.documentElement.classList.add("modal-open");
      document.body.classList.add("modal-open");
    }

    function removeModalOpen() {
      document.documentElement.classList.remove("modal-open");
      document.body.classList.remove("modal-open");
    }

    /* ============================================================
           CONFIRMATION MODAL
           ============================================================ */

    function openModal() {
      addModalOpen();
      modal.style.display = "flex";
      void modal.offsetWidth;
      modal.classList.add("active");
      if (confirmBtn) {
        setTimeout(function () {
          confirmBtn.focus();
        }, 80);
      }
    }

    function closeModal() {
      modal.classList.remove("active");
      setTimeout(function () {
        if (!modal.classList.contains("active")) {
          modal.style.display = "none";

          var blockModal = window.__fitpalRestaurantBlockModal;
          var blockOpen = blockModal && blockModal.classList.contains("active");

          if (!blockOpen) {
            removeModalOpen();
          }
        }
      }, 220);
    }

    cancelNodes.forEach(function (node) {
      node.addEventListener("click", function (e) {
        e.preventDefault();
        closeModal();
      });
    });

    /* ============================================================
           BLOCKING MODAL
           ============================================================ */

    var HEADER_BLOCK_MODAL_ID = "restaurantBlockSignOutModal";
    var FALLBACK_BLOCK_MODAL_ID = "restaurantBlockLogoutModal";
    var BLOCK_TEXT_ID = "restaurantBlockSignOutText";
    var FALLBACK_BLOCK_TEXT_ID = "restaurantBlockLogoutText";

    function ensureBlockModal() {
      var headerModal = document.getElementById(HEADER_BLOCK_MODAL_ID);
      if (headerModal) {
        window.__fitpalRestaurantBlockModal = headerModal;
        return headerModal;
      }

      if (window.__fitpalRestaurantBlockModal) {
        return window.__fitpalRestaurantBlockModal;
      }

      var existing = document.getElementById(FALLBACK_BLOCK_MODAL_ID);
      if (existing) {
        window.__fitpalRestaurantBlockModal = existing;
        return existing;
      }

      var assetBase = "../../shared/";
      var kitchenPage = document.getElementById("kitchenPage");
      if (kitchenPage && kitchenPage.dataset.assetBase) {
        assetBase = kitchenPage.dataset.assetBase;
      }

      var wrapper = document.createElement("div");
      wrapper.id = FALLBACK_BLOCK_MODAL_ID;
      wrapper.className = "logout-modal";
      wrapper.style.display = "none";
      wrapper.setAttribute("role", "dialog");
      wrapper.setAttribute("aria-modal", "true");
      wrapper.setAttribute("aria-labelledby", "restaurantBlockLogoutTitle");

      var overlay = document.createElement("div");
      overlay.className = "logout-modal-overlay";
      overlay.setAttribute("data-block-logout-cancel", "");
      wrapper.appendChild(overlay);

      var content = document.createElement("div");
      content.className = "logout-modal-content";

      var iconWrap = document.createElement("div");
      iconWrap.className = "logout-modal-icon";
      iconWrap.setAttribute("aria-hidden", "true");

      var icon = document.createElement("img");
      icon.src = assetBase + "assets/images/icons/error-warning-line.svg";
      icon.alt = "";
      icon.width = 28;
      icon.height = 28;
      icon.onerror = function () {
        this.onerror = null;
        this.src = assetBase + "assets/images/icons/information-fill.svg";
      };
      iconWrap.appendChild(icon);
      content.appendChild(iconWrap);

      var title = document.createElement("p");
      title.className = "logout-modal-title";
      title.id = "restaurantBlockLogoutTitle";
      title.textContent = "You can't sign out right now";
      content.appendChild(title);

      var text = document.createElement("p");
      text.className = "logout-modal-text";
      text.id = FALLBACK_BLOCK_TEXT_ID;
      content.appendChild(text);

      var actions = document.createElement("div");
      actions.className = "logout-modal-actions";

      var okBtn = document.createElement("button");
      okBtn.type = "button";
      okBtn.className = "logout-btn-cancel";
      okBtn.setAttribute("data-block-logout-cancel", "");
      okBtn.textContent = "OK";
      actions.appendChild(okBtn);

      content.appendChild(actions);
      wrapper.appendChild(content);

      document.body.appendChild(wrapper);

      window.__fitpalRestaurantBlockModal = wrapper;
      return wrapper;
    }

    function writeBlockMessage(textEl, count) {
      if (!textEl) return;

      if (count === 1) {
        textEl.textContent =
          "You have 1 active order. Finish it before signing out, " +
          "or hand it off to another staff member.";
      } else {
        textEl.textContent =
          "You have " +
          count +
          " active orders. Finish them before " +
          "signing out, or hand them off to another staff member.";
      }
    }

    function openBlockModal(activeCount) {
      var blockModal = ensureBlockModal();
      if (!blockModal) return;

      var count = parseInt(activeCount, 10) || 0;

      var textEl =
        blockModal.querySelector("#" + BLOCK_TEXT_ID) ||
        blockModal.querySelector("#" + FALLBACK_BLOCK_TEXT_ID);

      writeBlockMessage(textEl, count);

      addModalOpen();
      blockModal.style.display = "flex";
      void blockModal.offsetWidth;
      blockModal.classList.add("active");

      if (blockModal.dataset.handlersBound !== "1") {
        blockModal.dataset.handlersBound = "1";

        blockModal
          .querySelectorAll(
            "[data-block-logout-cancel], [data-block-signout-cancel]",
          )
          .forEach(function (node) {
            node.addEventListener("click", function (e) {
              e.preventDefault();
              closeBlockModal();
            });
          });
      }
    }

    function closeBlockModal() {
      var blockModal = window.__fitpalRestaurantBlockModal;
      if (!blockModal) return;

      blockModal.classList.remove("active");
      setTimeout(function () {
        if (!blockModal.classList.contains("active")) {
          blockModal.style.display = "none";

          if (!modal.classList.contains("active")) {
            removeModalOpen();
          }
        }
      }, 220);
    }

    /* ============================================================
           ESCAPE HANDLER
           ============================================================ */

    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;

      if (modal.classList.contains("active")) {
        closeModal();
      }

      var blockModal = window.__fitpalRestaurantBlockModal;
      if (blockModal && blockModal.classList.contains("active")) {
        closeBlockModal();
      }
    });

    /* ============================================================
           GUARD TRANSPORT
           ============================================================ */

    function readGuardConfig() {
      var endpoint = window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT;
      var token = window.RESTAURANT_CSRF_TOKEN;

      if (typeof endpoint !== "string" || endpoint === "") return null;
      if (typeof token !== "string" || token === "") return null;

      return { endpoint: endpoint, token: token };
    }

    function fetchActiveOrderCount(callback) {
      var config = readGuardConfig();
      if (!config) {
        callback(null);
        return;
      }

      var body = new URLSearchParams();
      body.append("action", "active_orders_count");
      body.append("csrf_token", config.token);

      fetch(config.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      })
        .then(function (response) {
          if (!response.ok) {
            return null;
          }
          return response.json().catch(function () {
            return null;
          });
        })
        .then(function (payload) {
          if (!payload || payload.status !== "success") {
            callback(null);
            return;
          }

          var count = parseInt(payload.count, 10);
          if (isNaN(count) || count < 0) {
            callback(null);
            return;
          }

          callback({
            has_active: payload.has_active === true || count > 0,
            count: count,
          });
        })
        .catch(function () {
          callback(null);
        });
    }

    function runGuard(callback) {
      if (typeof window.checkActiveOrders === "function") {
        try {
          window.checkActiveOrders(function (result) {
            callback(result || null);
          });
        } catch (err) {
          callback(null);
        }
        return;
      }

      var config = readGuardConfig();
      if (!config) {
        callback(null);
        return;
      }

      fetchActiveOrderCount(callback);
    }

    /* ============================================================
           TRIGGER DISPATCH
           ============================================================ */

    triggers.forEach(function (trigger) {
      trigger.addEventListener("click", function (e) {
        e.preventDefault();

        var hasKitchenHelper = typeof window.checkActiveOrders === "function";
        var config = readGuardConfig();

        if (!hasKitchenHelper && !config) {
          openModal();
          return;
        }

        if (trigger.dataset.guardPending === "1") return;
        trigger.dataset.guardPending = "1";

        runGuard(function (result) {
          trigger.dataset.guardPending = "";

          if (result && result.has_active && result.count > 0) {
            openBlockModal(result.count);
            return;
          }

          openModal();
        });
      });
    });

    /* ============================================================
           BACK / FORWARD CACHE
           ============================================================ */

    window.addEventListener("pageshow", function (e) {
      if (!e.persisted) return;

      if (modal.classList.contains("active")) {
        closeModal();
      }

      var blockModal = window.__fitpalRestaurantBlockModal;
      if (blockModal && blockModal.classList.contains("active")) {
        closeBlockModal();
      }
    });
  });
})();