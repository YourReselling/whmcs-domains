<?php

declare(strict_types=1);

if (! defined('WHMCS')) {
    exit('Direct access is not permitted.');
}

require_once __DIR__.'/bootstrap.php';

use YourReselling\Whmcs\Domains\Integration\WhmcsRenewalAutomation;

add_hook('InvoicePaidPreEmail', 1, static function (array $vars): void {
    WhmcsRenewalAutomation::paymentContext((int) $vars['invoiceid']);
});
add_hook('InvoicePaid', 1, static function (array $vars): void {
    WhmcsRenewalAutomation::paymentContext(null);
});
add_hook('DailyCronJob', 1, static function (array $vars): void {
    WhmcsRenewalAutomation::daily();
});
add_hook('DomainEdit', 1, static function (array $vars): void {
    WhmcsRenewalAutomation::synchronize((int) $vars['domainid']);
});
