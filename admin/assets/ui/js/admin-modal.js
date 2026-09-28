/**
 * FitPal Admin Modal Controller — Tabbed + Sub-Tab Wizard
 *
 * Three-level navigation:
 *
 *   Level 1 — top tabs       .admin-modal-tab[data-tab]
 *                             .admin-modal-tab-panel[data-tab-panel]
 *
 *   Level 2 — sub-tabs       .admin-subtab[data-subtab]
 *                             .admin-modal-phase[data-phase]
 *
 * Both levels are directly clickable. The footer arrows are kept
 * only as an optional previous/next aid for modals that declare
 * top tabs without sub-tabs (the not-yet-migrated restaurants and
 * riders modals). Modals that declare sub-tabs hide the footer
 * arrows, since the sub-tab row is the primary navigation.
 *
 * Modal contract
 * --------------
 *   <div class="admin-modal">
 *     <div class="admin-modal-backdrop" data-close-url="..."></div>
 *     <div class="admin-modal-panel">
 *       <div class="admin-modal-header">...</div>
 *
 *       <div class="admin-modal-tabs">
 *         <button class="admin-modal-tab active" data-tab="personal">Personal Info</button>
 *         <button class="admin-modal-tab" data-tab="addresses">Addresses</button>
 *       </div>
 *
 *       <div class="admin-modal-panel-body">
 *         <div class="admin-modal-tab-panel active" data-tab-panel="personal">
 *           <div class="admin-subtabs">
 *             <button class="admin-subtab active" data-subtab="credentials">Credentials</button>
 *             <button class="admin-subtab" data-subtab="profile">Profile</button>
 *           </div>
 *           <div class="admin-modal-phase active" data-phase="credentials">...</div>
 *           <div class="admin-modal-phase" data-phase="profile">...</div>
 *         </div>
 *         <div class="admin-modal-tab-panel" data-tab-panel="addresses">
 *           ... (no subtabs — flat list)
 *         </div>
 *       </div>
 *
 *       <div class="admin-modal-tab-footer">
 *         <button class="tab-arrow" data-phase-prev>...</button>
 *         <div class="admin-modal-footer-center">
 *           <form class="admin-modal-footer-actions">...</form>
 *         </div>
 *         <button class="tab-arrow" data-phase-next>...</button>
 *       </div>
 *     </div>
 *   </div>
 *
 * @package FitPal
 * @version 8.0 — Sub-phases are driven by directly-clickable
 *                sub-tabs. The footer arrows are hidden whenever a
 *                modal declares any sub-tabs, and are only used as
 *                a top-tab fallback on modals that do not.
 */

