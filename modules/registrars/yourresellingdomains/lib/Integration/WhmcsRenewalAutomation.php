<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

use WHMCS\Database\Capsule;
use WHMCS\Domain\Domain;

/** WHMCS integration only; the renewal state machine is independent of WHMCS. */
final class WhmcsRenewalAutomation
{
    private static ?int $paidInvoiceId = null;

    public static function paymentContext(?int $invoiceId): void
    {
        self::$paidInvoiceId = $invoiceId;
    }

    /** @return array{expiry: ?string, cancel: bool} */
    public static function domain(int $domainId): array
    {
        $row = Capsule::table('tbldomains')->where('id', $domainId)->where('registrar', 'yourresellingdomains')->first();
        if ($row === null) {
            throw new PanelException('Domain ist diesem Registrar nicht zugeordnet.');
        }

        return ['expiry' => empty($row->expirydate) || $row->expirydate === '0000-00-00' ? null : (string) $row->expirydate, 'cancel' => (bool) $row->donotrenew || $row->status === 'Cancelled'];
    }

    public static function operationKey(int $domainId, string $expiry, int $years): string
    {
        if (self::$paidInvoiceId !== null) {
            $paid = Capsule::table('tblinvoiceitems as items')
                ->join('tblinvoices as invoices', 'invoices.id', '=', 'items.invoiceid')
                ->where('items.invoiceid', self::$paidInvoiceId)
                ->where('items.relid', $domainId)
                ->where('items.type', 'Domain')
                ->where('invoices.status', 'Paid')
                ->exists();
            if (! $paid) {
                throw new PanelException('Keine bezahlte Domain-Verlängerung in diesem Rechnungsvorgang.');
            }

            return 'invoice:'.self::$paidInvoiceId;
        }

        return 'manual:'.$expiry.':'.$years;
    }

    /** Daily full scan avoids the 50-domain batch limit of normal domain synchronization. */
    public static function daily(): void
    {
        Domain::where('registrar', 'yourresellingdomains')
            ->whereIn('status', ['Active', 'Pending Registration', 'Pending Transfer', 'Expired', 'Cancelled'])
            ->chunkById(100, static function ($domains): void {
                foreach ($domains as $domain) {
                    self::synchronize((int) $domain->id);
                }
            });
    }

    /** Only domains assigned to this registrar may trigger its private callback. */
    public static function synchronize(int $domainId): void
    {
        $domain = Domain::where('id', $domainId)->where('registrar', 'yourresellingdomains')->first();
        if ($domain === null) {
            return;
        }
        try {
            $result = $domain->getRegistrarInterface()->call('Sync', ['domainid' => $domainId, 'domainname' => (string) $domain->domain]);
            if (! is_array($result) || isset($result['error'])) {
                throw new PanelException(is_array($result) ? (string) $result['error'] : 'Registrar-Synchronisierung nicht verfügbar.');
            }
        } catch (\Throwable $error) {
            logActivity('Your-Reselling: Domain #'.$domainId.' – Verlängerungsabgleich fehlgeschlagen: '.Panel::error($error, true));
        }
    }
}
