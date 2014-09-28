<?php

/**
 * @package CampaignMonitor
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    09 May 2013
 * */
class ShopAddSubscriberType extends eZWorkflowEventType {

    const TYPE_ID = 'shopaddsubscriber';

    public function __construct() {
        $this->eZWorkflowEventType( self::TYPE_ID, 'Campaign Monitor - Shop add subscriber' );
        $this->setTriggerTypes(
            array(
                'shop'            => array(
                    'confirmorder' => array(
                        'before'
                    )
                ),
                'recurringorders' => array(
                    'checkout' => array(
                        'before'
                    )
                )
            )
        );
    }

    public function execute( $process, $event ) {
        $parameters = $process->attribute( 'parameter_list' );
        $order      = eZOrder::fetch( $parameters['order_id'] );

        if( $this->canAddSubscriber( $order ) ) {
            $this->addSubscriber( $order );
        }

        return eZWorkflowType::STATUS_ACCEPTED;
    }

    public function canAddSubscriber( $order ) {
        $ini          = eZINI::instance( 'campaign_monitor.ini' );
        $checkboxAttr = $ini->variable( 'ShopAddSubscriber', 'NewsletterCheckbox' );

        if( $order instanceof eZOrder ) {
            $xml = new SimpleXMLElement( $order->attribute( 'data_text_1' ) );
            if(
                $xml != null && isset( $xml->{$checkboxAttr} )
            ) {
                return (bool) (string) $xml->{$checkboxAttr};
            }
        }

        return false;
    }

    public function addSubscriber( $order ) {
        $ini = eZINI::instance( 'campaign_monitor.ini' );

        $staticFields = $ini->variable( 'ShopAddSubscriber', 'StaticCustomFields' );
        $fields       = $ini->variable( 'ShopAddSubscriber', 'CustomFields' );

        $accountInfo = null;
        if( $order instanceof eZOrder ) {
            $accountInfo = new SimpleXMLElement( $order->attribute( 'data_text_1' ) );
            if( $accountInfo === null ) {
                return false;
            }
        }

        $subscriber = array(
            'EmailAddress' => (string) $accountInfo->email,
            'Name'         => (string) $accountInfo->first_name . ' ' . (string) $accountInfo->last_name,
            'CustomFields' => array(),
            'Resubscribe'  => true
        );

        foreach( $staticFields as $customField => $value ) {
            $subscriber['CustomFields'][] = array(
                'Key'   => $customField,
                'Value' => $value
            );
        }
        foreach( $fields as $customField => $accountInfoField ) {
            if( isset( $accountInfo->{$accountInfoField} ) ) {
                $value = (string) $accountInfo->{$accountInfoField};
                if( $customField === 'Country' ) {
                    $country = eZCountryType::fetchCountry( $value, 'Alpha3' );
                    if( is_array( $country ) && isset( $country['Name'] ) ) {
                        $value = $country['Name'];
                    }
                }

                $subscriber['CustomFields'][] = array(
                    'Key'   => $customField,
                    'Value' => $value
                );
            }
        }
        var_dump( $subscriber ); exit();
        if( array_key_exists( 'productsIown', $fields ) ) {
            $productSKUs  = array();
            $productItems = $order->attribute( 'product_items' );
            foreach( $productItems as $item ) {
                $productItem = $item['item_object'];
                if( $productItem instanceof eZProductCollectionItem === false ) {
                    continue;
                }

                $object = $productItem->attribute( 'contentobject' );
                if( $object instanceof eZContentObject === false ) {
                    continue;
                }

                $dataMap = $object->attribute( 'data_map' );
                if(
                    isset( $dataMap['product_id'] ) === false || isset( $dataMap['version'] ) === false
                ) {
                    $productSKUs[] = $object->attribute( 'main_node_id' );
                    continue;
                }

                $productSKU = $dataMap['product_id']->attribute( 'content' );
                $version    = trim( $dataMap['version']->attribute( 'content' ) );
                if( strlen( $version ) > 0 ) {
                    $productSKU .= '_' . $version;
                }

                $productSKUs[] = strtoupper( $productSKU );
            }

            $subscriber['CustomFields'][] = array(
                'Key'   => $fields['productsIown'],
                'Value' => implode( ',', $productSKUs )
            );
        }

        define( 'CS_REST_SOCKET_TIMEOUT', 30 );
        define( 'CS_REST_CALL_TIMEOUT', 30 );
        $auth   = $ini->variable( 'General', 'APIKey' );
        $listID = $ini->variable( 'ShopAddSubscriber', 'ListID' );
        $api    = new CS_REST_Subscribers( $listID, $auth );
        $result = $api->add( $subscriber );

        return $result->was_successful();
    }

}

eZWorkflowEventType::registerEventType( ShopAddSubscriberType::TYPE_ID, 'ShopAddSubscriberType' );