(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    // ============================================================
    // SCROLL LOCK
    // ============================================================
    function lockScroll() {
      document.body.style.overflow = "hidden";
    }
    function unlockScroll() {
      document.body.style.overflow = "";
    }

    // ============================================================
    // MODAL CONTROLLER
    // ============================================================
    function initializeWizard(modal) {
      var tabBar = modal.querySelector(".admin-modal-tabs");
      var panelBody = modal.querySelector(".admin-modal-panel-body");
      var tabButtons = modal.querySelectorAll(".admin-modal-tab");
      var prevArrow = modal.querySelector("[data-phase-prev], [data-tab-prev]");
      var nextArrow = modal.querySelector("[data-phase-next], [data-tab-next]");

      if (!tabBar || !panelBody || tabButtons.length === 0) {
        return;
      }

      // Does the modal use sub-tabs anywhere?
      var subtabRows = modal.querySelectorAll(".admin-subtabs");
      var usesSubTabs = subtabRows.length > 0;

      // Hide the footer arrows when sub-tabs are in play — the
      // sub-tab row is the primary navigation and the arrows would
      // just be visual noise.
      if (usesSubTabs) {
        if (prevArrow) prevArrow.style.display = "none";
        if (nextArrow) nextArrow.style.display = "none";
      }

      var activeTabIndex = 0;

      function activateTab(index) {
        if (index < 0 || index >= tabButtons.length) return;
        activeTabIndex = index;

        tabButtons.forEach(function (btn, i) {
          btn.classList.toggle("active", i === index);
        });

        var panels = panelBody.querySelectorAll("[data-tab-panel]");
        panels.forEach(function (panel, i) {
          panel.classList.toggle("active", i === index);
        });

        panelBody.scrollTop = 0;
      }

      function activateSubtab(panel, subtabKey) {
        if (!panel || !subtabKey) return;

        var subtabButtons = panel.querySelectorAll(".admin-subtab");
        subtabButtons.forEach(function (btn) {
          btn.classList.toggle(
            "active",
            btn.getAttribute("data-subtab") === subtabKey,
          );
        });

        var subPhases = panel.querySelectorAll(".admin-modal-phase");
        subPhases.forEach(function (p) {
          p.classList.toggle(
            "active",
            p.getAttribute("data-phase") === subtabKey,
          );
        });

        panelBody.scrollTop = 0;
      }

      // Top tab clicks
      tabButtons.forEach(function (btn, i) {
        btn.addEventListener("click", function (e) {
          e.preventDefault();
          activateTab(i);
        });
      });

      // Sub-tab clicks — per panel
      panelBody.querySelectorAll("[data-tab-panel]").forEach(function (panel) {
        var subButtons = panel.querySelectorAll(".admin-subtab");
        if (subButtons.length === 0) return;

        subButtons.forEach(function (btn) {
          btn.addEventListener("click", function (e) {
            e.preventDefault();
            activateSubtab(panel, btn.getAttribute("data-subtab"));
          });
        });
      });

      // Footer arrows are top-tab navigation only (used when the
      // modal has no sub-tabs at all).
      if (!usesSubTabs) {
        if (prevArrow) {
          prevArrow.addEventListener("click", function (e) {
            e.preventDefault();
            activateTab(activeTabIndex - 1);
          });
          prevArrow.disabled = activeTabIndex === 0;
        }
        if (nextArrow) {
          nextArrow.addEventListener("click", function (e) {
            e.preventDefault();
            activateTab(activeTabIndex + 1);
          });
          nextArrow.disabled = activeTabIndex === tabButtons.length - 1;
        }
      }

      modal.goToPhase = function (index) {
        activateTab(index);
      };

      activateTab(0);
    }

    // ============================================================
    // OPEN / CLOSE
    // ============================================================
    function openModal(modalId) {
      var modal = document.getElementById(modalId);
      if (!modal) return;

      lockScroll();
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");

      var focusTarget = modal.querySelector("[data-autofocus]");
      if (focusTarget && typeof focusTarget.focus === "function") {
        setTimeout(function () {
          focusTarget.focus();
        }, 80);
      }
    }

    function closeModal(modal) {
      if (!modal) return;
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      if (!document.querySelector(".admin-modal.is-open")) {
        unlockScroll();
      }
    }

    // ============================================================
    // EVENT DELEGATION
    // ============================================================
    document.addEventListener("click", function (e) {
      var opener = e.target.closest("[data-open-modal]");
      if (opener) {
        e.preventDefault();
        openModal(opener.getAttribute("data-open-modal"));
        return;
      }

      var closer = e.target.closest("[data-close-modal]");
      if (closer) {
        e.preventDefault();
        closeModal(closer.closest(".admin-modal"));
        return;
      }

      if (e.target.classList.contains("admin-modal-backdrop")) {
        var backdropUrl = e.target.getAttribute("data-close-url");
        if (backdropUrl) {
          window.location.href = backdropUrl;
        } else {
          closeModal(e.target.closest(".admin-modal"));
        }
        return;
      }
    });

    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      var open = document.querySelector(".admin-modal.is-open");
      if (open) closeModal(open);
    });

    // ============================================================
    // INIT
    // ============================================================
    document.querySelectorAll(".admin-modal").forEach(function (modal) {
      initializeWizard(modal);
      if (modal.classList.contains("is-open")) {
        lockScroll();
      }
    });

    window.AdminModal = {
      open: openModal,
      close: function (modalId) {
        var modal = document.getElementById(modalId);
        if (modal) closeModal(modal);
      },
      goToPhase: function (modalId, index) {
        var modal = document.getElementById(modalId);
        if (modal && typeof modal.goToPhase === "function") {
          modal.goToPhase(index);
        }
      },
    };
  });
})();