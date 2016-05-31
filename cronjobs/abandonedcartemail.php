<?php

/**
 * Abandoned Cart Email processing. Finds orders that have been started but not completed in a given date range.
 * For each order, send an email through Campaign Monitor.
 */

DEFINE ('SECONDS_IN_DAY', 60*60*24);

$ini = eZINI::instance('campaign_monitor.ini');
$dateRangeStart = $ini->variable('AbandonedCartEmail', 'DateRangeStartDays');
$dateRangeEnd = $ini->variable('AbandonedCartEmail', 'DateRangeEndDays');

// default values
if (!$dateRangeStart)
    $dateRangeStart = 7;
if (!$dateRangeEnd)
    $dateRangeEnd = 1;


$cli = eZCLI::instance();
if (!$cli->isQuiet()) {
    $cli->notice("Starting Abandoned Cart Email Processing");
}

$fromDate = time() - ($dateRangeStart * SECONDS_IN_DAY);
$toDate = time() - ($dateRangeEnd * SECONDS_IN_DAY);

$db = eZDB::instance();

$abandonedCarts = identifyAbandonedCarts($db, $fromDate, $toDate);

// we've passed through the data. Anything left in the $candidateCarts array is fair game
foreach ($abandonedCarts as $cartEmail) {
    $cli->notice("Abandoned cart detected: $cartEmail");
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
 * @param $db Database instance
 * @param $fromDate long start of date range to check for abandoned carts (ie the earliest date/time)
 * @param $toDate long end of date range to check for abandoned carts (ie the latest date/time)
 * @return array List of the email addresses with abandoned carts
 */
function identifyAbandonedCarts($db, $fromDate, $toDate) {
    $sql = "
    select max(created) as created, is_temporary, substring(data_text_1, locate('<email>', data_text_1) + length('<email>'), 
                 locate('</email>', data_text_1)-locate('<email>', data_text_1)-length('<email>')) as email
    from ezorder
    where created > $fromDate
    group by is_temporary, email
    order by email, is_temporary desc
";

    $rows = $db->arrayQuery($sql);
    $candidateCarts = array();

    foreach ($rows as $row) {
        $is_temporary = $row['is_temporary'];
        $created = $row['created'];
        $email = strtolower($row['email']);

        if ($is_temporary == 1 && $created > $fromDate && $created <= $toDate) {
            $candidateCarts[$email] = 1;
        } else if ($is_temporary == 0) {
            unset($candidateCarts[$email]);
        }
    }

    // we've passed through the data. Anything left in the $candidateCarts array is fair game
    return array_keys($candidateCarts);
}

/**
 * Sends the abandoned cart email through the Campaign Monitor interface
 *
 * @param $email String the recipient of the email
 */
function sendAbandonedCartEmail($email) {
    
}