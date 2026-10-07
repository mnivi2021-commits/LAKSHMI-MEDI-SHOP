-- Why an order is still pending (owner, 07-10-2026): chosen per order on Branch Details.
ALTER TABLE pending_orders
    ADD COLUMN pending_reason ENUM('price','payment_pending','product_mismatch','discount','stock','other') NULL
        COMMENT 'Why the order is still pending' AFTER status;
