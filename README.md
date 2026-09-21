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
| DRS.UA | all | | | |
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
| Traficom | .fr | FI | |

## Installation

**TODO**

```bash
git clone https://github.com/getnamingo/pnlcs-epp-registrar.git

mv pnlcs-epp-registrar/EPP /path/to/pnlcs/modules/Registrars/

cd /path/to/pnlcs

composer require pinga/tembo
```

## Upgrade

**TODO**

## Troubleshooting

**TODO**

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