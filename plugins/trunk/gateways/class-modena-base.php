<?php

use Modena\Payment\model\ModenaApplication;
use Modena\Payment\model\ModenaCustomer;
use Modena\Payment\model\ModenaOrderItem;
use Modena\Payment\model\ModenaRequest;
use Modena\Payment\Modena;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

abstract class Modena_Base_Payment extends WC_Payment_Gateway
{
    const PLUGIN_VERSION             = '2.8.1';
    const MODENA_META_KEY            = 'modena-application-id';
    const MODENA_SELECTED_METHOD_KEY = 'modena-payment-method';
    protected $client_id;
    protected $client_secret;
    protected $is_test_mode;
    protected $maturity_in_months;
    protected $site_url;
    protected $logger;
    protected $modena;
    protected $payment_button_max_height;
    protected $onboarding;
    protected $environment;
    protected $default_image;
    protected $hide_title = false;
    protected $default_icon_title_text;
    protected $default_alt;
    protected $default_payment_gateway_description;
    protected $current_WC_locale;

    public function __construct()
    {
        require_once MODENA_PLUGIN_PATH . 'autoload.php';
        require ABSPATH . WPINC . '/version.php';

        $this->onboarding = new Modena_Onboarding_Handler();
        $this->onboarding->maybe_received_credentials();

        $this->environment = $this->get_option('environment');

        $this->client_id     = $this->get_option($this->environment . '_client_id');
        $this->client_secret = $this->get_option($this->environment . '_client_secret');
        $this->is_test_mode  = $this->environment === 'sandbox';

        if(!empty(wc_get_checkout_url())) {
            $this->site_url = wc_get_checkout_url();
        } else {
            $this->site_url = get_home_url();
        }

        $this->logger   = new WC_Logger(array(new Modena_Log_Handler()));

        $userAgent = sprintf(
            'ModenaWoocommerce/%s WooCommerce/%s WordPress/%s',
            self::PLUGIN_VERSION,
            (!defined('WC_VERSION') ? '0.0.0' : WC_VERSION),
            (empty($wp_version) ? '0.0.0' : $wp_version)
        );

        $this->modena = new Modena(
            $this->client_id,
            $this->client_secret,
            $userAgent,
            $this->is_test_mode
        );
        $this->payment_button_max_height = 30;
        $this->icon                      = $this->default_image;

        $this->init_form_fields();

        $this->init_settings();

        if ($this->get_option('payment_button_max_height') >= 24 && $this->get_option('payment_button_max_height') <= 30) {
            $this->payment_button_max_height = $this->get_option('payment_button_max_height');
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        add_action('woocommerce_api_redirect_to_modena_' . $this->id, [$this, 'redirect_to_modena']);

        add_action('woocommerce_api_modena_response_' . $this->id, [$this, 'modena_response']);

        add_action('woocommerce_api_modena_async_response_' . $this->id, [$this, 'modena_async_response']);

        add_action('woocommerce_api_modena_cancel_' . $this->id, [$this, 'modena_cancel']);

        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'credentials_title_line'    => [
                'type'  => 'title',
                'class' => 'modena-header-css-class',
            ],
            'credentials_title'         => array(
                'title'       => __('Technical Support: +372 6604144 & info@modena.ee', 'modena'),
                'type'        => 'title',
                'description' => __('Select Live mode to accept payments and Sandbox mode to test payments.', 'modena'),
            ),
            'environment'               => array(
                'title'       => __('Environment', 'modena'),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'options'     => array(
                    'sandbox' => __('Sandbox mode', 'modena'),
                    'live'    => __('Live mode', 'modena'),
                ),
                'description' => __('<div id="environment_alert_desc"></div>', 'modena'),
                'default'     => 'sandbox',
                'desc_tip'    => __('Choose Sandbox mode to test payment using test API keys. Switch to live mode to accept payments with Modena using live API keys.',
                    'modena'),
            ),
            'sandbox_client_id'         => [
                'title'    => __('Sandbox Client ID', 'modena'),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'sandbox_client_secret'     => [
                'title'    => __('Sandbox Client Secret', 'modena'),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'live_client_id'            => [
                'title'    => __('Live Client ID', 'modena'),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'live_client_secret'        => [
                'title'    => __('Live Client Secret', 'modena'),
                'type'     => 'text',
                'desc_tip' => true,
            ],
            'gateway_title'             => [
                'type'  => 'title',
                'class' => 'modena-header-css-class',
            ],
            'enabled'                   => [
                'title'       => sprintf(__('%s Payment Gateway', 'modena'), $this->get_method_title()),
                'label'       => '<span class="modena-slider"></span>',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'class'       => 'modena-switch',
            ],
            'payment_button_max_height' => [
                'title'       => __('Payment Button Logo Max Height', 'modena'),
                'type'        => 'number',
                'description' => __('Maximum height of the payment logo in pixels. Default is: 30',
                    'modena'),
                'default'     => 30,
                'css'         => 'width:29em',
                'desc_tip'    => false,
            ],
        ];
    }

    public function admin_options()
    {
        $autoconfigure_live_button    = '<a href="' . esc_url($this->onboarding->get_autoconfig_url($this->id,
                false)) . '" class="button button-primary">' . __('Autoconfigure Modena API LIVE credentials',
                'modena') . '</a>';
        $autoconfigure_sandbox_button = '<a href="' . esc_url($this->onboarding->get_autoconfig_url($this->id,
                true)) . '" class="button button-primary">' . __('Autoconfigure Modena API SANDBOX credentials',
                'modena') . '</a>';

        $api_live_credentials_text    = sprintf(
            '<div class="live_env_alert">' . __('WARNING: LIVE ENVIRONMENT SELECTED!',
                'modena') . '</div><p style="padding-top:15px;">%s</p><p style="padding-top:15px;"><a href="%s" class="toggle-credential-settings" target="_blank">' . __('Or click here to toggle manual API credential input',
                'modena') . '</a></p>',
            $autoconfigure_live_button,
            esc_url($this->onboarding->get_partner_portal_url(false))
        );
        $api_sandbox_credentials_text = sprintf(
            '<p style="padding-top:15px;">%s</p><p style="padding-top:15px;"><a href="%s" class="toggle-credential-settings" target="_blank">' . __('Or click here to toggle manual API credential input',
                'modena') . '</a></p>',
            $autoconfigure_sandbox_button,
            esc_url($this->onboarding->get_partner_portal_url(true))
        );

        wc_enqueue_js("
            jQuery( function( $ ) {
                $('.description').css({'font-style':'normal'});
                $('.modena-header-css-class').css({'border-top': 'dashed 1px #ccc','padding-top': '15px','width': '100%'});
                var " . $this->id . "_live = jQuery(  '#woocommerce_" . $this->id . "_live_client_id, #woocommerce_" . $this->id . "_live_client_secret').closest( 'tr' );
                var " . $this->id . "_sandbox = jQuery(  '#woocommerce_" . $this->id . "_sandbox_client_id, #woocommerce_" . $this->id . "_sandbox_client_secret').closest( 'tr' );
                $( '#woocommerce_" . $this->id . "_environment' ).change(function(){
                    if ( 'live' === $( this ).val() ) {
                            $( " . $this->id . "_live  ).show();
                            $( " . $this->id . "_sandbox ).hide();
                            $( '#environment_alert_desc').html('" . $api_live_credentials_text . "');
                    } else {
                            $( " . $this->id . "_live ).hide();
                            $( " . $this->id . "_sandbox ).show();
                            $( '#environment_alert_desc').html('" . $api_sandbox_credentials_text . "');
                        }
                }).change();
            });
        ");
        parent::admin_options();
    }

    public function admin_scripts()
    {
        wp_register_style('modena-admin-style', MODENA_PLUGIN_URL . 'assets/css/modena-admin-style.css');
        wp_enqueue_style('modena-admin-style');
    }

    abstract protected function postPaymentOrderInternal($request);

    abstract protected function getPaymentApplicationStatus($request);

    public function redirect_to_modena()
    {
        $orderItems = [];

        $order = wc_get_order(sanitize_text_field($_GET['id']));

        $customer = new ModenaCustomer(
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_email(),
            $order->get_billing_phone(),
            join(', ', [
                $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(),
                $order->get_billing_state(),
            ])
        );

        foreach ($order->get_items(['line_item', 'fee', 'shipping']) as $orderItem) {
            $orderItems[] = new ModenaOrderItem(
                $orderItem->get_name(),
                $orderItem->get_total() + $orderItem->get_total_tax(),
                $orderItem->get_quantity(),
                get_woocommerce_currency()
            );
        }

        $maturityInMonths = isset($_GET['maturityInMonths']) ? sanitize_text_field($_GET['maturityInMonths']) : null;
        $selectedOption = isset($_GET['selectedOption']) ? sanitize_text_field($_GET['selectedOption']) : null;
        $paymentType = isset($_GET['paymentType']) ? sanitize_text_field($_GET['paymentType']) : null;

        $application = new ModenaApplication(
            $maturityInMonths,
            $selectedOption,
            strval($order->get_id()),
            $order->get_total(),
            $orderItems,
            $customer,
            date("Y-m-d\TH:i:s.u\Z"),
            get_woocommerce_currency()
        );

        $request = new ModenaRequest(
            $application,
            sprintf('%s/wc-api/modena_response_%s', HomeUrl::baseOnly(), $this->id),
            sprintf('%s/wc-api/modena_cancel_%s', HomeUrl::baseOnly(), $this->id),
            sprintf('%s/wc-api/modena_async_response_%s', HomeUrl::baseOnly(), $this->id)
        );

        try {
            $response = $this->postPaymentOrderInternal($request);
            if ($response->getApplicationId()) {
                $humanReadablePaymentMethod = $this->get_human_readable_selected_method($selectedOption);
                $order->add_meta_data(self::MODENA_META_KEY, $response->getApplicationId(), true);
                $order->add_meta_data(self::MODENA_SELECTED_METHOD_KEY, $humanReadablePaymentMethod, true);
                $order->save_meta_data();
                $order->save();
                wp_redirect($response->getRedirectLocation());
            }
        } catch (Exception $exception) {
            $this->logger->error('Exception occurred when redirecting to modena: ' . $exception->getMessage());
            $this->logger->error($exception->getTraceAsString());
            wp_safe_redirect(get_home_url());
        }

        exit;
    }

    private function get_human_readable_selected_method($selectedOption): string
    {
        if ($this instanceof Modena_Slice_Payment) {
            return __($this->title);
        }

        if ($this instanceof Modena_Business_Leasing) {
            return __($this->title);
        }

        if ($this instanceof Modena_Credit_Payment) {
            return __($this->title);
        }
        if ($this instanceof Modena_Click_Payment) {
            return __($this->title);
        }

        switch ($selectedOption) {
            case 'HABAEE2X':
                return 'Swedbank';
            case 'EEUHEE2X':
                return 'SEB';
            case 'LHVBEE22':
                return 'LHV';
            case 'RIKOEE22':
                return 'Luminor';
            case 'MTASKU':
                return 'mTasku';
            case 'PARXEE22':
                return 'Citadele';
            case 'EKRDEE22':
                return 'COOP';
            case 'MASTER_CARD':
                return 'Mastercard';
            case 'VISA':
                return 'VISA';
            default:
                $translations = $this->get_human_readable_method_translation();
                return $translations[get_locale()] ?? $translations['en_US'];
        }
    }

    public function get_human_readable_method_translation() {
        return array(
            'et' => 'Panga- ja kaardimaksed.',
            'ru_RU' => 'Интернетбанк или карта',
            'en_US' => 'Bank & Card Payments',
        );
    }

    public function modena_response()
    {
        global $woocommerce;
        $modenaResponse = $this->modena->getOrderResponseFromRequest($_POST);

        try {
            $applicationStatus = $this->getPaymentApplicationStatus($modenaResponse->getApplicationId());
            $order = wc_get_order($modenaResponse->getOrderId());

            if ($applicationStatus !== 'SUCCESS' || !$order || !$this->validate_modena_response($modenaResponse)) {
                $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Invalid application status, expected: SUCCESS | received: ' . $applicationStatus) . '. Modena response is invalid: ' . json_encode($modenaResponse);
                wp_redirect($this->site_url);
                wc_add_notice($this->add_mdn_notice());
                exit;
            }

            if ($order->get_payment_method() === $this->id) {
                if ($order->needs_payment()) {
                    $order->payment_complete();
                    $order->add_order_note(sprintf(__('Order paid by %s.', 'modena'), $this->title));
                    $woocommerce->cart->empty_cart();
                }

                wp_safe_redirect($this->get_return_url($order));
                exit;
            }
        } catch (Exception $e) {
            $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Exception occurred in payment response: ' . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            wp_redirect($this->site_url);
            wc_add_notice($this->add_mdn_notice());
            exit;
        }
    }

    private function validate_modena_response($modenaResponse)
    {
        if (!$modenaResponse->getOrderId() || !$modenaResponse->getApplicationId()) {
            return false;
        }

        $order              = wc_get_order($modenaResponse->getOrderId());
        $orderApplicationId = $order->get_meta(self::MODENA_META_KEY);

        return $orderApplicationId === $modenaResponse->getApplicationId();
    }

    public function modena_cancel()
    {
        global $woocommerce;

        $modenaResponse = $this->modena->getOrderResponseFromRequest($_POST);

        if (!$this->validate_modena_response($modenaResponse)) {
            $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Modena cancel response is invalid: ' . json_encode($modenaResponse));
            wp_redirect($this->site_url);
            exit;
        }

        try {
            $applicationStatus = $this->getPaymentApplicationStatus($modenaResponse->getApplicationId());

            if ($applicationStatus !== 'FAILED') {
                $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Invalid application status, expected: FAILED or REJECTED | received: ' . $applicationStatus);
                wp_redirect($this->site_url);
                wc_add_notice($this->add_mdn_notice());
                exit;
            }

            $woocommerce->cart->empty_cart();
            $order          = wc_get_order($modenaResponse->getOrderId());
            $payment_method = $order->get_payment_method();

            if ($payment_method === $this->id) {
                foreach ($order->get_items() as $orderItem) {
                    $data = $orderItem->get_data();
                    WC()->cart->add_to_cart($data['product_id'], $data['quantity'], $data['variation_id']);
                }

                wp_safe_redirect(wc_get_cart_url());
                wc_add_notice(__('Payment canceled.', 'modena'));
                exit;
            } else {
                $this->logger->error(sprintf(
                    'Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Payment canceled, but the order not found or payment method mismatch or order already paid. [method: %s, needs_payment: %s]',
                    $order->get_payment_method(),
                    $order->needs_payment()
                ));
                wp_redirect($this->site_url);
                wc_add_notice(__('Something went wrong, please try again later.', 'modena'));
                exit;
            }
        } catch (Exception $e) {
            $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Exception occurred in payment cancel function: ' . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            wp_redirect($this->site_url);
            wc_add_notice($this->add_mdn_notice());
            exit;
        }
    }

    public function modena_async_response()
    {
        global $woocommerce;

        $modenaResponse = $this->modena->getOrderResponseFromRequest($_POST);

        try {
            $applicationStatus = $this->getPaymentApplicationStatus($modenaResponse->getApplicationId());
            $order = wc_get_order($modenaResponse->getOrderId());

            if ($applicationStatus !== 'SUCCESS' || !$order || !$this->validate_modena_response($modenaResponse)) {
                $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Invalid application status, expected: SUCCESS | received: ' . $applicationStatus) . '. Modena response is invalid: ' . json_encode($modenaResponse);
                exit;
            }

            if ($order->get_payment_method() === $this->id) {
                if ($order->needs_payment()) {
                    $order->payment_complete();
                    $order->add_order_note(sprintf(__('Order paid by %s.', 'modena'), $this->title));
                    $woocommerce->cart->empty_cart();
                }

                exit;
            }
        } catch (Exception $e) {
            $this->logger->error('Order number ' . wc_get_order($modenaResponse->getOrderId()) . '. Exception occurred in payment response: ' . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            exit;
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('pending', sprintf(__('Order payment pending by %s ', 'modena'), $this->title));
        $order->save();

        return array(
            'result'   => 'success',
            'redirect' => $this->create_redirect_url(
                $order_id,
                $this->maturity_in_months,
                isset($_POST['MDN_OPTION']) ? sanitize_text_field($_POST['MDN_OPTION']) : null
            ),
        );
    }

    private function create_redirect_url($orderId, $maturityInMonths, $selectedOption)
    {
        return sprintf(
            '%s/wc-api/redirect_to_modena_%s?id=%d&maturityInMonths=%d&selectedOption=%s',
            HomeUrl::baseOnly(),
            $this->id,
            $orderId,
            $maturityInMonths,
            $selectedOption
        );
    }

    public function get_icon()
    {

        $marginLeft = $this->hide_title ? 'margin-left: 0 !important;' : '';
        $icon       = '<img src="' . WC_HTTPS::force_https_url($this->icon) . '" title="' . esc_attr($this->get_icon_title()) . '" alt="' . esc_attr($this->get_icon_alt()) . '" style="max-height:' . strval($this->payment_button_max_height) . 'px;' . $marginLeft . '"/>';

        return $this->icon ? apply_filters('woocommerce_gateway_icon', $icon, $this->id) : '';
    }

    public function get_description()
    {
        $description = $this->description;
        if ($this->hide_title) {
            $description .= '<style>label[for=payment_method_' . $this->id . '] { font-size: 0 !important; }</style>';
        }

        /*
         * Using the singleton pattern we can ensure that the <link> or <script> tags get added to the site only once.
         * */
        Modena_Load_Checkout_Assets::getInstance();

        return apply_filters('woocommerce_gateway_description', $description, $this->id);
    }

    public function get_icon_alt()
    {
        return $this->default_alt;
    }
    public function get_icon_title()
    {
        return $this->description . ' ' . $this->default_icon_title_text;
    }

    public function add_mdn_notice() {
        $translations = $this->get_mdn_notice_translations();
        return $translations[get_locale()] ?? $translations['en_US'];
    }

    public function get_mdn_notice_translations() {
        return array(
            'et'    => 'Tekkis tehniline tõrge tellimuse kinnitamisel. Palume ühendust võtta e-poe klienditoega.',
            'ru_RU' => 'Во время подтверждения заказа произошла техническая ошибка. Пожалуйста, свяжитесь со службой поддержки интернет-магазина.',
            'en_US' => 'It seems that there has been a problem confirming your order. Please contact the customer support for verification of your order.',
        );
    }
}