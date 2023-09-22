<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class ModenaShippingOmnivaOffice extends ModenaShippingBase {

  public function __construct($instance_id = 0) {
    $this->id                                    = 'modena-shipping-parcels-omniva-office';
    $this->modena_shipping_type                  = 'parcels';
    $this->modena_shipping_service               = 'Omniva';
    $this->title                                 = __('Omniva postkontorisse');
    $this->method_title                          = __('Modena - Omniva Parcel Post Office');
    $this->cost                                  = 7.99;
    $this->max_weight_for_modena_shipping_method = 35;

    parent::__construct($instance_id);
  }
}