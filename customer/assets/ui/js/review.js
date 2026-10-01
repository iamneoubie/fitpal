/**
 * FitPal Customer Review Page — client behaviour.
 *
 * Owns everything interactive on customer/pages/review.php:
 *
 *   - Tab switching between Products, Restaurant, and Rider. The
 *     three tabs are directly clickable and the Back / Next
 *     buttons at the bottom of each panel step through them in
 *     the declared order.
 *
 *   - Star pickers. Each .review-card carries five .review-star
 *     buttons. Clicking star N sets that subject's score in the
 *     in-memory ratings map and marks stars 1..N .active.
 *
 *   - Per-subject comments. Each .review-card carries one
 *     .review-comment-input textarea. As the customer types, the
 *     text is captured into the comments map keyed by "type:id".
 *     Empty strings are dropped.
 *
 *   - Submit. Reads every scored subject and every non-empty
 *     comment from the two in-memory maps, builds a FormData
 *     payload, and POSTs to feedback-handler.php. On success,
 *     reloads the page.
 *
 * ---------------------------------------------------------------------
 * STATE MODEL
 * ---------------------------------------------------------------------
 *   ratings = {
 *       'product:45':   5,
 *       'product:46':   4,
 *       'restaurant:7': 5,
 *       'rider:3':      5
 *   }
 *
 *   comments = {
 *       'product:45':   'The salmon was fresh.',
 *       'restaurant:7': 'Fast prep.',
 *       'rider:3':      'Very polite.'
 *   }
 *
 * Keys are `type:id`. A subject with no score is absent from
 * ratings. A subject with no comment is absent from comments, or
 * has an empty-string value that the submit path filters out.
 *
 * ---------------------------------------------------------------------
 * PAYLOAD
 * ---------------------------------------------------------------------
 *   action      'submit_review'
 *   csrf_token  window.FITPAL_REVIEW.csrfToken
 *   order_id    window.FITPAL_REVIEW.orderId
 *
 *   ratings[N][type]
 *   ratings[N][id]
 *   ratings[N][score]
 *
 *   comments[type:id]   the comment text, one field per non-empty
 *                       comment. The key matches the ratings key
 *                       so the handler can resolve the subject
 *                       without a second lookup.
 *
 * ---------------------------------------------------------------------
 * SUBMIT BEHAVIOUR
 * ---------------------------------------------------------------------
 *   client-side guard:
 *     at least one rating OR at least one non-empty comment. A
 *     score with no comment is fine; a comment with no score is
 *     fine.
 *
 *   success:
 *     window.location.reload().
 *
 *   failure:
 *     write the message into #reviewSubmitError, restore the button.
 *
 * ---------------------------------------------------------------------
 * NO DRAFT
 * ---------------------------------------------------------------------
 * Nothing is written until Submit. A refresh discards state.
 *
 * @package FitPal
 * @version 2.0 — Three-tab layout, per-subject comments, Back / Next
 *                navigation.
 *
 *                (1.0: four-tab wizard, single overall comment.)
 */
