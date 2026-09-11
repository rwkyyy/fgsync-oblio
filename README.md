# Oblio - Facturare și Gestiune pentru WooCommerce

Integrare nativă WooCommerce cu [Oblio.eu](https://www.oblio.eu): emitere automată de facturi,
proforme, avize și storno, procesare pe coadă, sincronizare stoc pe mai multe gestiuni și webhooks.

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
