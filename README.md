# PNLCS EPP Registrar

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A generic PNLCS registrar module for connecting to any domain registry that uses the EPP protocol.

This module is designed to work with both gTLD and ccTLD registries and provides a flexible foundation for EPP-based domain management in PNLCS.

## Registry Support

| Registry | TLDs | Profile | Needs |
|----------|----------|----------|----------|
| Generic RFC EPP | any | | |
| AFNIC | .fr/others | FR | |
| CARNET | .hr | HR | |
| Caucasus Online | .ge | GE | |
| CentralNic | all | | Set AuthInfo on Request / Min Data Set and gTLD Enabled (for gTLD) |
| CoCCA | all | | Set AuthInfo on Request / Min Data Set and gTLD Enabled (for gTLD) |
| CORE/Knipp | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Domicilium | .im | | |
| DRS.UA | all | generic | Contact Postal Address Type: loc |
| EURid | .eu | EU | |
| GoDaddy Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Google Nomulus | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Hello Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Hostmaster | .ua | UA | |
| Identity Digital | all | | Min Data Set and gTLD Enabled (for gTLD) |
| IIS | .se, .nu | SE | |
| IT.COM | all | | |
| Namingo | all | | |
| NASK | .pl | PL | |
| NIC Chile | .cl | | |
| NIC Mexico | .mx | MX | |
| NIC.LV | .lv | LV | |
| .PT | .pt | PT | |
| Regtons | all | | |
| RoTLD | .ro | | |
| RyCE | all | | |
| SIDN | all | | |
| SWITCH | .ch, .li | SWITCH | Set AuthInfo on Request |
| Tucows Registry | all | | Min Data Set and gTLD Enabled (for gTLD) |
| Verisign | all | VRSN | Min Data Set and gTLD Enabled (for gTLD) |
| ZADNA | .za | | |
| ZDNS | all | | |

### In Progress

| Registry | TLDs | Profile | Status |
|----------|----------|----------|----------|
| DENIC | .de | DE | |
| DOMREG | .lt | LT | |
| FORTH-ICS | .gr, .ελ | GR | |
| FRED | .cz/any | FRED | |
| NORID | .no | NO | |

### Paid Registry Support

| Registry | TLDs | Profile | Status |
|----------|----------|----------|----------|
| HKIRC | .hk | HK | |
| Internet.ee | .ee | EE | |
| Registro.it | .it | IT | |
| Traficom | .fi | FI | |

## Installation

Use a current PNLCS installation with `pnlcs.json` module discovery, `SyncsDomainData`, and `MapsClientFields` (the current PNLCS main branch requires PHP 8.4+). Enable PHP XML, SimpleXML and XMLWriter; Intl is recommended for internationalized domain names.

### Release archive (recommended)

