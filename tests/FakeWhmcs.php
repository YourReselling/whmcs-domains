<?php

declare(strict_types=1);

namespace WHMCS\Database {
    use WHMCS\Domain\Domain;

    /** Small WHMCS database boundary double; never connects to a customer database. */
    final class Capsule
    {
        public static array $rows = [];

        public static bool $locked = false;

        public static function table(string $name): Query
        {
            return new Query($name);
        }

        public static function connection(): self
        {
            return new self;
        }

        public function statement(string $sql): void {}

        public function select(string $sql, array $bindings): array
        {
            if (str_contains($sql, 'GET_LOCK')) {
                $acquired = ! self::$locked;
                self::$locked = true;

                return [(object) ['acquired' => (int) $acquired]];
            }
            self::$locked = false;

            return [];
        }
    }

    final class Query
    {
        private array $conditions = [];

        private bool $joined = false;

        public function __construct(private readonly string $table) {}

        public function where(string $column, mixed $value): self
        {
            $this->conditions[] = [$column, [$value]];

            return $this;
        }

        public function whereIn(string $column, array $values): self
        {
            $this->conditions[] = [$column, $values];

            return $this;
        }

        public function join(string $table, string $left, string $operator, string $right): self
        {
            \expect($this->table === 'tblinvoiceitems as items' && $table === 'tblinvoices as invoices' && $left === 'invoices.id' && $right === 'items.invoiceid', 'Unexpected invoice relationship');
            $this->joined = true;

            return $this;
        }

        public function first(): ?object
        {
            return $this->all()[0] ?? null;
        }

        public function value(string $column): mixed
        {
            return $this->first()->{$column} ?? null;
        }

        public function exists(): bool
        {
            return $this->first() !== null;
        }

        public function updateOrInsert(array $key, array $values): void
        {
            \expect($this->table === 'mod_yourreselling_domains', 'Unexpected database mutation');
            Capsule::$rows[$this->table][$key['domain_id']] = $key + $values;
        }

        public function chunkById(int $size, \Closure $callback): void
        {
            foreach (array_chunk($this->all(), $size) as $chunk) {
                $callback($chunk);
            }
        }

        private function all(): array
        {
            $source = Capsule::$rows[explode(' ', $this->table)[0]] ?? [];
            $results = [];
            foreach ($source as $row) {
                if ($this->joined) {
                    $invoice = Capsule::$rows['tblinvoices'][$row['invoiceid']] ?? [];
                    $row = array_combine(array_map(static fn (string $key): string => 'items.'.$key, array_keys($row)), array_values($row)) + array_combine(array_map(static fn (string $key): string => 'invoices.'.$key, array_keys($invoice)), array_values($invoice));
                }
                foreach ($this->conditions as [$column, $values]) {
                    if (! in_array($row[$column] ?? null, $values, true)) {
                        continue 2;
                    }
                }
                $results[] = $this->table === 'tbldomains' ? new Domain($row) : (object) $row;
            }

            return $results;
        }
    }
}

namespace WHMCS\Domain {
    use WHMCS\Database\Capsule;
    use WHMCS\Database\Query;

    final class Domain
    {
        public static array $params = [];

        public function __construct(private readonly array $row) {}

        public function __get(string $key): mixed
        {
            return $this->row[$key] ?? null;
        }

        public function __isset(string $key): bool
        {
            return isset($this->row[$key]);
        }

        public static function where(string $column, mixed $value): Query
        {
            return Capsule::table('tbldomains')->where($column, $value);
        }

        public function getRegistrarInterface(): self
        {
            return $this;
        }

        public function call(string $function, array $params): array
        {
            return ('yourresellingdomains_'.$function)($params + self::$params);
        }
    }
}
