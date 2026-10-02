# Web Project Structure

**Project:** fitpal
**Generated:** 2026-10-02 23:06:10
**Mode:** all

```
fitpal/
│
├── admin/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin-shared.css
│   │   │   ├── customers.css
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── profile.css
│   │   │   ├── restaurants.css
│   │   │   ├── riders.css
│   │   │   └── sign-in.css
│   │   └── ui/
│   │       └── js/
│   │           ├── admin-modal.js
│   │           ├── admin-moderation-buttons.js
│   │           ├── customers.js
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── logout.js
│   │           ├── profile.js
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
│   │   ├── admin-csrf-token.php
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
│   │   │   ├── customer-orders.css
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── menu-filter.css
│   │   │   ├── menu-product.css
│   │   │   ├── menu.css
│   │   │   ├── order-receipt.css
│   │   │   ├── order-tracking.css
│   │   │   ├── product-detail.css
│   │   │   ├── profile.css
│   │   │   ├── queue-panel.css
│   │   │   ├── review.css
│   │   │   ├── sign-in.css
│   │   │   ├── sign-up.css
│   │   │   └── wallet.css
│   │   └── ui/
│   │       └── js/
│   │           ├── cart.js
│   │           ├── checkout.js
│   │           ├── customer-order.js
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── logout.js
│   │           ├── menu.js
│   │           ├── order-tracking.js
│   │           ├── product-detail.js
│   │           ├── profile.js
│   │           ├── queue-panel.js
│   │           ├── review.js
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
│   │   │   ├── customer-order-queries.php
│   │   │   ├── customer-queries.php
│   │   │   ├── dashboard-queries.php
│   │   │   ├── product-queries.php
│   │   │   ├── queue-queries.php
│   │   │   ├── rider-queries.php
│   │   │   ├── tracking-queries.php
│   │   │   └── wallet-queries.php
│   │   └── handlers/
│   │       ├── address-handler.php
│   │       ├── cart-handler.php
│   │       ├── checkout-handler.php
│   │       ├── customer-order-handler.php
│   │       ├── feedback-handler.php
│   │       ├── feedback-queries.php
│   │       ├── get-branch-handler.php
│   │       ├── message-handler.php
│   │       ├── place-order-handler.php
│   │       ├── profile-handler.php
│   │       ├── queue-handler.php
│   │       ├── sign-in-handler.php
│   │       ├── sign-out-handler.php
│   │       ├── sign-up-handler.php
│   │       └── wallet-handler.php
│   ├── includes/
│   │   ├── customer-csrf-token.php
│   │   └── header.php
│   └── pages/
│       ├── cart.php
│       ├── checkout.php
│       ├── dashboard.php
│       ├── image-debug.php
│       ├── menu.php
│       ├── order-receipt.php
│       ├── order-tracking.php
│       ├── orders.php
│       ├── product-detail.php
│       ├── profile.php
│       ├── review.php
│       ├── sign-in.php
│       ├── sign-up.php
│       └── wallet.php
├── logs/
│   ├── bugs/
│   │   └── rider-resto.md
│   ├── content-fetcher-configuration/
│   │   ├── all_path.py
│   │   ├── dir-cutomer.py
│   │   ├── dir-menu-product-detail.py
│   │   ├── dir-restaurant.py
│   │   ├── dir-rider.py
│   │   ├── dir-shared.py
│   │   └── technical.py
│   ├── enhancement/
│   │   └── logic.md
│   ├── flowchart/
│   │   └── flow.md
│   ├── instructions/
│   │   ├── general.md
│   │   ├── general_v1.md
│   │   ├── test-create-guide.md
│   │   └── updating-fetcher-guide.md
│   ├── logic/
│   │   └── business-logic.md
│   ├── output/
│   │   ├── dir-shared_fetched_codebase.md
│   │   ├── directories_customer_fetched_codebase.md
│   │   ├── directories_restaurant_fetched_codebase.md
│   │   ├── directories_rider_fetched_codebase.md
│   │   └── technical_fetched_codebase.md
│   ├── tree-mapper/
│   │   └── project_structure.md
│   ├── vscode/
│   │   ├── imports/
│   │   │   └── VS Code Profile/
│   │   │       ├── custom-vscode-script.js
│   │   │       └── custom-vscode.css
│   │   ├── extensions.md
│   │   ├── keybindings.json
│   │   └── user-setting.json
│   ├── content-fetcher.py
│   ├── tree-mapper.py
│   └── update-config.py
├── restaurant/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── kitchen-orders.css
│   │   │   ├── profile.css
│   │   │   ├── sign-in.css
│   │   │   └── sign-up.css
│   │   └── ui/
│   │       └── js/
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── kitchen-order.js
│   │           ├── kitchen-realtime.js
│   │           ├── logout.js
│   │           ├── profile.js
│   │           ├── restaurant-chat-modal.js
│   │           ├── sign-in.js
│   │           └── sign-up.js
│   ├── backend/
│   │   ├── database/
│   │   │   ├── chat-queries.php
│   │   │   ├── kitchen-order-queries.php
│   │   │   ├── product-queries.php
│   │   │   ├── restaurant-connect.php
│   │   │   └── restaurant-queries.php
│   │   └── handlers/
│   │       ├── branch-lookup-handler.php
│   │       ├── chat-handler.php
│   │       ├── kitchen-order-handler.php
│   │       ├── profile-handler.php
│   │       ├── sign-in-handler.php
│   │       ├── sign-out-handler.php
│   │       └── sign-up-handler.php
│   ├── includes/
│   │   ├── chat-modal.php
│   │   ├── header.php
│   │   └── restaurant-csrf-token.php
│   └── pages/
│       ├── dashboard.php
│       ├── kitchen.php
│       ├── profile.php
│       ├── sign-in.php
│       └── sign-up.php
├── rider/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── assignment-panel.css
│   │   │   ├── dashboard.css
│   │   │   ├── deliveries.css
│   │   │   ├── earnings.css
│   │   │   ├── header.css
│   │   │   ├── profile.css
│   │   │   ├── sign-in.css
│   │   │   └── sign-up.css
│   │   └── ui/
│   │       └── js/
│   │           ├── assignment-panel.js
│   │           ├── dashboard.js
│   │           ├── deliveries.js
│   │           ├── earnings.js
│   │           ├── header.js
│   │           ├── logout.js
│   │           ├── profile.js
│   │           ├── rider-chat-modal.js
│   │           ├── sign-in.js
│   │           └── sign-up.js
│   ├── backend/
│   │   ├── database/
│   │   │   ├── product-queries.php
│   │   │   ├── rider-assignment-queries.php
│   │   │   └── rider-connect.php
│   │   └── handlers/
│   │       ├── assignment-handler.php
│   │       ├── message-handler.php
│   │       ├── rider-handler.php
│   │       ├── sign-in-handler.php
│   │       ├── sign-out-handler.php
│   │       └── sign-up-handler.php
│   ├── includes/
│   │   ├── assignment-panel.php
│   │   ├── header.php
│   │   ├── rider-chat-modal.php
│   │   └── rider-csrf-token.php
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
│   │   │   │   ├── arrow-left-double-fill.svg
│   │   │   │   ├── arrow-left-double-line.svg
│   │   │   │   ├── arrow-left-line.svg
│   │   │   │   ├── arrow-left-long-line.svg
│   │   │   │   ├── arrow-left-s-line.svg
│   │   │   │   ├── arrow-right-double-fill.svg
│   │   │   │   ├── arrow-right-double-line.svg
│   │   │   │   ├── arrow-right-long-line.svg
│   │   │   │   ├── arrow-right-s-line.svg
│   │   │   │   ├── arrow-turn-back-line.svg
│   │   │   │   ├── bill-fill.svg
│   │   │   │   ├── bill-line.svg
│   │   │   │   ├── building.svg
│   │   │   │   ├── cancel.svg
│   │   │   │   ├── car-fill.svg
│   │   │   │   ├── car-line.svg
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
│   │   │   │   ├── error-warning-fill.svg
│   │   │   │   ├── error-warning-line.svg
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
│   │   │   │   ├── riding-fill.svg
│   │   │   │   ├── riding-line.svg
│   │   │   │   ├── save-empty.svg
│   │   │   │   ├── save-fill.svg
│   │   │   │   ├── search-line.svg
│   │   │   │   ├── star-empty.svg
│   │   │   │   ├── star-fill.svg
│   │   │   │   ├── subtract-fill.svg
│   │   │   │   ├── subtract-line.svg
│   │   │   │   ├── target-fill.svg
│   │   │   │   ├── taxi-fill.svg
│   │   │   │   ├── taxi-line.svg
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
│   │   │   ├── manifest/
│   │   │   │   ├── drivers-license/
│   │   │   │   │   ├── drivers-license-1.jpg
│   │   │   │   │   ├── drivers-license-10.jpg
│   │   │   │   │   ├── drivers-license-2.jpg
│   │   │   │   │   ├── drivers-license-3.jpg
│   │   │   │   │   ├── drivers-license-4.jpg
│   │   │   │   │   ├── drivers-license-5.jpg
│   │   │   │   │   ├── drivers-license-6.jpg
│   │   │   │   │   ├── drivers-license-7.jpg
│   │   │   │   │   ├── drivers-license-8.jpg
│   │   │   │   │   └── drivers-license-9.jpg
│   │   │   │   ├── national-id/
│   │   │   │   │   ├── national-id-1.jpg
│   │   │   │   │   ├── national-id-2.jpg
│   │   │   │   │   └── national-id-3.jpg
│   │   │   │   ├── permits/
│   │   │   │   │   ├── business-permit.png
│   │   │   │   │   └── sanitary-permit.jpg
│   │   │   │   ├── products/
│   │   │   │   │   └── restaurant/
│   │   │   │   │       ├── asian-fusion-fit/
│   │   │   │   │       │   ├── 001-gluten-free-salmon-roll/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   ├── image-2.png
│   │   │   │   │       │   │   ├── image-3.jpg
│   │   │   │   │       │   │   └── image-4.jpg
│   │   │   │   │       │   ├── 002-grilled-fish-greens/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 003-matcha-banana-smotthie/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 004-nori-hand-roll/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 005-osaka-rice-bowl/
│   │   │   │   │       │   │   └── image-1.png
│   │   │   │   │       │   ├── 006-rainbow-poke-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 007-seaweed-sesame-salad/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 008-spicy-tuna-roll/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 009-tokyo-noodle-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   └── 010-wok-tossed-vegetables/
│   │   │   │   │       │       └── image-1.png
│   │   │   │   │       ├── green-bowl-cafe/
│   │   │   │   │       │   ├── 001-berry-almond/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 002-classic-vegan-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 003-edamame-citrus-salad/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 004-garden-harvest-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 005-market-greens-salad/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 006-morning-power-smoothie/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 007-seaside-poke-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 008-sunrise-breakfast-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   ├── 009-superfood-buddha-bowl/
│   │   │   │   │       │   │   ├── image-1.png
│   │   │   │   │       │   │   └── image-2.png
│   │   │   │   │       │   └── 010-zucchini-noodle-pesto/
│   │   │   │   │       │       ├── image-1.png
│   │   │   │   │       │       └── image-2.png
│   │   │   │   │       └── keto-kitchen/
│   │   │   │   │           ├── 001-chicken parmesan plate/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 002-egg avocado bowl/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 003-keto butcher plate/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 004-keto cauliflower pizza/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 005-keto garden salad/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 006-keto power bowl/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 007-keto-smash-burger/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 008-keto-steak-plate/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           ├── 009-salmon-dill-plate/
│   │   │   │   │           │   ├── image-1.png
│   │   │   │   │           │   └── image-2.png
│   │   │   │   │           └── 010-shrimp-scampi-zoodles/
│   │   │   │   │               ├── image-1.png
│   │   │   │   │               └── image-2.png
│   │   │   │   └── profiles/
│   │   │   │       ├── profile-1.jpg
│   │   │   │       ├── profile-10.jpg
│   │   │   │       ├── profile-2.jpg
│   │   │   │       ├── profile-3.jpg
│   │   │   │       ├── profile-4.jpg
│   │   │   │       ├── profile-5.jpg
│   │   │   │       ├── profile-6.jpg
│   │   │   │       ├── profile-7.jpg
│   │   │   │       ├── profile-8.jpg
│   │   │   │       └── profile-9.jpg
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
│   │   ├── database/
│   │   │   ├── database-connect.php
│   │   │   ├── fee-queries.php
│   │   │   ├── landing-queries.php
│   │   │   └── order-transaction-queries.php
│   │   └── handlers/
│   │       └── order-transaction-handler.php
│   ├── includes/
│   │   ├── footer.php
│   │   ├── header.php
│   │   ├── public-csrf-token.php
│   │   ├── session-activity.php
│   │   └── session-bootstrap.php
│   ├── pages/
│   │   ├── about.php
│   │   ├── contact.php
│   │   ├── privacy-policy.php
│   │   └── terms-conditions.php
│   └── uploads/
│       ├── customer/
│       │   └── profiles/
│       │       └── 1/
│       │           └── 10_02_2026_0.jpg
│       ├── restaurant/
│       │   └── permits/
│       │       └── 10/
│       │           ├── 10_02_2026_0.jpg
│       │           ├── 10_02_2026_1.png
│       │           ├── 10_02_2026_2.jpg
│       │           └── 10_02_2026_3.png
│       └── rider/
│           ├── documents/
│           │   └── 4/
│           │       ├── 10_02_2026_0.jpg
│           │       └── 10_02_2026_1.jpg
│           └── profiles/
│               └── 4/
│                   ├── 10_02_2026_0.jpg
│                   └── 10_02_2026_1.jpg
├── sql/
│   ├── seed/
│   │   ├── test/
│   │   │   └── orders.sql
│   │   ├── all-in-one.sql
│   │   ├── seed-1-core.sql
│   │   ├── seed-2-content.sql
│   │   └── seed-3-paths.sql
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
| PHP Files | 115 |
| CSS Files | 49 |
| JavaScript Files | 48 |
| JSON Files | 2 |
| Text/Markdown | 16 |
| Image Files | 247 |
| Other Files | 20 |

**Total Directories:** 125
**Total Files:** 495

---

*Generated by Web Project Tree Mapper*
*Script: tree-mapper.py*