<?php
if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

class MeprBitpayAPI
{
    private $plugin_name;
    private $public_key;
    private $secret_key;

    public function __construct($settings)
    {
        $this->plugin_name = 'Shadstone BitPay Integration';
        $this->secret_key = isset($settings->secret_key) ? $settings->secret_key : '';
        $this->public_key = isset($settings->public_key) ? $settings->public_key : '';
    }
}
