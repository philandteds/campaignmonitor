<?php
    $Module = array( 'name' => 'Campaign Monitor',
        'variable_params' => true );

$ViewList = array();
$ViewList['preview_abandoned_cart_email'] = array(
    'script' => 'previewabandonedcartemail.php',
    'params' => array('OrderID'),
    'functions' => array('read')
);

$FunctionList = array(
    'read' => array()
);
