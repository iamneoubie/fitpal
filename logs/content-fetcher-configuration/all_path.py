filesToCheck = [
    # ===== File Structure =====
    "/logs/instructions/general.md",
    "/logs/output/project_structure.md",
    "/sql/database.sql",
    "/sql/sample/seed-data.sql",

    # ===== Root =====
    "/index.php",

    # ===== admin/assets/css =====
    "/admin/assets/css/admin-tables.css",
    "/admin/assets/css/customers.css",
    "/admin/assets/css/dashboard.css",
    "/admin/assets/css/header.css",
    "/admin/assets/css/profile.css",
    "/admin/assets/css/restaurants.css",
    "/admin/assets/css/riders.css",
    "/admin/assets/css/sign-in.css",

    # ===== admin/assets/ui/js =====
    "/admin/assets/ui/js/customers.js",
    "/admin/assets/ui/js/dashboard.js",
    "/admin/assets/ui/js/header.js",
    "/admin/assets/ui/js/logout.js",
    "/admin/assets/ui/js/profile.js",
    "/admin/assets/ui/js/restaurants.js",
    "/admin/assets/ui/js/riders.js",
    "/admin/assets/ui/js/sign-in.js",

    # ===== admin/backend/database =====
    "/admin/backend/database/admin-connect.php",
    "/admin/backend/database/admin-queries.php",

    # ===== admin/backend/handlers =====
    "/admin/backend/handlers/admin-handler.php",
    "/admin/backend/handlers/sign-in-handler.php",
    "/admin/backend/handlers/sign-out-handler.php",

    # ===== admin/includes =====
    "/admin/includes/admin-csrf-token.php",
    "/admin/includes/header.php",

    # ===== admin/pages =====
    "/admin/pages/customers.php",
    "/admin/pages/dashboard.php",
    "/admin/pages/profile.php",
    "/admin/pages/restaurants.php",
    "/admin/pages/riders.php",
    "/admin/pages/sign-in.php",

    # ===== customer/assets/css =====
    "/customer/assets/css/cart.css",
    "/customer/assets/css/checkout.css",
    "/customer/assets/css/dashboard.css",
    "/customer/assets/css/header.css",
    "/customer/assets/css/menu-filter.css",
    "/customer/assets/css/menu-product.css",
    "/customer/assets/css/menu.css",
    "/customer/assets/css/order-receipt.css",
    "/customer/assets/css/order-tracking.css",
    "/customer/assets/css/orders.css",
    "/customer/assets/css/product-detail.css",
    "/customer/assets/css/profile.css",
    "/customer/assets/css/queue-panel.css",
    "/customer/assets/css/sign-in.css",
    "/customer/assets/css/sign-up.css",
    "/customer/assets/css/wallet.css",

    # ===== customer/assets/ui/js =====
    "/customer/assets/ui/js/cart.js",
    "/customer/assets/ui/js/checkout.js",
    "/customer/assets/ui/js/dashboard.js",
    "/customer/assets/ui/js/header.js",
    "/customer/assets/ui/js/logout.js",
    "/customer/assets/ui/js/menu.js",
    "/customer/assets/ui/js/order-tracking.js",
    "/customer/assets/ui/js/orders.js",
    "/customer/assets/ui/js/product-detail.js",
    "/customer/assets/ui/js/profile.js",
    "/customer/assets/ui/js/queue-panel.js",
    "/customer/assets/ui/js/sign-in.js",
    "/customer/assets/ui/js/sign-out.js",
    "/customer/assets/ui/js/sign-up.js",
    "/customer/assets/ui/js/wallet.js",

    # ===== customer/backend/database =====
    "/customer/backend/database/address-queries.php",
    "/customer/backend/database/branch-queries.php",
    "/customer/backend/database/cart-queries.php",
    "/customer/backend/database/customer-connect.php",
    "/customer/backend/database/customer-queries.php",
    "/customer/backend/database/dashboard-queries.php",
    "/customer/backend/database/fee-queries.php",
    "/customer/backend/database/order-queries.php",
    "/customer/backend/database/product-queries.php",
    "/customer/backend/database/queue-queries.php",
    "/customer/backend/database/rider-queries.php",
    "/customer/backend/database/tracking-queries.php",
    "/customer/backend/database/wallet-queries.php",

    # ===== customer/backend/handlers =====
    "/customer/backend/handlers/address-handler.php",
    "/customer/backend/handlers/cart-handler.php",
    "/customer/backend/handlers/checkout-handler.php",
    "/customer/backend/handlers/feedback-handler.php",
    "/customer/backend/handlers/get-branch-handler.php",
    "/customer/backend/handlers/message-handler.php",
    "/customer/backend/handlers/order-handler.php",
    "/customer/backend/handlers/place-order-handler.php",
    "/customer/backend/handlers/queue-handler.php",
    "/customer/backend/handlers/sign-in-handler.php",
    "/customer/backend/handlers/sign-out-handler.php",
    "/customer/backend/handlers/sign-up-handler.php",
    "/customer/backend/handlers/wallet-handler.php",

    # ===== customer/includes =====
    "/customer/includes/customer-csrf-token.php",
    "/customer/includes/header.php",

    # ===== customer/pages =====
    "/customer/pages/cart.php",
    "/customer/pages/checkout.php",
    "/customer/pages/dashboard.php",
    "/customer/pages/menu.php",
    "/customer/pages/order-receipt.php",
    "/customer/pages/order-tracking.php",
    "/customer/pages/orders.php",
    "/customer/pages/product-detail.php",
    "/customer/pages/profile.php",
    "/customer/pages/sign-in.php",
    "/customer/pages/sign-up.php",
    "/customer/pages/wallet.php",

    # ===== restaurant/assets/css =====
    "/restaurant/assets/css/dashboard.css",
    "/restaurant/assets/css/header.css",
    "/restaurant/assets/css/orders.css",
    "/restaurant/assets/css/profile.css",
    "/restaurant/assets/css/sign-in.css",
    "/restaurant/assets/css/sign-up.css",

    # ===== restaurant/assets/ui/js =====
    "/restaurant/assets/ui/js/dashboard.js",
    "/restaurant/assets/ui/js/header.js",
    "/restaurant/assets/ui/js/kitchen-realtime.js",
    "/restaurant/assets/ui/js/logout.js",
    "/restaurant/assets/ui/js/orders.js",
    "/restaurant/assets/ui/js/profile.js",
    "/restaurant/assets/ui/js/sign-in.js",
    "/restaurant/assets/ui/js/sign-up.js",

    # ===== restaurant/backend/database =====
    "/restaurant/backend/database/chat-queries.php",
    "/restaurant/backend/database/order-queries.php",
    "/restaurant/backend/database/restaurant-connect.php",
    "/restaurant/backend/database/restaurant-queries.php",

    # ===== restaurant/backend/handlers =====
    "/restaurant/backend/handlers/branch-lookup-handler.php",
    "/restaurant/backend/handlers/chat-handler.php",
    "/restaurant/backend/handlers/order-handler.php",
    "/restaurant/backend/handlers/profile-handler.php",
    "/restaurant/backend/handlers/sign-in-handler.php",
    "/restaurant/backend/handlers/sign-out-handler.php",
    "/restaurant/backend/handlers/sign-up-handler.php",

    # ===== restaurant/includes =====
    "/restaurant/includes/chat-modal.php",
    "/restaurant/includes/header.php",
    "/restaurant/includes/restaurant-csrf-token.php",

    # ===== restaurant/pages =====
    "/restaurant/pages/dashboard.php",
    "/restaurant/pages/kitchen.php",
    "/restaurant/pages/profile.php",
    "/restaurant/pages/sign-in.php",
    "/restaurant/pages/sign-up.php",

    # ===== rider/assets/css =====
    "/rider/assets/css/assignment-panel.css",
    "/rider/assets/css/dashboard.css",
    "/rider/assets/css/deliveries.css",
    "/rider/assets/css/earnings.css",
    "/rider/assets/css/header.css",
    "/rider/assets/css/profile.css",
    "/rider/assets/css/sign-in.css",
    "/rider/assets/css/sign-up.css",

    # ===== rider/assets/ui/js =====
    "/rider/assets/ui/js/assignment-panel.js",
    "/rider/assets/ui/js/dashboard.js",
    "/rider/assets/ui/js/deliveries.js",
    "/rider/assets/ui/js/earnings.js",
    "/rider/assets/ui/js/header.js",
    "/rider/assets/ui/js/logout.js",
    "/rider/assets/ui/js/profile.js",
    "/rider/assets/ui/js/rider-chat-modal.js",
    "/rider/assets/ui/js/sign-in.js",
    "/rider/assets/ui/js/sign-up.js",

    # ===== rider/backend/database =====
    "/rider/backend/database/assignment-queries.php",
    "/rider/backend/database/rider-connect.php",
    "/rider/backend/database/rider-queries.php",

    # ===== rider/backend/handlers =====
    "/rider/backend/handlers/assignment-handler.php",
    "/rider/backend/handlers/message-handler.php",
    "/rider/backend/handlers/rider-handler.php",
    "/rider/backend/handlers/sign-in-handler.php",
    "/rider/backend/handlers/sign-out-handler.php",
    "/rider/backend/handlers/sign-up-handler.php",

    # ===== rider/includes =====
    "/rider/includes/assignment-panel.php",
    "/rider/includes/header.php",
    "/rider/includes/rider-chat-modal.php",
    "/rider/includes/rider-csrf-token.php",

    # ===== rider/pages =====
    "/rider/pages/dashboard.php",
    "/rider/pages/deliveries.php",
    "/rider/pages/earnings.php",
    "/rider/pages/profile.php",
    "/rider/pages/sign-in.php",
    "/rider/pages/sign-up.php",

    # ===== shared/assets/css =====
    "/shared/assets/css/about.css",
    "/shared/assets/css/contact.css",
    "/shared/assets/css/database-connect.css",
    "/shared/assets/css/footer.css",
    "/shared/assets/css/global.css",
    "/shared/assets/css/header.css",
    "/shared/assets/css/landing.css",
    "/shared/assets/css/privacy-policy.css",
    "/shared/assets/css/terms-conditions.css",

    # ===== shared/assets/ui/js =====
    "/shared/assets/ui/js/contact.js",
    "/shared/assets/ui/js/header.js",

    # ===== shared/backend/database =====
    "/shared/backend/database/database-connect.php",
    "/shared/backend/database/landing-queries.php",

    # ===== shared/includes =====
    "/shared/includes/footer.php",
    "/shared/includes/header.php",
    "/shared/includes/view-helpers.php",

    # ===== shared/pages =====
    "/shared/pages/about.php",
    "/shared/pages/contact.php",
    "/shared/pages/privacy-policy.php",
    "/shared/pages/terms-conditions.php",

    # ===== test/customer =====
    "/test/customer/cart-test.php",
    "/test/customer/menu-test.php",
    "/test/customer/product-detail-test.php",
    "/test/customer/sign-in-test.php",
    "/test/customer/sign-up-test.php",

]
