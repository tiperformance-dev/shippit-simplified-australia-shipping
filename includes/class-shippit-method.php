<?php

/**
* Mamis - https://www.mamis.com.au
* Copyright © Mamis 2023-present. All rights reserved.
* See https://www.mamis.com.au/license
*/

class Mamis_Shippit_Method extends WC_Shipping_Method
{
    /**
     * @var Mamis_Shippit_Api
     */
    protected $api;

    /**
     * @var Mamis_Shippit_Helper
     */
    protected $helper;

    /**
     * @var Mamis_Shippit_Log
     */
    protected $log;

    /**
     * @var array
     */
    protected $allowed_methods;

    /**
     * @var array
     */
    protected $max_timeslots;

    /**
     * @var string|null
     */
    protected $quote_enabled;

    /**
     * @var string|null
     */
    protected $filter_attribute;

    /**
     * @var string|null
     */
    protected $filter_attribute_code;

    /**
     * @var string|null
     */
    protected $filter_attribute_value;

    /* php8 deprecation notice */
    protected $filter_disabled_products;

    /**
     * @var string|null
     */
    protected $margin;

    /**
     * @var string|null
     */
    protected $margin_amount;

    /**
     * @var bool
     */
    protected $eddDisplayEnabled;

    /**
     * @var bool
     */
    protected $eddHandlingEnabled;

    /**
     * @var int
     */
    protected $eddHandlingDays;

    /**
     * Constructor.
     */
    public function __construct(int $instance_id = 0)
    {
        $this->supports = [
            'shipping-zones',
            'instance-settings',
        ];

        $this->id = 'mamis_shippit';
        $this->title = __('Shippit', 'woocommerce-shippit');
        $this->method_title = __('Shippit', 'woocommerce-shippit');
        $this->method_description = __('Have Shippit provide you with live quotes directly from the carriers. Simply enable live quoting and set your preferences to begin.');

        $settings = new Mamis_Shippit_Settings_Method();
        $this->instance_id = absint($instance_id);
        $this->instance_form_fields = $settings->getFields();

        $this->api = new Mamis_Shippit_Api();
        $this->log = new Mamis_Shippit_Log(['area' => 'live-quote']);
        $this->helper = new Mamis_Shippit_Helper();

        $this->init();
    }

