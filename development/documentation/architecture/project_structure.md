# Web Project Structure

**Project:** fitpal
**Generated:** 2026-11-07 14:40:44
**Mode:** web_only

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
├── development/
│   ├── documentation/
│   │   ├── diagram/
│   │   ├── dump/
│   │   │   ├── general_v1.md
│   │   │   ├── test-create-guide.md
│   │   │   └── updating-fetcher-guide.md
│   │   └── logic/
│   │       ├── errors/
│   │       │   └── bugs.md
│   │       ├── features/
│   │       │   ├── suggestions.md
│   │       │   └── todo.md
│   │       ├── instructions/
│   │       │   └── public/
│   │       │       ├── business-logic.md
│   │       │       └── general.md
│   │       └── logs/
│   │           └── list.md
│   ├── environment/
│   │   └── workflows/
│   │       ├── extensions/
│   │       │   └── extensions.md
│   │       └── vscode/
│   │           ├── imports/
│   │           │   └── VS Code Profile/
│   │           │       ├── custom-vscode-script.js
│   │           │       └── custom-vscode.css
│   │           ├── keybindings.json
│   │           └── user-setting.json
│   └── kits/
│       └── configuration/
├── docker/
│   └── setup.md
├── restaurant/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── dashboard.css
│   │   │   ├── header.css
│   │   │   ├── kitchen-orders.css
│   │   │   ├── product-edit.css
│   │   │   ├── products.css
│   │   │   ├── profile.css
│   │   │   ├── restaurant-reapply.css
│   │   │   ├── sign-in.css
│   │   │   └── sign-up.css
│   │   └── ui/
│   │       └── js/
│   │           ├── dashboard.js
│   │           ├── header.js
│   │           ├── kitchen-order.js
│   │           ├── kitchen-realtime.js
│   │           ├── logout.js
│   │           ├── product-edit.js
│   │           ├── products.js
│   │           ├── profile.js
│   │           ├── restaurant-chat-modal.js
│   │           ├── restaurant-reapply.js
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
│   │       ├── product-handler.php
│   │       ├── product-media-handler.php
│   │       ├── profile-handler.php
│   │       ├── restaurant-reapply-handler.php
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
│       ├── product-edit.php
│       ├── products.php
│       ├── profile.php
│       ├── restaurant-reapply.php
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
│   │   │   ├── rider-reapply.css
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
│   │           ├── rider-reapply.js
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
│       ├── rider-reapply.php
│       ├── sign-in.php
│       └── sign-up.php
├── shared/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── about.css
│   │   │   ├── contact.css
│   │   │   ├── database-connect.css
│   │   │   ├── footer.css
│   │   │   ├── format-date.css
│   │   │   ├── global.css
│   │   │   ├── header.css
│   │   │   ├── landing.css
│   │   │   ├── privacy-policy.css
│   │   │   └── terms-conditions.css
│   │   ├── images/
│   │   │   ├── brand/
│   │   │   ├── icons/
│   │   │   ├── manifest/
│   │   │   │   ├── drivers-license/
│   │   │   │   ├── national-id/
│   │   │   │   ├── permits/
│   │   │   │   ├── products/
│   │   │   │   │   └── restaurant/
│   │   │   │   │       ├── asian-fusion-fit/
│   │   │   │   │       │   ├── 001-gluten-free-salmon-roll/
│   │   │   │   │       │   ├── 002-grilled-fish-greens/
│   │   │   │   │       │   ├── 003-matcha-banana-smotthie/
│   │   │   │   │       │   ├── 004-nori-hand-roll/
│   │   │   │   │       │   ├── 005-osaka-rice-bowl/
│   │   │   │   │       │   ├── 006-rainbow-poke-bowl/
│   │   │   │   │       │   ├── 007-seaweed-sesame-salad/
│   │   │   │   │       │   ├── 008-spicy-tuna-roll/
│   │   │   │   │       │   ├── 009-tokyo-noodle-bowl/
│   │   │   │   │       │   └── 010-wok-tossed-vegetables/
│   │   │   │   │       ├── green-bowl-cafe/
│   │   │   │   │       │   ├── 001-berry-almond/
│   │   │   │   │       │   ├── 002-classic-vegan-bowl/
│   │   │   │   │       │   ├── 003-edamame-citrus-salad/
│   │   │   │   │       │   ├── 004-garden-harvest-bowl/
│   │   │   │   │       │   ├── 005-market-greens-salad/
│   │   │   │   │       │   ├── 006-morning-power-smoothie/
│   │   │   │   │       │   ├── 007-seaside-poke-bowl/
│   │   │   │   │       │   ├── 008-sunrise-breakfast-bowl/
│   │   │   │   │       │   ├── 009-superfood-buddha-bowl/
│   │   │   │   │       │   └── 010-zucchini-noodle-pesto/
│   │   │   │   │       └── keto-kitchen/
│   │   │   │   │           ├── 001-chicken parmesan plate/
│   │   │   │   │           ├── 002-egg avocado bowl/
│   │   │   │   │           ├── 003-keto butcher plate/
│   │   │   │   │           ├── 004-keto cauliflower pizza/
│   │   │   │   │           ├── 005-keto garden salad/
│   │   │   │   │           ├── 006-keto power bowl/
│   │   │   │   │           ├── 007-keto-smash-burger/
│   │   │   │   │           ├── 008-keto-steak-plate/
│   │   │   │   │           ├── 009-salmon-dill-plate/
│   │   │   │   │           └── 010-shrimp-scampi-zoodles/
│   │   │   │   └── profiles/
│   │   │   ├── payment/
│   │   │   ├── rider-image/
│   │   │   └── showcase/
│   │   └── ui/
│   │       └── js/
│   │           ├── contact.js
│   │           ├── format-date.js
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
│   └── pages/
│       ├── about.php
│       ├── contact.php
│       ├── privacy-policy.php
│       └── terms-conditions.php
├── sql/
│   ├── debug/
│   └── seed/
├── test/
│   └── customer/
│       ├── cart-test.php
│       ├── menu-test.php
│       ├── product-detail-test.php
│       ├── sign-in-test.php
│       └── sign-up-test.php
├── index.php
└── readme.md
```

## Summary

| File Type | Count |
|-----------|-------|
| HTML files | 0 |
| PHP files | 121 |
| CSS files | 54 |
| JavaScript files | 53 |
| JSON files | 2 |
| Text / Markdown | 12 |
| Images | 0 |
| Other | 0 |

**Total directories:** 120
**Total files:** 242

---

*Generated by Web Project Tree Mapper*
*Script: tree-mapper.py*