Download **pnlcs-epp-VERSION.zip** or **pnlcs-epp-VERSION.tar.gz** from [Releases](https://github.com/getnamingo/pnlcs-epp-registrar/releases). Extract it into the PNLCS application directory. These attached archives contain:

```text
modules/Registrars/EPP/EppRegistrar.php
modules/Registrars/EPP/pnlcs.json
modules/Registrars/EPP/namingo/composer.json
modules/Registrars/EPP/namingo/composer.lock
modules/Registrars/EPP/namingo/vendor/autoload.php
```

The release workflow installs the locked Tembo and Monolog dependencies **inside `EPP/namingo`** before packaging. No Composer changes in the PNLCS application are needed. GitHub's automatic "Source code" ZIP/TAR downloads do not contain dependencies; use the attached module archive or the source instructions below.

### Source checkout

```bash
git clone https://github.com/getnamingo/pnlcs-epp-registrar.git
cd pnlcs-epp-registrar
composer install --working-dir=EPP/namingo --no-dev --prefer-dist --optimize-autoloader
cp -a EPP /path/to/pnlcs/modules/Registrars/
```

Run Composer only in the module's `namingo` directory. The old `composer require pinga/tembo` at the PNLCS root is no longer used. The module reports a clear error if its local autoloader is missing, even when a global Tembo autoloader is available.

In PNLCS, open the registrar settings, enable **epp**, enter the registry credentials, and assign it to the appropriate TLDs. The manifest registers the module automatically; no core provider edits are needed. Set certificate, private-key and CA paths to readable files. Relative paths are resolved from the module directory first. Keep private keys outside the public web directory.

## Configuration and WHMCS parity

The shared settings use WHMCS's names, order, labels and explanations, translated to PNLCS field types. PNLCS currently displays module help rather than individual field descriptions, so the same explanations are available from the registrar's **?** help badge via `getConfigHelp()`.

| Setting | Behavior |
|---|---|
| EPP Hostname / Port, client credentials, certificate, key, passphrase and CA | Standard Tembo connection settings. |
| Prefer TLS 1.3 | PNLCS retains the TLS 1.2/1.3 selector so existing saved values remain valid. WHMCS boolean values are also understood at runtime. |
| Verify TLS Certificate | Enabled by default in PNLCS. The draft's separate hostname verification and self-signed certificate controls remain available. |
| Object ID Prefix | Appended to new contact IDs as `RANDOM-PREFIX`, matching WHMCS. The NASK prefix is prepended for PL. Existing contact IDs are untouched. |
| Contact Postal Address Type | `int` (default) or `loc`, applied to **both contact:create and contact:update**. Set `loc` for registries such as DRS.UA. Registry-specific Tembo profiles may impose their own schema requirements. |
| Registry Profile | WHMCS profiles plus the additional Tembo profiles already exposed by the draft. See the support tables above before selecting experimental/paid profiles. |
| Nameserver Mode | `hostObj` by default; `hostAttr` embeds nameservers in domain commands and disables separate host operations. EU, HR, LV and GE keep their native attribute behavior. Generic hostAttr info/updates use an RFC XML adapter because Tembo 1.1.22's generic reader/updater only handles hostObj. |
| Set AuthInfo on Request | Generates and sets a fresh transfer code on each request, using Tembo's `authInfo` update parameter. Otherwise reads the existing code from domain info. |
| EPP Login Objects / Extensions | Comma/whitespace-separated URIs for the generic profile; blank uses the same defaults as WHMCS. |
| gTLD Registry / Use Minimum Data Set | Contact creation is omitted only when **both** are enabled. |
| EURid Billing Contact ID / NASK Contact Prefix | Same registry-specific behavior as WHMCS. |
| TMCH Claims Period Active | Enables claims checks. Registration requires an explicitly accepted, unexpired, domain-specific notice; see below. |
| Enable EPP Fee Extension | Performs premium checks when gTLD Registry is enabled. PNLCS keeps its explicit Allow Premium Registrations and Fee Extension Currency settings. Failed fee checks stop the operation; premium domains are unavailable unless allowed. |
| EPP Debug Logging / Log Path | Uses the bundled Monolog to log requests/responses to `storage/logs/epp` by default. Enable only while troubleshooting. |

The draft's client-field mappings (NIN, VAT, NIN type and PT validation), JSON extension overrides, timeout and additional TLS controls remain available after the shared settings. Contact JSON overrides cannot override the selected postal type. `additionalfields[NIN]`, `additionalfields[VAT]` and `additionalfields[NIN Type]` are also accepted during registration.

## PNLCS operations and integration limits

PNLCS's native registrar interface supports registration, transfer, renewal, nameservers, transfer codes, locking and availability. The module also implements authoritative domain sync. LV renewals retain the draft's local billing-date handling for that auto-renewing profile.

These additional callable methods are provided for authenticated admin/API integrations, but **PNLCS does not currently expose their own domain-management screens**:

- `getContactDetails($domain)` / `saveContactDetails($domain, $contacts)`
- `registerNameserver($hostname, $ip)` / `modifyNameserver($hostname, $oldIp, $newIp)` / `deleteNameserver($hostname)`
- `transferStatus`, `approveTransfer`, `rejectTransfer`, `cancelTransfer`
- `deleteDomain`, `restoreDomain`, `setHold`, `updateDnssec`, `poll`, `acknowledgePoll`
- `testConnection()` (PNLCS currently restricts its test button to HRD)

Integrations must perform the normal PNLCS authentication and domain-ownership checks before calling these methods. Contact IDs are always read from domain info; a caller cannot substitute an unrelated contact ID. Partial updates preserve existing registry values; identical updates to a shared contact execute once, and conflicting updates to that contact fail before writing.

```php
$module = app(\App\Services\Module\ModuleRegistry::class)->getRegistrarModule('epp');
$result = $module->getContactDetails($domain); // success + contacts keyed by role
$result = $module->saveContactDetails($domain, [
    'registrant' => ['email' => 'new@example.com'],
]);
// Also accepts WHMCS contactdetails labels such as Registrant / Email / Street 1.
```

When Minimum Data Set is enabled, registry contact read/update methods return an explanatory failure without contacting EPP. PNLCS's client record remains the local source; there is no WHMCS Namingo add-on contact table or automatic ICANN compliance workflow in this module. It does not silently edit a shared client record for a single-domain contact change.

PNLCS has no WHMCS-style TMCH notice/acceptance checkout or automatic premium repricing. An integration must display and store the actual claims notice and acceptance, and pass it during registration:

```php
$result = $module->register($domain, 1, [
    'tmch_claims' => [
        'domain' => 'example.tld',
        'noticeID' => $noticeId,
        'notAfter' => $noticeExpiry,       // UTC timestamp from the actual notice
        'accepted' => true,
        'acceptedDate' => $acceptedAt,    // UTC timestamp recorded at acceptance
    ],
]);
```

`checkAvailability()` returns `claims` and `premium` data for integrations. Missing, expired, unaccepted or mismatched claims notices stop registration before contacts are created. Leave claims disabled outside the registry's claims period. Enable premium registration only after arranging corresponding PNLCS pricing; the fee check does not update invoices.

## Upgrade

Replace `modules/Registrars/EPP` with the new release contents, preserving certificates and any local operational files. For source installations, run `composer install --working-dir=modules/Registrars/EPP/namingo --no-dev --prefer-dist --optimize-autoloader` after copying the new files. Clear PNLCS caches with `php artisan optimize:clear` if the module is not discovered, and restart long-running queue workers.

Review the new **gTLD Registry** switch: unlike the draft, Minimum Data Set and fee checks now require it. Select **Contact Postal Address Type** explicitly if your registry needs `loc`. Existing draft field mappings and TLS versions are retained. No database migration is required.

## Development and release packaging

```bash
composer install --working-dir=EPP/namingo --no-dev
php tests/run.php
php tests/tembo-xml.php
php tests/local-dependencies.php
scripts/build-release.sh v1.1.0
```

Tests exercise module behavior with PNLCS boundary doubles and actual installed Tembo XML serializers with socket I/O replaced. They do not contact a registry. GitHub Actions runs the tests on PHP 8.4/8.5 and checks the archive's module-local autoloader. Publishing a GitHub release runs the release workflow and attaches ready-to-install ZIP/TAR archives. Update dependencies deliberately in `EPP/namingo` and commit the resulting lockfile.

## Troubleshooting

- **Module-local Tembo is missing:** install an attached release archive or run the module-local Composer command above. Installing Tembo globally does not satisfy this check.
- **only loc type is supported:** select `loc` under Contact Postal Address Type. This covers creation and updates, including the generic DRS.UA profile.
- **Connection or TLS failure:** check host/port, registry IP allowlisting, certificate/key paths, CA trust and chosen TLS version. `testConnection()` is available to admin tooling even where the stock PNLCS page has no EPP test button.
- **Unsupported profile or operation:** follow the registry's schema requirements and the support tables; the settings screen alone does not certify every registry integration.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/pnlcs-epp-registrar/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find PNLCS EPP Registrar useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

PNLCS EPP Registrar is licensed under the MIT License.