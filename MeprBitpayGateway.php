<?php
if (!defined('ABSPATH')) {
  die('You are not allowed to call this page directly.');
}

class MeprPaystackGateway extends MeprBaseRealGateway
{
  public static $paystack_plan_id_str = '_mepr_paystack_plan_id';

  /** This will be where the gateway api will interacted from */
  public $paystack_api;

  /** Used in the view to identify the gateway */
  public function __construct()
  {
    $this->name = __("Bitpay", 'memberpress');
    $this->icon = MP_PAYSTACK_IMAGES_URL . '/card.png';
    $this->desc = __('Pay via Bitpay', 'memberpress');
    $this->set_defaults();

    $this->capabilities = array(
      'process-payments',
      'process-refunds',
      'create-subscriptions',
      'cancel-subscriptions',
      'suspend-subscriptions',
      'resume-subscriptions',
      'send-cc-expirations'
    );

    // Setup the notification actions for this gateway
    $this->notifiers = array(
      'whk' => 'webhook_listener',
      'callback' => 'callback_handler',
    );

    $this->message_pages = array('subscription' => 'subscription_message');
  }

  public function load($settings)
  {
    $this->settings = (object) $settings;
    $this->set_defaults();
    $this->paystack_api = new MeprPaystackAPI($this->settings);
  }

  protected function set_defaults()
  {
    if (!isset($this->settings)) {
      $this->settings = array();
    }

    $this->settings = (object) array_merge(
      array(
        'gateway' => 'MeprPaystackGateway',
        'id' => $this->generate_id(),
        'label' => '',
        'use_label' => true,
        'icon' => MP_PAYSTACK_IMAGES_URL . '/bitpay.png',
        'use_icon' => true,
        'use_desc' => true,
        'email' => '',
        'sandbox' => false,
        'force_ssl' => false,
        'debug' => false,
        'test_mode' => false,
        'api_keys' => array(
          'test' => array(
            'public' => '',
            'secret' => ''
          ),
          'live' => array(
            'public' => '',
            'secret' => ''
          )
        )
      ),
      (array) $this->settings
    );

    $this->id = $this->settings->id;
    $this->label = $this->settings->label;
    $this->use_label = $this->settings->use_label;
    $this->use_icon = $this->settings->use_icon;
    $this->use_desc = $this->settings->use_desc;
    // $this->has_spc_form = $this->settings->use_paystack_checkout ? false : true;
    //$this->recurrence_type = $this->settings->recurrence_type;

    if ($this->is_test_mode()) {
      $this->settings->public_key = trim($this->settings->api_keys['test']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['test']['secret']);
    } else {
      $this->settings->public_key = trim($this->settings->api_keys['live']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['live']['secret']);
    }
  }

  /** 
   * Used to send data to a given payment gateway. In gateways which redirect
   * before this step is necessary this method should just be left blank.
   */
  public function process_payment($txn, $trial = false)
  {
    if (isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    } else {
      throw new MeprGatewayException(__('Payment transaction intialization was unsuccessful, please try again.', 'memberpress'));
    }

    $mepr_options = MeprOptions::fetch();

    // Handle zero decimal currencies in Paystack
    $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($txn->total), 0) : MeprUtils::format_float(($txn->total * 100), 0);

    // Initialize the charge on Paystack's servers - this will charge the user's card
    $args = MeprHooks::apply_filters('mepr_paystack_payment_args', array(
      'amount' => $amount,
      'currency' => $mepr_options->currency_code,
      'email'   => $usr->user_email,
      'reference' => $txn->trans_num,
      'callback_url' => $this->notify_url('callback'),
      'description' => sprintf(__('%s (transaction: %s)', 'memberpress'), $prd->post_title, $txn->id),
      'metadata' => array(
        'platform'       => 'MemberPress Paystack',
        'transaction_id' => $txn->id,
        'trial_payment'  => $trial,
        'site_url'       => esc_url(get_site_url()),
        'ip_address'     => $_SERVER['REMOTE_ADDR']
      )
    ), $txn);

    // Initialize a new payment here
    $response = (object) $this->paystack_api->send_request("transaction/initialize/", $args);

    if (!$response->status) {
      return false;
    }

