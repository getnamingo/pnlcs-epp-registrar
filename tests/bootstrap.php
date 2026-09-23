<?php
/** Minimal PNLCS boundary doubles; no database or live registry is contacted. */
declare(strict_types=1);
namespace App\Contracts {
    interface RegistrarModuleInterface {
        public function register(\App\Models\Domain $domain, int $years, array $params = []): array;
        public function transfer(\App\Models\Domain $domain, string $eppCode): array;
        public function renew(\App\Models\Domain $domain, int $years): array;
        public function getNameservers(\App\Models\Domain $domain): array;
        public function saveNameservers(\App\Models\Domain $domain, array $nameservers): bool;
        public function getEPPCode(\App\Models\Domain $domain): string;
        public function getLockStatus(\App\Models\Domain $domain): bool;
        public function toggleLock(\App\Models\Domain $domain, bool $lock): bool;
        public function checkAvailability(string $domain): array;
        public function getConfigFields(): array;
        public function getModuleName(): string;
    }
    interface SyncsDomainData { public function syncDomain(\App\Models\Domain $domain): array; }
}
namespace App\Models {
    class Client {
        private array $data;
        public function __construct(array $data = []) { $this->data = $data + ['first_name'=>'Ілля', 'last_name'=>'Test', 'company_name'=>'Example', 'address1'=>'1 Test Road', 'city'=>'Kyiv', 'country'=>'UA', 'postcode'=>'01001', 'email'=>'test@example.test', 'phone_prefix'=>'+380', 'full_phone'=>'+380 501234567']; }
        public function __get($key) { return $this->data[$key] ?? null; }
        public function __isset($key) { return isset($this->data[$key]); }
    }
    class Domain {
        public static function normalise($domain) { return strtolower(trim($domain)); }
        private array $data;
        public array $updates = [];
        public function __construct(array $data = []) { $this->data = $data + ['domain'=>'example.test', 'client'=>new Client(), 'registration_period'=>1, 'nameservers'=>'["ns1.example.net","ns2.example.net"]']; }
        public function __get($key) { return $this->data[$key] ?? null; }
        public function __isset($key) { return isset($this->data[$key]); }
        public function update(array $data) { $this->updates[] = $data; $this->data = array_replace($this->data, $data); }
    }
    class RegistrarSettings {
        public static array $values = [];
        public static function whereRaw(...$args) { return new self(); }
        public function get() { return $this; }
        public function mapWithKeys($callback) { return $this; }
        public function all() { return self::$values; }
    }
    class Setting { public static function get($key, $default = null) { return $default; } }
}
namespace App\Support {
    trait MapsClientFields {
        protected function clientCustomFieldOptions(): array { return []; }
        protected function resolveClientField($client, $field, array $auto = []): ?string { return $field ? $client->$field : null; }
    }
}
namespace Illuminate\Support\Facades { class Log { public static function __callStatic($method, $args) {} } }
namespace Carbon {
    class Carbon extends \DateTime {
        public static function parse($date) { return new self($date); }
        public function copy() { return clone $this; }
        public function addYears($n) { return $this->modify("+$n years"); }
        public function toDateString() { return $this->format('Y-m-d'); }
        public function greaterThan($other) { return $this > $other; }
        public function isPast() { return $this < new self('today'); }
    }
}
namespace {
    function now() { return new \Carbon\Carbon(); }
    function base_path($path = '') { return __DIR__.'/../'.$path; }
    function storage_path($path = '') { return sys_get_temp_dir().'/pnlcs-epp-tests/'.$path; }
    function check(bool $condition, string $message): void { if (! $condition) { throw new \RuntimeException($message); } }
    set_error_handler(static function ($severity, $message, $file, $line) { if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); } return false; });
}
