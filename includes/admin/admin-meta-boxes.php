<?php
// MUST have WordPress.
if ( !defined( 'WPINC' ) ) {
    exit( 'Do NOT access this file directly: ' . basename( __FILE__ ) );
}

function wpinv_add_meta_boxes( $post_type, $post ) {
    global $wpi_mb_invoice;
    if ( $post_type == 'wpi_invoice' && !empty( $post->ID ) ) {
        $wpi_mb_invoice = wpinv_get_invoice( $post->ID );
    }
    
    if ( !empty( $wpi_mb_invoice ) && !$wpi_mb_invoice->has_status( array( 'draft', 'auto-draft' ) ) ) {
        add_meta_box( 'wpinv-mb-resend-invoice', __( 'Resend Invoice', 'invoicing' ), 'WPInv_Meta_Box_Details::resend_invoice', 'wpi_invoice', 'side', 'high' );
    }
    
    if ( !empty( $wpi_mb_invoice ) && $wpi_mb_invoice->is_recurring() && !wpinv_is_subscription_payment( $wpi_mb_invoice ) ) {
        add_meta_box( 'wpinv-mb-subscriptions', __( 'Subscriptions', 'invoicing' ), 'WPInv_Meta_Box_Details::subscriptions', 'wpi_invoice', 'side', 'high' );
    }
    
    if ( wpinv_is_subscription_payment( $wpi_mb_invoice ) ) {
        add_meta_box( 'wpinv-mb-renewals', __( 'Renewal Payments', 'invoicing' ), 'WPInv_Meta_Box_Details::renewals', 'wpi_invoice', 'side', 'high' );
    }
    
    add_meta_box( 'wpinv-details', __( 'Invoice Details', 'invoicing' ), 'WPInv_Meta_Box_Details::output', 'wpi_invoice', 'side', 'default' );
    add_meta_box( 'wpinv-payment-meta', __( 'Payment Meta', 'invoicing' ), 'WPInv_Meta_Box_Details::payment_meta', 'wpi_invoice', 'side', 'default' );
   
    add_meta_box( 'wpinv-address', __( 'Billing Details', 'invoicing' ), 'WPInv_Meta_Box_Billing_Details::output', 'wpi_invoice', 'normal', 'high' );
    add_meta_box( 'wpinv-items', __( 'Invoice Items', 'invoicing' ), 'WPInv_Meta_Box_Items::output', 'wpi_invoice', 'normal', 'high' );
    add_meta_box( 'wpinv-notes', __( 'Invoice Notes', 'invoicing' ), 'WPInv_Meta_Box_Notes::output', 'wpi_invoice', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'wpinv_add_meta_boxes', 30, 2 );

function wpinv_save_meta_boxes( $post_id, $post, $update = false ) {
    remove_action( 'save_post', __FUNCTION__ );
    
    // $post_id and $post are required
    if ( empty( $post_id ) || empty( $post ) ) {
        return;
    }
        
    if ( !current_user_can( 'edit_post', $post_id ) || empty( $post->post_type ) ) {
        return;
    }
    
    // Dont' save meta boxes for revisions or autosaves
    if ( defined( 'DOING_AUTOSAVE' ) || is_int( wp_is_post_revision( $post ) ) || is_int( wp_is_post_autosave( $post ) ) ) {
        return;
    }
        
    if ( $post->post_type == 'wpi_invoice' ) {
        if ( ( defined( 'DOING_AJAX') && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
            return;
        }
    
        if ( isset( $_POST['wpinv_save_invoice'] ) && wp_verify_nonce( $_POST['wpinv_save_invoice'], 'wpinv_save_invoice' ) ) {
            WPInv_Meta_Box_Items::save( $post_id, $_POST, $post );
        }
    } else if ( $post->post_type == 'wpi_item' ) {
        // verify nonce
        if ( isset( $_POST['wpinv_vat_meta_box_nonce'] ) && wp_verify_nonce( $_POST['wpinv_vat_meta_box_nonce'], 'wpinv_item_meta_box_save' ) ) {
            $fields                                 = array();
            $fields['_wpinv_price']              = 'wpinv_item_price';
            $fields['_wpinv_vat_class']          = 'wpinv_vat_class';
            $fields['_wpinv_vat_rule']           = 'wpinv_vat_rules';
            $fields['_wpinv_type']               = 'wpinv_item_type';
            $fields['_wpinv_is_recurring']       = 'wpinv_is_recurring';
            $fields['_wpinv_recurring_period']   = 'wpinv_recurring_period';
            $fields['_wpinv_recurring_interval'] = 'wpinv_recurring_interval';
            $fields['_wpinv_recurring_limit']    = 'wpinv_recurring_limit';
            
            foreach ( $fields as $field => $name ) {
                if ( isset( $_POST[ $name ] ) ) {
                    if ( $field == '_wpinv_price' ) {
                        $value = wpinv_sanitize_amount( $_POST[ $name ] );
                    } else {
                        $value = is_string( $_POST[ $name ] ) ? sanitize_text_field( $_POST[ $name ] ) : $_POST[ $name ];
                    }
                    
                    $value = apply_filters( 'wpinv_item_metabox_save_' . $field, $value, $name );
                    
                    update_post_meta( $post_id, $field, $value );
                }
            }
        }
    }
}
add_action( 'save_post', 'wpinv_save_meta_boxes', 10, 3 );

function wpinv_bulk_and_quick_edit_save( $post_id, $post, $update = false ) {
    if ( !( !empty( $_POST['action'] ) && $_POST['action'] == 'inline-save' ) ) {
        return;
    }
    
    // $post_id and $post are required
    if ( empty( $post_id ) || empty( $post ) ) {
        return;
    }
        
    if ( !current_user_can( 'edit_post', $post_id ) || empty( $post->post_type ) ) {
        return;
    }
    
    // Dont' save meta boxes for revisions or autosaves
    if ( defined( 'DOING_AUTOSAVE' ) || is_int( wp_is_post_revision( $post ) ) || is_int( wp_is_post_autosave( $post ) ) ) {
        return;
    }

    if ( $post->post_type == 'wpi_item' ) {
        // verify nonce
        if ( isset( $_POST['_wpinv_item_price'] ) ) {
            update_post_meta( $post_id, '_wpinv_price', wpinv_sanitize_amount( $_POST['_wpinv_item_price'] ) );
        }
        
        if ( isset( $_POST['_wpinv_vat_class'] ) ) {
            update_post_meta( $post_id, '_wpinv_vat_class', sanitize_text_field( $_POST['_wpinv_vat_class'] ) );
        }

        if ( isset( $_POST['_wpinv_vat_rules'] ) ) {
            update_post_meta( $post_id, '_wpinv_vat_rule', sanitize_text_field( $_POST['_wpinv_vat_rules'] ) );
        }
        
        if ( isset( $_POST['_wpinv_item_type'] ) ) {
            update_post_meta( $post_id, '_wpinv_type', sanitize_text_field( $_POST['_wpinv_item_type'] ) );
        }
    }
}
add_action( 'save_post', 'wpinv_bulk_and_quick_edit_save', 10, 3 );

function wpinv_register_item_meta_boxes() {    
    add_meta_box( 'wpinv_field_prices', __( 'Item Price', 'invoicing' ), 'WPInv_Meta_Box_Items::prices', 'wpi_item', 'normal', 'high' );

    if ( wpinv_allow_vat_rules() ) {
        add_meta_box( 'wpinv_field_vat_rules', __( 'VAT rules type to use', 'invoicing' ), 'WPInv_Meta_Box_Items::vat_rules', 'wpi_item', 'normal', 'high' );
    }
    
    if ( wpinv_allow_vat_classes() ) {
        add_meta_box( 'wpinv_field_vat_classes', __( 'VAT rates class to use', 'invoicing' ), 'WPInv_Meta_Box_Items::vat_classes', 'wpi_item', 'normal', 'high' );
    }
    
    add_meta_box( 'wpinv_field_item_info', __( 'Item info', 'invoicing' ), 'WPInv_Meta_Box_Items::item_info', 'wpi_item', 'side', 'core' );
}

function wpinv_register_discount_meta_boxes() {
    add_meta_box( 'wpinv_discount_fields', __( 'Discount Details', 'invoicing' ), 'wpinv_discount_metabox_details', 'wpi_discount', 'normal', 'high' );
}

function wpinv_discount_metabox_details( $post ) {
    $discount_id    = $post->ID;
    $discount       = wpinv_get_discount( $discount_id );
    
    $type           = wpinv_get_discount_type( $discount_id );
    $min_total      = wpinv_get_discount_min_total( $discount_id );
    $max_total      = wpinv_get_discount_max_total( $discount_id );
    $max_uses       = wpinv_get_discount_max_uses( $discount_id );
    $single_use     = wpinv_discount_is_single_use( $discount_id );
    
    $min_total      = $min_total > 0 ? $min_total : '';
    $max_total      = $max_total > 0 ? $max_total : '';
    $max_uses       = $max_uses > 0 ? $max_uses : '';
?>
<?php do_action( 'wpinv_discount_form_top', $post ); ?>
<?php wp_nonce_field( 'wpinv_discount_metabox_nonce', 'wpinv_discount_metabox_nonce' ); ;?>
<table class="form-table wpi-form-table">
    <tbody>
        <?php do_action( 'wpinv_discount_form_first', $post ); ?>
        <?php do_action( 'wpinv_discount_form_before_code', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_code"><?php _e( 'Discount Code', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" name="code" id="wpinv_discount_code" class="medium-text" value="<?php echo esc_attr( wpinv_get_discount_code( $discount_id ) ); ?>" required>
                <p class="description"><?php _e( 'Enter a code for this discount, such as 10OFF', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_type', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_type"><?php _e( 'Discount Type', 'invoicing' ); ?></label>
            </th>
            <td>
                <select id="wpinv_discount_type" name="type" class="medium-text">
                    <?php foreach ( wpinv_get_discount_types() as $value => $label ) { ?>
                    <option value="<?php echo $value ;?>" <?php selected( $type, $value ); ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
                <p class="description"><?php _e( 'The kind of discount to apply for this discount.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_amount', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_amount"><?php _e( 'Amount', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" name="amount" id="wpinv_discount_amount" class="wpi-field-price wpi-price" value="<?php echo esc_attr( wpinv_get_discount_amount( $discount_id ) ); ?>" required> <font class="wpi-discount-p">%</font><font class="wpi-discount-f" style="display:none;"><?php echo wpinv_currency_symbol() ;?></font>
                <p style="display:none;" class="description"><?php _e( 'Enter the discount amount in USD', 'invoicing' ); ?></p>
                <p class="description"><?php _e( 'Enter the discount value. Ex: 10', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_start', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_start"><?php _e( 'Start date', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" class="medium-text wpiDatepicker" id="wpinv_discount_start" data-dateFormat="yy-mm-dd" name="start" value="<?php echo esc_attr( wpinv_get_discount_start_date( $discount_id ) ); ?>">
                <p class="description"><?php _e( 'Enter the start date for this discount code in the format of yyyy-mm-dd. For no start date, leave blank. If entered, the discount can only be used after or on this date.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_expiration', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_expiration"><?php _e( 'Expiration date', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" class="medium-text wpiDatepicker" id="wpinv_discount_expiration" data-dateFormat="yy-mm-dd" name="expiration" value="<?php echo esc_attr( wpinv_get_discount_expiration( $discount_id ) ); ?>">
                <p class="description"><?php _e( 'Enter the expiration date for this discount code in the format of yyyy-mm-dd. Leave blank for no expiration.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_min_total', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_min_total"><?php _e( 'Minimum Amount', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" name="min_total" id="wpinv_discount_min_total" class="wpi-field-price wpi-price" value="<?php echo $min_total; ?>">
                <p class="description"><?php _e( 'This allows you to set the minimum amount (subtotal, including taxes) allowed when using the discount.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_max_total', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_max_total"><?php _e( 'Maximum Amount', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="text" name="max_total" id="wpinv_discount_max_total" class="wpi-field-price wpi-price" value="<?php echo $max_total; ?>">
                <p class="description"><?php _e( 'This allows you to set the maximum amount (subtotal, including taxes) allowed when using the discount.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_max_uses', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_max_uses"><?php _e( 'Max Uses', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="number" min="0" step="1" id="wpinv_discount_max_uses" name="max_uses" class="medium-text" value="<?php echo $max_uses; ?>">
                <p class="description"><?php _e( 'The maximum number of times this discount can be used. Leave blank for unlimited.', 'invoicing' ); ?></p>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_before_single_use', $post ); ?>
        <tr>
            <th valign="top" scope="row">
                <label for="wpinv_discount_single_use"><?php _e( 'Use Once Per User', 'invoicing' ); ?></label>
            </th>
            <td>
                <input type="checkbox" value="1" name="single_use" id="wpinv_discount_single_use" <?php checked( true, $single_use ); ?>>
                <span class="description"><?php _e( 'Limit this discount to a single use per user?', 'invoicing' ); ?></span>
            </td>
        </tr>
        <?php do_action( 'wpinv_discount_form_last', $post ); ?>
    </tbody>
</table>
<?php do_action( 'wpinv_discount_form_bottom', $post ); ?>
    <?php
}

function wpinv_discount_metabox_save( $post_id, $post, $update = false ) {
    $post_type = !empty( $post ) ? $post->post_type : '';
    
    if ( $post_type != 'wpi_discount' ) {
        return;
    }
    
    if ( !isset( $_POST['wpinv_discount_metabox_nonce'] ) || ( isset( $_POST['wpinv_discount_metabox_nonce'] ) && !wp_verify_nonce( $_POST['wpinv_discount_metabox_nonce'], 'wpinv_discount_metabox_nonce' ) ) ) {
        return;
    }
    
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX') && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
        return;
    }
    
    if ( !current_user_can( 'manage_options', $post_id ) ) {
        return;
    }
    
    return wpinv_store_discount( $post_id, $_POST, $post, $update );
}
add_action( 'save_post', 'wpinv_discount_metabox_save', 10, 3 );