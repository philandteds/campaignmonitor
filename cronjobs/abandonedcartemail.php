<?php

/**
 * Abandoned Cart Email processing. Finds orders that have been started but not completed in a given date range.
 * For each order, send an email through Camaign Monitor.
 */

$cli = eZCLI::instance();
if (!$cli->isQuiet()) {
    $cli->notice("Starting Abandoned Cart Email Processing");
}

$db = eZDB::instance();


if (!$cli->isQuiet()) {
    $cli->notice("Finished Abandoned Cart Email Processing");
}
