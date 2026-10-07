<?php

/**
* Mamis - https://www.mamis.com.au
* Copyright © Mamis 2023-present. All rights reserved.
* See https://www.mamis.com.au/license
*/

class Mamis_Shippit_Helper
{
    /**
     * Convert the dimension to a different unit size
     *
     * based on https://gist.github.com/mbrennan-afa/1812521
     *
     * @param  float $dimension  The dimension to be converted
     * @param  string $unit      The unit to be converted to
     * @return float             The converted dimension
     */
    public function convertDimension($dimension, $unit = 'm'): float
    {
        $dimensionCurrentUnit = get_option('woocommerce_dimension_unit');
        $dimensionCurrentUnit = strtolower($dimensionCurrentUnit);
        $unit = strtolower($unit);

        if ($dimensionCurrentUnit !== $unit) {
            // Unify all units to cm first
            switch ($dimensionCurrentUnit) {
                case 'inch':
                    $dimension *= 2.54;
                    break;
                case 'm':
                    $dimension *= 100;
                    break;
                case 'mm':
                    $dimension *= 0.1;
                    break;
            }

            // Output desired unit
            switch ($unit) {
                case 'inch':
                    $dimension *= 0.3937;
                    break;
                case 'm':
                    $dimension *= 0.01;
                    break;
                case 'mm':
                    $dimension *= 10;
                    break;
            }
        }

        return (float) $dimension;
    }

    /**
     * Convert the weight to a different unit size
     *
     * based on https://gist.github.com/mbrennan-afa/1812521
     *
     * @param  float $weight     The weight to be converted
     * @param  string $unit      The unit to be converted to
     * @return float             The converted weight
     */
    public function convertWeight($weight, $unit = 'kg'): float
    {
        $weightCurrentUnit = get_option('woocommerce_weight_unit');
        $weightCurrentUnit = strtolower($weightCurrentUnit);
        $unit = strtolower($unit);

        if ($weightCurrentUnit !== $unit) {
            // Unify all units to kg first
            switch ($weightCurrentUnit) {
                case 'g':
                    $weight *= 0.001;
                    break;
                case 'lbs':
                    $weight *= 0.4535;
                    break;
                case 'oz':
                    $weight *= 0.0279798545;
                    break;
            }

            // Output desired unit
            switch ($unit) {
                case 'g':
                    $weight *= 1000;
                    break;
                case 'lbs':
                    $weight *= 2.204;
                    break;
                case 'oz':
                    $weight *= 35.274;
                    break;
            }
        }

        return (float) $weight;
    }

