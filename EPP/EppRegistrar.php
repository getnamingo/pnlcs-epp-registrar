<?php

declare(strict_types=1);

namespace Modules\Registrars\EPP;

use App\Contracts\RegistrarModuleInterface;
use App\Contracts\SyncsDomainData;
use App\Models\Domain;
use App\Models\RegistrarSettings;
use App\Models\Setting;
use App\Support\MapsClientFields;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Pinga\Tembo\EppRegistryFactory;

/**
 * Generic EPP registrar for PNLCS using Namingo's Pinga\Tembo EPP client.
 *
 * Release archives include Tembo in this module's `namingo/vendor/` directory.
 * Source checkouts install dependencies there, without changing PNLCS Composer.
 *
 * Implements every operation currently exposed by PNLCS's registrar contract,
 * authoritative sync, connection testing, and several advanced EPP helpers.
 */
final class EppRegistrar implements RegistrarModuleInterface, SyncsDomainData
{
    use MapsClientFields;

    /** @var array<string, string|null> */
    private array $settings = [];

    private bool $namingoLoaded = false;

    /** Registry profiles currently exposed by Namingo's EppRegistryFactory. */
    private const PROFILES = [
        'generic' => 'Generic RFC EPP',
        'EE' => 'EE (.ee)',
        'EU' => 'EU / EURid (.eu)',
        'FI' => 'FI (.fi)',
        'FR' => 'FR / AFNIC (.fr)',
        'FRED' => 'FRED (CZ.NIC family)',
        'GE' => 'GE (.ge)',
        'GR' => 'GR (.gr)',
        'HK' => 'HK (.hk)',
        'HR' => 'HR (.hr)',
        'IS' => 'IS (.is)',
        'IT' => 'IT (.it)',
        'LT' => 'LT (.lt)',
        'LV' => 'LV (.lv)',
        'MX' => 'MX (.mx)',
        'NO' => 'NO (.no)',
        'PL' => 'PL / NASK (.pl)',
        'PT' => 'PT (.pt)',
        'SE' => 'SE / IIS (.se/.nu)',
        'SWITCH' => 'SWITCH (.ch/.li, generic Namingo backend)',
        'UA' => 'UA (.ua)',
        'VRSN' => 'Verisign / gTLD-style',
    ];

    /** Profiles whose Tembo implementation requires inline hostAttr nameservers. */
    private const HOST_ATTR_PROFILES = ['EU', 'HR', 'LV', 'GE'];

    public function __construct()
    {
        $this->settings = $this->loadSettings();
    }

    public function getModuleName(): string
    {
        // PNLCS uses this value to look up registrar_settings during domain sync.
        return 'epp';
    }

    public function getConfigFields(): array
    {
        $clientFields = $this->clientCustomFieldOptions();
        $fieldOptions = ['' => '— Auto detect —'] + $clientFields;

        return [
            [
                'name' => 'host',
                'label' => 'EPP Hostname',
                'type' => 'text',
                'default' => '',
                'description' => 'Registry EPP endpoint hostname (e.g. epp.registry.tld).',
                'required' => true,
            ],
            [
                'name' => 'port',
                'label' => 'EPP Port',
                'type' => 'text',
                'default' => '700',
                'description' => 'TCP port used by the registry (700 is the standard EPP port, but some registries use a different value).',
                'required' => true,
            ],
            [
                'name' => 'tls_version',
                'label' => 'Prefer TLS 1.3',
                'type' => 'select',
                'default' => '1.2',
                'options' => ['1.2' => 'TLS 1.2', '1.3' => 'TLS 1.3'],
                'description' => 'Select TLS 1.3 when supported by the registry, or TLS 1.2. Existing PNLCS TLS settings are preserved.',
            ],
            [
                'name' => 'verify_peer',
                'label' => 'Verify TLS Certificate',
                'type' => 'yesno',
                'default' => '1',
                'description' => 'Validate the registry server certificate (recommended). Disable only for test environments.',
            ],
            [
                'name' => 'cafile',
                'label' => 'CA Bundle Path',
                'type' => 'text',
                'default' => '',
                'description' => 'Path to a CA bundle file used to verify the registry certificate (required when “Verify TLS Certificate” is enabled).',
            ],
            [
                'name' => 'local_cert',
                'label' => 'Client Certificate (PEM)',
                'type' => 'text',
                'default' => 'cert.pem',
                'description' => 'Path to your registrar client certificate in PEM format.',
            ],
            [
                'name' => 'local_pk',
                'label' => 'Client Private Key',
                'type' => 'text',
                'default' => 'key.pem',
                'description' => 'Path to your private key file (PEM).',
            ],
            [
                'name' => 'passphrase',
                'label' => 'Private Key Passphrase',
                'type' => 'password',
                'default' => '',
                'description' => 'Passphrase for the private key (leave blank if the key is not encrypted).',
            ],
            [
                'name' => 'clid',
                'label' => 'Client ID (clID)',
                'type' => 'text',
                'default' => '',
                'description' => 'Registrar identifier provided by the registry.',
                'required' => true,
            ],
            [
                'name' => 'pw',
                'label' => 'Client Password',
                'type' => 'password',
                'default' => '',
                'description' => 'EPP login password provided by the registry.',
                'required' => true,
            ],
            [
                'name' => 'registrarprefix',
                'label' => 'Object ID Prefix',
                'type' => 'text',
                'default' => '',
                'description' => 'Prefix used when generating registry object IDs (contacts/hosts). Use the value required by the registry, if any.',
            ],
            [
                'name' => 'contact_postal_type',
                'label' => 'Contact Postal Address Type',
                'type' => 'select',
                'options' => ['int' => 'int', 'loc' => 'loc'],
                'default' => 'int',
                'description' => 'EPP postalInfo type used when creating and updating contacts. int = internationalized format; loc = localized format.',
            ],
            [
                'name' => 'registry_profile',
                'label' => 'Registry Profile',
                'type' => 'select',
                'options' => self::PROFILES,
                'default' => 'generic',
                'description' => 'Select the registry profile matching the registry implementation. See the Registry Support list in the README.',
            ],
            [
                'name' => 'ns_mode',
                'label' => 'Nameserver Mode',
                'type' => 'select',
                'options' => ['hostObj' => 'hostObj', 'hostAttr' => 'hostAttr'],
                'default' => 'hostObj',
                'description' => 'hostObj uses EPP host objects (default). hostAttr embeds nameservers directly in domain commands; host create/modify/delete operations are unavailable.',
            ],
            [
                'name' => 'set_authinfo_on_info',
                'label' => 'Set AuthInfo on Request',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Enable if the registry does not return the transfer code on domain info and requires setting it manually first.',
            ],
            [
                'name' => 'login_objects',
                'label' => 'EPP Login Objects',
                'type' => 'textarea',
                'default' => '',
                'description' => 'Comma-separated EPP login object URIs. Leave empty to use defaults. urn:ietf:params:xml:ns:domain-1.0, urn:ietf:params:xml:ns:contact-1.0, urn:ietf:params:xml:ns:host-1.0',
            ],
            [
                'name' => 'login_extensions',
                'label' => 'EPP Login Extensions',
                'type' => 'textarea',
                'default' => '',
                'description' => 'Comma-separated EPP login extension URIs. Leave empty to use defaults. urn:ietf:params:xml:ns:secDNS-1.1, urn:ietf:params:xml:ns:rgp-1.0',
            ],
            [
                'name' => 'gtld',
                'label' => 'gTLD Registry',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Enable this if the registry is a generic TLD (gTLD) operated under ICANN policies.',
            ],
            [
                'name' => 'min_data_set',
                'label' => 'Use Minimum Data Set',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Use the ICANN Minimum Data Set. Applies only when gTLD Registry is enabled.',
            ],
            [
                'name' => 'eurid_billing_contact',
                'label' => 'EURid Billing Contact ID',
                'type' => 'text',
                'default' => '',
                'description' => 'Optional billing contact handle for EURid. Used only when EPP profile is EU.',
            ],
            [
                'name' => 'pl_contact_prefix',
                'label' => 'NASK (.pl) Contact Prefix',
                'type' => 'text',
                'default' => '',
                'description' => 'Optional contact ID prefix for NASK (.pl). Used when EPP profile is PL.',
            ],
            [
                'name' => 'tmch_claims_period_active',
                'label' => 'TMCH Claims Period Active',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Indicates that this TLD is currently in the TMCH Claims Period. When enabled, TMCH Claims Notice checks will be performed. Registration requires an accepted, unexpired notice in params[tmch_claims]; PNLCS has no built-in claims checkout.',
            ],
            [
                'name' => 'enable_fee_extension',
                'label' => 'Enable EPP Fee Extension (Premium Domains)',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Use domainCheckFee to detect and price premium domains',
            ],
            [
                'name' => 'debug_log',
                'label' => 'EPP Debug Logging',
                'type' => 'yesno',
                'default' => '0',
                'description' => 'Write raw EPP requests and responses to the configured log directory. Enable only while troubleshooting, then disable.',
            ],
            [
                'name' => 'debug_log_path',
                'label' => 'EPP Debug Log Path',
                'type' => 'text',
                'default' => storage_path('logs/epp'),
                'description' => 'Absolute directory for raw EPP request and response logs; defaults to PNLCS storage/logs/epp.',
            ],
            // PNLCS field mappings and advanced overrides retained from the draft.
            ['name' => 'timeout', 'label' => 'Connection Timeout (seconds)', 'type' => 'text', 'default' => '30', 'required' => false],
            ['name' => 'verify_peer_name', 'label' => 'Verify TLS Hostname', 'type' => 'yesno', 'default' => '1'],
            ['name' => 'allow_self_signed', 'label' => 'Allow Self-signed Certificates', 'type' => 'yesno', 'default' => '0'],
            ['name' => 'contact_id_prefix', 'label' => 'Contact ID Prefix', 'type' => 'text', 'required' => false],
            ['name' => 'nin_field', 'label' => 'NIN / Personal ID Client Field', 'type' => 'select', 'options' => $fieldOptions, 'required' => false],
            ['name' => 'vat_field', 'label' => 'VAT / Tax ID Client Field', 'type' => 'select', 'options' => $fieldOptions, 'required' => false],
            ['name' => 'nin_type_field', 'label' => 'NIN Type Client Field', 'type' => 'select', 'options' => $fieldOptions, 'required' => false],
            ['name' => 'pt_validated_field', 'label' => 'PT Validation Flag Client Field', 'type' => 'select', 'options' => $fieldOptions, 'required' => false],
            ['name' => 'pt_validated_date_field', 'label' => 'PT Validation Date Client Field', 'type' => 'select', 'options' => $fieldOptions, 'required' => false],
            ['name' => 'allow_premium', 'label' => 'Allow Premium Registrations', 'type' => 'yesno', 'default' => '0'],
            ['name' => 'fee_currency', 'label' => 'Fee Extension Currency', 'type' => 'text', 'default' => 'USD', 'required' => false],
            ['name' => 'domain_create_extra_json', 'label' => 'Extra domain:create Parameters (JSON)', 'type' => 'textarea', 'required' => false],
            ['name' => 'contact_create_extra_json', 'label' => 'Extra contact:create Parameters (JSON)', 'type' => 'textarea', 'required' => false],
        ];
    }