    /**
     * Initialize plugin parts.
     *
     * @since 1.0.0
     */
    public function init()
    {
        // Initiate instance settings as class variables
        $this->title                   = $this->get_option('title');
        $this->allowed_methods         = $this->get_option('allowed_methods');
        $this->max_timeslots           = $this->get_option('max_timeslots');
        $this->filter_disabled_products = $this->get_option('filter_disabled_products');
        $this->filter_attribute        = $this->get_option('filter_attribute');
        $this->filter_attribute_code   = $this->get_option('filter_attribute_code');
        $this->filter_attribute_value  = $this->get_option('filter_attribute_value');
        $this->margin                  = $this->get_option('margin');
        $this->margin_amount           = $this->get_option('margin_amount');
        $this->eddDisplayEnabled       = get_option('wc_settings_shippit_edd_display_enabled', 'no') === 'yes';
        $this->eddHandlingEnabled      = get_option('wc_settings_shippit_edd_handling_enabled', 'no') === 'yes';
        $this->eddHandlingDays         = (int) get_option('wc_settings_shippit_edd_handling_days', 1);

        wp_enqueue_script('shippit-script');

        // Add action hook to save the shipping method instance settings when they saved
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Add shipping method.
     *
     * Add shipping method to WooCommerce.
     *
     */
    public static function add_shipping_method($methods)
    {
        if (class_exists('Mamis_Shippit_Method')) {
            $methods['mamis_shippit'] = 'Mamis_Shippit_Method';
        }

        return $methods;
    }

    /**
     * Calculate shipping.
     *
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping($package = array())
    {
        // Check if the module is enabled and used for shipping quotes
        if (get_option('wc_settings_shippit_enabled') != 'yes') {
            return;
        }

        // Ensure we are on a page where we actually want to calculate the shipping cost; this call is *EXPENSIVE* (>3 sec)
        // Allow shipping-related AJAX but prevent expensive calculations in admin/product pages
        $is_shipping_ajax = wp_doing_ajax() && (
            doing_action('woocommerce_checkout_update_order_review') ||
            strpos($_REQUEST['action'] ?? '', 'shipping') !== false ||
            strpos($_REQUEST['action'] ?? '', 'apple_pay') !== false ||
            strpos($_REQUEST['action'] ?? '', 'update_order_review') !== false
        );

        if ( ( is_admin() && ! $is_shipping_ajax ) || ( ! is_admin() && strpos($_SERVER["REQUEST_URI"], 'product') !== false ) ) {
            return;
        }

        // Ensure we have a shipping method available for use
        if (empty($this->allowed_methods)) {
            return;
        }

        $quoteDestination = $package['destination'];
        $quoteContents = $package['contents'];
        
        // Check if we can ship the products by disabled filtering
        if (!$this->_canShipDisabledProducts($package)) {
            return;
        }
        
        // Check if we can ship the products by attribute filtering
        if ($this->canShipEnabledAttributes($quoteContents) === false) {
            return;
        }

        $this->fetchQuotes($quoteDestination, $quoteContents);
    }

    // Added by JB to work on Zimbabwe postcode problem
    protected function country_requires_postcode($country_code) {
        // Get WC_Countries instance
        $wc_countries = new WC_Countries();
        
        // Get country locale
        $locale = $wc_countries->get_country_locale();
        
        // Check if country has postcode hidden in its locale
        if (isset($locale[$country_code]['postcode']) && 
            (isset($locale[$country_code]['postcode']['hidden']) && $locale[$country_code]['postcode']['hidden'] === true || 
                isset($locale[$country_code]['postcode']['required']) && $locale[$country_code]['postcode']['required'] === false)) {
            return false;
        }
        
        return true;
    }    

    /**
     * Perform a request for a shipping quotes based on the destination + contents provided
     *
     * @param array $quoteDestination
     * @param array $quoteContents
     * @return void
     */
    protected function fetchQuotes($quoteDestination, $quoteContents)
    {
        $isPriorityAvailable = in_array('priority', $this->allowed_methods);
        $isExpressAvailable = in_array('express', $this->allowed_methods);
        $isStandardAvailable = in_array('standard', $this->allowed_methods);

        if ($isPriorityAvailable) {
            $isPriorityAvailable = $this->isPriorityAvailableByStock($quoteContents);
        }

        $dropoffSuburb = $quoteDestination['city'];
        $dropoffPostcode = $quoteDestination['postcode'];
        $dropoffState = $quoteDestination['state'];
        $dropoffCountryCode = $quoteDestination['country'];

        // Only make a live quote request if required fields are present
        if (empty($dropoffSuburb)) {
            $this->log->debug(
                'A suburb is required for a live quote'
            );

            return;
        }
        elseif (empty($dropoffPostcode)) {
            $country = WC()->customer->get_shipping_country();
                // Return false if country doesn't require postcodes
                if ($this->country_requires_postcode($country)) {
                    // postcode is required; return
                    $this->log->debug(
                        'A postcode is required for a live quote'
                    );
                    return;
                } else {
                    // postcode not required (eg. ZW) -- allow
                    $this->log->debug(
                        'Postcode not required for this country $country'
                    );
                }            
        }
        elseif (empty($dropoffCountryCode)) {
            $this->log->debug(
                'A country is required for a live quote'
            );

            return;
        }

        // set dateorder  as tomorrow after 4pm FIXME this is hard coded
        $now = new DateTime();
        $now->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ) );       
        // ENABLE THIS LOGIC
        if ($now->format('Hi') > 1600 AND 1==1) {
            $quoteDate = $now->modify('+1 day')->format('Y-m-d');
            $this->log->debug('After 4pm; quote as tomorrow: '.$quoteDate);
        } else {
            $quoteDate = '';
        }

