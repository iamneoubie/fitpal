# Web Project Structure

**Project:** fitpal
**Generated:** 2026-10-20 03:50:59
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
├── docker/
│   ├── mysql_data/
│   │   ├── #innodb_redo/
│   │   │   ├── #ib_redo10_tmp
│   │   │   ├── #ib_redo11_tmp
│   │   │   ├── #ib_redo12_tmp
│   │   │   ├── #ib_redo13_tmp
│   │   │   ├── #ib_redo14_tmp
│   │   │   ├── #ib_redo15_tmp
│   │   │   ├── #ib_redo16_tmp
│   │   │   ├── #ib_redo17_tmp
│   │   │   ├── #ib_redo18_tmp
│   │   │   ├── #ib_redo19_tmp
│   │   │   ├── #ib_redo20_tmp
│   │   │   ├── #ib_redo21_tmp
│   │   │   ├── #ib_redo22_tmp
│   │   │   ├── #ib_redo23_tmp
│   │   │   ├── #ib_redo24_tmp
│   │   │   ├── #ib_redo25_tmp
│   │   │   ├── #ib_redo26_tmp
│   │   │   ├── #ib_redo27_tmp
│   │   │   ├── #ib_redo28_tmp
│   │   │   ├── #ib_redo29_tmp
│   │   │   ├── #ib_redo30_tmp
│   │   │   ├── #ib_redo31_tmp
│   │   │   ├── #ib_redo32_tmp
│   │   │   ├── #ib_redo33_tmp
│   │   │   ├── #ib_redo34_tmp
│   │   │   ├── #ib_redo35_tmp
│   │   │   ├── #ib_redo36_tmp
│   │   │   ├── #ib_redo37_tmp
│   │   │   ├── #ib_redo38_tmp
│   │   │   ├── #ib_redo39_tmp
│   │   │   ├── #ib_redo40_tmp
│   │   │   └── #ib_redo9
│   │   ├── #innodb_temp/
│   │   │   ├── temp_1.ibt
│   │   │   ├── temp_10.ibt
│   │   │   ├── temp_2.ibt
│   │   │   ├── temp_3.ibt
│   │   │   ├── temp_4.ibt
│   │   │   ├── temp_5.ibt
│   │   │   ├── temp_6.ibt
│   │   │   ├── temp_7.ibt
│   │   │   ├── temp_8.ibt
│   │   │   └── temp_9.ibt
│   │   ├── fitpal_food_delivery/
│   │   │   ├── administrator.ibd
│   │   │   ├── administrator_profile.ibd
│   │   │   ├── cart.ibd
│   │   │   ├── customer.ibd
│   │   │   ├── customer_address.ibd
│   │   │   ├── customer_profile.ibd
│   │   │   ├── customization_instance.ibd
│   │   │   ├── delivery_rider.ibd
│   │   │   ├── delivery_rider_address.ibd
│   │   │   ├── delivery_rider_document.ibd
│   │   │   ├── delivery_rider_emergency_contact.ibd
│   │   │   ├── delivery_rider_profile.ibd
│   │   │   ├── dietary_information.ibd
│   │   │   ├── feedback.ibd
│   │   │   ├── financial_account.ibd
│   │   │   ├── ingredient.ibd
│   │   │   ├── message.ibd
│   │   │   ├── notification.ibd
│   │   │   ├── orders.ibd
│   │   │   ├── product.ibd
│   │   │   ├── product_composition.ibd
│   │   │   ├── queue_item.ibd
│   │   │   ├── restaurant.ibd
│   │   │   ├── restaurant_account.ibd
│   │   │   ├── restaurant_branch.ibd
│   │   │   ├── restaurant_permit.ibd
│   │   │   ├── rider_collection.ibd
│   │   │   └── transaction.ibd
│   │   ├── mysql/
│   │   │   ├── general_log.CSM
│   │   │   ├── general_log.CSV
│   │   │   ├── general_log_213.sdi
│   │   │   ├── slow_log.CSM
│   │   │   ├── slow_log.CSV
│   │   │   └── slow_log_214.sdi
│   │   ├── performance_schema/
│   │   │   ├── accounts_145.sdi
│   │   │   ├── binary_log_trans_189.sdi
│   │   │   ├── cond_instances_82.sdi
│   │   │   ├── data_lock_waits_161.sdi
│   │   │   ├── data_locks_160.sdi
│   │   │   ├── error_log_83.sdi
│   │   │   ├── events_errors_su_139.sdi
│   │   │   ├── events_errors_su_140.sdi
│   │   │   ├── events_errors_su_141.sdi
│   │   │   ├── events_errors_su_142.sdi
│   │   │   ├── events_errors_su_143.sdi
│   │   │   ├── events_stages_cu_111.sdi
│   │   │   ├── events_stages_hi_112.sdi
│   │   │   ├── events_stages_hi_113.sdi
│   │   │   ├── events_stages_su_114.sdi
│   │   │   ├── events_stages_su_115.sdi
│   │   │   ├── events_stages_su_116.sdi
│   │   │   ├── events_stages_su_117.sdi
│   │   │   ├── events_stages_su_118.sdi
│   │   │   ├── events_statement_119.sdi
│   │   │   ├── events_statement_120.sdi
│   │   │   ├── events_statement_121.sdi
│   │   │   ├── events_statement_122.sdi
│   │   │   ├── events_statement_123.sdi
│   │   │   ├── events_statement_124.sdi
│   │   │   ├── events_statement_125.sdi
│   │   │   ├── events_statement_126.sdi
│   │   │   ├── events_statement_127.sdi
│   │   │   ├── events_statement_128.sdi
│   │   │   ├── events_statement_129.sdi
│   │   │   ├── events_statement_130.sdi
│   │   │   ├── events_transacti_131.sdi
│   │   │   ├── events_transacti_132.sdi
│   │   │   ├── events_transacti_133.sdi
│   │   │   ├── events_transacti_134.sdi
│   │   │   ├── events_transacti_135.sdi
│   │   │   ├── events_transacti_136.sdi
│   │   │   ├── events_transacti_137.sdi
│   │   │   ├── events_transacti_138.sdi
│   │   │   ├── events_waits_cur_84.sdi
│   │   │   ├── events_waits_his_85.sdi
│   │   │   ├── events_waits_his_86.sdi
│   │   │   ├── events_waits_sum_87.sdi
│   │   │   ├── events_waits_sum_88.sdi
│   │   │   ├── events_waits_sum_89.sdi
│   │   │   ├── events_waits_sum_90.sdi
│   │   │   ├── events_waits_sum_91.sdi
│   │   │   ├── events_waits_sum_92.sdi
│   │   │   ├── file_instances_93.sdi
│   │   │   ├── file_summary_by__94.sdi
│   │   │   ├── file_summary_by__95.sdi
│   │   │   ├── global_status_181.sdi
│   │   │   ├── global_variables_184.sdi
│   │   │   ├── host_cache_96.sdi
│   │   │   ├── hosts_146.sdi
│   │   │   ├── keyring_componen_191.sdi
│   │   │   ├── keyring_keys_152.sdi
│   │   │   ├── log_status_174.sdi
│   │   │   ├── memory_summary_b_154.sdi
│   │   │   ├── memory_summary_b_155.sdi
│   │   │   ├── memory_summary_b_156.sdi
│   │   │   ├── memory_summary_b_157.sdi
│   │   │   ├── memory_summary_g_153.sdi
│   │   │   ├── metadata_locks_159.sdi
│   │   │   ├── mutex_instances_97.sdi
│   │   │   ├── objects_summary__98.sdi
│   │   │   ├── performance_time_99.sdi
│   │   │   ├── persisted_variab_187.sdi
│   │   │   ├── prepared_stateme_175.sdi
│   │   │   ├── processlist_100.sdi
│   │   │   ├── replication_appl_165.sdi
│   │   │   ├── replication_appl_166.sdi
│   │   │   ├── replication_appl_167.sdi
│   │   │   ├── replication_appl_168.sdi
│   │   │   ├── replication_appl_170.sdi
│   │   │   ├── replication_appl_171.sdi
│   │   │   ├── replication_asyn_172.sdi
│   │   │   ├── replication_asyn_173.sdi
│   │   │   ├── replication_conn_162.sdi
│   │   │   ├── replication_conn_164.sdi
│   │   │   ├── replication_grou_163.sdi
│   │   │   ├── replication_grou_169.sdi
│   │   │   ├── rwlock_instances_101.sdi
│   │   │   ├── session_account__151.sdi
│   │   │   ├── session_connect__150.sdi
│   │   │   ├── session_status_182.sdi
│   │   │   ├── session_variable_185.sdi
│   │   │   ├── setup_actors_102.sdi
│   │   │   ├── setup_consumers_103.sdi
│   │   │   ├── setup_instrument_104.sdi
│   │   │   ├── setup_objects_105.sdi
│   │   │   ├── setup_threads_106.sdi
│   │   │   ├── socket_instances_147.sdi
│   │   │   ├── socket_summary_b_148.sdi
│   │   │   ├── socket_summary_b_149.sdi
│   │   │   ├── status_by_accoun_177.sdi
│   │   │   ├── status_by_host_178.sdi
│   │   │   ├── status_by_thread_179.sdi
│   │   │   ├── status_by_user_180.sdi
│   │   │   ├── table_handles_158.sdi
│   │   │   ├── table_io_waits_s_107.sdi
│   │   │   ├── table_io_waits_s_108.sdi
│   │   │   ├── table_lock_waits_109.sdi
│   │   │   ├── threads_110.sdi
│   │   │   ├── tls_channel_stat_190.sdi
│   │   │   ├── user_defined_fun_188.sdi
│   │   │   ├── user_variables_b_176.sdi
│   │   │   ├── users_144.sdi
│   │   │   ├── variables_by_thr_183.sdi
│   │   │   └── variables_info_186.sdi
│   │   ├── sys/
│   │   │   └── sys_config.ibd
│   │   ├── #ib_16384_0.dblwr
│   │   ├── #ib_16384_1.dblwr
│   │   ├── auto.cnf
│   │   ├── binlog.000001
│   │   ├── binlog.000002
│   │   ├── binlog.index
│   │   ├── ca-key.pem
│   │   ├── ca.pem
│   │   ├── client-cert.pem
│   │   ├── client-key.pem
│   │   ├── ib_buffer_pool
│   │   ├── ibdata1
│   │   ├── ibtmp1
│   │   ├── mysql.ibd
│   │   ├── private_key.pem
│   │   ├── public_key.pem
│   │   ├── server-cert.pem
│   │   ├── server-key.pem
│   │   ├── undo_001
│   │   └── undo_002
│   ├── www/
│   ├── commands.sh
│   ├── docker-compose.yaml
│   ├── my.cnf
│   └── setup.md
├── logs/
│   ├── bugs/
│   │   └── rider-resto.md
│   ├── content-fetcher-configuration/
│   │   ├── all_path.py
│   │   ├── dir-admin.py
│   │   ├── dir-cutomer.py
│   │   ├── dir-menu-product-detail.py
│   │   ├── dir-restaurant.py
│   │   ├── dir-rider.py
│   │   ├── dir-shared.py
│   │   └── dir-technical.py
│   ├── diagram/
│   │   ├── data-flow-diagram.puml
│   │   ├── system-architecture.puml
│   │   └── use-case.puml
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
│   │   ├── business-logic.md
│   │   ├── CONTEXT DIAGRAM.md
│   │   └── FitPal is divided into five main modules.md
│   ├── output/
│   │   ├── all_path_fetched_codebase.md
│   │   ├── dir-admin_fetched_codebase.md
│   │   ├── dir-cutomer_fetched_codebase.md
│   │   ├── dir-restaurant_fetched_codebase.md
│   │   ├── dir-rider_fetched_codebase.md
│   │   ├── dir-shared_fetched_codebase.md
│   │   ├── dir-technical_fetched_codebase.md
│   │   ├── nojscss_fetched_codebase.md
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
│   │   │   │   ├── remove-circle-fill.svg
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
│   │   │   │   │   ├── image-1.png
│   │   │   │   │   └── image-2.jpg
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
│   ├── pages/
│   │   ├── about.php
│   │   ├── contact.php
│   │   ├── privacy-policy.php
│   │   └── terms-conditions.php
│   └── uploads/
│       └── customer/
│           └── profiles/
│               └── 1/
│                   └── 10_19_2026_0.jpg
├── sql/
│   ├── debug/
│   │   └── test.sql
│   ├── seed/
│   │   ├── all-in-one.sql
│   │   ├── seed-1-core.sql
│   │   ├── seed-2-content.sql
│   │   ├── seed-3-paths.sql
│   │   └── seed-4-orders.sql
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
| PHP Files | 121 |
| CSS Files | 54 |
| JavaScript Files | 53 |
| JSON Files | 2 |
| Text/Markdown | 23 |
| Image Files | 240 |
| Other Files | 235 |

**Total Directories:** 127
**Total Files:** 725

---

*Generated by Web Project Tree Mapper*
*Script: tree-mapper.py*