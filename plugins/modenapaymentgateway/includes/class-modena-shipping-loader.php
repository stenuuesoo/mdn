
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Shipping_Loader {
    public function init(): void
    {
        require_once(MODENA_PLUGIN_PATH . 'includes/class-modena-shipping-ai.php');

        /**
         *
         * Checks for the existence of the required classes and initializes
         * Handles errors if the required classes do not exist.
         *
         * @return void
         */


        function run_shipping(): void {


            if (!class_exists('WC_Estonia_Shipping_Method') && class_exists('WC_Shipping_Method')) {
                add_filter('woocommerce_shipping_methods', 'load_modena_shipping_methods');
                add_action('woocommerce_shipping_init', 'init_WC_estonia');

            } else {
                $errorMessage = "Error: ";
                if (!class_exists('WC_Estonia_Shipping_Method')) {
                    $errorMessage .= "The 'WC_Estonia_Shipping_Method' class does not exist. ";
                }
                if (!class_exists('WC_Shipping_Method')) {
                    $errorMessage .= "The 'WC_Shipping_Method' class does not exist. ";
                }
                error_log($errorMessage);
                add_action('admin_notices', 'modena_shipping_error_notice');
            }
        }
        /**
         * @param array $methods Array of existing WooCommerce shipping methods.
         * @return array Updated array of WooCommerce shipping methods, including the Modena Shipping Self Service method.
         */
        function load_modena_shipping_methods(array $methods): array {
            $methods['estonia_shipping'] = 'WC_Estonia_Shipping_Method';
            return $methods;
        }
        /**
         * Displays an admin notice with an error message.

         * @return void
         */
        function modena_shipping_error_notice(): void {
            echo '<div class="notice notice-error"><p><strong>Modena Shipping Error:</strong> The required classes were not found. Please ensure the necessary dependencies are installed and active.</p></div>';
        }
        /**
         * Call the function to clear the debug.log file
         *
         **/


    }
    function init_WC_estonia(): void {
        $shipping_ai_object = new WC_Estonia_Shipping_Method();
    }
}