    /** PNLCS currently renders module help, but not per-field descriptions. */
    public function getConfigHelp(): string
    {
        $help = ['Connect PNLCS to any domain registry using the standard EPP protocol.'];
        foreach ($this->getConfigFields() as $field) {
            if (! empty($field['description'])) {
                $help[] = '<strong>'.$field['label'].'</strong>: '.$field['description'];
            }
        }

        return implode('<br><br>', $help);
    }

    public function testConnection(): array
    {
        try {
            return $this->withEpp(function ($epp): array {
                $hello = $epp->hello();
                if (is_array($hello)) {
                    $this->throwOnError($hello, 'EPP hello failed');
                } elseif (! is_string($hello) || ! str_contains($hello, 'greeting')) {
                    throw new \RuntimeException('EPP hello returned no greeting.');
                }

                return ['success' => true, 'message' => 'Connected and authenticated to the EPP server.'];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function register(Domain $domain, int $years, array $params = []): array
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);
            $years = max(1, $years);

            return $this->withEpp(function ($epp) use ($domain, $fqdn, $years, $params): array {
                $check = $epp->domainCheck(['domains' => [$fqdn]]);
                $this->throwOnError($check, 'Domain check failed');

                [$available, $reason] = $this->availabilityFromResponse($check, $fqdn);
                if (! $available) {
                    throw new \RuntimeException($fqdn.' is not available'.($reason !== '' ? ': '.$reason : '.'));
                }

                $claims = $this->claimsNotice($fqdn, $params);
                $premium = $this->premiumCheck($epp, $fqdn, 'create', $years);

                $contacts = [];
                if (! $this->isMinDataSet()) {
                    $contacts = $this->createContacts($epp, $domain, $params);
                }

                $nameservers = $this->nameserversFor($domain, $params);
                $this->ensureHostObjects($epp, $nameservers);

                $payload = [
                    'domainname' => $fqdn,
                    'period' => $years,
                    'nss' => $this->formatNameservers($nameservers),
                    'authInfoPw' => $this->randomPassword(),
                ];

                if (! $this->isMinDataSet()) {
                    $payload['registrant'] = $contacts['registrant'] ?? null;
                    $domainContacts = array_filter([
                        'admin' => $contacts['admin'] ?? null,
                        'tech' => $contacts['tech'] ?? null,
                        'billing' => $contacts['billing'] ?? null,
                    ]);

                    if ($this->profile() === 'EU' && trim((string) ($this->settings['eurid_billing_contact'] ?? '')) !== '') {
                        $domainContacts['billing'] = trim((string) $this->settings['eurid_billing_contact']);
                    }

                    if ($domainContacts !== []) {
                        $payload['contacts'] = $domainContacts;
                    }
                }

                $payload = array_replace_recursive($payload, $this->jsonSetting('domain_create_extra_json'));
                $created = $claims === []
                    ? $epp->domainCreate($payload)
                    : $epp->domainCreateClaims(array_replace($payload, $claims));
                $this->throwOnError($created, 'Domain create failed');

                $expiry = $this->responseDate($created, ['exDate', 'expiryDate', 'expiry_date'])
                    ?? now()->addYears($years)->toDateString();

                $domain->update([
                    'registrar' => 'epp',
                    'status' => 'active',
                    'registration_period' => $years,
                    'registration_date' => now()->toDateString(),
                    'expiry_date' => $expiry,
                    'next_due_date' => $expiry,
                    'nameservers' => json_encode($nameservers),
                ]);

                return [
                    'success' => true,
                    'message' => 'Domain registered through EPP.',
                    'domain' => $fqdn,
                    'expiry_date' => $expiry,
                    'premium' => $premium,
                ];
            });
        } catch (\Throwable $e) {
            $this->logFailure('register', $domain->domain, $e);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function transfer(Domain $domain, string $eppCode): array
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);
            $years = max(1, (int) ($domain->registration_period ?: 1));

            return $this->withEpp(function ($epp) use ($domain, $fqdn, $years, $eppCode): array {
                $payload = [
                    'domainname' => $fqdn,
                    'years' => $years,
                    'authInfoPw' => $eppCode,
                    'op' => 'request',
                ];

                // AFNIC transfer requests need the existing admin/tech handles.
                if ($this->profile() === 'FR') {
                    $info = $this->domainInfo($epp, $fqdn);
                    $this->throwOnError($info, 'Domain info failed before transfer');
                    $roleIds = $this->contactRolesFromDomainInfo($info);
                    $payload['admin'] = $roleIds['admin'] ?? null;
                    $payload['tech'] = $roleIds['tech'] ?? null;
                }

                $response = $epp->domainTransfer($payload);
                $this->throwOnError($response, 'Transfer request failed');

                $domain->update(['registrar' => 'epp', 'status' => 'pending']);

                return ['success' => true, 'message' => 'Domain transfer requested through EPP.'];
            });
        } catch (\Throwable $e) {
            $this->logFailure('transfer', $domain->domain, $e);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function renew(Domain $domain, int $years): array
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);
            $years = max(1, $years);

            return $this->withEpp(function ($epp) use ($domain, $fqdn, $years): array {
                // Namingo's WHMCS module treats LV as an auto-renewing profile and
                // intentionally does not issue domain:renew. Preserve that model,
                // but refresh registry data before moving PNLCS's billing date.
                if ($this->profile() === 'LV') {
                    $info = $this->domainInfo($epp, $fqdn);
                    $this->throwOnError($info, 'LV domain info failed');
                    $authoritative = $this->responseDate($info, ['exDate', 'expiryDate', 'expiry_date']);
                    $local = $domain->expiry_date?->copy();

                    if ($authoritative !== null && ($local === null || Carbon::parse($authoritative)->greaterThan($local))) {
                        // Registry auto-renew has already happened. Believe it
                        // instead of adding the paid period a second time.
                        $newExpiry = Carbon::parse($authoritative)->toDateString();
                    } else {
                        $newExpiry = ($local ?? now())->addYears($years)->toDateString();
                    }
                } else {
                    $this->premiumCheck($epp, $fqdn, 'renew', $years);
                    $response = $epp->domainRenew([
                        'domainname' => $fqdn,
                        'regperiod' => $years,
                    ]);
                    $this->throwOnError($response, 'Domain renewal failed');

                    $newExpiry = $this->responseDate($response, ['exDate', 'expiryDate', 'expiry_date']);
                    if (! $newExpiry) {
                        $base = $domain->expiry_date?->copy() ?? now();
                        $newExpiry = $base->addYears($years)->toDateString();
                    }
                }

                $domain->update([
                    'expiry_date' => $newExpiry,
                    'next_due_date' => $newExpiry,
                    'status' => 'active',
                ]);

                return [
                    'success' => true,
                    'message' => "Domain renewed through EPP for {$years} year(s).",
                    'expiry_date' => $newExpiry,
                ];
            });
        } catch (\Throwable $e) {
            $this->logFailure('renew', $domain->domain, $e);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getNameservers(Domain $domain): array
    {
        try {
            return $this->withEpp(function ($epp) use ($domain): array {
                $info = $this->domainInfo($epp, $this->asciiDomain($domain->domain));
                $this->throwOnError($info, 'Domain info failed');

                return $this->nameserversFromInfo($info);
            });
        } catch (\Throwable $e) {
            $this->logFailure('getNameservers', $domain->domain, $e, 'warning');

            $fallback = json_decode((string) ($domain->nameservers ?? '[]'), true);

            return is_array($fallback) ? array_values(array_filter($fallback)) : [];
        }
    }

    public function saveNameservers(Domain $domain, array $nameservers): bool
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);
            $nameservers = $this->normalizeNameservers($nameservers);

