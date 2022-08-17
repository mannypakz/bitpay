<?php
if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}
/*
Integration of Bitpay into MemberPress
*/
class MpBitpay
{
    public function __construct()
    {
        // Add Gateway Path
        add_filter('mepr-gateway-paths', array($this, 'add_mepr_gateway_paths'));

        // Add Option Scripts
        add_action('mepr-options-admin-enqueue-script', array($this, 'add_options_admin_enqueue_script'));
    }

    //Add Bitpay path to general gateway page
    public function add_mepr_gateway_paths($tabs)
    {
        array_push($tabs, MP_BITPAY_PATH);
        return $tabs;
    }

    public static function add_options_admin_enqueue_script($hook)
    {
        if ($hook == 'memberpress_page_memberpress-options') {
            wp_enqueue_script(
                'mp-bitpay-options-js',
                MP_BITPAY_JS_URL . '/admin_options.js',
                array(
                    'jquery',
                )
            );
            return $hook;
        }
    }
}
