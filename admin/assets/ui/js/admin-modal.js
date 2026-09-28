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
 * Both levels are directly clickable. The footer arrows now WRAP:
 * reaching the last item advances to the first, and reaching the
 * first item steps back to the last. This applies to whichever
 * walk set is currently active:
 *
 *   - If the active top tab has sub-tabs, the arrows walk the
 *     sub-tabs and wrap within that strip.
 *   - If the active top tab has no sub-tabs (like Addresses),
 *     the arrows walk the top tabs and wrap within that strip.
 *
 * Arrows are only disabled when the walk set has fewer than two
 * items, because wrapping makes the ends no longer terminal.
 *
 * @package FitPal
 * @version 10.0 — Arrows wrap instead of clamping at the ends.
 *                Previously prev was disabled at index 0 and next
 *                was disabled at the last index. Now both arrows
 *                step cyclically, so a reviewer can loop through
 *                every phase without having to reverse direction
 *                at the end. Arrows are only disabled when the
 *                active walk set has zero or one item.
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

      var activeTabIndex = 0;

      function getActivePanel() {
        var panels = panelBody.querySelectorAll("[data-tab-panel]");
        return panels[activeTabIndex] || null;
      }

      function getActiveSubtabIndex(panel) {
        if (!panel) return -1;
        var subs = panel.querySelectorAll(".admin-subtab");
        for (var i = 0; i < subs.length; i++) {
          if (subs[i].classList.contains("active")) return i;
        }
        return -1;
      }

      function activateTab(index) {
        var count = tabButtons.length;
        if (count === 0) return;

        // Wrap into range rather than clamping.
        index = ((index % count) + count) % count;
        activeTabIndex = index;

        tabButtons.forEach(function (btn, i) {
          btn.classList.toggle("active", i === index);
        });

        var panels = panelBody.querySelectorAll("[data-tab-panel]");
        panels.forEach(function (panel, i) {
          panel.classList.toggle("active", i === index);
        });

        panelBody.scrollTop = 0;
        updateArrowState();
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
        updateArrowState();
      }

      // Decide what the arrows should do right now.
      //
      // Because arrows wrap, they are only disabled when the
      // current walk set has fewer than two items — there is
      // nothing to wrap between in that case.
      function updateArrowState() {
        var panel = getActivePanel();
        var subs = panel ? panel.querySelectorAll(".admin-subtab") : [];
        var walkCount = subs.length > 0 ? subs.length : tabButtons.length;
        var canWalk = walkCount > 1;

        if (prevArrow) prevArrow.disabled = !canWalk;
        if (nextArrow) nextArrow.disabled = !canWalk;
      }

      // Determine which walk set is active right now and return
      // a descriptor so prev/next share the same dispatch logic.
      function getWalkSet() {
        var panel = getActivePanel();
        var subs = panel ? panel.querySelectorAll(".admin-subtab") : [];
        if (subs.length > 0) {
          return {
            kind: "subtab",
            panel: panel,
            items: subs,
            index: getActiveSubtabIndex(panel),
          };
        }
        return {
          kind: "tab",
          panel: null,
          items: tabButtons,
          index: activeTabIndex,
        };
      }

      function arrowPrev() {
        var walk = getWalkSet();
        var count = walk.items.length;
        if (count < 2) return;

        var target = ((walk.index - 1) % count + count) % count;

        if (walk.kind === "subtab") {
          var btn = walk.items[target];
          activateSubtab(walk.panel, btn.getAttribute("data-subtab"));
        } else {
          activateTab(target);
        }
      }

      function arrowNext() {
        var walk = getWalkSet();
        var count = walk.items.length;
        if (count < 2) return;

        var target = (walk.index + 1) % count;

        if (walk.kind === "subtab") {
          var btn = walk.items[target];
          activateSubtab(walk.panel, btn.getAttribute("data-subtab"));
        } else {
          activateTab(target);
        }
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

      if (prevArrow) {
        prevArrow.addEventListener("click", function (e) {
          e.preventDefault();
          arrowPrev();
        });
      }
      if (nextArrow) {
        nextArrow.addEventListener("click", function (e) {
          e.preventDefault();
          arrowNext();
        });
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