            return $this->withEpp(function ($epp) use ($domain, $fqdn, $nameservers): bool {
                $this->ensureHostObjects($epp, $nameservers);

                if ($this->usesHostAttributes()) {
                    $payload = [
                        'domainname' => $fqdn,
                        'nss' => $this->formatNameservers($nameservers),
                    ];
                } else {
                    $payload = ['domainname' => $fqdn];
                    foreach ($nameservers as $i => $name) {
                        $payload['ns'.($i + 1)] = $name;
                    }
                }

                $response = $this->needsHostAttrAdapter()
                    ? $this->updateHostAttributes($epp, $fqdn, $nameservers)
                    : $epp->domainUpdateNS($payload);
                $this->throwOnError($response, 'Nameserver update failed');

                // DomainService also persists this after true is returned. This
                // direct update keeps callers outside DomainService consistent.
                $domain->update(['nameservers' => json_encode($nameservers)]);

                return true;
            });
        } catch (\Throwable $e) {
            $this->logFailure('saveNameservers', $domain->domain, $e);

            return false;
        }
    }

    public function getEPPCode(Domain $domain): string
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);

            return $this->withEpp(function ($epp) use ($fqdn): string {
                if ($this->boolSetting('set_authinfo_on_info')) {
                    $newCode = $this->randomPassword();
                    $response = $epp->domainUpdateAuthinfo([
                        'domainname' => $fqdn,
                        'authInfo' => $newCode,
                    ]);
                    $this->throwOnError($response, 'Could not set a new AuthInfo code');

                    return $newCode;
                }

                $info = $this->domainInfo($epp, $fqdn);
                $this->throwOnError($info, 'Domain info failed');
                foreach (['authInfo', 'authInfoPw', 'authinfo', 'pw'] as $key) {
                    if (isset($info[$key]) && is_scalar($info[$key]) && trim((string) $info[$key]) !== '') {
                        return (string) $info[$key];
                    }
                }

                return '';
            });
        } catch (\Throwable $e) {
            $this->logFailure('getEPPCode', $domain->domain, $e, 'warning');

            return '';
        }
    }

    public function getLockStatus(Domain $domain): bool
    {
        try {
            return $this->withEpp(function ($epp) use ($domain): bool {
                $info = $this->domainInfo($epp, $this->asciiDomain($domain->domain));
                $this->throwOnError($info, 'Domain info failed');
                $statuses = $this->statuses($info);

                return in_array('clientTransferProhibited', $statuses, true)
                    || in_array('serverTransferProhibited', $statuses, true);
            });
        } catch (\Throwable $e) {
            $this->logFailure('getLockStatus', $domain->domain, $e, 'warning');

            return true; // safe failure mode
        }
    }

    public function toggleLock(Domain $domain, bool $lock): bool
    {
        try {
            $fqdn = $this->asciiDomain($domain->domain);

            return $this->withEpp(function ($epp) use ($fqdn, $lock): bool {
                $info = $this->domainInfo($epp, $fqdn);
                $this->throwOnError($info, 'Domain info failed');
                $statuses = $this->statuses($info);

                if (! $lock && in_array('serverTransferProhibited', $statuses, true)) {
                    throw new \RuntimeException('The registry has a serverTransferProhibited lock; the registrar cannot remove it.');
                }

                $hasClientLock = in_array('clientTransferProhibited', $statuses, true);
                if ($hasClientLock === $lock) {
                    return true;
                }

                $response = $epp->domainUpdateStatus([
                    'domainname' => $fqdn,
                    'command' => $lock ? 'add' : 'rem',
                    'status' => 'clientTransferProhibited',
                ]);
                $this->throwOnError($response, 'Registrar lock update failed');

                return true;
            });
        } catch (\Throwable $e) {
            $this->logFailure('toggleLock', $domain->domain, $e);

            return false;
        }
    }

    public function checkAvailability(string $domain): array
    {
        try {
            $fqdn = $this->asciiDomain($domain);

            return $this->withEpp(function ($epp) use ($fqdn): array {
                $response = $epp->domainCheck(['domains' => [$fqdn]]);
                $this->throwOnError($response, 'Domain check failed');
                [$available, $reason] = $this->availabilityFromResponse($response, $fqdn);

                $premium = $available ? $this->premiumCheck($epp, $fqdn, 'create', 1, false) : ['checked' => false, 'premium' => false];
                $claims = null;
                if ($available && $this->boolSetting('tmch_claims_period_active')) {
                    $claims = $epp->domainCheckClaims(['domainname' => $fqdn]);
                    $this->throwOnError($claims, 'TMCH claims check failed');
                    $claims = array_map(static fn ($value) => $value instanceof \SimpleXMLElement ? (string) $value : $value, $claims);
                }

                return [
                    'available' => $available && (! $premium['premium'] || $this->boolSetting('allow_premium')),
                    'domain' => $fqdn,
                    'method' => 'epp',
                    'reason' => $premium['premium'] && ! $this->boolSetting('allow_premium')
                        ? 'Premium domains are not enabled.' : $reason,
                    'premium' => $premium,
                    'claims' => $claims,
                ];
            });
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'domain' => $domain,
                'method' => 'epp',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function syncDomain(Domain $domain): array
    {
        try {
            return $this->withEpp(function ($epp) use ($domain): array {
                $info = $this->domainInfo($epp, $this->asciiDomain($domain->domain));
                $this->throwOnError($info, 'Domain info failed');

                $expiry = $this->responseDate($info, ['exDate', 'expiryDate', 'expiry_date']);
                $statuses = $this->statuses($info);

                return [
                    'success' => true,
                    'expiry_date' => $expiry,
                    'status' => $this->mapDomainStatus($statuses, $expiry),
                    'locked' => in_array('clientTransferProhibited', $statuses, true)
                        || in_array('serverTransferProhibited', $statuses, true),
                    'nameservers' => $this->nameserversFromInfo($info),
                ];
            });
        } catch (\Throwable $e) {
            $this->logFailure('syncDomain', $domain->domain, $e, 'warning');

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Advanced helpers. PNLCS does not currently expose these in its base
    // registrar interface, but they are useful to admin tooling/API extensions.
    // ---------------------------------------------------------------------

    /** Registry contacts, keyed by registrant/admin/tech/billing. */
    public function getContactDetails(Domain $domain): array
    {
        if ($this->isMinDataSet()) {
            return ['success' => false, 'message' => 'Minimum Data Set contacts are held locally. Use the PNLCS client record.'];
        }

        try {
            return $this->withEpp(function ($epp) use ($domain): array {
                $info = $this->domainInfo($epp, $this->asciiDomain($domain->domain));
                $this->throwOnError($info, 'Domain info failed');
                $contacts = $cache = [];
                foreach ($this->contactRolesFromDomainInfo($info) as $role => $id) {
                    if (! isset($cache[$id])) {
                        $response = $epp->contactInfo(['contact' => $id]);
                        $this->throwOnError($response, 'Contact info failed for '.$id);
                        $cache[$id] = $this->contactDetailsFromInfo($response);
                        $cache[$id]['id'] = $id;
                    }
                    $contacts[$role] = $cache[$id];
                }

                return ['success' => true, 'contacts' => $contacts];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update only supplied roles and fields; never accept a caller-provided ID.
     * Accepts Tembo field names or WHMCS contactdetails labels.
     */
    public function saveContactDetails(Domain $domain, array $contacts): array
    {
        if ($this->isMinDataSet()) {
            return ['success' => false, 'message' => 'Minimum Data Set contacts are held locally. Update the PNLCS client record.'];
        }

        try {
            return $this->withEpp(function ($epp) use ($domain, $contacts): array {
                $info = $this->domainInfo($epp, $this->asciiDomain($domain->domain));
                $this->throwOnError($info, 'Domain info failed');
                $roles = $this->contactRolesFromDomainInfo($info);
                $updates = $cache = [];
                $contacts = $contacts['contactdetails'] ?? $contacts;
                if ($contacts === []) {
                    throw new \RuntimeException('No contact changes were supplied.');
                }
                foreach ($contacts as $role => $fields) {
                    $role = match (strtolower((string) $role)) {
                        'registrant' => 'registrant',
                        'admin', 'administrator' => 'admin',
                        'tech', 'technical' => 'tech',
                        'billing' => 'billing',
                        default => throw new \RuntimeException('Unknown contact role: '.$role),
                    };
                    if (! is_array($fields) || ! isset($roles[$role])) {
                        throw new \RuntimeException('No registry contact or valid fields for '.$role.'.');
                    }
                    $id = $roles[$role];
                    if (! isset($cache[$id])) {
                        $response = $epp->contactInfo(['contact' => $id]);
                        $this->throwOnError($response, 'Contact info failed for '.$id);
                        $cache[$id] = $this->contactDetailsFromInfo($response);
                        if ($this->profile() === 'PT') {
                            $cache[$id]['validated'] = (string) ($response['validated'] ?? 'false');
                            $cache[$id]['validatedDate'] = (string) ($response['validatedDate'] ?? '');
                        }
                    }
                    $payload = array_replace($cache[$id], $this->normalizeContactFields($fields));
                    $payload['id'] = $id;
                    $payload['type'] = $this->contactPostalType();
                    if ($this->profile() === 'PT') {
                        $payload['validated'] = $this->toEppBooleanString((string) ($payload['validated'] ?? 'false'));
                        $payload['validatedDate'] = ! empty($payload['validatedDate']) ? $this->isoDateTime($payload['validatedDate']) : '';
                    }
                    $payload['country'] = strtoupper($payload['country']);
                    $payload['fullphonenumber'] = $this->normalizePhone($payload['fullphonenumber']);
                    if (isset($updates[$id]) && $updates[$id] !== $payload) {
                        throw new \RuntimeException('Conflicting changes for shared registry contact '.$id.'.');
                    }
                    $updates[$id] = $payload;
                }
                foreach ($updates as $id => $payload) {
                    $response = $epp->contactUpdate($payload);
                    $this->throwOnError($response, 'Contact update failed for '.$id);
                }

                return ['success' => true, 'message' => 'Domain contacts updated through EPP.'];
            });
        } catch (\Throwable $e) {
            $this->logFailure('saveContactDetails', $domain->domain, $e);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function registerNameserver(string $hostname, string $ipAddress): array
    {
        return $this->hostCommand('hostCreate', ['hostname' => $hostname, 'ipaddress' => $ipAddress]);
    }

    public function modifyNameserver(string $hostname, string $currentIpAddress, string $newIpAddress): array
    {
        return $this->hostCommand('hostUpdate', [
            'hostname' => $hostname,
            'currentipaddress' => $currentIpAddress,
            'newipaddress' => $newIpAddress,
        ]);
    }

    public function deleteNameserver(string $hostname): array
    {
        return $this->hostCommand('hostDelete', ['hostname' => $hostname]);
    }

    private function hostCommand(string $method, array $payload): array
    {
        if ($this->usesHostAttributes()) {
            return ['success' => false, 'message' => 'Host object operations are unavailable when Nameserver Mode is hostAttr. Change the domain nameservers instead.'];
        }
        try {
            $payload['hostname'] = $this->asciiHostname($payload['hostname']);
            foreach (['ipaddress', 'currentipaddress', 'newipaddress'] as $key) {
                if (isset($payload[$key]) && ! filter_var($payload[$key], FILTER_VALIDATE_IP)) {
                    throw new \RuntimeException('Invalid nameserver IP address.');
                }
            }

            return $this->withEpp(function ($epp) use ($method, $payload): array {
                $response = $epp->{$method}($payload);
                $this->throwOnError($response, 'Nameserver operation failed');

                return ['success' => true, 'response' => $response];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function contactDetailsFromInfo(array $info): array
    {
        [$first, $last] = array_pad(preg_split('/\s+/', trim((string) ($info['name'] ?? '')), 2), 2, '');

        return [
            'firstname' => $first, 'lastname' => $last,
            'companyname' => (string) ($info['org'] ?? ''),
            'address1' => (string) ($info['street1'] ?? ''),
            'address2' => (string) ($info['street2'] ?? ''),
            'address3' => (string) ($info['street3'] ?? ''),
            'city' => (string) ($info['city'] ?? ''),
            'state' => (string) ($info['state'] ?? ''),
            'postcode' => (string) ($info['postal'] ?? ''),
            'country' => (string) ($info['country'] ?? ''),
            'fullphonenumber' => (string) ($info['voice'] ?? ''),
            'email' => (string) ($info['email'] ?? ''),
        ];
    }

    private function normalizeContactFields(array $fields): array
    {
        $aliases = [
            'Organization' => 'companyname', 'Organisation Name' => 'companyname',
            'Street 1' => 'address1', 'Address 1' => 'address1',
            'Street 2' => 'address2', 'Address 2' => 'address2',
            'Street 3' => 'address3', 'Address 3' => 'address3',
            'City' => 'city', 'State or Province' => 'state', 'State' => 'state',
            'Postal Code' => 'postcode', 'Postcode' => 'postcode',
            'Country Code' => 'country', 'Country' => 'country',
            'Phone' => 'fullphonenumber', 'Email' => 'email',
        ];
        $result = [];
        $allowed = array_keys($this->contactDetailsFromInfo([]));
        foreach ($fields as $key => $value) {
            $key = $aliases[$key] ?? $key;
            if (in_array($key, $allowed, true) || in_array($key, ['validated', 'validatedDate'], true)) {
                if (! is_scalar($value)) {
                    throw new \RuntimeException('Invalid contact field: '.$key);
                }
                $result[$key] = (string) $value;
            }
        }
        if (isset($fields['Name']) || isset($fields['Full Name'])) {
            [$result['firstname'], $result['lastname']] = array_pad(
                preg_split('/\s+/', trim((string) ($fields['Name'] ?? $fields['Full Name'])), 2), 2, ''
            );
        }

        return $result;
    }

    public function transferStatus(Domain $domain): array
    {
        return $this->transferOperation($domain, 'query');
    }

    public function approveTransfer(Domain $domain): array
    {
        return $this->transferOperation($domain, 'approve');
    }

    public function rejectTransfer(Domain $domain): array
    {
        return $this->transferOperation($domain, 'reject');
    }

    public function cancelTransfer(Domain $domain): array
    {
        return $this->transferOperation($domain, 'cancel');
    }

    public function deleteDomain(Domain $domain): array
    {
        return $this->simpleDomainCommand($domain, 'domainDelete', ['domainname' => $this->asciiDomain($domain->domain)]);
    }

    public function restoreDomain(Domain $domain): array
    {
        return $this->simpleDomainCommand($domain, 'domainRestore', ['domainname' => $this->asciiDomain($domain->domain)]);
    }

    public function setHold(Domain $domain, bool $hold): array
    {
        return $this->simpleDomainCommand($domain, 'domainUpdateStatus', [
            'domainname' => $this->asciiDomain($domain->domain),
            'command' => $hold ? 'add' : 'rem',
            'status' => 'clientHold',
        ]);
    }

    /** Pass a Namingo-compatible secDNS payload through to domainUpdateDNSSEC. */
    public function updateDnssec(Domain $domain, array $params): array
    {
        $params['domainname'] = $this->asciiDomain($domain->domain);

        return $this->simpleDomainCommand($domain, 'domainUpdateDNSSEC', $params);
    }

    public function poll(): array
    {
        try {
            return $this->withEpp(function ($epp): array {
                $response = $epp->pollReq();
                $this->throwOnError($response, 'EPP poll failed');

                return ['success' => true, 'response' => $response];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function acknowledgePoll(string|int $messageId): array
    {
        try {
            return $this->withEpp(function ($epp) use ($messageId): array {
                $response = $epp->pollAck(['msgID' => (string) $messageId]);
                $this->throwOnError($response, 'EPP poll acknowledgement failed');

                return ['success' => true, 'response' => $response];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Namingo connection plumbing
    // ---------------------------------------------------------------------

    private function withEpp(callable $callback): mixed
    {
        $epp = $this->openClient();

        try {
            return $callback($epp);
        } finally {
            try {
                $epp->logout([]);
            } catch (\Throwable) {
                try {
                    $epp->logout();
                } catch (\Throwable) {
                    // Connection may already be gone.
                }
            }

            try {
                $epp->disconnect();
            } catch (\Throwable) {
                // Same: never mask the original command result.
            }
        }
    }

    private function openClient(): object
    {
        $this->ensureNamingoLoaded();

        $profile = $this->profile();
        $epp = EppRegistryFactory::create($profile);

        if ($this->boolSetting('debug_log')) {
            $logPath = trim((string) ($this->settings['debug_log_path'] ?? ''));
            if ($logPath === '') {
                $logPath = storage_path('logs/epp');
            }
            if (method_exists($epp, 'setLogPath')) {
                $epp->setLogPath($logPath);
            }
        } elseif (method_exists($epp, 'disableLogging')) {
            $epp->disableLogging();
        }

        if ($profile === 'generic') {
            $objects = $this->uriList(
                $this->settings['login_objects'] ?? null,
                [
                    'urn:ietf:params:xml:ns:domain-1.0',
                    'urn:ietf:params:xml:ns:contact-1.0',
                    'urn:ietf:params:xml:ns:host-1.0',
                ]
            );
            $extensions = $this->uriList(
                $this->settings['login_extensions'] ?? null,
                [
                    'urn:ietf:params:xml:ns:secDNS-1.1',
                    'urn:ietf:params:xml:ns:rgp-1.0',
                ]
            );

            if (method_exists($epp, 'setLoginObjects')) {
                $epp->setLoginObjects($objects);
            }
            if (method_exists($epp, 'setLoginExtensions')) {
                $epp->setLoginExtensions($extensions);
            }
        }

        $host = trim((string) ($this->settings['host'] ?? ''));
        $port = (int) ($this->settings['port'] ?? 700);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \RuntimeException('EPP host/port is not configured correctly.');
        }

        $cert = $this->resolveReadablePath((string) ($this->settings['local_cert'] ?? ''), false);
        $key = $this->resolveReadablePath((string) ($this->settings['local_pk'] ?? ''), false);
        $ca = $this->resolveReadablePath((string) ($this->settings['cafile'] ?? ''), false);

        if (($cert === null) xor ($key === null)) {
            throw new \RuntimeException('Configure both the EPP client certificate and private key, or leave both blank.');
        }

        $connection = [
            'host' => $host,
            'port' => $port,
            'timeout' => max(1, (int) ($this->settings['timeout'] ?? 30)),
            'tls' => ($this->settings['tls_version'] ?? '1.2') === '1.3' || $this->boolSetting('tls_version') ? '1.3' : '1.2',
            'bind' => false,
            'bindip' => '0.0.0.0:0',
            'verify_peer' => $this->boolSetting('verify_peer', true),
            'verify_peer_name' => $this->boolSetting('verify_peer_name', true),
            'allow_self_signed' => $this->boolSetting('allow_self_signed', false),
            'cafile' => $ca ?? '',
            'local_cert' => $cert ?? '',
            'local_pk' => $key ?? '',
            'passphrase' => (string) ($this->settings['passphrase'] ?? ''),
        ];

        try {
            $epp->connect($connection);

            $login = $epp->login([
                'clID' => (string) ($this->settings['clid'] ?? ''),
                'pw' => (string) ($this->settings['pw'] ?? ''),
                'prefix' => trim((string) ($this->settings['registrarprefix'] ?? 'pnlcs')) ?: 'pnlcs',
            ]);
            $this->throwOnError($login, 'EPP login failed');
        } catch (\Throwable $e) {
            try {
                $epp->disconnect();
            } catch (\Throwable) {
                // Preserve the connection/login failure.
            }
            throw $e;
        }

        return $epp;
    }

    private function ensureNamingoLoaded(): void
    {
        if ($this->namingoLoaded) {
            return;
        }

        $autoload = __DIR__.'/namingo/vendor/autoload.php';
        if (! is_file($autoload)) {
            throw new \RuntimeException(
                'Module-local Tembo is missing. Install a release archive or run '
                .'composer install --no-dev in modules/Registrars/EPP/namingo.'
            );
        }

        require_once $autoload;
        if (! class_exists(EppRegistryFactory::class)) {
            throw new \RuntimeException('The module-local Tembo installation is incomplete. Reinstall the module dependencies.');
        }
        $this->namingoLoaded = true;
    }

    // ---------------------------------------------------------------------
    // EPP command helpers
    // ---------------------------------------------------------------------

    private function createContacts(object $epp, Domain $domain, array $params): array
    {
        $client = $domain->client;
        if (! $client) {
            throw new \RuntimeException('The domain has no client, so EPP contacts cannot be created.');
        }

        $roles = match ($this->profile()) {
            'EU', 'SWITCH' => ['registrant', 'tech'],
            'PL', 'GE' => ['registrant'],
            default => ['registrant', 'admin', 'tech', 'billing'],
        };

        $nin = $this->resolveClientField(
            $client,
            $this->nullableSetting('nin_field'),
            ['nin', 'personal_id', 'national_id', 'pesel']
        );
        $vat = $this->resolveClientField(
            $client,
            $this->nullableSetting('vat_field'),
            ['vat', 'vat_id', 'tax_id']
        ) ?? ($client->tax_id ?: null);
        $ninType = $this->resolveClientField(
            $client,
            $this->nullableSetting('nin_type_field'),
            ['nin_type']
        );

        $nin = $params['additionalfields']['NIN'] ?? $nin;
        $vat = $params['additionalfields']['VAT'] ?? $vat;
        $ninType = $params['additionalfields']['NIN Type'] ?? $ninType;

        $created = [];
        foreach ($roles as $role) {
            $id = $this->contactId();
            $payload = [
                'id' => $id,
                'type' => $this->contactPostalType(),
                'firstname' => (string) ($params['firstname'] ?? $client->first_name ?? ''),
                'lastname' => (string) ($params['lastname'] ?? $client->last_name ?? ''),
                'companyname' => (string) ($params['companyname'] ?? $client->company_name ?? ''),
                'address1' => (string) ($params['address1'] ?? $params['address'] ?? $client->address1 ?? ''),
                'address2' => (string) ($params['address2'] ?? $client->address2 ?? ''),
                'address3' => (string) ($params['address3'] ?? ''),
                'city' => (string) ($params['city'] ?? $client->city ?? ''),
                'state' => (string) ($params['state'] ?? $client->state ?? ''),
                'postcode' => (string) ($params['postcode'] ?? $client->postcode ?? ''),
                'country' => strtoupper((string) ($params['countrycode'] ?? $params['country'] ?? $client->country ?? '')),
                'fullphonenumber' => $this->normalizePhone((string) ($params['fullphonenumber'] ?? $params['phone'] ?? $client->full_phone ?? ''), (string) ($client->phone_prefix ?? '')),
                'email' => (string) ($params['email'] ?? $client->email ?? ''),
                'authInfoPw' => $this->randomPassword(),
            ];

            switch ($this->profile()) {
                case 'EU':
                    $payload['euType'] = $role;
                    break;
                case 'SE':
                    $payload['orgno'] = $nin;
                    $payload['vatno'] = $vat;
                    break;
                case 'LV':
                    $payload['regNr'] = $nin;
                    $payload['vatNr'] = $vat;
                    break;
                case 'HR':
                    $payload['nin'] = $nin;
                    $payload['nin_type'] = $ninType;
                    break;
                case 'PT':
                    $payload['vat'] = $vat;
                    $validated = $this->resolveClientField(
                        $client,
                        $this->nullableSetting('pt_validated_field'),
                        ['pt_validated', 'validated']
                    );
                    $validatedDate = $this->resolveClientField(
                        $client,
                        $this->nullableSetting('pt_validated_date_field'),
                        ['pt_validated_date', 'validated_date']
                    );
                    $payload['validated'] = 'false';
                    $payload['validatedDate'] = '';
                    if ($validated !== null) {
                        $payload['validated'] = $this->toEppBooleanString($validated);
                    }
                    if ($validatedDate !== null) {
                        $payload['validatedDate'] = $this->isoDateTime($validatedDate);
                    }
                    break;
                case 'GE':
                    $payload['nin'] = $nin;
                    break;
            }

            $payload = array_replace_recursive($payload, $this->jsonSetting('contact_create_extra_json'));
            $payload['type'] = $this->contactPostalType();
            // Null extras can change XML semantics in registry-specific classes.
            $payload = array_filter($payload, static fn ($value) => $value !== null);

            $response = $epp->contactCreate($payload);
            $this->throwOnError($response, "EPP contact:create failed for {$role}");
            $created[$role] = (string) ($response['id'] ?? $id);
        }

        return $created;
    }

    private function claimsNotice(string $domain, array $params): array
    {
        if (! $this->boolSetting('tmch_claims_period_active')) {
            return [];
        }
        $notice = $params['tmch_claims'] ?? null;
        if (! is_array($notice)
            || strtolower((string) ($notice['domain'] ?? '')) !== $domain
            || ! filter_var($notice['accepted'] ?? false, FILTER_VALIDATE_BOOL)
            || trim((string) ($notice['noticeID'] ?? '')) === '') {
            throw new \RuntimeException('TMCH Claims Notice is missing or unaccepted for domain: '.$domain);
        }
        $notAfter = strtotime((string) ($notice['notAfter'] ?? ''));
        $acceptedDate = strtotime((string) ($notice['acceptedDate'] ?? ''));
        if ($notAfter === false || $notAfter <= time() || $acceptedDate === false
            || $acceptedDate > time() || $acceptedDate >= $notAfter) {
            throw new \RuntimeException('TMCH Claims Notice is missing or expired for domain: '.$domain);
        }

        return [
            'noticeID' => (string) $notice['noticeID'],
            'notAfter' => gmdate('Y-m-d\TH:i:s\Z', $notAfter),
            'acceptedDate' => gmdate('Y-m-d\TH:i:s\Z', $acceptedDate),
        ];
    }

    private function premiumCheck(object $epp, string $domain, string $command, int $years, bool $enforce = true): array
    {
        if (! $this->boolSetting('gtld') || ! $this->boolSetting('enable_fee_extension')) {
            return ['checked' => false, 'premium' => false];
        }

        try {
            $response = $epp->domainCheckFee([
                'domainname' => $domain,
                'currency' => strtoupper(trim((string) ($this->settings['fee_currency'] ?? 'USD'))) ?: 'USD',
                'command' => $command,
                'years' => $years,
            ]);
            $this->throwOnError($response, 'EPP fee check failed');

            $feeClass = strtolower(trim((string) ($response['feeClass'] ?? '')));
            if (isset($response['domains']) && is_array($response['domains'])) {
                $first = reset($response['domains']);
                if (is_array($first) && isset($first['feeClass'])) {
                    $feeClass = strtolower(trim((string) $first['feeClass']));
                }
            }

            $premium = $feeClass === 'premium';
            if ($enforce && $premium && ! $this->boolSetting('allow_premium')) {
                throw new \RuntimeException('Premium domain detected, but premium registrations/renewals are disabled.');
            }

            return ['checked' => true, 'premium' => $premium, 'response' => $response];
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'premium domain detected')) {
                throw $e;
            }

            // Fee extensions are not universally implemented. If explicitly
            // enabled and the server rejects it, fail rather than silently lose
            // a premium price signal.
            throw new \RuntimeException('EPP fee extension check failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function needsHostAttrAdapter(): bool
    {
        return $this->usesHostAttributes() && ! in_array($this->profile(), self::HOST_ATTR_PROFILES, true);
    }

    private function domainInfo(object $epp, string $domain): array
    {
        if (! $this->needsHostAttrAdapter()) {
            return $epp->domainInfo(['domainname' => $domain]);
        }
        $name = htmlspecialchars($domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $response = $epp->rawXml(['xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            .'<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><info>'
            .'<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            .'<domain:name hosts="all">'.$name.'</domain:name></domain:info></info></command></epp>']);
        $this->throwOnError($response, 'Domain info failed');
        $xml = simplexml_load_string((string) ($response['xml'] ?? ''), \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new \RuntimeException('Invalid domain info XML.');
        }
        $xml->registerXPathNamespace('d', 'urn:ietf:params:xml:ns:domain-1.0');
        $data = ($xml->xpath('//d:infData') ?: [])[0] ?? null;
        if ($data === null) {
            throw new \RuntimeException('Domain info response has no infData.');
        }
        $data = $data->children('urn:ietf:params:xml:ns:domain-1.0');
        $info = ['code' => $response['code'], 'ns' => [], 'status' => [], 'contact' => []];
        foreach (['name', 'registrant', 'clID', 'crID', 'crDate', 'upDate', 'exDate', 'trDate'] as $key) {
            $info[$key] = (string) $data->{$key};
        }
        $info['authInfo'] = (string) $data->authInfo->pw;
        foreach ($data->ns->hostAttr ?? [] as $ns) {
            $info['ns'][] = (string) $ns->hostName;
        }
        foreach ($data->status as $status) {
            $info['status'][] = (string) $status->attributes()->s;
        }
        foreach ($data->contact as $contact) {
            $info['contact'][] = ['type' => (string) $contact->attributes()->type, 'id' => (string) $contact];
        }

        return $info;
    }

    private function updateHostAttributes(object $epp, string $domain, array $nameservers): array
    {
        $current = $this->nameserversFromInfo($this->domainInfo($epp, $domain));
        $add = array_values(array_diff($nameservers, $current));
        $remove = array_values(array_diff($current, $nameservers));
        if ($add === [] && $remove === []) {
            return ['code' => 1000];
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><update>'
            .'<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            .'<domain:name>'.$escape($domain).'</domain:name>';
        foreach (['add' => $add, 'rem' => $remove] as $operation => $hosts) {
            if ($hosts === []) {
                continue;
            }
            $xml .= '<domain:'.$operation.'><domain:ns>';
            foreach ($this->formatNameservers($hosts) as $host) {
                $xml .= '<domain:hostAttr><domain:hostName>'.$escape($host['hostName']).'</domain:hostName>';
                if ($operation === 'add') {
                    foreach (['ipv4' => 'v4', 'ipv6' => 'v6'] as $key => $family) {
                        if (! empty($host[$key])) {
                            $xml .= '<domain:hostAddr ip="'.$family.'">'.$escape($host[$key]).'</domain:hostAddr>';
                        }
                    }
                }
                $xml .= '</domain:hostAttr>';
            }
            $xml .= '</domain:ns></domain:'.$operation.'>';
        }
        $xml .= '</domain:update></update><clTRID>epp-ns-'.bin2hex(random_bytes(8)).'</clTRID></command></epp>';

        return $epp->rawXml(['xml' => $xml]);
    }

    private function ensureHostObjects(object $epp, array $nameservers): void
    {
        if ($this->usesHostAttributes()) {
            return;
        }

        foreach ($nameservers as $hostname) {
            $check = $epp->hostCheck(['hostname' => $hostname]);
            $this->throwOnError($check, 'Host check failed for '.$hostname);

            if (! $this->hostAvailableFromResponse($check, $hostname)) {
                continue;
            }

            $create = $epp->hostCreate(['hostname' => $hostname]);
            $this->throwOnError($create, 'Host create failed for '.$hostname);
        }
    }

    private function transferOperation(Domain $domain, string $operation): array
    {
        try {
            return $this->withEpp(function ($epp) use ($domain, $operation): array {
                $response = $epp->domainTransfer([
                    'domainname' => $this->asciiDomain($domain->domain),
                    'op' => $operation,
                ]);
                $this->throwOnError($response, 'Transfer '.$operation.' failed');

                return ['success' => true, 'response' => $response];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function simpleDomainCommand(Domain $domain, string $method, array $params): array
    {
        try {
            return $this->withEpp(function ($epp) use ($method, $params): array {
                if (! method_exists($epp, $method)) {
                    throw new \RuntimeException("Namingo profile does not implement {$method}().");
                }
                $response = $epp->{$method}($params);
                $this->throwOnError($response, "EPP {$method} failed");

                return ['success' => true, 'response' => $response];
            });
        } catch (\Throwable $e) {
            $this->logFailure($method, $domain->domain, $e);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Response/data mapping
    // ---------------------------------------------------------------------

    private function availabilityFromResponse(array $response, string $domain): array
    {
        $domains = $response['domains'] ?? [];
        $item = null;

        if (is_array($domains)) {
            if (isset($domains[$domain]) && is_array($domains[$domain])) {
                $item = $domains[$domain];
            } else {
                $first = reset($domains);
                if (is_array($first)) {
                    $item = $first;
                }
            }
        }

        if (! is_array($item)) {
            throw new \RuntimeException('EPP domain:check returned no domain result.');
        }

        $available = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($available === null) {
            $available = ((int) ($item['avail'] ?? 0)) === 1;
        }

        return [(bool) $available, trim((string) ($item['reason'] ?? ''))];
    }

    private function hostAvailableFromResponse(array $response, string $hostname): bool
    {
        $hosts = $response['hosts'] ?? [];
        if (! is_array($hosts)) {
            return false;
        }

        $item = null;
        if (isset($hosts[$hostname]) && is_array($hosts[$hostname])) {
            $item = $hosts[$hostname];
        } else {
            foreach ($hosts as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                if (($candidate['name'] ?? $candidate['hostname'] ?? null) === $hostname) {
                    $item = $candidate;
                    break;
                }
                $item ??= $candidate;
            }
        }

        if (! is_array($item)) {
            return false;
        }

        $available = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $available ?? (((int) ($item['avail'] ?? 0)) === 1);
    }

    private function nameserversFromInfo(array $info): array
    {
        $result = [];
        foreach ((array) ($info['ns'] ?? $info['nss'] ?? []) as $ns) {
            if (is_string($ns)) {
                $name = $ns;
            } elseif (is_array($ns)) {
                $name = $ns['hostName'] ?? $ns['hostname'] ?? $ns['name'] ?? null;
            } else {
                $name = null;
            }

            if ($name !== null && trim((string) $name) !== '') {
                $result[] = strtolower(rtrim(trim((string) $name), '.'));
            }
        }

        return array_values(array_unique($result));
    }

    private function statuses(array $info): array
    {
        $statuses = $info['status'] ?? [];
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        if (! is_array($statuses)) {
            return [];
        }

        $out = [];
        foreach ($statuses as $status) {
            if (is_array($status)) {
                $status = $status['s'] ?? $status['status'] ?? null;
            }
            if (is_scalar($status) && trim((string) $status) !== '') {
                $out[] = trim((string) $status);
            }
        }

        return array_values(array_unique($out));
    }

    private function mapDomainStatus(array $statuses, ?string $expiry): string
    {
        // Only return statuses PNLCS itself defines. EPP hold/inactive
        // statuses describe delegation or operation locks, not loss of the
        // registration, so they remain active in PNLCS.
        if (in_array('pendingDelete', $statuses, true)) {
            return 'redemption';
        }
        if (in_array('pendingCreate', $statuses, true) || in_array('pendingTransfer', $statuses, true)) {
            return 'pending';
        }

        if ($expiry !== null) {
            try {
                if (Carbon::parse($expiry)->isPast()) {
                    return 'expired';
                }
            } catch (\Throwable) {
                // Ignore malformed registry date and use statuses instead.
            }
        }

        return 'active';
    }

    private function contactRolesFromDomainInfo(array $info): array
    {
        $roles = [];
        if (! empty($info['registrant'])) {
            $roles['registrant'] = (string) $info['registrant'];
        }
        foreach ((array) ($info['contact'] ?? []) as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $type = (string) ($contact['type'] ?? '');
            $id = (string) ($contact['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $roles[$type] = $id;
            }
        }

        return $roles;
    }

    private function throwOnError(mixed $response, string $prefix): void
    {
        if (! is_array($response)) {
            throw new \RuntimeException($prefix.': invalid EPP response');
        }
        if (isset($response['code']) && (int) $response['code'] >= 2000) {
            throw new \RuntimeException($prefix.': '.($response['msg'] ?? $response['error'] ?? 'EPP error').' ('.$response['code'].')');
        }
        if (is_array($response) && isset($response['error']) && trim((string) $response['error']) !== '') {
            throw new \RuntimeException($prefix.': '.(string) $response['error']);
        }
    }

    // ---------------------------------------------------------------------
    // Local/config helpers
    // ---------------------------------------------------------------------

    private function loadSettings(): array
    {
        try {
            return RegistrarSettings::whereRaw('LOWER(registrar) = ?', ['epp'])
                ->get()
                ->mapWithKeys(static fn ($row) => [(string) $row->setting => $row->value])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function profile(): string
    {
        $raw = trim((string) ($this->settings['registry_profile'] ?? 'generic'));
        if ($raw === '' || strtolower($raw) === 'generic') {
            return 'generic';
        }

        return strtoupper($raw);
    }

    private function boolSetting(string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $this->settings) || $this->settings[$key] === null || $this->settings[$key] === '') {
            return $default;
        }

        $value = strtolower(trim((string) $this->settings[$key]));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private function nullableSetting(string $key): ?string
    {
        $value = trim((string) ($this->settings[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function jsonSetting(string $key): array
    {
        $raw = trim((string) ($this->settings[$key] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$key} contains invalid JSON.");
        }

        return $decoded;
    }

    private function nameserversFor(Domain $domain, array $params): array
    {
        $provided = [];

        if (isset($params['nameservers']) && is_array($params['nameservers'])) {
            $provided = $params['nameservers'];
        }
        foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $key) {
            if (! empty($params[$key])) {
                $provided[] = $params[$key];
            }
        }

        if ($provided === [] && $domain->nameservers) {
            $stored = is_array($domain->nameservers)
                ? $domain->nameservers
                : json_decode((string) $domain->nameservers, true);
            if (is_array($stored)) {
                $provided = $stored;
            }
        }

        if ($provided === []) {
            for ($i = 1; $i <= 5; $i++) {
                try {
                    $value = trim((string) Setting::get('DefaultNameserver'.$i, ''));
                } catch (\Throwable) {
                    $value = '';
                }
                if ($value !== '') {
                    $provided[] = $value;
                }
            }
        }

        return $this->normalizeNameservers($provided);
    }

    private function normalizeNameservers(array $nameservers): array
    {
        $result = [];
        foreach ($nameservers as $name) {
            if (! is_scalar($name)) {
                continue;
            }
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $name = $this->asciiHostname($name);
            $result[] = strtolower(rtrim($name, '.'));
        }

        return array_slice(array_values(array_unique($result)), 0, 5);
    }

    private function formatNameservers(array $nameservers): array
    {
        if (! $this->usesHostAttributes()) {
            return array_values($nameservers);
        }

        $out = [];
        foreach ($nameservers as $host) {
            $row = ['hostName' => $host];
            // In-bailiwick glue is required by some profiles. DNS lookup is only
            // used to enrich the payload; absence is not itself an error.
            if ($this->looksInBailiwickForProfile($host)) {
                $a = @dns_get_record($host, DNS_A);
                if (! empty($a[0]['ip'])) {
                    $row['ipv4'] = $a[0]['ip'];
                }
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (! empty($aaaa[0]['ipv6'])) {
                    $row['ipv6'] = $aaaa[0]['ipv6'];
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private function usesHostAttributes(): bool
    {
        return ($this->settings['ns_mode'] ?? 'hostObj') === 'hostAttr'
            || in_array($this->profile(), self::HOST_ATTR_PROFILES, true);
    }

    private function looksInBailiwickForProfile(string $host): bool
    {
        return match ($this->profile()) {
            'EU' => str_ends_with(strtolower($host), '.eu'),
            'HR' => str_ends_with(strtolower($host), '.hr'),
            'LV' => str_ends_with(strtolower($host), '.lv'),
            'GE' => str_ends_with(strtolower($host), '.ge'),
            default => false,
        };
    }

    private function asciiDomain(string $domain): string
    {
        $domain = Domain::normalise($domain);
        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new \RuntimeException("'{$domain}' is not a valid domain name.");
        }

        if (function_exists('idn_to_ascii')) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($domain, $flags, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $domain = $ascii;
            }
        }

        return strtolower(rtrim($domain, '.'));
    }


    private function asciiHostname(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            throw new \RuntimeException("'{$hostname}' is not a valid hostname.");
        }

        if (function_exists('idn_to_ascii')) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($hostname, $flags, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $hostname = $ascii;
            }
        }

        return strtolower(rtrim($hostname, '.'));
    }

    private function contactId(): string
    {
        $prefix = $this->profile() === 'PL'
            ? trim((string) ($this->settings['pl_contact_prefix'] ?? ''))
            : trim((string) ($this->settings['contact_id_prefix'] ?? ''));

        $random = strtoupper(bin2hex(random_bytes(6)));

        $registrar = strtoupper(trim((string) ($this->settings['registrarprefix'] ?? '')));

        return $prefix.$random.($registrar !== '' ? '-'.$registrar : '');
    }

    private function randomPassword(int $length = 16): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*+-=?'];
        $characters = [];
        foreach ($sets as $set) {
            $characters[] = $set[random_int(0, strlen($set) - 1)];
        }
        $alphabet = implode('', $sets);
        while (count($characters) < max(4, $length)) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private function normalizePhone(string $phone, string $prefix = ''): string
    {
        $phone = trim($phone);
        if ($phone === '' || preg_match('/^\+\d{1,3}\.\d+$/', $phone)) {
            return $phone;
        }
        $prefix = ltrim(trim($prefix), '+');
        if ($prefix === '' && preg_match('/^\+(\d{1,3})[ .-]+(.+)$/', $phone, $parts)) {
            $prefix = $parts[1];
        }
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($prefix !== '' && str_starts_with($digits, $prefix)) {
            return '+'.$prefix.'.'.substr($digits, strlen($prefix));
        }

        return preg_replace('/[^0-9+]/', '', $phone) ?? $phone;
    }

    private function isMinDataSet(): bool
    {
        return $this->boolSetting('gtld') && $this->boolSetting('min_data_set');
    }

    private function contactPostalType(): string
    {
        return ($this->settings['contact_postal_type'] ?? 'int') === 'loc' ? 'loc' : 'int';
    }

    private function uriList(mixed $raw, array $defaults): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $defaults;
        }

        return array_values(array_filter(array_map(
            static fn (string $value) => trim($value),
            preg_split('/[,\s]+/', $raw) ?: []
        )));
    }

    private function resolveReadablePath(string $path, bool $required): ?string
    {
        $path = trim($path);
        if ($path === '') {
            if ($required) {
                throw new \RuntimeException('Required EPP TLS file path is empty.');
            }

            return null;
        }

        $candidates = [$path];
        if (! str_starts_with($path, '/') && ! preg_match('~^[A-Za-z]:[\\\\/]~', $path)) {
            $candidates = [
                __DIR__.'/'.$path,
                __DIR__.'/namingo/'.$path,
                base_path($path),
            ];
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && is_readable($real)) {
                return $real;
            }
        }

        throw new \RuntimeException('EPP TLS file not found or unreadable: '.$path);
    }

    private function responseDate(array $response, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (empty($response[$key])) {
                continue;
            }
            try {
                return Carbon::parse((string) $response[$key])->toDateString();
            } catch (\Throwable) {
                // Try the next alias.
            }
        }

        return null;
    }

    private function toEppBooleanString(string $value): string
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'validated'], true)
            ? 'true'
            : 'false';
    }

    private function isoDateTime(string $value): string
    {
        try {
            return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function logFailure(string $operation, string $domain, \Throwable $e, string $level = 'error'): void
    {
        $message = "EPP {$operation} failed for {$domain}: {$e->getMessage()}";
        if ($level === 'warning') {
            Log::warning($message);
        } else {
            Log::error($message);
        }
    }
}