    return MeprUtils::wp_redirect("{$response->data['authorization_url']}");
  }

  /** 
   * Used to record a successful payment by the given gateway. It should have
   * the ability to record a successful payment or a failure. It is this method
   * that should be used when receiving a Paystack Webhook.
   */
  public function record_payment()
  {
    $this->email_status("Starting record_payment: " . MeprUtils::object_to_string($_REQUEST), $this->settings->debug);

    if (isset($_REQUEST['data'])) {
      $charge = $this->get_request_data();

      $this->email_status("record_payment: \n" . MeprUtils::object_to_string($charge, true) . "\n", $this->settings->debug);

      $obj = MeprTransaction::get_one_by_trans_num($charge->reference);

      if (is_object($obj) and isset($obj->id)) {
        $txn = new MeprTransaction;
        $txn->load_data($obj);

        $usr = $txn->user();

        // Just short circuit if the txn has already completed
        if ($txn->status == MeprTransaction::$complete_str)
          return $txn;

        $txn->status  = MeprTransaction::$complete_str;
        // This will only work before maybe_cancel_old_sub is run
        $upgrade = $txn->is_upgrade();
        $downgrade = $txn->is_downgrade();

        $event_txn = $txn->maybe_cancel_old_sub();
        $txn->store();

        if ($charge->metadata['trial_payment'] == "1") {
          $this->record_trial_payment($txn);
        }

        // Set Auth Token for Current User
        $this->set_auth_token($usr, $charge->authorization);

        $this->email_status("Standard Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

        $prd = $txn->product();

        if ($prd->period_type == 'lifetime') {
          if ($upgrade) {
            $this->upgraded_sub($txn, $event_txn);
          } else if ($downgrade) {
            $this->downgraded_sub($txn, $event_txn);
          } else {
            $this->new_sub($txn);
          }

          MeprUtils::send_signup_notices($txn);
        }

        MeprUtils::send_transaction_receipt_notices($txn);
        // MeprUtils::send_cc_expiration_notices($txn);
        return $txn;
      }
    }

    return false;
  }

  /** 
   * Used to send subscription data to a given payment gateway. In gateways
   * which redirect before this step is necessary this method should just be
   * left blank.
   */
  public function process_create_subscription($txn)
  {
    if (isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    } else {
      throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
    }

    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    //Handle Free Trial period stuff
    if ($sub->trial) {
      //Prepare the $txn for the process_payment method
      $txn->set_subtotal($sub->trial_amount);
      $txn->status = MeprTransaction::$pending_str;
      $this->record_trial_payment($txn);
      return $txn;
    }

    error_log("********** MeprPaystackGateway::process_create_subscription Subscription:\n" . MeprUtils::object_to_string($sub));

    // Get the customer
    // $customer = $this->paystack_customer($txn->subscription_id);

    // $sub->subscr_id = $customer->customer_code;
    // $sub->subscr_id = 'ts_' . uniqid();
    // $sub->store();

    // Get the plan
    $plan = $this->paystack_plan($txn->subscription(), true);

    //Reload the subscription now that it should have a token set
    $sub = new MeprSubscription($sub->id);

    // Default to 0 for infinite occurrences
    $total_occurrences = $sub->limit_cycles ? $sub->limit_cycles_num : 0;

    $args = MeprHooks::apply_filters('mepr_paystack_subscription_args', array(
      'callback_url' => $this->notify_url('callback'),
      'reference' => $txn->trans_num, // MeprTransaction::generate_trans_num(),
      'email' => $usr->user_email,
      'plan' => $plan->plan_code,
      'invoice_limit' => $total_occurrences,
      'currency' => $mepr_options->currency_code,
      'channels' => ['card'], // Set payment channel to accept only card for subscriptions
      "start_date" => MeprUtils::get_date_from_ts((time() + (($sub->trial) ? MeprUtils::days($sub->trial_days) : 0)), 'Y-m-d'),
      'metadata' => array(
        'platform' => 'MemberPress Paystack',
        'transaction_id' => $txn->id,
        'subscription_id' => $sub->id,
        'site_url' => esc_url(get_site_url()),
        'ip_address' => $_SERVER['REMOTE_ADDR']
      )
    ), $txn, $sub);

    $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn, true) . "\n", $this->settings->debug);

    error_log("********** MeprPaystackGateway::process_create_subscription altered Subscription:\n" . MeprUtils::object_to_string($sub));
    error_log("********** MeprPaystackGateway::process_create_subscription Transaction:\n" . MeprUtils::object_to_string($txn));

    // Initialize a new payment here
    $response = (object) $this->paystack_api->send_request("transaction/initialize/", $args);
    if ($response->status) {
      return MeprUtils::wp_redirect("{$response->data['authorization_url']}");
    }
    return false;
  }

  /** 
   * This method should be used by the class to record a successful subscription transaction from
   * the gateway. This method should also be used by a Silent Posts.
   */
  public function record_transaction_for_subscription()
  {
    $mepr_options = MeprOptions::fetch();

    if (isset($_REQUEST['data'])) {
      $charge = $this->get_request_data();

      // Get Transaction from paystack reference or charge id
      $obj = MeprTransaction::get_one($charge->metadata['transaction_id']) ?? MeprTransaction::get_one_by_trans_num($charge->reference);

      if (!is_object($obj) and !isset($obj->id)) {
        MeprUtils::wp_redirect($mepr_options->account_page_url('action=subscriptions'));
      }

      $txn = new MeprTransaction();
      $txn->load_data($obj);

      if ($txn->status == MeprTransaction::$pending_str && $charge->status == 'success') {
        $txn->status == MeprTransaction::$confirmed_str;
        $txn->store();
      }

      //Reload the txn now that it should have a proper trans_num set
      $txn = new MeprTransaction($txn->id);

      return $txn;
    }
  }

  /** 
   * Used to record a successful subscription by the given gateway. It should have
   * the ability to record a successful subscription or a failure. It is this method
   * that should be used when receiving a Paystack Webhook.
   */
  public function record_create_subscription()
  {
    $mepr_options = MeprOptions::fetch();

    if (isset($_REQUEST['data'])) {
      $sdata = $this->get_request_data();

      error_log("********** MeprPaystackGateway::record_create_subscription sData: \n" . MeprUtils::object_to_string($sdata));

      $sub = $this::get_subscr_by_plan_code($sdata->plan['plan_code']);
      if (!$sub) {
        return false;
      }

      error_log("********** MeprPaystackGateway::record_create_subscription Subscription: \n" . MeprUtils::object_to_string($sub));

      $sub->status    = MeprSubscription::$active_str;
      $sub->subscr_id = $sdata->subscription_code;

      $card = $this->get_card($sdata);
      if (!empty($card) && $card['reusable']) {
        $sub->cc_last4 = $card['last4'];
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year = $card['exp_year'];
      }

      $sub->created_at = gmdate('c');
      $sub->store();

      // Add the email token, customer and subcription code and as metadata
      $sub->update_meta('paystack_email_token', $sdata->email_token);
      $sub->update_meta('paystack_customer_code', $sdata->customer['customer_code']);
      $sub->update_meta('paystack_subscription_code', $sdata->subscription_code);

      // This will only work before maybe_cancel_old_sub is run
      $upgrade   = $sub->is_upgrade();
      $downgrade = $sub->is_downgrade();

      $event_txn = $sub->maybe_cancel_old_sub();

      $txn = $sub->first_txn();

      if ($txn == false || !($txn instanceof MeprTransaction)) {
        $txn = new MeprTransaction();
        $txn->user_id = $sub->user_id;
        $txn->product_id = $sub->product_id;
      }

      $old_total = $txn->total;

      // If no trial or trial amount is zero then we've got to make
      // sure the confirmation txn lasts through the trial
      if (!$sub->trial || ($sub->trial && $sub->trial_amount <= 0.00)) {
        $trial_days      = ($sub->trial) ? $sub->trial_days : $mepr_options->grace_init_days;
        $txn->status     = MeprTransaction::$confirmed_str;
        $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($trial_days), 'Y-m-d 23:59:59');
        $txn->set_subtotal(0.00); // Just a confirmation txn
        $txn->store();
      }

      $txn->set_gross($old_total); // Artificially set the subscription amount

      if ($upgrade) {
        $this->upgraded_sub($sub, $event_txn);
      } else if ($downgrade) {
        $this->downgraded_sub($sub, $event_txn);
      } else {
        $this->new_sub($sub, true);
      }

      //Reload the txn now that it should have a proper trans_num set
      $txn = new MeprTransaction($txn->id);

      MeprUtils::send_signup_notices($txn);

      return array('subscription' => $sub, 'transaction' => $txn);
    }

    return false;
  }

  /** 
   * Used to record a successful recurring payment by the given gateway. It
   * should have the ability to record a successful payment or a failure. It is
   * this method that should be used when receiving a Paystack Webhook.
   */
  public function record_subscription_payment()
  {
    if (isset($_REQUEST['data'])) {
      $sdata = $this->get_request_data();

      error_log("********** MeprPaystackGateway::record_subscription_payment sData: \n" . MeprUtils::object_to_string($sdata));

      if (isset($sdata->invoice_code)) {
        if ($sdata->paid == true) {
          return $this->record_subscription_invoice($sdata);
        }

        return $this->record_payment_failure();
      }

      return $this->record_subscription_charge($sdata);
    }

    return false;
  }

  protected function record_subscription_invoice($invoice)
  {
    // Make sure there's a valid subscription for this request and this payment hasn't already been recorded
    if (
      !($sub = MeprSubscription::get_one_by_subscr_id($invoice->subscription['subscription_code'])) && MeprTransaction::txn_exists($invoice->transaction['reference'])
    ) {
      return false;
    }

    //If this isn't for us, bail
    if ($sub->gateway != $this->id) {
      return false;
    }

    $first_txn = $txn = $sub->first_txn();

    if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
      $coupon_id = $sub->coupon_id;
    } else {
      $coupon_id = $first_txn->coupon_id;
    }

    $this->email_status(
      "record_subscription_invoice:" .
        "\nSubscription: " . MeprUtils::object_to_string($sub, true) .
        "\nTransaction: " . MeprUtils::object_to_string($txn, true),
      $this->settings->debug
    );

    $txn = new MeprTransaction();
    $txn->user_id    = $sub->user_id;
    $txn->product_id = $sub->product_id;
    $txn->status     = MeprTransaction::$complete_str;
    $txn->coupon_id  = $coupon_id;
    $txn->trans_num  = $invoice->transaction['reference'] ?? MeprTransaction::generate_trans_num();
    $txn->gateway    = $this->id;
    $txn->subscription_id = $sub->id;

    if (MeprUtils::is_zero_decimal_currency()) {
      $txn->set_gross((float) $invoice->amount);
    } else {
      $txn->set_gross((float) $invoice->amount / 100);
    }

    $txn->expires_at = MeprUtils::ts_to_mysql_date($invoice['subscription']->next_payment_date, 'Y-m-d 23:59:59');

    $txn->store();

    $usr = $txn->user();
    // Set Auth Token for Current User
    $this->set_auth_token($usr, $invoice->authorization);

    $sub->status = MeprSubscription::$active_str;
    $sub->update_meta('paystack_invoice_code', $invoice->invoice_code);
    $sub->update_meta('paystack_email_token', $invoice->subscription['email_token']);

    if ($card = $this->get_card($invoice)) {
      $sub->cc_exp_month = $card['exp_month'];
      $sub->cc_exp_year  = $card['exp_year'];
      $sub->cc_last4     = $card['last4'];
    }

    // $sub->subscr_id = $invoice->subscription['subscription_code'];
    // $sub->gateway = $this->id;
    $sub->store();

    // If a limit was set on the recurring cycles we need
    // to cancel the subscr if the txn_count >= limit_cycles_num
    // This is not possible natively with Paystack so we
    // just cancel the subscr when limit_cycles_num is hit
    $sub->limit_payment_cycles();

    $this->email_status(
      "Subscription Invoice\n" .
        MeprUtils::object_to_string($txn->rec, true),
      $this->settings->debug
    );

    MeprUtils::send_transaction_receipt_notices($txn);
    MeprUtils::send_cc_expiration_notices($txn);

    return $txn;
  }

  protected function record_subscription_charge($charge)
  {
    error_log("********** MeprPaystackGateway::record_subscription_charge Charge: \n" . MeprUtils::object_to_string($charge));

    // Make sure there's a valid subscription for this request and this payment hasn't already been recorded
    if (
       !($sub = $this::get_subscr_by_plan_code($charge->plan['plan_code'])) && MeprTransaction::txn_exists($charge->reference)
    ) {
      return false;
    }

    error_log("********** MeprPaystackGateway::record_subscription_charge Subscription: \n" . MeprUtils::object_to_string($sub));

    //If this isn't for us, bail
    if ($sub->gateway != $this->id) {
      return false;
    }

    $txn = $sub->first_txn();

    error_log("********** MeprPaystackGateway::record_subscription_charge Transaction: \n" . MeprUtils::object_to_string($txn));

    if ($txn == false || !($txn instanceof MeprTransaction)) {
      $coupon_id = $sub->coupon_id;
    } else {
      $coupon_id = $txn->coupon_id;
    }

    $this->email_status(
      "record_subscription_charge:" .
        "\nSubscription: " . MeprUtils::object_to_string($sub, true) .
        "\nTransaction: " . MeprUtils::object_to_string($txn, true),
      $this->settings->debug
    );

    $txn             = new MeprTransaction();
    $txn->user_id    = $sub->user_id;
    $txn->product_id = $sub->product_id;
    $txn->status     = MeprTransaction::$complete_str;
    $txn->coupon_id  = $coupon_id;
    $txn->trans_num  = $charge->reference;
    $txn->gateway    = $this->id;
    $txn->subscription_id = $sub->id;

    if (MeprUtils::is_zero_decimal_currency()) {
      $txn->set_gross((float) $charge->amount);
    } else {
      $txn->set_gross((float) $charge->amount / 100);
    }

    // $txn->expires_at = MeprUtils::ts_to_mysql_date($sdata['subscription']['current_period_end'], 'Y-m-d 23:59:59');

    $txn->store();

    $usr = $txn->user();

    // Set Auth Token for Current User
    $this->set_auth_token($usr, $charge->authorization);

    // Let's sleep the process, so we can wait for the subscription create webhook to be handled fully.
    // sleep(10);

    // $sub->subscr_id = $sub->get_meta('paystack_subscription_code', true);
    $sub->status = MeprSubscription::$active_str;

    if ($card = $this->get_card($charge)) {
      $sub->cc_exp_month = $card['exp_month'];
      $sub->cc_exp_year  = $card['exp_year'];
      $sub->cc_last4     = $card['last4'];
    }

    $sub->store();

    // If a limit was set on the recurring cycles we need
    // to cancel the subscr if the txn_count >= limit_cycles_num
    // This is not possible natively with Paystack so we
    // just cancel the subscr when limit_cycles_num is hit
    $sub->limit_payment_cycles();

    $this->email_status(
      "Subscription Transaction\n" .
        MeprUtils::object_to_string($txn->rec, true),
      $this->settings->debug
    );

    //Reload the txn
    $txn = new MeprTransaction($txn->id);

    MeprUtils::send_transaction_receipt_notices($txn);
    MeprUtils::send_cc_expiration_notices($txn);

    return $txn;
  }

  /**
   * @param $plan_code
   *
   * @return bool|MeprSubscription
   */
  protected static function get_subscr_by_plan_code($plan_code)
  {
    global $wpdb;
    $mepr_db = new MeprDb();

    $sql = "
      SELECT sub.id
        FROM {$mepr_db->subscriptions} AS sub
       WHERE sub.token=%s
       ORDER BY sub.id DESC
       LIMIT 1
    ";

    $sql = $wpdb->prepare($sql, $plan_code);
    //error_log("********** MeprPaystackGateway::get_subscr_by_plan_code SQL: \n" . MeprUtils::object_to_string($sql));

    $sub_id = $wpdb->get_var($sql);
    //error_log("********** MeprPaystackGateway::get_subscr_by_plan_code sub_id: {$sub_id}\n");

    if ($sub_id) {
      return new MeprSubscription($sub_id);
    } else {
      return false;
    }
  }

  /** 
   * Used to cancel a subscription by the given gateway. This method should be used
   * by the class to record a successful cancellation from the gateway. This method
   * should also be used by any IPN requests or Silent Posts.
   *
   * We bill the outstanding amount of the previous subscription,
   * cancel the previous subscription and create a new subscription
   */
  public function process_update_subscription($sub_id)
  {
  }

  /** This method should be used by the class to record a successful cancellation
   * from the gateway. This method should also be used by any IPN requests or
   * Silent Posts.
   */
  public function record_update_subscription()
  {
    // No need for this one with paystack
  }

  /** Used to suspend a subscription by the given gateway.
   */
  public function process_suspend_subscription($sub_id)
  {
    $sub = new MeprSubscription($sub_id);

    $args = MeprHooks::apply_filters('mepr_paystack_suspend_subscription_args', array(
      'code' => $sub->subscr_id,
      'token' => $sub->get_meta('paystack_email_token'),
    ), $sub);

    // Yeah ... we're cancelling here bro ... with paystack we should be able to restart again
    $res = $this->paystack_api->send_request("subscription/disable", $args);

    $_REQUEST['recurring_payment_id'] = $sub->subscr_id;

    return $this->record_suspend_subscription();
  }

  /** This method should be used by the class to record a successful suspension
   * from the gateway.
   */
  public function record_suspend_subscription()
  {
    $subscr_id = sanitize_text_field($_REQUEST['recurring_payment_id']);
    $sub = MeprSubscription::get_one_by_subscr_id($subscr_id);

    if (!$sub) {
      return false;
    }

    // Seriously ... if sub was already suspended what are we doing here?
    if ($sub->status == MeprSubscription::$suspended_str) {
      return $sub;
    }

    $sub->status = MeprSubscription::$suspended_str;
    $sub->store();

    MeprUtils::send_suspended_sub_notices($sub);

    return $sub;
  }

  /** 
   * Used to suspend a subscription by the given gateway.
   */
  public function process_resume_subscription($sub_id)
  {
    $mepr_options = MeprOptions::fetch();
    MeprHooks::do_action('mepr-pre-paystack-resume-subscription', $sub_id); //Allow users to change the subscription programatically before resuming it
    $sub = new MeprSubscription($sub_id);

    $orig_trial        = $sub->trial;
    $orig_trial_days   = $sub->trial_days;
    $orig_trial_amount = $sub->trial_amount;

    if ($sub->is_expired() and !$sub->is_lifetime()) {
      $expiring_txn = $sub->expiring_txn();

      // if it's already expired with a real transaction
      // then we want to resume immediately
      if (
        $expiring_txn != false && $expiring_txn instanceof MeprTransaction &&
        $expiring_txn->status != MeprTransaction::$confirmed_str
      ) {
        $sub->trial = false;
        $sub->trial_days = 0;
        $sub->trial_amount = 0.00;
        $sub->store();
      }
    } else {
      $sub->trial = true;
      $sub->trial_days = MeprUtils::tsdays(strtotime($sub->expires_at) - time());
      $sub->trial_amount = 0.00;
      $sub->store();
    }

    // Create new plan with optional trial in place ...
    // $plan = $this->paystack_plan($sub, true);

    $sub->trial        = $orig_trial;
    $sub->trial_days   = $orig_trial_days;
    $sub->trial_amount = $orig_trial_amount;
    $sub->store();

    $args = MeprHooks::apply_filters('mepr_paystack_resume_subscription_args', array(
      'code' => $sub->subscr_id,
      'token' => $sub->get_meta('paystack_email_token'),
    ), $sub);

    $this->email_status(
      "process_resume_subscription: \n" .
        MeprUtils::object_to_string($sub, true) . "\n",
      $this->settings->debug
    );

    $this->paystack_api->send_request("subscription/enable", $args);

    $_REQUEST['recurring_payment_id'] = $sub->subscr_id;
    return $this->record_resume_subscription();
  }

  /** This method should be used by the class to record a successful resuming of
   * as subscription from the gateway.
   */
  public function record_resume_subscription()
  {
    $subscr_id = sanitize_text_field($_REQUEST['recurring_payment_id']);
    $sub = MeprSubscription::get_one_by_subscr_id($subscr_id);

    if (!$sub) {
      return false;
    }

    // Seriously ... if sub was already active what are we doing here?
    if ($sub->status == MeprSubscription::$active_str) {
      return $sub;
    }

    $sub->status = MeprSubscription::$active_str;
    $sub->store();

    //Check if prior txn is expired yet or not, if so create a temporary txn so the user can access the content immediately
    $prior_txn = $sub->latest_txn();
    if ($prior_txn == false || !($prior_txn instanceof MeprTransaction) || strtotime($prior_txn->expires_at) < time()) {
      $txn = new MeprTransaction();
      $txn->subscription_id = $sub->id;
      $txn->trans_num  = $sub->subscr_id . '-' . uniqid();
      $txn->status     = MeprTransaction::$confirmed_str;
      $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
      $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days(1), 'Y-m-d H:i:s');
      $txn->set_subtotal(0.00); // Just a confirmation txn
      $txn->store();
    }

    MeprUtils::send_resumed_sub_notices($sub);

    return $sub;
  }

  /** Used to cancel a subscription by the given gateway. This method should be used
   * by the class to record a successful cancellation from the gateway. This method
   * should also be used by any IPN requests or Silent Posts.
   */
  public function process_cancel_subscription($sub_id)
  {
    $sub = new MeprSubscription($sub_id);

    if (!isset($sub->id) || (int) $sub->id <= 0)
      throw new MeprGatewayException(__('This subscription is invalid.', 'memberpress'));

    $args = MeprHooks::apply_filters('mepr_paystack_cancel_subscription_args', array(
      'code' => $sub->subscr_id,
      'token' => $sub->get_meta('paystack_email_token'),
    ), $sub);

    // Yeah ... we're cancelling here bro ... but this time we don't want to restart again
    $res = $this->paystack_api->send_request("subscription/disable", $args);

    $_REQUEST['recurring_payment_id'] = $sub->subscr_id;

    return $this->record_cancel_subscription();
  }

  /** This method should be used by the class to record a successful cancellation
   * from the gateway. This method should also be used by any IPN requests or
   * Silent Posts.
   */
  public function record_cancel_subscription()
  {
    $subscr_id = $_REQUEST['recurring_payment_id'] ?? $_REQUEST['data']->subscription_code;
    $sub = MeprSubscription::get_one_by_subscr_id($subscr_id);

    if (!$sub) {
      return false;
    }

    // Seriously ... if sub was already cancelled what are we doing here?
    if ($sub->status == MeprSubscription::$cancelled_str) {
      return $sub;
    }

    $sub->status = MeprSubscription::$cancelled_str;
    $sub->store();

    $sub->limit_reached_actions();

    MeprUtils::send_cancelled_sub_notices($sub);

    return $sub;
  }

  public function process_trial_payment($txn)
  {
    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    //Prepare the $txn for the process_payment method
    $txn->set_subtotal($sub->trial_amount);
    $txn->status = MeprTransaction::$pending_str;

    //Attempt processing the payment here 
    $this->process_payment($txn, true);
  }

  public function record_trial_payment($txn)
  {
    $sub = $txn->subscription();

    //Update the txn member vars and store
    $txn->txn_type = MeprTransaction::$payment_str;
    $txn->status = MeprTransaction::$complete_str;
    $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($sub->trial_days), 'Y-m-d 23:59:59');
    $txn->store();

    return true;
  }

  /** 
   * Used to record a declined payment. 
   */
  public function record_payment_failure()
  {
    if (isset($_REQUEST['data'])) {
      $charge = $this->get_request_data();
      $txn_ref = sanitize_text_field($_REQUEST['reference']);
      $txn_res = MeprTransaction::get_one_by_trans_num($txn_ref);

      if (is_object($txn_res) and isset($txn_res->id)) {
        $txn = new MeprTransaction($txn_res->id);
        $txn->trans_num = $txn_ref;
        $txn->status = MeprTransaction::$failed_str;
        $txn->store();
      } elseif (
        isset($charge)  &&
        $sub = MeprSubscription::get_one_by_subscr_id($charge->subscription['subscription_code'])
      ) {
        $first_txn = $sub->first_txn();

        if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
          $coupon_id = $sub->coupon_id;
        } else {
          $coupon_id = $first_txn->coupon_id;
        }

        $txn = new MeprTransaction();
        $txn->user_id = $sub->user_id;
        $txn->product_id = $sub->product_id;
        $txn->coupon_id = $coupon_id;
        $txn->txn_type = MeprTransaction::$payment_str;
        $txn->status = MeprTransaction::$failed_str;
        $txn->subscription_id = $sub->id;
        $txn->trans_num = $charge->id;
        $txn->gateway = $this->id;

        if (MeprUtils::is_zero_decimal_currency()) {
          $txn->set_gross((float) $charge->amount);
        } else {
          $txn->set_gross((float) $charge->amount / 100);
        }

        $txn->store();

        //If first payment fails, Paystack will not set up the subscription, so we need to mark it as cancelled in MP
        if ($sub->txn_count == 0) {
          $sub->status = MeprSubscription::$cancelled_str;
        } else {
          $sub->status = MeprSubscription::$active_str;
        }
        $sub->gateway = $this->id;
        $sub->expire_txns(); //Expire associated transactions for the old subscription
        $sub->store();
      } else {
        return false; // Nothing we can do here ... so we outta here
      }

      MeprUtils::send_failed_txn_notices($txn);

      return $txn;
    }

    return false;
  }

  /** 
   * This method should be used by the class to push a refund request to to the gateway.
   */
  public function process_refund(MeprTransaction $txn)
  {
    $mepr_options = MeprOptions::fetch();

    $_REQUEST['trans_num'] = $txn->trans_num;

    // Handle zero decimal currencies in Paystack
    $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($txn->amount), 0) : MeprUtils::format_float(($txn->amount * 100), 0);

    $args = MeprHooks::apply_filters('mepr_paystack_refund_args', array(
      'transaction' => $txn->trans_num,
      'amount'      => $amount,
      'currency'    => $mepr_options->currency_code,
      'merchant_note'  => 'Refund Memberpress Transaction'
    ), $txn);

    $refund = (object) $this->paystack_api->send_request("refund", $args);

    $this->email_status("Paystack Refund: " . MeprUtils::object_to_string($refund), $this->settings->debug);

    return $this->record_refund();
  }

  /** 
   * This method should be used by the class to record a successful refund from
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function record_refund()
  {
    $trans_num = $_REQUEST['trans_num'];
    $obj = MeprTransaction::get_one_by_trans_num($trans_num);

    if (!is_null($obj) && (int) $obj->id > 0) {
      $txn = new MeprTransaction($obj->id);

      // Seriously ... if txn was already refunded what are we doing here?
      if ($txn->status == MeprTransaction::$refunded_str) {
        return $txn->id;
      }

      $txn->status = MeprTransaction::$refunded_str;
      $txn->store();

      MeprUtils::send_refunded_txn_notices($txn);

      return $txn->id;
    }

    return false;
  }

  /** 
   * This gets called on the 'init' hook when the signup form is processed ...
   * this is in place so that payment solutions like paypal can redirect
   * before any content is rendered.
   */
  public function process_signup_form($txn)
  {
  }

  public function display_payment_page($txn)
  {
    // Nothing to do here ...
  }

  /** 
   * This gets called on wp_enqueue_script and enqueues a set of
   * scripts for use on the page containing the payment form
   */
  public function enqueue_payment_form_scripts()
  {
  }

  /** 
   * This gets called on the_content and just renders the payment form
   */
  public function display_payment_form($amount, $user, $product_id, $txn_id)
  {
    $mepr_options = MeprOptions::fetch();
    $prd = new MeprProduct($product_id);
    $coupon = false;

    $txn = new MeprTransaction($txn_id);

    //Artifically set the price of the $prd in case a coupon was used
    if ($prd->price != $amount) {
      $coupon = true;
      $prd->price = $amount;
    }

    $invoice = MeprTransactionsHelper::get_invoice($txn);
    echo $invoice;
?>
    <div class="mp_wrapper mp_payment_form_wrapper">
      <div class="mp_wrapper mp_payment_form_wrapper">
        <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
        <form action="" method="post" id="mepr_paystack_payment_form" class="mepr-checkout-form mepr-form mepr-card-form" novalidate>
          <input type="hidden" name="mepr_process_payment_form" value="Y" />
          <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn->id; ?>" />

          <?php MeprHooks::do_action('mepr-paystack-payment-form', $txn); ?>
          <div class="mepr_spacer">&nbsp;</div>

          <input type="submit" class="mepr-submit" value="<?php _e('Pay Now', 'memberpress'); ?>" />
          <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
          <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
        </form>
      </div>
    </div>
  <?php
  }

  /** 
   * Validates the payment form before a payment is processed 
   */
  public function validate_payment_form($errors)
  {
  }

  /** 
   * Displays the form for the given payment gateway on the MemberPress Options page 
   */
  public function display_options_form()
  {
    $mepr_options = MeprOptions::fetch();

    $test_secret_key      = trim($this->settings->api_keys['test']['secret']);
    $test_public_key      = trim($this->settings->api_keys['test']['public']);
    $live_secret_key      = trim($this->settings->api_keys['live']['secret']);
    $live_public_key      = trim($this->settings->api_keys['live']['public']);
    $force_ssl            = ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true);
    $debug                = ($this->settings->debug == 'on' or $this->settings->debug == true);
    $test_mode            = ($this->settings->test_mode == 'on' or $this->settings->test_mode == true);

    $test_secret_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][test][secret]";
    $test_public_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][test][public]";
    $live_secret_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][live][secret]";
    $live_public_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][live][public]";
    $force_ssl_str            = "{$mepr_options->integrations_str}[{$this->id}][force_ssl]";
    $debug_str                = "{$mepr_options->integrations_str}[{$this->id}][debug]";
    $test_mode_str            = "{$mepr_options->integrations_str}[{$this->id}][test_mode]";
  ?>
    <table id="mepr-paystack-test-keys-<?php echo $this->id; ?>" class="form-table mepr-paystack-test-keys mepr-hidden">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_public_key_str; ?>"><?php _e('Test Public Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $test_public_key_str; ?>" value="<?php echo $test_public_key; ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_secret_key_str; ?>"><?php _e('Test Secret Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $test_secret_key_str; ?>" value="<?php echo $test_secret_key; ?>" /></td>
        </tr>
      </tbody>
    </table>
    <table id="mepr-paystack-live-keys-<?php echo $this->id; ?>" class="form-table mepr-paystack-live-keys">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $live_public_key_str; ?>"><?php _e('Live Public Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $live_public_key_str; ?>" value="<?php echo $live_public_key; ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $live_secret_key_str; ?>"><?php _e('Live Secret Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $live_secret_key_str; ?>" value="<?php echo $live_secret_key; ?>" /></td>
        </tr>
      </tbody>
    </table>
    <table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_mode_str; ?>"><?php _e('Test Mode', 'memberpress'); ?></label></th>
          <td><input class="mepr-paystack-testmode" data-integration="<?php echo $this->id; ?>" type="checkbox" name="<?php echo $test_mode_str; ?>" <?php echo checked($test_mode); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $force_ssl_str; ?>"><?php _e('Force SSL', 'memberpress'); ?></label></th>
          <td><input type="checkbox" name="<?php echo $force_ssl_str; ?>" <?php echo checked($force_ssl); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $debug_str; ?>"><?php _e('Send Debug Emails', 'memberpress'); ?></label></th>
          <td><input type="checkbox" name="<?php echo $debug_str; ?>" <?php echo checked($debug); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label><?php _e('Paystack Webhook URL:', 'memberpress'); ?></label></th>
          <td><?php MeprAppHelper::clipboard_input($this->notify_url('whk')); ?></td>
        </tr>
      </tbody>
    </table>
  <?php
  }

  /** 
   * Validates the form for the given payment gateway on the MemberPress Options page 
   */
  public function validate_options_form($errors)
  {
    $mepr_options = MeprOptions::fetch();

    $testmode = isset($_REQUEST[$mepr_options->integrations_str][$this->id]['test_mode']);
    $testmodestr  = $testmode ? 'test' : 'live';

    if (
      !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) ||
      empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret'])  ||
      !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) ||
      empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public'])
    ) {
      $errors[] = __("All Paystack keys must be filled in.", 'memberpress');
    }

    return $errors;
  }

  /** 
   * This gets called on wp_enqueue_script and enqueues a set of
   * scripts for use on the front end user account page.
   */
  public function enqueue_user_account_scripts()
  {
  }

  /** Displays the update account form on the subscription account page **/
  public function display_update_account_form($subscription_id, $errors = array(), $message = "")
  {
  ?>
    <div>
      <div class="mepr_update_account_table">
        <div><strong><?php _e('Update your Credit Card information below', 'memberpress'); ?></strong></div>
        <div class="mp-form-row">
          <p>Paystack currently doesn't support changing Credit Card for recurring subscription. To change your card details, cancel the current subscription and subscribe again.</p>
        </div>
      </div>
    </div>

    </div>
<?php
  }

  /** Validates the payment form before a payment is processed */
  public function validate_update_account_form($errors = array())
  {
  }

  /** Actually pushes the account update to the payment processor */
  public function process_update_account_form($subscription_id)
  {
  }

  /** Returns boolean ... whether or not we should be sending in test mode or not */
  public function is_test_mode()
  {
    return (isset($this->settings->test_mode) and $this->settings->test_mode);
  }

  public function force_ssl()
  {
    return (isset($this->settings->force_ssl) and ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true));
  }

  /** 
   * Get the renewal base date for a given subscription. This is the date MemberPress will use to calculate expiration dates.
   * Of course this method is meant to be overridden when a gateway requires it.
   */
  public function get_renewal_base_date(MeprSubscription $sub)
  {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    $q = $wpdb->prepare(
      "
        SELECT e.created_at
          FROM {$mepr_db->events} AS e
         WHERE e.event='subscription-resumed'
           AND e.evt_id_type='subscriptions'
           AND e.evt_id=%d
         ORDER BY e.created_at DESC
         LIMIT 1
      ",
      $sub->id
    );

    $renewal_base_date = $wpdb->get_var($q);
    if (!empty($renewal_base_date)) {
      return $renewal_base_date;
    }

    return $sub->created_at;
  }

  /** 
   * This method should be used by the class to verify a successful payment by the given
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function callback_handler()
  {
    $this->email_status("Callback Just Came In (" . $_SERVER['REQUEST_METHOD'] . "):\n" . MeprUtils::object_to_string($_REQUEST, true) . "\n", $this->settings->debug);

    $mepr_options = MeprOptions::fetch();

    // get the transaction reference from paystack callback
    $reference = sanitize_text_field($_REQUEST['reference']);

    $this->email_status('Paystack Verify Charge Transaction Happening Now ... ' . $reference, $this->settings->debug);

    $response = (object) $this->paystack_api->send_request("transaction/verify/{$reference}", [], 'get');

    $_REQUEST['data'] = $charge = (object) $response->data;

    $this->email_status('Paystack Verification: ' . MeprUtils::object_to_string($charge), $this->settings->debug);

    if (!$response->status || $charge->status == 'failed') {
      $this->record_payment_failure();
      //If all else fails, just send them to their account page
      MeprUtils::wp_redirect($mepr_options->account_page_url('action=subscriptions') . '?message=Payment Failed');
    }

    // Log Payment successful payment from this addon to paystack
    $this->paystack_api->log_transaction_success($reference);

    if (empty($charge->plan)) {
      $txn = $this->record_payment();
    } else {
      $txn = $this->record_transaction_for_subscription();
    }

    // Redirect to thank you page
    $product = new MeprProduct($txn->product_id);
    $sanitized_title = sanitize_title($product->post_title);
    $query_params = array(
      'membership' => $sanitized_title,
      'trans_num' => $txn->trans_num,
      'membership_id' => $product->ID
    );
    if ($txn->subscription_id > 0) {
      $sub = $txn->subscription();
      $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
    }
    MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
  }

  /** Paystack SPECIFIC METHODS **/
  public function webhook_listener()
  {
    $this->email_status("Webhook Just Came In (" . $_SERVER['REQUEST_METHOD'] . "):\n" . MeprUtils::object_to_string($_REQUEST, true) . "\n", $this->settings->debug);
    // retrieve the request's body
    $request = @file_get_contents('php://input');

    if ($this->paystack_api->validate_webhook($request) == true) {
      // parse it as JSON
      $request = (object) json_decode($request, true);
      $_REQUEST['data'] = $obj = (object) $request->data;

      if ($request->event == 'charge.success') {
        $this->email_status("###Event: {$request->event}\n" . MeprUtils::object_to_string($request, true) . "\n", $this->settings->debug);

        if (!isset($obj->id) || isset($obj->metadata['invoice_action'])) return;

        if (isset($obj->plan)) {
          // Record subscription for an instant charge
          return $this->record_subscription_payment();
        } 

        return $this->record_payment();
      } else if ($request->event == 'charge.refunded') {
        // $this->record_refund();
      } else if ($request->event == 'subscription.create') {
        return $this->record_create_subscription(); // done on page
      } else if ($request->event == 'subscription.enable') {
        // $this->record_update_subscription(); // done on page
      } else if ($request->event == 'subscription.disable') {
        return $this->record_cancel_subscription();
      } else if (
        $request->event == 'invoice.create' || $request->event == 'invoice.update'
      ) {
        // Record subscription for an recurring charge
        return $this->record_subscription_payment();
      } else if ($request->event == 'invoice.payment_failed') {
        $this->record_payment_failure();
      }
    }
  }

  /** 
   * Originally I thought these should be associated with
   * our membership objects but now I realize they should be
   * associated with our subscription objects
   */
  public function paystack_plan($sub, $is_new = false)
  {
    try {
      if ($is_new) {
        $paystack_plan = $this->create_new_plan($sub);
      } else {
        $plan_code = $this->get_plan_code($sub);
        if (empty($plan_code)) {
          $paystack_plan = $this->create_new_plan($sub);
        } else {
          $paystack_plan = $this->paystack_api->send_request("plan/{$plan_code}", array(), 'get');
        }
      }
    } catch (Exception $e) {
      // The call resulted in an error ... meaning that
      // there's no plan like that so let's create one

      // Don't enclose this in try/catch ... we want any errors to bubble up
      $paystack_plan = $this->create_new_plan($sub);
    }

    return (object) $paystack_plan->data;
  }

  public function get_plan_code($sub)
  {
    $meta_plan_code = $sub->token;

    if (is_null($meta_plan_code)) {
      return $sub->id;
    } else {
      return $meta_plan_code;
    }
  }

  public function create_new_plan($sub)
  {
    $mepr_options = MeprOptions::fetch();
    $prd          = $sub->product();
    // $user      = $sub->user();

    // There's no plan like that so let's create one
    if ($sub->period_type == 'weeks') {
      $interval = 'weekly';
      // for test purpose 
      // $interval = 'hourly';
    } else if ($sub->period_type == 'months') {
      $interval = 'monthly';
    } else if ($sub->period_type == 'years') {
      $interval = 'annually';
    }
    //Handle zero decimal currencies in Paystack
    $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($sub->price), 0) : MeprUtils::format_float(($sub->price * 100), 0);

    $args = MeprHooks::apply_filters('mepr_paystack_create_plan_args', array(
      'amount' => $amount,
      'interval' => $interval,
      'invoice_limit' => $sub->limit_cycles,
      'name' => $prd->post_title,
      'currency' => $mepr_options->currency_code,
      'description' => substr(str_replace(array("'", '"', '<', '>', '$', '®'), '', get_option('blogname')), 0, 21) //Get rid of invalid chars
    ), $sub);

    if ($sub->trial) {
      $args = array_merge(array("trial_period_days" => $sub->trial_days), $args);
    }

    $paystack_plan = (object) $this->paystack_api->send_request('plan', $args);

    $sub->token = $paystack_plan->data['plan_code'];
    $sub->store();

    return $paystack_plan;
  }

  public function get_auth_token($user)
  {
    return get_user_meta($user->ID, 'mepr_paystack_auth_token', true);
  }

  public function set_auth_token($user, $auth)
  {
    $auth_token = $auth['authorization_code'];
    return update_user_meta($user->ID, 'mepr_paystack_auth_token', $auth_token);
  }

  /** Get the default card object from a subscribed customer response */
  public function get_default_card($data, $sub)
  {
    $data = (object) $data; // ensure we're dealing with a stdClass object
    $usr = $sub->user();
    $default_card_token = $this->get_auth_token($usr);

    if (isset($default_card_token)) {
      foreach ($data->authorizations as $authorization) {
        if ($authorization['authorization_code'] == $default_card_token && $authorization['channel'] == 'card') {
          return $authorization;
        }
      }
    }
    return false;
  }


  /** Get card object from a subscription charge response */
  public function get_card($data)
  {
    if (isset($data->authorization) && $data->authorization['channel'] == 'card') {
      return $data->authorization;
    }
  }

  protected function get_request_data()
  {
    return (object) $_REQUEST['data'];
  }
}
