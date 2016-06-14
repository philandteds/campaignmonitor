<?php

$tpl = eZTemplate::factory();

$orderId = $Params['OrderID'];
$order = eZOrder::fetch($orderId);

$Result = array();
$tpl->setVariable("order", $order);
$tpl->setVariable("locale", eZLocale::instance()->localeFullCode());
$Result['content'] = $tpl->fetch( 'design:shop/orderemail/html/abandoned_cart.tpl' );
$Result['pagelayout'] = false;