    /**
     * Determines if the order uses a Shippit Live quote shipping method
     *
     * @param WC_Order $order
     * @return boolean
     */
    public function isShippitLiveQuote(WC_Order $order): bool
    {
        $shippingMethods = $order->get_shipping_methods();

        foreach ($shippingMethods as $shippingMethod) {
            // If the method is a shippit live quote, return the title of the method
            if ($shippingMethod->get_method_id() === 'mamis_shippit') {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves the metadata attached to the live quote if it's a Shippit live quote
     *
     * @param WC_Order $order
     * @param string $metaAttribute
     * @return string|null|void
     */
    public function getShippitLiveQuoteMetaAttributeValue(WC_Order $order, $metaAttribute)
    {
        $shippingMethods = $order->get_shipping_methods();

        foreach ($shippingMethods as $shippingMethod) {
            // If the method is a shippit live quote, return the title of the method
            if ($shippingMethod->get_method_id() === 'mamis_shippit') {
                return $shippingMethod->get_meta($metaAttribute);
            }
        }
    }

    /**
     * Retrieve the mapped shipping method for the order
     *
     * @param WC_Order $order
     * @return string|false
     */
    public function getMappedShippingMethod(WC_Order $order)
    {
        $shippingMethods = $order->get_shipping_methods();
        $mappingsStandard = get_option('wc_settings_shippit_standard_shipping_methods', array());
        $mappingsExpress = get_option('wc_settings_shippit_express_shipping_methods', array());
        $mappingsClickAndCollect = get_option('wc_settings_shippit_clickandcollect_shipping_methods', array());
        $mappingsPlainLabel = get_option('wc_settings_shippit_plainlabel_shipping_methods', array());

        foreach ($shippingMethods as $shippingMethod) {
            $shippingMethodId = $this->getShippingMethodId($shippingMethod);

            // If the method is a shippit live quote, return the title of the method
            if ($shippingMethod->get_method_id() == 'mamis_shippit') {
                $serviceLevel = $shippingMethod->get_meta('service_level');

                if (!empty($serviceLevel)) {
                    return $serviceLevel;
                }
            }

            // Otherwise, attempt to locate a suitable method based on shipping method mappings
            if (in_array($shippingMethodId, $mappingsStandard)) {
                return 'standard';
            }
            elseif (in_array($shippingMethodId, $mappingsExpress)) {
                return 'express';
            }
            elseif (in_array($shippingMethodId, $mappingsClickAndCollect)) {
                return 'click_and_collect';
            }
            elseif (in_array($shippingMethodId, $mappingsPlainLabel)) {
                return 'plainlabel';
            }
        }

        return false;
    }

    public function getFriendlyCourierName($courier_type, $service_level)
    {
        $courierFriendlyNames = [
            //Priority
            'AlliedExpressSameday' => 'Allied Express Same Day',
            'Bonds' => 'Bonds',
            'YelloOndemand' => 'Yello',
            
            //Express
            'EparcelExpress' => 'Express Post',
            'DhlExpressInternational' => 'DHL Express International',
            'EparcelInternationalExpress' => 'International Express Post',
            'DhlExpress' => 'DHL Express',
            
            //Standard
            'EparcelInternational' => 'International Post',
            'Eparcel' => 'Standard Post',
            'CouriersPlease' => 'Couriers Please',
			'CouriersPleaseLMU' => 'Couriers Please',
            'AlliedExpressOvernight' => 'Allied',
            'Fastway' => 'Fastway',
            'AramexAuNz' => 'Aramex',
            'Tnt' => 'TNT',

            //On Demand
            'UberOndemand' => 'Uber Direct'
        ];
        
        $friendlyName = $courierFriendlyNames[$courier_type] ?? ucwords($service_level);

        return $friendlyName;

    }

    protected function getShippingMethodId(WC_Order_Item_Shipping $shippingMethod): string
    {
        // Since Woocommerce v3.4.0, the instance_id is saved in a seperate property of the shipping method
        // To add support for v3.4.0, we'll append the instance_id, as this is how we store a mapping in Shippit
        if (!empty($shippingMethod['instance_id'])) {
            $shippingMethodId = sprintf(
                '%s:%s',
                $shippingMethod['method_id'],
                $shippingMethod['instance_id']
            );
        }
        else {
            $shippingMethodId = $shippingMethod['method_id'];
        }

        // If the shipping method id has more than 1 ":" occarance,
        // we only want the {method_id}:{instance_id}
        // — stripping all other data
        if (substr_count($shippingMethodId, ':') > 1) {
            $shippingMethodId = sprintf(
                '%s:%s',
                strtok($shippingMethodId, ':'),
                strtok(':')
            );
        }

        return $shippingMethodId;
    }

    /**
     * Retrieve the merchant operating hours, keyed by lowercase day name
     *
     * Synced from Shippit when the settings page is saved.
     *
     * @return array
     */
    protected function getOperatingHoursByDay()
    {
        $workingDays = get_option('wc_settings_shippit_merchant_operating_hours', array());

        if (!is_array($workingDays) || empty($workingDays)) {
            return array();
        }

        $hoursByDay = array();

        foreach ($workingDays as $workingDay) {
            // Entries may be arrays or objects depending on how they were stored
            $workingDay = (array) $workingDay;

            if (empty($workingDay['day'])) {
                continue;
            }

            $hoursByDay[strtolower($workingDay['day'])] = $workingDay;
        }

        return $hoursByDay;
    }

    /**
     * Retrieve the configured preparation time, in minutes
     *
     * @return int
     */
    protected function getPreparationMinutes()
    {
        return (int) get_option('wc_settings_shippit_merchant_preparation_time', 0);
    }

    /**
     * Build a DateTime for a HH:MM time on the given day, in the store timezone
     *
     * @param DateTime $day
     * @param string $time
     * @return DateTime|null
     */
    protected function getTimeOnDay(DateTime $day, $time)
    {
        if (empty($time) || strpos($time, ':') === false) {
            return null;
        }

        return new DateTime($day->format('Y-m-d') . ' ' . $time, wp_timezone());
    }

    /**
     * Determine if the store can no longer dispatch today - ie. the order
     * could not be prepared before closing time, or the store is shut today
     *
     * An order placed before opening is NOT past the cutoff, as the store can
     * still dispatch it once it opens.
     *
     * @return bool|null Null when the operating hours have not been synced
     */
    public function isPastPickupCutoff()
    {
        $hoursByDay = $this->getOperatingHoursByDay();

        if (empty($hoursByDay)) {
            return null;
        }

        $now = new DateTime('now', wp_timezone());
        $dayName = strtolower($now->format('l'));

        if (empty($hoursByDay[$dayName]) || empty($hoursByDay[$dayName]['is_open'])) {
            return true;
        }

        $close = $this->getTimeOnDay($now, $hoursByDay[$dayName]['end_of_workday']);

        if ($close === null) {
            return null;
        }

        $candidate = clone $now;
        $candidate->modify(sprintf('+%d minutes', $this->getPreparationMinutes()));

        return ($candidate > $close);
    }

    /**
     * Determine if an ASAP pickup can be booked right now - ie. the store is
     * open and the order can still be prepared before closing
     *
     * @return bool
     */
    protected function canPickupAsap()
    {
        $hoursByDay = $this->getOperatingHoursByDay();
        $now = new DateTime('now', wp_timezone());
        $dayName = strtolower($now->format('l'));

        if (empty($hoursByDay[$dayName]) || empty($hoursByDay[$dayName]['is_open'])) {
            return false;
        }

        $open = $this->getTimeOnDay($now, $hoursByDay[$dayName]['beginning_of_workday']);
        $close = $this->getTimeOnDay($now, $hoursByDay[$dayName]['end_of_workday']);

        if ($open === null || $close === null) {
            return false;
        }

        $candidate = clone $now;
        $candidate->modify(sprintf('+%d minutes', $this->getPreparationMinutes()));

        return ($candidate >= $open && $candidate <= $close);
    }

    /**
     * Retrieve the next day the store is open, today included
     *
     * @param DateTime $from
     * @return DateTime|null
     */
    public function getNextOpenDay(DateTime $from)
    {
        $hoursByDay = $this->getOperatingHoursByDay();

        if (empty($hoursByDay)) {
            return null;
        }

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $day = clone $from;

            if ($dayOffset > 0) {
                $day->modify(sprintf('+%d days', $dayOffset));
            }

            $dayName = strtolower($day->format('l'));

            if (!empty($hoursByDay[$dayName]['is_open'])) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Calculate the pickup time to send for an on demand order
     *
     * Returns null when the store is open and there is still time to prepare
     * the order before closing - Shippit then books an ASAP pickup itself,
     * which is the behaviour prior to this feature. Also returns null when the
     * operating hours are unavailable, so the feature fails open.
     *
     * @return string|null ISO 8601, eg. 2026-09-29T10:00:00+10:00
     */
    public function getOnDemandPickupAt()
    {
        $hoursByDay = $this->getOperatingHoursByDay();

        if (empty($hoursByDay)) {
            return null;
        }

        // Open, and the order can still be prepared before closing - let
        // Shippit calculate the pickup as it does today
        if ($this->canPickupAsap()) {
            return null;
        }

        $preparationMinutes = $this->getPreparationMinutes();
        $now = new DateTime('now', wp_timezone());

        $candidate = clone $now;
        $candidate->modify(sprintf('+%d minutes', $preparationMinutes));

        // Use the first open day whose opening time plus preparation still
        // falls within that day's operating hours. Today is included so that
        // an order placed before opening is picked up the same morning.
        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $day = clone $now;

            if ($dayOffset > 0) {
                $day->modify(sprintf('+%d days', $dayOffset));
            }

            $dayName = strtolower($day->format('l'));

            if (empty($hoursByDay[$dayName]) || empty($hoursByDay[$dayName]['is_open'])) {
                continue;
            }

            $open = $this->getTimeOnDay($day, $hoursByDay[$dayName]['beginning_of_workday']);
            $close = $this->getTimeOnDay($day, $hoursByDay[$dayName]['end_of_workday']);

            if ($open === null || $close === null) {
                continue;
            }

            $pickup = clone $open;
            $pickup->modify(sprintf('+%d minutes', $preparationMinutes));

            // Today's opening slot has already been and gone
            if ($dayOffset === 0 && $pickup < $candidate) {
                continue;
            }

            if ($pickup <= $close) {
                return $pickup->format('c');
            }
        }

        return null;
    }

    /**
     * Format a date for display on a shipping method label
     *
     * Short by design - the delivery options sit in a narrow column and a full
     * d/m/Y date wraps onto a second line at mobile widths. Defined here so
     * every service level renders its date the same way.
     *
     * @param string|int $date A date string or a timestamp
     * @return string eg. '8 Oct', or an empty string if it cannot be read
     */
    public function formatLabelDate($date)
    {
        $timestamp = is_numeric($date) ? (int) $date : strtotime($date);

        if (empty($timestamp)) {
            return '';
        }

        return wp_date('j M', $timestamp);
    }

    /**
     * Format a pickup time for display on a shipping method label
     *
     * Matches the casing Shippit uses for its own priority delivery windows
     * (10AM-1PM) so the times read consistently down the list. The minutes are
     * only shown when there are any, as a pickup usually lands on the hour.
     *
     * @param string $pickupAt
     * @return string
     */
    public function formatPickupAtLabel($pickupAt)
    {
        $timestamp = strtotime($pickupAt);

        if (empty($timestamp)) {
            return '';
        }

        $timeFormat = wp_date('i', $timestamp) === '00' ? 'gA' : 'g:iA';

        return $this->formatLabelDate($timestamp) . ' from ' . wp_date($timeFormat, $timestamp);
    }
}
