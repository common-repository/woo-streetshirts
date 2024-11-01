<?php
/*
Plugin Name: Woo Streetshirts
Plugin URI: http://www.streetshirts.co.uk
Description: Connect streetshirts to your WooCommerce store. White-label T-shirt drop-shipping from the U.K.
Version: 1.0.4
Author: streetshirts
Author URI: http://www.streetshirts.com/
Developer: Steve Winn
Developer URI: https://connect.streetshirts.com
Text Domain: woo-streetshirts
Copyright: © 2016-2018 streetshirts.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    //Add page to menu
    function register_streetshirts_home_page() {
        add_submenu_page( 'woocommerce', 'streetshirts:connect', 'streetshirts', 'manage_options', 'streetshirts-home-page', 'streetshirts_home_page_callback' );
    }

    function streetshirts_is_installed(){
        global $wpdb;

        //Get truncated API key. We use this as password to encrypt a test value.
        //A pre-shared key only known to woocommerce and streetshirts
        $trunc_api_key = null;
        foreach($wpdb->get_results(
            "SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM {$wpdb->prefix}woocommerce_api_keys WHERE 1 = 1 {$search}"
        ) as $key => $row) {
            $my_column = $row->truncated_key;
            if (0 === strpos($row->description, 'streetshirts')) {
                $trunc_api_key = $my_column;
            }
        }

        //Either the key exists or the app has not yet been fully installed.
        //We use this to authenticate request to our site. No worry about the static IV. Each key only encrypts one item
        //No data stored here
        global $streetshirts_token;
        if ( is_null( $trunc_api_key ) )
            return false;
        else {
            //Calculate token
            $password = $trunc_api_key;
            $method = 'AES-256-CBC';
            $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on" ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
            $iv = chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1) . chr(0x0) . chr(0x1);
            $encrypted = openssl_encrypt($actual_link, $method, $password, 0,$iv);
            $streetshirts_token = $encrypted; //We use this to verify requests. $trunc_api_key acts as a pre-shared key.
            return true;
        }
    }

    function streetshirts_home_page_callback() {
        $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on" ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        if(streetshirts_is_installed()) { //In case user deletes keys we check each time.
            echo '<iframe src="https://connect.streetshirts.com/ext/woo/default.aspx?shop=' . $actual_link . '&hash=' . $GLOBALS['streetshirts_token'] . '" frameborder="0" style="overflow:hidden;width:100%" width="100%" id="streetshirts_iframe" scrolling="no"></iframe>';
            echo '<script>iFrameResize({checkOrigin:false, heightCalculationMethod:"max"}, \'#streetshirts_iframe\')</script>';
        }
        else{
            echo '<iframe src="https://connect.streetshirts.com/ext/woo/install.aspx?shop=' . $actual_link . '" frameborder="0" style="overflow:hidden;width:100%;height:1000px" width="100%" height="1000px" id="streetshirts_iframe" scrolling="no"></iframe>';
        }
    }

    add_action('admin_menu', 'register_streetshirts_home_page',99);

    // define the item in the meta box by adding an item to the $actions array
    function ss_wc_add_order_meta_box_actions( $actions ) {
        global $theorder;

        // bail if the order has been not paid for or this action has been run
        if ( ! $theorder->is_paid() || get_post_meta( $theorder->get_id() , '_wc_order_marked_sent_to_streetshirts', true ) ) {
            return $actions;
        }

        // add "streetshirts" custom action
        $actions['ns_streetshirts_connect'] = __( 'Send to streetshirts connect', 'streetshirts' );
        return $actions;
    }

    // add our own item to the order actions meta box
    add_action( 'woocommerce_order_actions', 'ss_wc_add_order_meta_box_actions' );

    // run the code that should execute with this action is triggered
    function ss_wc_process_order_meta_box_actions( $order ) {
        $message = sprintf( __( 'Attempting to send [streetshirts]', 'streetshirts' ));
        $order->add_order_note( $message );
        $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on" ? "https" : "http") . "://$_SERVER[HTTP_HOST]";

        if(streetshirts_is_installed()) {
            $resp = wp_remote_get("Location: https://connect.streetshirts.com/ext/woo/go.aspx?shop=" . $actual_link . "&id=" . $order->get_id() . "&hash=" . $GLOBALS['streetshirts_token'], array('timeout' => 240, 'httpversion' => '1.1'));
            $response = wp_remote_retrieve_body($resp);

            if (strpos($response, 'Order Successfully Stored') !== false) {
                $message = sprintf(__('Order Successfully Stored [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);

                update_post_meta($order->id, '_wc_order_marked_sent_to_streetshirts', 'yes');
            }

            if (strpos($response, 'Credit Stop') !== false) {
                $message = sprintf(__('Order Failed To Send: Credit Stop [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Credit Limit Reached') !== false) {
                $message = sprintf(__('Order Failed To Send: Credit Limit Reached [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Too Many Items') !== false) {
                $message = sprintf(__('Order Failed To Send: Too Many Items [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Order Already Submitted') !== false) {
                $message = sprintf(__('Order Failed To Send: Duplicate [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'No Items') !== false) {
                $message = sprintf(__('Order Failed To Send: No Items [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Bad Request (No Action Taken)') !== false) {
                $message = sprintf(__('Order Failed To Send: Bad Request (No Action Taken) [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Product Data Error') !== false) {
                $message = sprintf(__('Order Failed To Send: Product Data Error [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Plugin Offline') !== false) {
                $message = sprintf(__('Order Failed To Send: Plugin Offline (retry in 20 minutes) [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }

            if (strpos($response, 'Customer Data Incomplete') !== false) {
                $message = sprintf(__('Order Failed To Send: Customer Data Incomplete [streetshirts]', 'streetshirts'));
                $order->add_order_note($message);
            }
        }
    }

    // process the custom order meta box order action
    add_action( 'woocommerce_order_action_ns_streetshirts_connect', 'ss_wc_process_order_meta_box_actions' );

    function streetshirts_scripts_with_jquery()
    {
        wp_enqueue_script( 'streetshirts-iframe-resizer', plugins_url( '/js/iframeResizer.min.js', __FILE__ ), array('jquery'), '1.0.0', false);
    }
    add_action( 'admin_enqueue_scripts', 'streetshirts_scripts_with_jquery' );
}