=== Oblio - Facturare și Gestiune pentru WooCommerce ===
Contributors: oblio
Tags: woocommerce, invoicing, oblio, invoice, romania
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically issue invoices, proformas, delivery notes and credit notes in Oblio, with queued processing, multi-warehouse stock sync and webhooks.

== Description ==

A native integration between WooCommerce and Oblio.eu, rebuilt from the ground up for performance and reliability.

**Documents**

* Issue invoices, proformas, delivery notes and credit notes (storno), automatically or manually from the order screen.
* Proforma to invoice rule: a proforma is transformed into an invoice or deleted; a proforma is never issued after an invoice.
* Automatic credit note on WooCommerce refunds (partial and full). Experimental bridge to the WooCommerce Returns feature, activated automatically only when that feature is available in your WooCommerce version.

**Performance**

* Queued processing (Action Scheduler) so issuing never blocks checkout or status changes.
* Automatic retries with backoff, plus a reconciliation job for missed invoices.
* Stock synced in batches, across one or more warehouses (locations), matching the WooCommerce SKU with the Oblio product code.

**Collection and notifications**

* Automatic "mark as paid", configurable per payment method, with exceptions.
* Optional, verified webhooks for real-time stock updates.
* Invoices issued the moment the order reaches a status, or on a schedule (batch), your choice.
* Customer notifications two ways: a standalone email from the plugin, or a button linking to the invoice inside WooCommerce's own order emails.

**Compatibility**

* Works with both High-Performance Order Storage (HPOS) and the classic order storage.
* Compatible with WPML / WooCommerce Multilingual (invoice language and currency from the order).
* Logs through WooCommerce (WooCommerce, Status, Logs) and a dedicated status panel.
* One-click import from the old "WooCommerce Oblio" plugin.

This plugin uses the Oblio.eu API. You need an Oblio account and an API secret.

== External services ==

This plugin connects to the Oblio.eu invoicing service (https://www.oblio.eu) through its API (https://www.oblio.eu/api) to create and manage your accounting documents. Sending data to Oblio is the core purpose of the plugin and happens only for the actions you enable.

When data is sent:

* When a document is issued (invoice, proforma, delivery note, or credit note), automatically on the order status you choose or manually from the order screen.
* When an order is marked as paid ("collection"), if you enable it.
* When stock is synchronised from Oblio, on the schedule you set or on demand.
* When you test the connection or import settings from the connection screen.
* When webhooks are enabled, Oblio calls your site to notify it of stock changes.

What is sent for a document: your company identifier (CIF), the customer's billing details (name, company, address, tax/registration identifiers, email and phone when present), the order lines (product name, code/SKU, quantity, price, VAT), shipping and fees, totals, and the payment method. Authentication uses your Oblio account email and API secret. No data is sent to any party other than Oblio.

Oblio service: https://www.oblio.eu
Oblio Terms and Conditions: https://www.oblio.eu/terms
Oblio confidentiality/privacy policy: it is part of the Terms and Conditions above (the "notă de confidențialitate"); the current version is published on https://www.oblio.eu

Please review those documents before use. Sending customer and order data to Oblio is subject to your agreement with Oblio and your own privacy obligations.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Open the Oblio settings, enter your email and API secret, then test the connection.

== Frequently Asked Questions ==

= Is it compatible with HPOS? =

Yes, with both HPOS and the classic order storage.

= How are updates delivered? =

Through WordPress.org, like any plugin. There is no custom updater.

= How do I migrate from the old plugin? =

The Oblio settings include an "Import settings" button on the connection screen. Documents already issued are shown automatically.

= When are invoices issued? =

Two modes, under Documents. "Immediately" issues the invoice as soon as the order enters one of the selected statuses, useful when the invoice must exist before the parcel leaves the warehouse. "Scheduled (batch)" issues invoices periodically at the interval you choose, useful when invoicing happens later, e.g. on delivery. In both modes a reconciliation scan re-checks recent orders (the last 7 days by default, adjustable with a filter) and re-queues any invoice a missed hook or a brief Oblio outage skipped.

= What happens when I enable webhooks? =

Webhooks are optional and off by default. When you enable them, Oblio notifies your site in real time (the flow is one way, from Oblio to your site). Enable "real-time stock sync" and, when stock changes in Oblio, your WooCommerce stock updates almost immediately (the scheduled sync stays as a fallback). Each request is verified with a secret in the callback URL. Turning webhooks off removes the subscriptions from Oblio.

= How does the stock webhook work? Can it replace the scheduled sync? =

Oblio is the source of truth for stock. Its webhooks are one-way (Oblio notifies your site), so stock flows from Oblio into WooCommerce, there is no push of WooCommerce stock back to Oblio. With webhooks enabled, a stock change in Oblio triggers a near-real-time (debounced) sync, so the scheduled sync is only a fallback. Stock is decremented in Oblio when you issue an invoice with the "use stock" option. The product's Oblio code must match the WooCommerce SKU.

= How do customers get the invoice by email? =

Under Email, pick a mode. "Standalone" sends a separate message from the plugin when the document is issued, using your subject/message templates. "Button" instead adds a button linking to the Oblio invoice inside WooCommerce's own order emails, for the order statuses you select (for example, the Completed order email). In "Button" mode, if the invoice has not been issued yet when that email is sent, the plugin issues it at that moment so the button always links to a real invoice; this happens even when automatic invoicing is off. Use the `oblio_fgwoo_email_button_issue` filter to disable that behaviour if you only want a button when an invoice already exists.

== Changelog ==

= 1.0.0 =
* First stable release. Complete rewrite: WP HTTP API client, Action Scheduler queues, document engine (invoice, proforma, delivery note, credit note), multi-warehouse stock sync, optional webhooks, status panel, import from the old plugin, HPOS and classic compatibility.
