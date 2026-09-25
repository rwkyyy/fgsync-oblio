# FGSync for Oblio

[![Versiune WordPress.org](https://img.shields.io/wordpress/plugin/v/fgsync-oblio.svg?logo=wordpress&logoColor=white&label=wp.org)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Testat până la](https://img.shields.io/wordpress/plugin/tested/fgsync-oblio.svg?label=testat%20p%C3%A2n%C4%83%20la)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Descărcări](https://img.shields.io/wordpress/plugin/dt/fgsync-oblio.svg?label=desc%C4%83rc%C4%83ri)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/fgsync-oblio.svg?label=rating)](https://ro.wordpress.org/plugins/fgsync-oblio/#reviews)
[![CI](https://github.com/rwkyyy/oblio-fgwoo/actions/workflows/ci.yml/badge.svg)](https://github.com/rwkyyy/oblio-fgwoo/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)](composer.json)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.2%2B-7f54b3?logo=woocommerce&logoColor=white)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![HPOS](https://img.shields.io/badge/HPOS-compatibil-4c1)](fgsync-oblio.php)
[![Licență](https://img.shields.io/badge/licen%C8%9B%C4%83-GPL--2.0--or--later-blue)](LICENSE)

Integrare independentă WooCommerce cu [Oblio.eu](https://www.oblio.eu): emitere automată de facturi,
proforme, avize și storno, procesare pe cozi (queue), sincronizare stoc pe mai multe gestiuni și webhooks.

Plugin open-source independent, nedezvoltat, neaprobat și nesusținut de Oblio.eu. Oblio este un
serviciu terț, este necesar un cont Oblio activ.

Acesta este repository-ul de dezvoltare. Pluginul este publicat pe WordPress.org la
**[ro.wordpress.org/plugins/fgsync-oblio](https://ro.wordpress.org/plugins/fgsync-oblio/)** — acolo găsiți
versiunea stabilă de instalat. Descrierea completă și changelog-ul publicate pe WordPress.org se află în
[`readme.txt`](readme.txt).

## Funcționalități

* Facturi, proforme, avize și storno — automat pe status de comandă sau manual din ecranul comenzii.
* Procesare pe coadă (Queue / Action Scheduler): emiterea nu blochează niciodată checkout-ul/site-ul/ecranul operatorului.
* Sincronizare stoc în loturi, pe una sau mai multe gestiuni, după SKU.
* Încasare automată ("marcat ca plătit"), configurabilă pe metodă de plată.
* Compatibil HPOS (High-Performance Order Storage) și stocare clasică a comenzilor.
* Compatibil WPML / WooCommerce Multilingual.
* Compatibilitate Bundles (limitat)
* Compatibilitate OSS

Lista completă e în [`readme.txt`](readme.txt), secțiunea `== Description ==`.

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

## Testare / CI

La fiecare push sau pull request pe `main`/`develop`, [GitHub Actions](.github/workflows/ci.yml) rulează,
pe PHP 8.1, 8.2 și 8.3:

* lint PHP pe toate fișierele;
* PHPCS (WordPress Coding Standards) și PHPStan;
* suita PHPUnit.

Release-urile (tag-uri) sunt publicate automat pe SVN-ul WordPress.org

## Suport și contribuții

Probleme și sugestii: [issue tracker-ul de pe GitHub](https://github.com/rwkyyy/oblio-fgwoo/issues) sau
forumul de suport de pe [pagina pluginului](https://ro.wordpress.org/plugins/fgsync-oblio/). Suport
community/best-effort, fără SLA garantat.

## Licență

GPL-2.0-or-later. Vezi [LICENSE](LICENSE).
