<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sync verified SefrelShop product reviews
 * with Dokan Store Reviews.
 *
 * This creates a genuine Dokan Vendor Review
 * using the Dokan Store Reviews module's
 * native data structure.
 */
class SefrelShop_Dokan_Vendor_Review {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_action(
            'sefrelshop_product_review_submitted',
            array( $this, 'create_dokan_vendor_review' ),
            10,
            4
        );
    }

    /**
     * Create a Dokan Store Review from a SefrelShop product review.
     *
     * @param WC_Order $order
     * @param int      $product_id
     * @param int      $rating
     * @param int      $review_id
     *
     * @return void
     */
    public function create_dokan_vendor_review(
        $order,
        int $product_id,
        int $rating,
        int $review_id
    ): void {

        /*
         * Dokan Vendor Review module must be active.
         */
        if ( ! post_type_exists( 'dokan_store_reviews' ) ) {
            return;
        }

        /*
         * Validate review.
         */
        $review = get_comment( $review_id );

        if ( ! $review instanceof WP_Comment ) {
            return;
        }

        /*
         * Make sure this is actually a product review.
         */
        if ( 'review' !== $review->comment_type ) {
            return;
        }

        /*
         * Prevent duplicate Dokan reviews.
         */
        $existing_dokan_review_id = get_comment_meta(
            $review_id,
            '_sefrelshop_dokan_store_review_id',
            true
        );

        if ( $existing_dokan_review_id ) {
            return;
        }

        /*
         * Get the Dokan vendor responsible for this product.
         */
        if ( ! function_exists( 'dokan_get_vendor_by_product' ) ) {
            return;
        }

        $vendor = dokan_get_vendor_by_product( $product_id );

        if ( ! $vendor || ! is_object( $vendor ) ) {
            return;
        }

        if ( ! method_exists( $vendor, 'get_id' ) ) {
            return;
        }

        $seller_id = absint( $vendor->get_id() );

        if ( ! $seller_id ) {
            return;
        }

        /*
         * Ensure the rating is valid.
         */
        $rating = max( 1, min( 5, absint( $rating ) ) );

        /*
         * Get product information.
         */
        $product = wc_get_product( $product_id );

        $product_name = $product
            ? $product->get_name()
            : __( 'Purchased Product', 'sefrelshop' );

        /*
         * Generate the Dokan review title.
         *
         * We intentionally identify the product so that
         * the vendor can understand what the review relates to.
         */
        $review_title = sprintf(
            __( 'Review for %s', 'sefrelshop' ),
            $product_name
        );

        /*
         * Build the Dokan Store Review post.
         *
         * IMPORTANT:
         * Dokan's own implementation expects a post of type
         * dokan_store_reviews.
         */
        $dokan_review_post = array(
            'post_title'   => sanitize_text_field( $review_title ),
            'post_content' => wp_kses_post( $review->comment_content ),
            'post_author'  => absint( $review->user_id ),
            'post_type'    => 'dokan_store_reviews',
            'post_status'  => 'publish',
        );

        /*
         * If the original review has no logged-in user ID,
         * use the WooCommerce review author email to find the user.
         */
        if ( ! $review->user_id && ! empty( $review->comment_author_email ) ) {
            $user = get_user_by(
                'email',
                sanitize_email( $review->comment_author_email )
            );

            if ( $user ) {
                $dokan_review_post['post_author'] = absint( $user->ID );
            }
        }

        /*
         * A SefrelShop review is submitted only by a logged-in
         * customer, but retain a safety check.
         */
        if ( empty( $dokan_review_post['post_author'] ) ) {
            return;
        }

        /*
         * Create the native Dokan Store Review.
         */
        $dokan_review_id = wp_insert_post(
            $dokan_review_post,
            true
        );

        if ( is_wp_error( $dokan_review_id ) ) {
            error_log(
                sprintf(
                    '[SefrelShop] Failed to create Dokan vendor review for product review %d: %s',
                    $review_id,
                    $dokan_review_id->get_error_message()
                )
            );

            return;
        }

        $dokan_review_id = absint( $dokan_review_id );

        if ( ! $dokan_review_id ) {
            return;
        }

        /*
         * Dokan's native Vendor Review fields.
         */
        update_post_meta(
            $dokan_review_id,
            'store_id',
            $seller_id
        );

        update_post_meta(
            $dokan_review_id,
            'rating',
            $rating
        );

        /*
         * SefrelShop integration metadata.
         *
         * These allow us to identify exactly which product review
         * generated the Dokan review.
         */
        update_post_meta(
            $dokan_review_id,
            '_sefrelshop_product_review_id',
            $review_id
        );

        update_post_meta(
            $dokan_review_id,
            '_sefrelshop_product_id',
            $product_id
        );

        update_post_meta(
            $dokan_review_id,
            '_sefrelshop_order_id',
            $order instanceof WC_Order
                ? $order->get_id()
                : 0
        );

        update_post_meta(
            $dokan_review_id,
            '_sefrelshop_verified_purchase',
            '1'
        );

        /*
         * Link the WooCommerce product review back to
         * the Dokan Vendor Review.
         */
        update_comment_meta(
            $review_id,
            '_sefrelshop_dokan_store_review_id',
            $dokan_review_id
        );

        /*
         * Also store the vendor ID on the WooCommerce review.
         */
        update_comment_meta(
            $review_id,
            '_sefrelshop_dokan_vendor_id',
            $seller_id
        );

        /*
         * Invalidate Dokan's store review cache.
         *
         * This mirrors Dokan's own DSR_View implementation.
         */
        if ( class_exists( '\WeDevs\Dokan\Cache' ) ) {
            \WeDevs\Dokan\Cache::invalidate_group( 'store_reviews' );
        }

        /*
         * Fire Dokan's native Store Review hook.
         *
         * This is the same hook Dokan fires after its own
         * Store Review submission.
         */
        do_action(
            'dokan_store_review_saved',
            $dokan_review_id,
            array(
                'store_id' => $seller_id,
            ),
            $rating
        );

        /*
         * Add a useful order note for internal traceability.
         */
        if ( $order instanceof WC_Order ) {
            $order->add_order_note(
                sprintf(
                    'Dokan vendor review #%d created from SefrelShop product review #%d for vendor #%d (%d/5).',
                    $dokan_review_id,
                    $review_id,
                    $seller_id,
                    $rating
                )
            );

            $order->save();
        }

        /*
         * Optional integration hook for future SefrelShop features.
         */
        do_action(
            'sefrelshop_dokan_vendor_review_created',
            $dokan_review_id,
            $seller_id,
            $product_id,
            $rating,
            $review_id,
            $order
        );
    }
}