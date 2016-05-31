<?php

/**
 * Abandoned Cart Email processing. Finds orders that have been started but not completed in a given date range.
 * For each order, send an email through Campaign Monitor.
 */

DEFINE ('SECONDS_IN_DAY', 60*60*24);

$dateRangeStart = 50;
$dateRangeEnd = 40;


$cli = eZCLI::instance();
if (!$cli->isQuiet()) {
    $cli->notice("Starting Abandoned Cart Email Processing");
}

$fromDate = time() - ($dateRangeStart * SECONDS_IN_DAY);
$toDate = time() - ($dateRangeEnd * SECONDS_IN_DAY);

$db = eZDB::instance();

// To identify whether a cart has been abandoned, we must identify temporary carts (is_temporary = 0) with no corresponding later
// non-temporary ones. Unfortunately, the email address is buried in an XML field, and a pure SQL solution is extremely slow without
// the means to do an efficient join. Therefore, we handle the exclusion of completed carts in code by iterating through all
// carts, adding those that are temporary and removing those that are not. The dataset is sorted so that non-temporary carts
// always come after temporary ones.
$sql = "
    select max(created) as created, is_temporary, substring(data_text_1, locate('<email>', data_text_1) + length('<email>'), 
                 locate('</email>', data_text_1)-locate('<email>', data_text_1)-length('<email>')) as email
    from ezorder
    where created > $fromDate
    group by is_temporary, email
    order by email, is_temporary
";

$rows = $db->arrayQuery($sql);
$candidateCarts = array();

foreach ($rows as $row) {
    $is_temporary = $row['is_temporary'];
    $created = $row['created'];
    $email = $row['email'];

    if ($is_temporary == 1 && $created > $fromDate && $created <= $toDate) {
        $candidateCarts[$email] = 1;
    } else if ($is_temporary == 0) {
        unset($candidateCarts[$email]);
    }
}

// we've passed through the data. Anything left in the $candidateCarts array is fair game
foreach (array_keys($candidateCarts) as $cartEmail) {
    $cli->notice("Abandoned cart detected: $cartEmail");
}

if (!$cli->isQuiet()) {
    $cli->notice("Finished Abandoned Cart Email Processing");
}
