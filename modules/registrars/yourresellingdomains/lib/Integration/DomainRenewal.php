<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains\Integration;

/** Bridges paid WHMCS terms to the API's cancellation-at-expiry model. */
final class DomainRenewal
{
    public function __construct(private readonly DomainGateway $gateway, private readonly StateStore $store) {}

    /**
     * The initial provider term is already purchased. Subsequent terms require RenewDomain.
     * Existing installations adopt their WHMCS expiry, never an unpaid provider extension.
     *
     * @return array<string,mixed>
     */
    public function reconcile(?string $localExpiry, int $noticeDays, \DateTimeImmutable $today, bool $newOrder = false, bool $cancelRequested = false): array
    {
        self::validateNotice($noticeDays);

        return $this->store->locked(function () use ($localExpiry, $noticeDays, $today, $newOrder, $cancelRequested): array {
            $remote = $this->gateway->details();
            if (($remote['status'] ?? '') !== 'active') {
                return $remote;
            }
            $state = $this->initialise($remote, $newOrder ? null : $localExpiry, $newOrder);
            $this->apply($state, $remote, $noticeDays, $today, $cancelRequested);

            // WHMCS uses the purchased term for invoicing; the API rolls over later.
            $remote['expires_at'] = $state['renewal']['paid_until'];

            return $remote;
        });
    }

    /**
     * Persist the entitlement before toggling auto-renew, so a timeout can be retried.
     * A paid invoice ID is the operation key; manual calls use the WHMCS expiry/term.
     */
    public function renew(int $years, string $localExpiry, string $operationKey, int $noticeDays, \DateTimeImmutable $today): void
    {
        self::validateNotice($noticeDays);
        if ($years < 1 || $years > 10 || $operationKey === '') {
            throw new PanelException('Ungültiger Verlängerungszeitraum oder fehlende Vorgangskennung.');
        }
        $this->store->locked(function () use ($years, $localExpiry, $operationKey, $noticeDays, $today): void {
            $remote = $this->gateway->details();
            if (($remote['status'] ?? '') !== 'active' || self::date((string) ($remote['expires_at'] ?? '')) <= $today->setTime(0, 0)) {
                throw new PanelException('Domain ist nicht mehr aktiv oder bereits abgelaufen. Auto-Renew ist keine Wiederherstellung; bitte beim Anbieter klären.');
            }
            $state = $this->initialise($remote, $localExpiry);
            $pending = $state['renewal']['pending'] ?? null;
            if ($pending !== null) {
                $record = $state['renewal']['operations'][$pending];
                if ($record['years'] !== $years || (str_starts_with($operationKey, 'invoice:') && $operationKey !== $pending)) {
                    throw new PanelException('Eine andere Verlängerung ist noch nicht bestätigt. Zuerst diesen Vorgang wiederholen.');
                }
                $operationKey = $pending;
            }
            if (isset($state['renewal']['operations'][$operationKey]) && $state['renewal']['operations'][$operationKey]['years'] !== $years) {
                throw new PanelException('Verlängerungszeitraum stimmt nicht mit dem gespeicherten Vorgang überein.');
            }
            if (! isset($state['renewal']['operations'][$operationKey])) {
                $base = self::date($state['renewal']['paid_until']);
                $target = self::addYears($base, $years)->format('Y-m-d');
                if ($target < self::date((string) $remote['expires_at'])->format('Y-m-d')) {
                    throw new PanelException('Anbieter- und bezahlte Laufzeit widersprechen sich. Vor Verlängerung abgleichen.');
                }
                $state['renewal']['operations'][$operationKey] = ['years' => $years, 'until' => $target];
                $state['renewal']['operations']['manual:'.$localExpiry.':'.$years] = ['years' => $years, 'until' => $target];
                $state['renewal']['paid_until'] = $target;
                $state['renewal']['pending'] = $operationKey;
                $this->store->write($state);
            }
            $this->apply($state, $remote, $noticeDays, $today);
            $state['renewal']['pending'] = null;
            $this->store->write($state);
        });
    }

    /** @param array<string,mixed> $remote @return array<string,mixed> */
    private function initialise(array $remote, ?string $localExpiry, bool $newOrder = false): array
    {
        self::date((string) ($remote['expires_at'] ?? ''));
        $state = $this->store->read();
        if (! isset($state['renewal'])) {
            if (! $newOrder && empty($localExpiry)) {
                throw new PanelException('WHMCS-Ablaufdatum fehlt. Bezahlte Laufzeit vor automatischer Kündigung abgleichen.');
            }
            $state['renewal'] = ['paid_until' => self::date($localExpiry ?? $remote['expires_at'])->format('Y-m-d'), 'operations' => [], 'pending' => null];
            $this->store->write($state);
        }

        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $remote */
    private function apply(array $state, array $remote, int $noticeDays, \DateTimeImmutable $today, bool $cancelRequested = false): void
    {
        $expiry = self::date((string) ($remote['expires_at'] ?? ''));
        $paidUntil = self::date($state['renewal']['paid_until']);
        $enabled = $paidUntil > $expiry || (! $cancelRequested && $today->setTime(0, 0) < $paidUntil->modify('-'.$noticeDays.' days'));
        if (! is_bool($remote['auto_renew'] ?? null)) {
            throw new PanelException('API-Antwort enthält keinen gültigen Auto-Renew-Status.');
        }
        if ($remote['auto_renew'] !== $enabled) {
            $response = $this->gateway->operation('POST', $enabled ? 'enableAutoRenew' : 'disableAutoRenew', []);
            if (($response['auto_renew'] ?? null) !== $enabled) {
                throw new PanelException('Die Kündigungsentscheidung wurde von der API nicht bestätigt.');
            }
        }
    }

    public static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '2000-01-01') {
            throw new PanelException('Ungültiges Ablaufdatum; bezahlte Laufzeit vor einer Änderung abgleichen.');
        }

        return $date;
    }

    private static function validateNotice(int $noticeDays): void
    {
        if ($noticeDays < 7 || $noticeDays > 90) {
            throw new PanelException('Kündigungsvorlauf muss zwischen 7 und 90 Tagen liegen.');
        }
    }

    private static function addYears(\DateTimeImmutable $date, int $years): \DateTimeImmutable
    {
        $target = $date->modify('+'.$years.' years');
        if ($target->format('m-d') !== $date->format('m-d')) {
            $target = $target->modify('last day of previous month');
        }

        return $target;
    }
}
