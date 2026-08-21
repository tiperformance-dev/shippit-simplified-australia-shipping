<?php

/**
 * Mamis - https://www.mamis.com.au
 * Copyright © Mamis 2023-present. All rights reserved.
 * See https://www.mamis.com.au/license
 */

class Mamis_Shippit_Data_Mapper_Order extends Mamis_Shippit_Object
{
    protected $helper;
    protected $order;

    public function __invoke($order)
    {
        $this->helper = new Mamis_Shippit_Helper();
        $this->order = $order;

        $this->mapRetailerReference()
            ->mapRetailerInvoice()
            ->mapUserAttributes()
            ->mapReceiverName()
            ->mapReceiverContactNumber()
            ->mapReceiverLanguageCode()
            ->mapCourierType()
            ->mapCourierAllocation()
            ->mapDeliveryDate()
            ->mapDeliveryWindow()
            ->mapDeliveryCompany()
            ->mapDeliveryAddress()
            ->mapDeliverySuburb()
            ->mapDeliveryState()
            ->mapDeliveryPostcode()
            ->mapDeliveryCountryCode()
            ->mapDeliveryInstructions()
            ->mapAuthorityToLeave()
            ->mapProductCurrency()
            ->mapParcelAttributes();

        return $this;
    }

    public function mapRetailerReference()
    {
        $retailerReference = $this->order->get_id();

        return $this->setRetailerReference($retailerReference);
    }

    public function mapRetailerInvoice()
    {
        $retailerInvoice = $this->order->get_order_number();

        return $this->setRetailerInvoice($retailerInvoice);
    }

    public function mapReceiverName()
    {
        $receiverName = sprintf(
            '%s %s',
            $this->order->get_shipping_first_name(),
            $this->order->get_shipping_last_name()
        );

        return $this->setReceiverName(trim($receiverName));
    }

    public function mapReceiverContactNumber()
    {
        $receiverContactNumber = $this->order->get_billing_phone();

        return $this->setReceiverContactNumber($receiverContactNumber);
    }

    public function mapReceiverLanguageCode()
    {
        // WooCommerce does not provide order level
        // language code, so we rely on store locale
        $merchantLocale = get_locale();

        if (empty($merchantLocale)) {
            return $this;
        }

        $languageCode = explode('_', $merchantLocale);

        return $this->setReceiverLanguageCode(reset($languageCode));
    }

    public function mapUserAttributes()
    {
        $userAttributes = array(
            'email' => $this->order->get_billing_email(),
            'first_name' => $this->order->get_billing_first_name(),
            'last_name' => $this->order->get_billing_last_name(),
        );

        return $this->setUserAttributes($userAttributes);
    }

    public function mapCourierType()
    {
        if ($this->helper->isShippitLiveQuote($this->order)) {
            // If a shippit live quote is available, we'll set a courier allocation
            // as such, return early
            return $this;
        }

        $mappedShippingMethod = $this->helper->getMappedShippingMethod($this->order);

        // Plain label services are assigned as a courier allocation
        if ($mappedShippingMethod == 'plainlabel') {
            return $this;
        }
        elseif ($mappedShippingMethod !== false) {
            return $this->setCourierType($mappedShippingMethod);
        }

        return $this->setCourierType('standard');
    }

    public function mapCourierAllocation()
    {
        if ($this->helper->isShippitLiveQuote($this->order)) {
            $courierAllocation = $this->helper->getShippitLiveQuoteMetaAttributeValue($this->order, 'courier_allocation');

            return $this->setCourierAllocation($courierAllocation);
        }

        $mappedShippingMethod = $this->helper->getMappedShippingMethod($this->order);

        if ($mappedShippingMethod == 'plainlabel') {
            return $this->setCourierAllocation($mappedShippingMethod);
        }

        return $this;
    }

    public function mapDeliveryDate()
    {
        // Only provide a delivery date if the order has a shippit live quote
        if (!$this->helper->isShippitLiveQuote($this->order)) {
            return $this;
        }

        $deliveryDate = $this->helper->getShippitLiveQuoteMetaAttributeValue($this->order, 'delivery_date');

        if (empty($deliveryDate)) {
            return $this;
        }

        return $this->setDeliveryDate($deliveryDate);
    }

    public function mapDeliveryWindow()
    {
        // Only provide a delivery date if the order has a shippit live quote
        if (!$this->helper->isShippitLiveQuote($this->order)) {
            return $this;
        }

        $deliveryWindow = $this->helper->getShippitLiveQuoteMetaAttributeValue($this->order, 'delivery_window');

        if (empty($deliveryWindow)) {
            return $this;
        }

        return $this->setDeliveryWindow($deliveryWindow);
    }

    public function mapDeliveryCompany()
    {
        $deliveryCompany = $this->order->get_shipping_company();

        return $this->setDeliveryCompany($deliveryCompany);
    }

    public function mapDeliveryAddress()
    {
        $deliveryAddress = sprintf(
            '%s %s',
            $this->order->get_shipping_address_1(),
            $this->order->get_shipping_address_2()
        );

        return $this->setDeliveryAddress(trim($deliveryAddress));
    }

    public function mapDeliverySuburb()
    {
        $deliverySuburb = $this->order->get_shipping_city();

        return $this->setDeliverySuburb($deliverySuburb);
    }

    public function mapDeliveryPostcode()
    {
        $deliveryPostcode = $this->order->get_shipping_postcode();

        return $this->setDeliveryPostcode($deliveryPostcode);
    }

