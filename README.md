# FGSync for Oblio

Integrare independentă WooCommerce cu [Oblio.eu](https://www.oblio.eu): emitere automată de facturi,
proforme, avize și storno, procesare pe coadă, sincronizare stoc pe mai multe gestiuni și webhooks.

Plugin open-source independent, nedezvoltat, neaprobat și nesusținut de Oblio.eu. Oblio este un
serviciu terț, este necesar un cont Oblio activ.

Acesta este repository-ul de dezvoltare. Descrierea pluginului și changelog-ul publicate pe
WordPress.org se află în [`readme.txt`](readme.txt).

## Cerințe

* PHP 8.1+
* WordPress 6.5+
* WooCommerce 8.2+

## Dezvoltare

```bash
composer install
composer phpcs      # coding standards
composer phpstan    # analiză statică
composer test       # PHPUnit
```

## Licență

GPL-2.0-or-later. Vezi [LICENSE](LICENSE).
