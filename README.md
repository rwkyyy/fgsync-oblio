# Oblio - Facturare și Gestiune pentru WooCommerce

Native WooCommerce integration for [Oblio.eu](https://www.oblio.eu): automatic invoices, proformas,
delivery notes (avize) and storno, queued processing, multi-warehouse stock sync and webhooks.

This is the development repository. The plugin listing, description and changelog live in
[`readme.txt`](readme.txt) (the WordPress.org format).

## Requirements

* PHP 8.1+
* WordPress 6.5+
* WooCommerce 8.2+

## Development

```bash
composer install
composer phpcs      # coding standards
composer phpstan    # static analysis
composer test       # PHPUnit
```

## Release process

Pushing a commit to `main` that bumps the `Version` header in
`facturare-gestiune-oblio-woocommerce.php` (kept in sync with `Stable tag` in `readme.txt`)
triggers a GitHub Actions workflow that tags the release and publishes a GitHub Release with the
matching changelog entry. See `.github/workflows/`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