    public function mapDeliveryState()
    {
        $deliveryState = $this->order->get_shipping_state();

        // If no state has been provided, use the suburb
        if (empty($deliveryState)) {
            $deliveryState = $this->order->get_shipping_city();
        }

        return $this->setDeliveryState($deliveryState);
    }

    public function mapDeliveryCountryCode()
    {
        $deliveryCountryCode = $this->order->get_shipping_country();

        return $this->setDeliveryCountryCode(trim($deliveryCountryCode));
    }

    public function mapDeliveryInstructions()
    {
        // order notes are not the same thing as delivery instructions
        //$deliveryInstructions = $this->order->get_customer_note();
        $deliveryInstructions = "";

        return $this->setDeliveryInstructions($deliveryInstructions);
    }

    public function mapAuthorityToLeave()
    {
        $authorityToLeaveData = $this->order->get_meta('authority_to_leave', true);

        if (in_array(strtolower($authorityToLeaveData), ['yes', 'y', 'true', 'atl'])) {
            return $this->setAuthorityToLeave('Yes');
        }
        elseif (in_array(strtolower($authorityToLeaveData), ['no', 'n', 'false'])) {
            return $this->setAuthorityToLeave('No');
        }

        return $this;
    }

    public function mapProductCurrency()
    {
        $orderCurrency = $this->order->get_currency();

        if (empty($orderCurrency)) {
            return $this;
        }

        return $this->setProductCurrency($orderCurrency);
    }

    public function mapParcelAttributes()
    {
        $itemsData = array();
        $orderItems = $this->order->get_items();

        $virtualItemCount = 0;

        foreach ($orderItems as $orderItem) {
            // 2025-01-15 re-initialise the mapper each time otherwise we send stale product information from the previous item in the order to the API
            $orderItemDataMapper = new Mamis_Shippit_Data_Mapper_Order_Item();

            // If the order item does not have a linked product, skip it
            if (empty($orderItem['product_id'])) {
                continue;
            }

            $product = $orderItem->get_product();

            // If the linked product no longer exists, skip it
            if (empty($product)) {
                continue;
            }

            // If the product is a virtual item, skip it
            if ($product->is_virtual()) {
                $virtualItemCount++;

                continue;
            }

            $itemsData[] = $orderItemDataMapper->__invoke(
                $this->order,
                $orderItem,
                $product
            )->toArray();
        }

        // If the order has no shippable items, fall back to a default parcel
        // so that orders such as fee-only orders can still be synced
        if (empty($itemsData)) {
            $defaultParcel = $this->getDefaultParcel($virtualItemCount);

            if (!empty($defaultParcel)) {
                $itemsData[] = $defaultParcel;
            }
        }

        return $this->setParcelAttributes($itemsData);
    }

    /**
     * Retrieve the default parcel to be used when an order
     * does not contain any shippable products
     *
     * Note: The parcel is intentionally sent without a sku, so that the
     * fulfillment webhook is able to attribute the shipped quantity to
     * the order - @see Mamis_Shippit_Shipment::updateOrder
     *
     * @param int $virtualItemCount The number of virtual items on the order
     * @return array
     */
    protected function getDefaultParcel($virtualItemCount)
    {
        // Note: each option is read with its configured default as the fallback,
        // so the parcel applies on a store that has never saved the settings page
        $isEnabled = get_option(
            'wc_settings_shippit_default_parcel_enabled',
            Mamis_Shippit_Settings::DEFAULT_PARCEL_ENABLED
        );

        if ($isEnabled !== 'yes') {
            return array();
        }

        // If the order only contains virtual items, it is not intended
        // to be shipped - don't substitute a default parcel
        if ($virtualItemCount > 0) {
            return array();
        }

        $parcelWeight = get_option(
            'wc_settings_shippit_default_parcel_weight',
            Mamis_Shippit_Settings::DEFAULT_PARCEL_WEIGHT
        );

        // A cleared weight field stores an empty string, which is not the same
        // as the option being absent - fall back to the configured default
        if (!is_numeric($parcelWeight)) {
            $parcelWeight = Mamis_Shippit_Settings::DEFAULT_PARCEL_WEIGHT;
        }

        $parcel = array(
            'qty' => 1,
            'weight' => $this->helper->convertWeight($parcelWeight),
        );

        if (
            !defined('SHIPPIT_IGNORE_ITEM_DIMENSIONS')
            || !SHIPPIT_IGNORE_ITEM_DIMENSIONS
        ) {
            // Shippit refers to the vertical dimension as depth, WooCommerce as
            // height - the same mapping Mamis_Shippit_Data_Mapper_Order_Item makes
            $parcelDimensions = array(
                'length' => get_option(
                    'wc_settings_shippit_default_parcel_length',
                    Mamis_Shippit_Settings::DEFAULT_PARCEL_LENGTH
                ),
                'width' => get_option(
                    'wc_settings_shippit_default_parcel_width',
                    Mamis_Shippit_Settings::DEFAULT_PARCEL_WIDTH
                ),
                'depth' => get_option(
                    'wc_settings_shippit_default_parcel_height',
                    Mamis_Shippit_Settings::DEFAULT_PARCEL_HEIGHT
                ),
            );

            foreach ($parcelDimensions as $dimension => $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $parcel[$dimension] = $this->helper->convertDimension($value);
            }
        }

        return $parcel;
    }

    protected function getLegacyShippingOptions($shippingMethodId)
    {
        if (stripos($shippingMethodId, 'priority') === FALSE) {
            return;
        }

        return explode('_', $shippingMethodId);
    }
}