        $quoteData = array(
            'order_date' => $quoteDate, // get all available dates
            'dropoff_address' => $this->getDropoffAddress($quoteDestination),
            'dropoff_suburb' => $dropoffSuburb,
            'dropoff_postcode' => $dropoffPostcode,
            'dropoff_state' => $dropoffState,
            'dropoff_country_code' => $dropoffCountryCode,
            'parcel_attributes' => $this->getParcelAttributes($quoteContents),
            'return_all_quotes' => true,
            'dutiable_amount' => WC()->cart->get_cart_contents_total(),
        );    
        
        $shippingQuotes = $this->api->getQuote($quoteData);

        if ($shippingQuotes) {
            foreach ($shippingQuotes as $shippingQuote) {
                if ($shippingQuote->success) {
                    switch ($shippingQuote->service_level) {
                        case 'priority':
                            if ($isPriorityAvailable) {
                                $this->addPriorityQuote($shippingQuote);
                            }

                            break;
                        case 'express':
                            if ($isExpressAvailable) {
                                $this->addExpressQuote($shippingQuote);
                            }

                            break;
                        case 'standard':
                            if ($isStandardAvailable) {
                                $this->addStandardQuote($shippingQuote);
                            }

                            break;
                        case 'on_demand':
                            if ($isExpressAvailable) {
                                $this->addExpressQuote($shippingQuote);
                            }

                            break;
                    }
                }
            }
        }
        else {
            return false;
        }
    }

    /**
     * Retrieve the parcel attributes from the quote contents
     *
     * @param array $quoteContents
     * @return array
     */
    protected function getParcelAttributes($quoteContents)
    {
        $parcelAttributes = [];

        foreach ($quoteContents as $quoteItem) {
            $parcel = [];

            // If product is variation, load variation ID
            if ($quoteItem['variation_id']) {
                $cartItem = wc_get_product($quoteItem['variation_id']);
            }
            else {
                $cartItem = wc_get_product($quoteItem['product_id']);
            }

            $itemWeight = $cartItem->get_weight();
            $itemHeight = $cartItem->get_height();
            $itemLength = $cartItem->get_length();
            $itemWidth = $cartItem->get_width();

            $parcel['qty'] = $quoteItem['quantity'];

            if (!empty($itemWeight)) {
                $parcel['weight'] = $this->helper->convertWeight($itemWeight);
            }
            else {
                // stub weight to 0.2kg
                $parcel['weight'] = 0.2;
            }

            if (
                !defined('SHIPPIT_IGNORE_ITEM_DIMENSIONS')
                || !SHIPPIT_IGNORE_ITEM_DIMENSIONS
            ) {
                if (!empty($itemHeight)) {
                    $parcel['depth'] = $this->helper->convertDimension($itemHeight);
                }

                if (!empty($itemLength)) {
                    $parcel['length'] = $this->helper->convertDimension($itemLength);
                }

                if (!empty($itemWidth)) {
                    $parcel['width'] = $this->helper->convertDimension($itemWidth);
                }
            }

            $parcelAttributes[] = $parcel;
        }

        return $parcelAttributes;
    }

    /**
     * Get the dropoff address value for a quote
     *
     * @param array $quoteDestination
     * @return string|null
     */
    protected function getDropoffAddress($quoteDestination)
    {
        $addresses = [
            $quoteDestination['address'],
            $quoteDestination['address_2'],
        ];

        $addresses = array_filter($addresses, function ($address) {
            $address = trim($address);

            return !empty($address);
        });

        if (empty($addresses)) {
            return null;
        }

        return implode(', ', $addresses);
    }

    /**
     * Add a standard quote rate(s) to the list of available shipping methods
     *
     * @param object $shippingQuote
     * @return void
     */
    protected function addStandardQuote($shippingQuote)
    {
        foreach ($shippingQuote->quotes as $quote) {
            $quotePrice = $this->getQuotePrice($quote->price);

            $taxes = WC_Tax::calc_inclusive_tax($quotePrice, WC_Tax::get_shipping_tax_rates());
            $cost = $quotePrice - array_sum($taxes);

            $baseLabel = $this->helper->getFriendlyCourierName($shippingQuote->courier_type, $shippingQuote->service_level);
            $label = $this->eddDisplayEnabled ? $this->buildEddLabel($baseLabel, $quote) : $baseLabel;

            $rate = array(
                // unique id for each rate
                'id'    => 'Mamis_Shippit_' . $shippingQuote->courier_type,
                'label' => $label,
                'cost'  => $cost,
                'taxes' => $taxes,
                'meta_data' => array(
                    'service_level'      => $shippingQuote->service_level,
                    'courier_allocation' => $shippingQuote->courier_type,
                ),
            );

            $this->add_rate($rate);
        }
    }

    /**
     * Add a express quote rate(s) to the list of available shipping methods
     *
     * @param object $shippingQuote
     * @return void
     */
    protected function addExpressQuote($shippingQuote)
    {
        foreach ($shippingQuote->quotes as $quote) {
            $quotePrice = $this->getQuotePrice($quote->price);
            //FIXME: Add an overhead to Uber orders
            if ($shippingQuote->courier_type == 'UberOndemand') {
                $quotePrice = $quotePrice * 1.1;
                $quotePrice = $quotePrice + 10;
            }

            $taxes = WC_Tax::calc_inclusive_tax($quotePrice, WC_Tax::get_shipping_tax_rates());
            $cost = $quotePrice - array_sum($taxes);

            $baseLabel = $this->helper->getFriendlyCourierName($shippingQuote->courier_type, $shippingQuote->service_level);
            $label = $this->eddDisplayEnabled ? $this->buildEddLabel($baseLabel, $quote) : $baseLabel;

            $rate = array(
                'id'    => 'Mamis_Shippit_' . $shippingQuote->courier_type,
                'label' => $label,
                'cost'  => $cost,
                'taxes' => $taxes,
                'meta_data' => array(
                    'service_level'      => $shippingQuote->service_level,
                    'courier_allocation' => $shippingQuote->courier_type,
                ),
            );

            $this->add_rate($rate);
        }
    }

    /**
     * Add a priority quote rate(s) to the list of available shipping methods
     *
     * @param object $shippingQuote
     * @return void
     */
    protected function addPriorityQuote($shippingQuote)
    {
        $timeSlotCount = 0;

        foreach ($shippingQuote->quotes as $priorityQuote) {
            if (!empty($this->max_timeslots) && $this->max_timeslots <= $timeSlotCount) {
                break;
            }

            $timeSlotCount++;

            $quotePrice = $this->getQuotePrice($priorityQuote->price);

            $taxes = WC_Tax::calc_inclusive_tax($quotePrice, WC_Tax::get_shipping_tax_rates());
            $cost = $quotePrice - array_sum($taxes);

            if (!empty($priorityQuote->delivery_date)) {
                $displayDeliveryDate = $this->eddHandlingEnabled && $this->eddHandlingDays > 0
                    ? $this->addBusinessDays($priorityQuote->delivery_date, $this->eddHandlingDays)
                    : date('d/m/Y', strtotime($priorityQuote->delivery_date));
            } else {
                $displayDeliveryDate = 'TBD';
            }

            $rate = array(
                'id' => sprintf(
                    'Mamis_Shippit_%s_%s_%s',
                    $shippingQuote->service_level,
                    $priorityQuote->delivery_date,
                    $priorityQuote->delivery_window
                ),
                'label' => sprintf(
                    '%s Courier - Delivered %s between %s',
                    $this->helper->getFriendlyCourierName($priorityQuote->courier_type, $shippingQuote->service_level),
                    $displayDeliveryDate,
                    $priorityQuote->delivery_window_desc
                ),
                'cost'  => $cost,
                'taxes' => $taxes,
                'meta_data' => array(
                    'service_level'      => $shippingQuote->service_level,
                    'courier_allocation' => $priorityQuote->courier_type,
                    'delivery_date'      => $priorityQuote->delivery_date,
                    'delivery_window'    => $priorityQuote->delivery_window,
                ),
            );

            $this->add_rate($rate);
        }
    }

    /**
     * Get the quote price, including the margin amount
     * @param  float $quotePrice The quote amount
     * @return float             The quote amount, with margin
     *                           if applicable
     */
    protected function getQuotePrice($quotePrice)
    {
        switch ($this->margin) {
            case 'yes-fixed':
                $quotePrice += (float) $this->margin_amount;
                break;
            case 'yes-percentage':
                $quotePrice *= (1 + ( (float) $this->margin_amount / 100));
        }

        // ensure we get the lowest price, but not below 0.
        $quotePrice = max(0, $quotePrice);

        return $quotePrice;
    }

    /**
     * Checks if we can ship the products in the cart
     * remove Shippit for some items
     */
    private function _canShipDisabledProducts($package)
    {
        if ($this->filter_disabled_products == null) {
            return true;
        }

        $disallowedProducts = $this->filter_disabled_products;

        $products = $package['contents'];
        $productIds = array();

        foreach ($products as $itemKey => $product) {
            $productIds[] = $product['product_id'];
        }

        if (!empty($disallowedProducts)) {
            // If item is enabled return false
            if ($productIds = array_intersect($productIds, $disallowedProducts)) {
                $this->log->info(
                    'Can\'t Ship Products - some disabled. Skipping quote'
                );

                return false;
            }
        }

        return true;
}

    /**
     * Determine if the quote package content contains items we can quote on
     *
     * @param array $package
     * @return boolean
     */
    protected function canShipEnabledAttributes($products)
    {
        if ($this->filter_attribute === 'no') {
            return true;
        }

        $attributeCode = $this->filter_attribute_code;

        // Check if there is an attribute code set
        if (empty($attributeCode)) {
            return true;
        }

        $attributeValue = $this->filter_attribute_value;

        // Check if there is an attribute value set
        if (empty($attributeValue)) {
            return true;
        }

        foreach ($products as $product) {
            $productObject = new WC_Product($product['product_id']);
            $productAttributeValue = $productObject->get_attribute($attributeCode);

            if (strpos($productAttributeValue, $attributeValue) === false) {
                $this->log->info(
                    'A product in the cart does not match enabled filter attributes, skipping quoting'
                );

                return false;
            }
        }

        $this->log->debug(
            'The products in the cart matches enabled filter attributes'
        );

        return true;
    }

    /**
     * Checks if the Shippit Live Quote method is enabled
     *
     * @return boolean
     */
    private function isLiveQuotesEnabled(): bool
    {
        $zones = WC_Shipping_Zones::get_zones();

        foreach ($zones as $zone) {
            $shippingMethods = $zone['shipping_methods'];

            foreach ($shippingMethods as $shippingMethod) {
                if ($shippingMethod->id != 'mamis_shippit') {
                    continue;
                }

                return $shippingMethod->id = 'mamis_shippit'
                    && $shippingMethod->enabled == 'yes';
            }
        }
    }

    /**
     * Check whether Priority shipping should be offered based on QB Stock on Hand.
     * Returns false if any cart item's QB SOH is below the quantity in the cart.
     *
     * @param array $quoteContents
     * @return bool
     */
    protected function isPriorityAvailableByStock(array $quoteContents): bool
    {
        global $wpdb;

        // Build a map of SKU → required cart quantity, skipping items we can't resolve
        $skuToQty = [];

        foreach ($quoteContents as $cartItem) {
            $productId = !empty($cartItem['variation_id']) ? $cartItem['variation_id'] : $cartItem['product_id'];
            $product = wc_get_product($productId);

            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();

            if (empty($sku)) {
                $this->log->info(sprintf('Priority stock check: product ID %d has no SKU, skipping', $productId));
                continue;
            }

            $skuToQty[$sku] = (int) $cartItem['quantity'];
        }

        if (empty($skuToQty)) {
            return true;
        }

        // Fetch all SOH values in a single batched query
        $skus = array_keys($skuToQty);
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));

        if (!$wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tip_staging' AND table_name='qb_inventory'")) {
            $this->log->warning('QB inventory table not found — skipping SOH check');
            return true;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT sku, soh FROM tip_staging.qb_inventory WHERE sku IN ($placeholders)", $skus),
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $this->log->error('QB inventory query failed: ' . $wpdb->last_error);
            return true; // fail open — don't hide priority on transient DB errors
        }

        $sohMap = array_column($rows, 'soh', 'sku');

        foreach ($skuToQty as $sku => $cartQty) {
            $soh = isset($sohMap[$sku]) ? (int) $sohMap[$sku] : null;

            if ($soh === null || $soh < $cartQty) {
                $this->log->info(
                    sprintf('Priority shipping hidden: QB SOH for SKU %s is %s, cart qty is %d', $sku, $soh ?? 'not found', $cartQty)
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Build the shipping label with an EDD suffix for standard/express quotes.
     *
     * Priority order:
     *  1. delivery_date from API  → "Est. delivery DD/MM/YYYY" (+ handling days)
     *  2. estimated_transit_time  → parse business-day count, add handling days, compute date
     *  3. Handling enabled only   → "Allow X business days for dispatch"
     *  4. Fallback                → base label unchanged
     *
     * @param string $baseLabel
     * @param object $quote
     * @return string
     */
    protected function buildEddLabel(string $baseLabel, object $quote): string
    {
        if (!empty($quote->delivery_date)) {
            $displayDate = $this->eddHandlingEnabled && $this->eddHandlingDays > 0
                ? $this->addBusinessDays($quote->delivery_date, $this->eddHandlingDays)
                : date('d/m/Y', strtotime($quote->delivery_date));
            return $baseLabel . ' - Est. delivery ' . $displayDate;
        }

        if (!empty($quote->estimated_transit_time)) {
            preg_match('/(\d+)/', $quote->estimated_transit_time, $matches);
            $transitDays = isset($matches[1]) ? (int) $matches[1] : 0;
            $totalDays = $transitDays + ($this->eddHandlingEnabled ? $this->eddHandlingDays : 0);
            if ($totalDays > 0) {
                $displayDate = $this->addBusinessDays(date('Y-m-d'), $totalDays);
                return $baseLabel . ' - Est. delivery ' . $displayDate;
            }
        }

        if ($this->eddHandlingEnabled && $this->eddHandlingDays > 0) {
            return $baseLabel . ' - Allow ' . $this->eddHandlingDays . ' business day' . ($this->eddHandlingDays > 1 ? 's' : '') . ' for dispatch';
        }

        return $baseLabel;
    }

    /**
     * Add a number of business days (Mon–Fri) to a date string and return it formatted as d/m/Y.
     *
     * @param string $dateStr  Date parseable by DateTime (e.g. '2026-06-25')
     * @param int    $days     Number of business days to add
     * @return string          Formatted date string (d/m/Y)
     */
    protected function addBusinessDays(string $dateStr, int $days): string
    {
        if ($days <= 0) {
            return date('d/m/Y', strtotime($dateStr));
        }

        try {
            $dt = new DateTime($dateStr);
        } catch (Exception $e) {
            $this->log->error(sprintf('addBusinessDays: invalid date string "%s"', $dateStr));
            return date('d/m/Y');
        }

        $added = 0;

        while ($added < $days) {
            $dt->modify('+1 day');
            $dow = (int) $dt->format('N'); // 1=Mon … 7=Sun
            if ($dow < 6) {
                $added++;
            }
        }

        return $dt->format('d/m/Y');
    }
}
