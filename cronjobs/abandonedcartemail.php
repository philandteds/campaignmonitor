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


// default values
if (!$dateRangeStart)
    $dateRangeStart = 7;
if (!$dateRangeEnd)
    $dateRangeEnd = 1;

$fromDate = time() - ($dateRangeStart * SECONDS_IN_DAY);
$toDate = time() - ($dateRangeEnd * SECONDS_IN_DAY);

$abandonedCarts = identifyAbandonedCarts($fromDate, $toDate, $subject);
foreach ($abandonedCarts as $email => $orderId) {
    $cli->notice("Abandoned cart detected: $email. Latest Order: $orderId");

    $sendResponse = sendAbandonedCartEmail($email, $orderId, $sender, $subject, $apiKey, $clientId, $cli);
    if( $sendResponse ) logAbandonedCartEmailSend($email, $sender, $subject, true);
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
function identifyAbandonedCarts($fromDate, $toDate, $emailSubject) {
    $db = eZDB::instance();

    $encodedSubject = $db->escapeString($emailSubject);

    $sql = "
        select created, orderId, is_temporary, tmp_email
        from (
            select max(created) as created, max(id) as orderId, is_temporary, substring(data_text_1, locate('<email>', data_text_1) + length('<email>'), 
                            locate('</email>', data_text_1)-locate('<email>', data_text_1)-length('<email>')) as tmp_email
            from ezorder
            where created > $fromDate
            group by is_temporary, tmp_email
        ) as carts
        where not exists (select 1 from mail_logs where  receivers=tmp_email and created > $fromDate and subject like '$encodedSubject')
        order by tmp_email, is_temporary desc
";

    $rows = $db->arrayQuery($sql);
    $candidateCarts = array();

    foreach ($rows as $row) {
        $is_temporary = $row['is_temporary'];
        $created = $row['created'];
        $email = strtolower($row['tmp_email']);
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

    $originalSiteAccess = eZSiteAccess::current();

    if (!$orderId) {
        $cli->error("Cannot find $orderId for $email");
        return false;
    }

    $order = eZOrder::fetch( $orderId );
    $siteaccess = getSiteAccessForOrder($order);

    if ($siteaccess) {
        // switch to siteaccess of user in order to generate localized, internationalized, translated HTML
        eZSiteAccess::load(
            array( 'name' => $siteaccess,
                   'type' => eZSiteAccess::TYPE_STATIC,
                   'uri_part' => array()));
        $cli->notice("Switched to siteaccess $siteaccess");
    } else {
        $cli->error("Cannot find a siteaccess for $orderId");
        return false;
    }

    $cm_ini = eZINI::instance('campaign_monitor.ini');
    if($cm_ini->variable('AbandonedCartEmail', 'SendEmail') != 'enabled') return false;

    $tpl = eZTemplate::factory();
    $tpl->setVariable( "order", $order );
    $locale = getLocaleForSiteAccess($siteaccess);
    $tpl->setVariable( "locale", $locale);

    $html = $tpl->fetch(EMAIL_TEMPLATE_NAME);
    if (!$html) {
        $cli->error("Could not render email for $email (siteaccess $siteaccess). This is probably because no design in that siteaccess includes " . EMAIL_TEMPLATE_NAME);
        return false;
    }

    if ($tpl->hasVariable("subject")) {
        $subject = $tpl->variable("subject");
    }

    // revert back to original siteaccess
    eZSiteAccess::load($originalSiteAccess);

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


/**
 * Given an order, determine its siteaccess. Strangely, the accountInformation() call doesn't return this, so
 * we extract it manually.
 *
 * @param order eZOrder order to inspect
 * @return siteaccess name or false if it could not be determined
 */
function getSiteAccessForOrder($order) {

    $xml = $order->attribute('data_text_1');
    if (!$xml) {
        return false;
    }

    $matches = array();
    if (!preg_match("/<siteaccess>(.*)<\\/siteaccess>/", $xml, $matches)) {
        return false;
    }

    return $matches[1];
}

/**
 * Given a siteacess name, derive its locale string
 *
 * @param $siteaccess string name of the siteaccess
 * @return string locale of the siteaccess, or null if it could not be determined
 */
function getLocaleForSiteAccess($siteaccesstoFind) {

    $ini = eZIni::instance();
    $languageSiteAccesses = $ini->variable('RegionalSettings', 'LanguageSA' );

    // look for the language that references the siteaccess
    foreach ($languageSiteAccesses as $locale => $siteaccess) {
        if (trim($siteaccess) == $siteaccesstoFind) {
            return $locale;
        }
    }
    return false;
}