(function () {
    'use strict';

    var CONFIG = window.FITPAL_REVIEW || {};

    var ORDER_ID    = parseInt(CONFIG.orderId || '0', 10);
    var CSRF_TOKEN  = CONFIG.csrfToken || '';
    var HANDLER_URL = CONFIG.handlerUrl
        || '../backend/handlers/feedback-handler.php';
    var ALREADY_REVIEWED = CONFIG.alreadyReviewed === true;
    var TAB_ORDER = Array.isArray(CONFIG.tabOrder) && CONFIG.tabOrder.length > 0
        ? CONFIG.tabOrder
        : ['products', 'restaurant', 'rider'];

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    // ---- state ----
    var ratings  = Object.create(null);
    var comments = Object.create(null);

    // ================================================================
    // TAB SWITCHING
    // ================================================================

    function initTabs() {
        var tabStrip = qs('#reviewTabs');
        if (!tabStrip) return;

        var tabs   = qsa('.review-tab', tabStrip);
        var panels = qsa('.review-panel');
        if (tabs.length === 0 || panels.length === 0) return;

        function activate(target) {
            if (!target) return;

            tabs.forEach(function (tab) {
                var isActive = tab.getAttribute('data-tab-target') === target;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                var isActive = panel.getAttribute('data-tab-panel') === target;
                panel.classList.toggle('active', isActive);
            });

            // The submit error slot lives inside the Rider panel.
            // Clear it whenever the customer leaves that panel, so a
            // refusal from a previous attempt does not linger.
            if (target !== 'rider') {
                clearSubmitError();
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activate(tab.getAttribute('data-tab-target'));
            });
        });

        // Back / Next buttons — each carries the target tab name.
        qsa('.review-nav-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-next-target')
                    || btn.getAttribute('data-back-target')
                    || '';

                if (target === '') return;

                // Buttons with data-next-target that point past the
                // last tab are Submit buttons, handled elsewhere.
                activate(target);
            });
        });

        // Expose for the submit path to return to Products on error.
        return activate;
    }

    // ================================================================
    // STAR PICKERS
    // ================================================================

    function renderStars(card, score) {
        qsa('.review-star', card).forEach(function (star) {
            var value = parseInt(star.getAttribute('data-score') || '0', 10);
            star.classList.toggle('active', value <= score);
        });
    }

    function initStarPickers() {
        qsa('.review-card').forEach(function (card) {
            var type = card.getAttribute('data-rating-type') || '';
            var id   = card.getAttribute('data-rating-id')   || '';
            if (type === '' || id === '') return;

            var key   = type + ':' + id;
            var stars = qsa('.review-star', card);
            if (stars.length === 0) return;

            if (typeof ratings[key] === 'number') {
                renderStars(card, ratings[key]);
            }

            stars.forEach(function (star) {
                star.addEventListener('click', function () {
                    var value = parseInt(star.getAttribute('data-score') || '0', 10);
                    if (value < 1 || value > 5) return;

                    ratings[key] = value;
                    renderStars(card, value);
                    clearSubmitError();
                });
            });
        });
    }

    // ================================================================
    // PER-SUBJECT COMMENT INPUTS
    // ================================================================

    function initCommentInputs() {
        qsa('.review-card').forEach(function (card) {
            var type     = card.getAttribute('data-rating-type') || '';
            var id       = card.getAttribute('data-rating-id')   || '';
            var textarea = qs('.review-comment-input', card);
            if (!textarea) return;
            if (type === '' || id === '') return;

            var key = type + ':' + id;

            textarea.addEventListener('input', function () {
                var value = textarea.value;
                if (value.trim() === '') {
                    delete comments[key];
                } else {
                    comments[key] = value;
                }
                clearSubmitError();
            });

            // Reflect any pre-existing value (there is none on a
            // fresh load because the wizard is not rendered when a
            // review already exists). Kept for symmetry with
            // renderStars().
            if (typeof comments[key] === 'string') {
                textarea.value = comments[key];
            }
        });
    }

    // ================================================================
    // SUBMIT
    // ================================================================

    function getSubmitErrorSlot() {
        return qs('#reviewSubmitError');
    }

    function clearSubmitError() {
        var slot = getSubmitErrorSlot();
        if (slot) slot.textContent = '';
    }

    function showSubmitError(message) {
        var slot = getSubmitErrorSlot();
        if (slot) slot.textContent = message;
    }

    function buildPayload() {
        var body = new FormData();

        body.append('action', 'submit_review');
        body.append('csrf_token', CSRF_TOKEN);
        body.append('order_id', String(ORDER_ID));

        var ratingIndex = 0;
        Object.keys(ratings).forEach(function (key) {
            var parts = key.split(':');
            if (parts.length !== 2) return;

            var type  = parts[0];
            var id    = parts[1];
            var score = ratings[key];

            if (typeof score !== 'number' || score < 1 || score > 5) return;
            if (type === '' || id === '') return;

            body.append('ratings[' + ratingIndex + '][type]',  type);
            body.append('ratings[' + ratingIndex + '][id]',    id);
            body.append('ratings[' + ratingIndex + '][score]', String(score));

            ratingIndex++;
        });

        // Comments travel as a flat map keyed by "type:id". The
        // handler decodes the map and stores it in the feedback
        // envelope's JSON content field.
        Object.keys(comments).forEach(function (key) {
            var text = comments[key];
            if (typeof text !== 'string') return;
            var trimmed = text.trim();
            if (trimmed === '') return;

            body.append('comments[' + key + ']', trimmed);
        });

        return body;
    }

    function ratedCount() {
        return Object.keys(ratings).length;
    }

    function commentCount() {
        var n = 0;
        Object.keys(comments).forEach(function (k) {
            if (typeof comments[k] === 'string' && comments[k].trim() !== '') {
                n++;
            }
        });
        return n;
    }

    function initSubmit() {
        var submitBtn = qs('#reviewSubmitBtn');
        if (!submitBtn) return;

        var defaultLabel = submitBtn.getAttribute('data-label-default') || 'Submit Review';
        var busyLabel    = submitBtn.getAttribute('data-label-busy')    || 'Submitting…';

        submitBtn.addEventListener('click', function () {
            clearSubmitError();

            if (ratedCount() === 0 && commentCount() === 0) {
                showSubmitError(
                    'Please rate at least one thing or leave at least one comment before submitting.'
                );
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = busyLabel;

            var body = buildPayload();

            fetch(HANDLER_URL, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        try {
                            return JSON.parse(text);
                        } catch (parseError) {
                            console.error(
                                '[review.js] Non-JSON response:',
                                response.status,
                                text.slice(0, 200)
                            );
                            return {
                                status:  'error',
                                message: 'Server returned an unexpected response (' +
                                         response.status + '). Please try again.'
                            };
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.location.reload();
                        return;
                    }

                    submitBtn.disabled = false;
                    submitBtn.textContent = defaultLabel;

                    showSubmitError(
                        (data && data.message)
                            ? data.message
                            : 'Could not submit your review. Please try again.'
                    );
                })
                .catch(function (error) {
                    console.error('[review.js] Submit failed:', error);

                    submitBtn.disabled = false;
                    submitBtn.textContent = defaultLabel;

                    showSubmitError(
                        'A network error occurred. Please check your connection and try again.'
                    );
                });
        });
    }

    // ================================================================
    // BOOTSTRAP
    // ================================================================

    document.addEventListener('DOMContentLoaded', function () {
        if (ALREADY_REVIEWED) return;

        initTabs();
        initStarPickers();
        initCommentInputs();
        initSubmit();
    });
})();