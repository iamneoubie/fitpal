# Web Project Structure

**Project:** fitpal
**Generated:** 2026-09-20 22:33:58
**Mode:** all

```
fitpal/
│
├── admin/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin-tables.css
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── profile.css
│   │   │   └── sign-in.css
│   │   └── ui/
│   │       └── js/
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── restaurants.js
│   │           ├── riders.js
│   │           └── sign-in.js
│   ├── backend/
│   │   ├── database/
│   │   │   ├── admin-connect.php
│   │   │   └── admin-queries.php
│   │   └── handlers/
│   │       ├── admin-handler.php
│   │       ├── sign-in-handler.php
│   │       └── sign-out-handler.php
│   ├── includes/
│   │   └── header.php
│   └── pages/
│       ├── customers.php
│       ├── dashboard.php
│       ├── profile.php
│       ├── restaurants.php
│       ├── riders.php
│       └── sign-in.php
├── customer/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── cart.css
│   │   │   ├── checkout.css
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── menu-filter.css
│   │   │   ├── menu-product.css
│   │   │   ├── menu.css
│   │   │   ├── orders.css
│   │   │   ├── product-detail.css
│   │   │   ├── profile.css
│   │   │   ├── queue-panel.css
│   │   │   ├── sign-in.css
│   │   │   ├── sign-up.css
│   │   │   └── wallet.css
│   │   └── ui/
│   │       └── js/
│   │           ├── cart.js
│   │           ├── checkout.js
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── menu.js
│   │           ├── orders.js
│   │           ├── product-detail.js
│   │           ├── profile.js
│   │           ├── queue-panel.js
│   │           ├── sign-in.js
│   │           ├── sign-out.js
│   │           ├── sign-up.js
│   │           └── wallet.js
│   ├── backend/
│   │   ├── database/
│   │   │   ├── address-queries.php
│   │   │   ├── branch-queries.php
│   │   │   ├── cart-queries.php
│   │   │   ├── customer-connect.php
│   │   │   ├── customer-queries.php
│   │   │   ├── dashboard-queries.php
│   │   │   ├── fee-queries.php
│   │   │   ├── order-queries.php
│   │   │   ├── product-queries.php
│   │   │   ├── queue-queries.php
│   │   │   ├── rider-queries.php
│   │   │   └── wallet-queries.php
│   │   └── handlers/
│   │       ├── address-handler.php
│   │       ├── cart-handler.php
│   │       ├── checkout-handler.php
│   │       ├── feedback-handler.php
│   │       ├── get-branch-handler.php
│   │       ├── order-handler.php
│   │       ├── place-order-handler.php
│   │       ├── queue-handler.php
│   │       ├── sign-in-handler.php
│   │       ├── sign-out-handler.php
│   │       ├── sign-up-handler.php
│   │       └── wallet-handler.php
│   ├── includes/
│   │   └── header.php
│   └── pages/
│       ├── cart.php
│       ├── checkout.php
│       ├── dashboard.php
│       ├── menu.php
│       ├── orders.php
│       ├── product-detail.php
│       ├── profile.php
│       ├── sign-in.php
│       ├── sign-up.php
│       └── wallet.php
├── logs/
│   ├── content-fetcher-configuration/
│   │   ├── all_path.py
│   │   ├── rider.py
│   │   ├── riders.py
│   │   ├── technical_path.py
│   │   └── tests_path.py
│   ├── instructions/
│   │   ├── test-create-guide.md
│   │   └── updating-fetcher-guide.md
│   ├── output/
│   │   ├── all_path_fetched_codebase.md
│   │   ├── project_structure.md
│   │   ├── rider_fetched_codebase.md
│   │   └── riders_fetched_codebase.md
│   ├── content-fetcher.py
│   └── tree-mapper.py
├── restaurant/
├── rider/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin-tables.css
│   │   │   ├── dashboard.css
│   │   │   ├── deliveries.css
│   │   │   ├── earnings.css
│   │   │   ├── header.css
│   │   │   ├── profile.css
│   │   │   ├── sign-in.css
│   │   │   └── sign-up.css
│   │   └── ui/
│   │       └── js/
│   │           ├── dashboard.js
│   │           ├── deliveries.js
│   │           ├── earnings.js
│   │           ├── header.js
│   │           ├── profile.js
│   │           ├── sign-in.js
│   │           └── sign-up.js
│   ├── backend/
│   │   ├── database/
│   │   │   ├── rider-connect.php
│   │   │   └── rider-queries.php
│   │   └── handlers/
│   │       ├── admin-handler.php
│   │       ├── message-handler.php
│   │       ├── rider-handler.php
│   │       ├── sign-in-handler.php
│   │       ├── sign-out-handler.php
│   │       └── sign-up-handler.php
│   ├── includes/
│   │   └── header.php
│   └── pages/
│       ├── dashboard.php
│       ├── deliveries.php
│       ├── earnings.php
│       ├── profile.php
│       ├── sign-in.php
│       └── sign-up.php
├── shared/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── about.css
│   │   │   ├── contact.css
│   │   │   ├── database-connect.css
│   │   │   ├── footer.css
│   │   │   ├── global.css
│   │   │   ├── header.css
│   │   │   ├── landing.css
│   │   │   ├── privacy-policy.css
│   │   │   └── terms-conditions.css
│   │   ├── images/
│   │   │   ├── brand/
│   │   │   │   ├── Logo.ico
│   │   │   │   └── Logo.png
│   │   │   ├── icons/
│   │   │   │   ├── about-empty.svg
│   │   │   │   ├── about-fill.svg
│   │   │   │   ├── add-circle-empty.svg
│   │   │   │   ├── add-line.svg
│   │   │   │   ├── add-to-queue.svg
│   │   │   │   ├── add.svg
│   │   │   │   ├── arrow-drop-down-line.svg
│   │   │   │   ├── arrow-drop-left-line (1).svg
│   │   │   │   ├── arrow-drop-left-line.svg
│   │   │   │   ├── arrow-drop-right-line.svg
│   │   │   │   ├── arrow-drop-up-line.svg
│   │   │   │   ├── arrow-go-back-line.svg
│   │   │   │   ├── arrow-left-circle-line.svg
│   │   │   │   ├── arrow-left-line.svg
│   │   │   │   ├── arrow-left-long-line.svg
│   │   │   │   ├── arrow-left-s-line.svg
│   │   │   │   ├── arrow-right-long-line.svg
│   │   │   │   ├── arrow-right-s-line.svg
│   │   │   │   ├── arrow-turn-back-line.svg
│   │   │   │   ├── bill-fill.svg
│   │   │   │   ├── bill-line.svg
│   │   │   │   ├── building.svg
│   │   │   │   ├── cancel.svg
│   │   │   │   ├── cart-arrow-downsvg.svg
│   │   │   │   ├── cart-arrow-up.svg
│   │   │   │   ├── cart-plus.svg
│   │   │   │   ├── cart-shopping-fast.svg
│   │   │   │   ├── cart-shopping.svg
│   │   │   │   ├── chart-line-up.svg
│   │   │   │   ├── close-circle-fill.svg
│   │   │   │   ├── close-circle-line.svg
│   │   │   │   ├── close-fill.svg
│   │   │   │   ├── close-large-fill.svg
│   │   │   │   ├── close-large-line.svg
│   │   │   │   ├── close-line.svg
│   │   │   │   ├── coin-fill.svg
│   │   │   │   ├── coin-line.svg
│   │   │   │   ├── community-general.svg
│   │   │   │   ├── contact-us-fill.svg
│   │   │   │   ├── contact-us-line.svg
│   │   │   │   ├── draggable.svg
│   │   │   │   ├── dropdown-list.svg
│   │   │   │   ├── edit.svg
│   │   │   │   ├── equalizer-line.svg
│   │   │   │   ├── facebook-mono.svg
│   │   │   │   ├── facebook.svg
│   │   │   │   ├── file-image-fill.svg
│   │   │   │   ├── file-image-line.svg
│   │   │   │   ├── file-user-fill.svg
│   │   │   │   ├── file-user-line.svg
│   │   │   │   ├── file-warning-fill.svg
│   │   │   │   ├── fill-form.svg
│   │   │   │   ├── focus-line.svg
│   │   │   │   ├── folder-user-fill.svg
│   │   │   │   ├── folder-user-line.svg
│   │   │   │   ├── funds-box-analytic-fill.svg
│   │   │   │   ├── funds-circle-analytic-fill.svg
│   │   │   │   ├── gallery-fill.svg
│   │   │   │   ├── github-mono.svg
│   │   │   │   ├── github.svg
│   │   │   │   ├── hamburger-menu.svg
│   │   │   │   ├── hand-coin-fill.svg
│   │   │   │   ├── hand-coin-line.svg
│   │   │   │   ├── history-line.svg
│   │   │   │   ├── id-card-fill.svg
│   │   │   │   ├── id-card-line.svg
│   │   │   │   ├── image-edit-fill.svg
│   │   │   │   ├── image-fill.svg
│   │   │   │   ├── image-line.svg
│   │   │   │   ├── image-upload-fill.svg
│   │   │   │   ├── info-card-fill.svg
│   │   │   │   ├── info-card-line.svg
│   │   │   │   ├── information-fill.svg
│   │   │   │   ├── instagram-mono.svg
│   │   │   │   ├── instagram.svg
│   │   │   │   ├── list-settings-fill.svg
│   │   │   │   ├── list-settings-line.svg
│   │   │   │   ├── list-view.svg
│   │   │   │   ├── location-fill.svg
│   │   │   │   ├── location-target-fill.svg
│   │   │   │   ├── logoutsvg.svg
│   │   │   │   ├── mail.svg
│   │   │   │   ├── menu-search-fill.svg
│   │   │   │   ├── more-horizonal-fill.svg
│   │   │   │   ├── more-horizontal-line.svg
│   │   │   │   ├── more-vertical-fill.svg
│   │   │   │   ├── more-vertical-line.svg
│   │   │   │   ├── multi-image-fill.svg
│   │   │   │   ├── order.svg
│   │   │   │   ├── package.svg
│   │   │   │   ├── pages-line.svg
│   │   │   │   ├── password-hide.svg
│   │   │   │   ├── password-unhide.svg
│   │   │   │   ├── people-team.svg
│   │   │   │   ├── phone-fill.svg
│   │   │   │   ├── phone-line.svg
│   │   │   │   ├── profile.svg
│   │   │   │   ├── qr-code-fill.svg
│   │   │   │   ├── qr-code-line.svg
│   │   │   │   ├── qr-scan-fill.svg
│   │   │   │   ├── qr-scan-line.svg
│   │   │   │   ├── question-fill.svg
│   │   │   │   ├── question-line.svg
│   │   │   │   ├── remove-circle-fillsvg
│   │   │   │   ├── remove-circle-line.svg
│   │   │   │   ├── remove-fill.svg
│   │   │   │   ├── remove-large-fill.svg
│   │   │   │   ├── remove-large-line.svg
│   │   │   │   ├── remove-line.svg
│   │   │   │   ├── reset.svg
│   │   │   │   ├── restaurant-fill.svg
│   │   │   │   ├── restaurant.svg
│   │   │   │   ├── save-empty.svg
│   │   │   │   ├── save-fill.svg
│   │   │   │   ├── search-line.svg
│   │   │   │   ├── star-empty.svg
│   │   │   │   ├── star-fill.svg
│   │   │   │   ├── subtract-fill.svg
│   │   │   │   ├── subtract-line.svg
│   │   │   │   ├── target-fill.svg
│   │   │   │   ├── time-fill.svg
│   │   │   │   ├── time-update.svg
│   │   │   │   ├── trash.svg
│   │   │   │   ├── update.svg
│   │   │   │   ├── updatesvg.svg
│   │   │   │   ├── user-minus-fill.svg
│   │   │   │   ├── user-minus-line.svg
│   │   │   │   ├── user-profile-circle.svg
│   │   │   │   ├── verified-badge-fill.svg
│   │   │   │   ├── verified-badge-line.svg
│   │   │   │   ├── verified-empty.svg
│   │   │   │   ├── verified-fill.svg
│   │   │   │   ├── wallet-fill.svg
│   │   │   │   ├── wallet-line.svg
│   │   │   │   ├── x-formerly-twitter.svg
│   │   │   │   ├── youtube-mono.svg
│   │   │   │   └── youtube.svg
│   │   │   ├── payment/
│   │   │   │   └── QR.jpg
│   │   │   ├── rider-image/
│   │   │   │   └── rider.png
│   │   │   └── showcase/
│   │   │       └── hero-image.png
│   │   └── ui/
│   │       └── js/
│   │           ├── contact.js
│   │           └── header.js
│   ├── backend/
│   │   └── database/
│   │       ├── database-connect.php
│   │       └── landing-queries.php
│   ├── includes/
│   │   ├── footer.php
│   │   ├── header.php
│   │   └── view-helpers.php
│   └── pages/
│       ├── about.php
│       ├── contact.php
│       ├── privacy-policy.php
│       └── terms-conditions.php
├── sql/
│   ├── sample/
│   │   └── seed-data.sql
│   └── database.sql
├── test/
│   └── customer/
│       ├── cart-test.php
│       ├── menu-test.php
│       ├── product-detail-test.php
│       ├── sign-in-test.php
│       └── sign-up-test.php
├── .gitignore
├── index.php
├── LICENSE
└── readme.md
```

## Summary

| File Type | Count |
|-----------|-------|
| HTML Files | 0 |
| PHP Files | 77 |
| CSS Files | 36 |
| JavaScript Files | 27 |
| JSON Files | 0 |
| Text/Markdown | 7 |
| Image Files | 141 |
| Other Files | 12 |

**Total Directories:** 54
**Total Files:** 299

---

*Generated by Web Project Tree Mapper*
*Script: tree-mapper.py*