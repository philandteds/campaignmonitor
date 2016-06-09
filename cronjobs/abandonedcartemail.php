<?php

/**
 * Abandoned Cart Email processing. Finds orders that have been started but not completed in a given date range.
 * For each order, send an email through Campaign Monitor.
 */

define ('EMAIL_TEMPLATE_NAME', 'design:shop/orderemail/html/abandoned_cart.tpl');
define ('CAMPAIGN_MONITOR_GROUP', 'Abandoned Cart');
define ('SECONDS_IN_DAY', 60*60*24);

$cli = eZCLI::instance();
if (!$cli->isQuiet()) {
    $cli->notice("Starting Abandoned Cart Email Processing");
}

$ini = eZINI::instance('campaign_monitor.ini');
$dateRangeStart = $ini->variable('AbandonedCartEmail', 'DateRangeStartDays');
$dateRangeEnd = $ini->variable('AbandonedCartEmail', 'DateRangeEndDays');
$sender = $ini->variable('AbandonedCartEmail', 'EmailSender');
$apiKey = $ini->variable('General', 'APIKey');
$clientId = $ini->variable('General', 'ClientID');

$subject = 'Abandoned Cart';
$siteAccess = $script->SiteAccess;


// default values
if (!$dateRangeStart)
    $dateRangeStart = 7;
if (!$dateRangeEnd)
    $dateRangeEnd = 1;

$fromDate = time() - ($dateRangeStart * SECONDS_IN_DAY);
$toDate = time() - ($dateRangeEnd * SECONDS_IN_DAY);

$abandonedCarts = identifyAbandonedCarts($fromDate, $toDate, $subject, $siteAccess);
foreach ($abandonedCarts as $email => $orderId) {
    $cli->notice("Abandoned cart detected: $email. Latest Order: $orderId");

    sendAbandonedCartEmail($email, $orderId, $sender, $subject, $apiKey, $clientId, $cli);
    logAbandonedCartEmailSend($email, $sender, $subject, true);
}

if (!$cli->isQuiet()) {
    $cli->notice("Finished Abandoned Cart Email Processing");
}


/**
 * Find the abandoned carts (within a date range)
 *
 * To identify whether a cart has been abandoned, we must identify temporary carts (is_temporary = 0) with no corresponding later
 * non-temporary ones. Unfortunately, the email address is buried in an XML field, and a pure SQL solution is extremely slow without
 * the means to do an efficient join. Therefore, we handle the exclusion of completed carts in code by iterating through all
 * carts, adding those that are temporary and removing those that are not. The dataset is sorted so that non-temporary carts
 * always come after temporary ones.
 *
 * Any carts that already have an abandoned cart email entry in mail_logs are not considered.
 *
 * @param $fromDate integer start of date range to check for abandoned carts (ie the earliest date/time)
 * @param $toDate integer end of date range to check for abandoned carts (ie the latest date/time)
 * @param $emailSubject string the Subject message to look for in the mail_logs table
 * @return array Map of the email addresses with abandoned carts & their order numbers (email address is the key, order number is the value)
 */
function identifyAbandonedCarts($fromDate, $toDate, $emailSubject, $siteAccess) {
    $db = eZDB::instance();

    $encodedSubject = $db->escapeString($emailSubject);
    $encodedSiteAccess = $db->escapeString($siteAccess);

    $sql = "
        select created, orderId, is_temporary, email
        from (
            select max(created) as created, max(id) as orderId, is_temporary, substring(data_text_1, locate('<email>', data_text_1) + length('<email>'), 
                            locate('</email>', data_text_1)-locate('<email>', data_text_1)-length('<email>')) as email
            from ezorder
            where created > $fromDate
            and data_text_1 like '%<siteaccess>$encodedSiteAccess</siteaccess>%'
            group by is_temporary, email
        ) as carts
        where not exists (select 1 from mail_logs where  receivers=email and created > $fromDate and subject like '$encodedSubject')
        order by email, is_temporary desc
";

    $rows = $db->arrayQuery($sql);
    $candidateCarts = array();

    foreach ($rows as $row) {
        $is_temporary = $row['is_temporary'];
        $created = $row['created'];
        $email = strtolower($row['email']);
        $orderId = strtolower($row['orderId']);

        if ($is_temporary == 1 && $created > $fromDate && $created <= $toDate) {
            $candidateCarts[$email] = $orderId;
        } else if ($is_temporary == 0) {
            unset($candidateCarts[$email]);
        }
    }

    // we've passed through the data. Anything left in the $candidateCarts array is fair game
    return $candidateCarts;
}

/**
 * Sends the abandoned cart email through the Campaign Monitor interface
 *
 * @param $email String the recipient of the email
 * @param $orderId integer the order ID to pass to the template (as the "order" variable)
 * @param $sender String the email address of the email sender
 * @param $subject String email subject line
 * @param $apiKey String the Campaign Monitor API key (for auth)
 * @param $clientId String the Campaign Monitor Client ID (for auth)
 * @param $cli eZCLI the CLI session (for logging)
 * @return bool true on success, false on failure.
 */
function sendAbandonedCartEmail($email, $orderId, $sender, $subject, $apiKey, $clientId, $cli) {

    $tpl = eZTemplate::factory();

    if ($orderId) {
        $order = eZOrder::fetch( $orderId );
        $tpl->setVariable( "order", $order );
    }

    $html = $tpl->fetch(EMAIL_TEMPLATE_NAME);

    if ($tpl->hasVariable("subject")) {
        $subject = $tpl->variable("subject");
    }


    if (!$html) {
        $cli->notice("Error when rendering " . EMAIL_TEMPLATE_NAME . " . for email: $email. order ID: $orderId");
        return false;
    }

    if (!$cli->isQuiet()) {
        $cli->notice("Sending Abandoned Cart Email to $email. Order ID: $orderId");
    }


    $message = array(
        'From' => $sender,
        'ReplyTo' => null,
        'To' => array(
            $email
        ),
        'CC' => null,
        'BCC' => null,
        'Subject' => $subject,
        'Html' => $html,
        'Text' => null, // let campaign monitor autogenerate the text part from the HTML
        'Attachments' => null
    );

    // send
    $campaignMonitorAPI = new CS_REST_Transactional_ClassicEmail($apiKey);
    $campaignMonitorAPI->set_client($clientId);
    $response = $campaignMonitorAPI->send($message, CAMPAIGN_MONITOR_GROUP);

    if (!$cli->isQuiet()) {
        $formattedResponse = print_r($response, true);
        $cli->notice("Response: $formattedResponse");
    }

    return $response->http_status_code >= 200 && $response->http_status_code < 300;
}


/**
 * Records this send in the mail_log table.
 *
 * @param $email string Email recipient
 * @param $sender string Email sender
 * @param $subject string Email subject
 * @param $success boolean Did campaign monitor return a  code in the 200 success range?
 */
function logAbandonedCartEmailSend($email, $sender, $subject, $success) {
    
    $logRecord = new MailLogItem(array(
        'date' => time(),
        'subject' => $subject,
        'receivers' => $email,
        'is_sent' => 1,
        'error' => $success ? null : "1",
        'sender' => $sender));

    $logRecord->store();
}