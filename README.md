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

**Minimum requirement:** A current PNLCS installation with module discovery (PHP 8.4+). The installer requires Bash, curl, tar, Perl, PHP CLI and Composer 2, and uses the `www-data` web-server user, like the WHMCS installer.

1. Install with the automated installer:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/pnlcs-epp-registrar/main/install-pnlcs-epp.sh) namingo
```

Replace `namingo` with the registry name. Run without parameters to list all supported registries:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/pnlcs-epp-registrar/main/install-pnlcs-epp.sh)
```

The installer automatically detects PNLCS under `/var/www`. You may specify the path explicitly:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/pnlcs-epp-registrar/main/install-pnlcs-epp.sh) namingo /var/www/pnlcs
```

2. During installation, the script can optionally generate a **self-signed EPP client certificate for testing**. It prints the certificate and private-key paths. For production, use credentials issued or approved by the registry.

3. In **Configuration → Domain Registrars**, enable the installed registrar and enter the EPP host, port, login credentials and certificate/key paths. Select the correct **Registry Profile** and other registry-specific options. The installer name identifies the separate module; it does not select the Tembo protocol profile automatically. For DRS.UA, use `generic` and set **Contact Postal Address Type** to `loc`.

4. In **Configuration → Domain Pricing**, add the TLD, assign the installed registrar and configure pricing.

5. Clear application caches and restart queue workers after installing or upgrading:

```bash
cd /var/www/pnlcs
php artisan optimize:clear
php artisan queue:restart
```

For `namingo`, the installer creates `modules/Registrars/Namingo/NamingoRegistrar.php`, namespace `Modules\Registrars\Namingo`, class `NamingoRegistrar`, and registrar key `namingo`. It updates the manifest, settings lookups, domain registrar assignments and log paths consistently. Other profiles receive their own names, so several registry modules can coexist.

Tembo and Monolog are installed from the lockfile into **`modules/Registrars/Namingo/namingo/vendor`**. PNLCS's root Composer files and Tembo's namespaces are not changed. Only module files and local dependencies are deployed; repository development files are excluded.

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

Run the same installer with the **same registry name and PNLCS path**:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/pnlcs-epp-registrar/main/install-pnlcs-epp.sh) namingo /var/www/pnlcs
```

It replaces the module code and local dependencies, preserves existing `.pem`, `.key`, `.crt` and `.cer` credentials (including nested files), and leaves database settings and other registry modules intact. It stages the replacement before switching directories and restores the previous directory if the replacement fails. Run the cache/worker commands above afterwards.

The installer uses a reviewed, commit-pinned module version, following the WHMCS installer. Future module releases require updating its `VERSION` and `SOURCE_COMMIT` together.

An older generic `EPP` installation still uses registrar key `epp`. Installing a named profile creates a separate registrar; configure it and reassign the relevant TLDs/domains before retiring the old module. Database assignments are not silently migrated.

## Troubleshooting

- **Module-local Tembo is missing:** rerun the installer for the affected registry, or run `composer install --no-dev` inside that module’s `namingo` directory. Installing Tembo globally does not satisfy this check.
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