Customers Scenario A

    scenario A-1
    - customer orders using payment method cod,
    - nothing gets deducted from wallet
    - customer cancels that order
    - nothing gets added to wallet
    - still recorded in transaction
    - order_status = cancelled

    scenario A-2
    - customer orders using payment method online,
    - nothing gets deducted from wallet
    - customer cancels that order
    - payment gets added back/refunded
    - still recorded in transaction
    - order_status = refunded

    scenario A-3
    - customer orders using payment method wallet,
    - amount gets deducted from wallet
    - customer cancels that order
    - amount gets added back to wallet
    - still recorded in transaction
    - order_status = refunded

Restaurant Scenario B

    scenario B-1
    - customer orders using payment method cod,
    - nothing gets deducted from wallet
    - restaurant cancels that order
    - nothing gets added to wallet
    - still recorded in transaction
    - order_status = cancelled

    scenario B-2
    - customer orders using payment method online,
    - nothing gets deducted from wallet
    - restaurant cancels that order
    - payment gets added back/refunded
    - still recorded in transaction
    - order_status = refunded

    scenario B-3
    - customer orders using payment method wallet,
    - amount gets deducted from wallet
    - restaurant cancels that order
    - amount gets added back to wallet
    - still recorded in transaction
    - order_status = refunded

    NOTE
    - NO GROSS REVENUE UNTIL THE ORDER IS FINISHED/DELIVERED.

Rider Scenario C

    NOTE
    - this only happens when rider accepts the order.
    - rider collection, rider liability, and rider earnings are separate.
    - COD orders can make the rider's wallet balance negative because the rider becomes responsible for the order amount.
    - rider earnings themselves should never become negative.

    scenario C-1
    - customer orders using payment method cod,
    - restaurant assigns that order to rider
    - rider accepts that order assignment
    - rider becomes responsible for the order amount
    - rider collects the full order amount from customer

        if order is:
            - Subtotal: ₱350.00
            - Delivery Fee: ₱50.00
            - Service Fee: ₱5.00
            - VAT: ₱42.00
            - Total: ₱447.00
            - rider wallet/liability = -₱447
            - rider collection = ₱447
            - rider earnings = ₱0
        after order is delivered:
            - ₱350 goes to restaurant
            - ₱50 goes to rider as delivery earnings
            - service fee and VAT are handled separately
            - rider wallet/liability is settled
            - all financial events are recorded in transaction
            - order_status = delivered

    scenario C-2
    - customer orders using payment method online or wallet,
    - restaurant assigns that order to rider
    - rider accepts that order assignment
    - customer already paid electronically
    - rider wallet/liability = ₱0
    - rider collection = ₱0
    - rider earnings = ₱0

        after order is delivered:
        - ₱350 goes to restaurant
        - ₱50 goes to rider as delivery earnings
        - all financial events are recorded in transaction
        - order_status = delivered

    scenario C-3
    - customer orders using payment method cod,
    - restaurant assigns that order to rider
    - rider accepts that order assignment
    - rider wallet/liability = -₱447
    - rider collects ₱447 from customer

        if order fails before delivery:
        - rider does not receive the ₱50 delivery earnings
        - rider remains responsible for the ₱447 until the failed-order settlement is processed
        - order_status = failed
        - all financial events are still recorded in transaction

    scenario C-4
    - customer orders using payment method online or wallet,
    - restaurant assigns that order to rider
    - rider accepts that order assignment
    - customer already paid electronically
    - rider wallet/liability = ₱0
    - rider collection = ₱0

        if order fails before delivery:
        - rider does not receive the ₱50 delivery earnings
        - any required refund is processed through the order-transaction-handler
        - order_status = failed
        - all financial events are still recorded in transaction

    NOTE
    - rider wallet/liability may become negative for COD orders.
    - rider earnings should never become negative.
    - rider collection and rider earnings are separate.
    - customer payment, rider liability, rider collection, restaurant settlement, rider earnings, refunds, and revenue are separate transactions.
    - NO GROSS REVENUE UNTIL THE ORDER IS SUCCESSFULLY DELIVERED.
