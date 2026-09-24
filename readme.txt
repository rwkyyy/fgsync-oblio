=== FGSync for Oblio ===
Contributors: rwky
Tags: woocommerce, invoicing, oblio, invoice, romania
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invoicing and stock management for WooCommerce through Oblio.

== Description ==

FGSync for Oblio is an independent open-source integration between WooCommerce and the Oblio.eu invoicing service. It is not developed, endorsed, maintained, or supported by Oblio.eu; Oblio is a third-party service, and an active Oblio account is required.

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

What is sent for a document: your company identifier (CIF), the customer's billing details (name, company, address, tax/registration identifiers, email and phone when present), the order lines (product name, code/SKU, quantity, price, VAT), shipping and fees, totals, and the payment method. Authentication uses your Oblio account email and API secret. No data is sent to any party other than Oblio.

Oblio service: https://www.oblio.eu
Oblio Terms and Conditions: https://www.oblio.eu/terms
Oblio confidentiality/privacy policy: it is part of the Terms and Conditions above (the "notă de confidențialitate"); the current version is published on https://www.oblio.eu

Please review those documents before use. Sending customer and order data to Oblio is subject to your agreement with Oblio and your own privacy obligations.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Open the FGSync settings (top-level "FGSync" menu), enter your Oblio account email and API secret, then test the connection.

== Frequently Asked Questions ==

= Is this plugin made or supported by Oblio? =

No. FGSync for Oblio is an independent, community-built open-source integration. It is not developed, endorsed, maintained, or supported by Oblio.eu. Oblio is a third-party invoicing service accessed through its public API; an active Oblio account is required to use this plugin.

= What support is available? =

Support is community/best-effort, through the WordPress.org support forum or the plugin's public issue tracker. There is no guaranteed response time or SLA.

= Is it compatible with HPOS? =

Yes, with both HPOS and the classic order storage.

= How are updates delivered? =

Through WordPress.org, like any plugin. There is no custom updater.

= How do I migrate from the old plugin? =

The settings include an "Import settings" button on the connection screen. Documents already issued are shown automatically.

= When are invoices issued? =

Two modes, under Documents. "Immediately" issues the invoice as soon as the order enters one of the selected statuses, useful when the invoice must exist before the parcel leaves the warehouse. "Scheduled (batch)" issues invoices periodically at the interval you choose, useful when invoicing happens later, e.g. on delivery. In both modes a reconciliation scan re-checks recent orders (the last 7 days by default, adjustable with a filter) and re-queues any invoice a missed hook or a brief Oblio outage skipped.

= How do customers get the invoice by email? =

Under Email, pick a mode. "Standalone" sends a separate message from the plugin when the document is issued, using your subject/message templates. "Button" instead adds a button linking to the Oblio invoice inside WooCommerce's own order emails, for the order statuses you select (for example, the Completed order email). In "Button" mode, if the invoice has not been issued yet when that email is sent, the plugin issues it at that moment so the button always links to a real invoice; this happens even when automatic invoicing is off. Use the `oblio_fgwoo_email_button_issue` filter to disable that behaviour if you only want a button when an invoice already exists.

== Changelog ==

= 1.1.0 =
* Rebrand: renamed the plugin to "FGSync for Oblio" (slug/text domain `fgsync-oblio`, was `oblio-fgwoo`), an independent open-source integration. Oblio no longer appears as author, contributor, or support provider; author is now Eduard Doloc. Plugin URI and Author URI now point to neutral, developer-controlled pages instead of oblio.eu.
* Added an explicit in-plugin and readme disclosure that this is an independent integration, not developed, endorsed, maintained, or supported by Oblio.eu.
* Removed the bundled Oblio logo image; the admin menu and settings header now use a generic icon.
* Internal-only changes (PHP namespace, compile-time constants, script/style handles): renamed to the new brand. All persisted data — settings, order/product meta, scheduled jobs, webhook subscriptions, rate-limit state — keeps its existing `oblio_fgwoo_...` storage keys unchanged, so upgrading in place does not reset your configuration or lose data.
* Because the plugin slug and main file changed, this version is registered with WordPress as a different plugin from the old `oblio-fgwoo` builds used during the beta. Sites running a pre-1.1.0 beta build must deactivate/remove the old plugin folder and install this one; see the "Upgrading from a pre-1.1.0 beta" note below. Settings and order data are preserved because they are stored under the unchanged `oblio_fgwoo_` option/meta prefix; only the plugin's own active-plugin registration, and any queued/in-flight Action Scheduler jobs that were already running under the old install at the moment of swap, are affected.
* Minor: moved a handful of directly-printed inline `style=""` attributes into the stylesheet.
* Fixed a race condition that could issue two invoices for the same order (e.g. a manual "Issue invoice" click overlapping with an already-queued automatic issue) by serializing document issuance per order with a short-lived lock.
* The orders list now flags a failed invoice with a red "!" badge (hover for the reason) instead of plain text, and the order screen's Oblio box shows the last failure reason under the issue button so you can see why a document isn't out without checking the log.
* The order screen's Oblio box no longer offers "Issue proforma" once the invoice is already issued, and no longer shows the "can't delete, not last in series" note on every older document — deleting stays available only where it's actually allowed.
* A couple of status colors (error/success) now match WooCommerce's own admin color scheme where it's loaded, falling back to the plugin's own colors elsewhere.
* Fixed the orders list "Emitere eșuată" filter showing orders that had already been invoiced (and even stornoed) since — it now only lists orders that failed and still have no invoice.

= 1.0.2 =
* Temporarily disabled the stock webhook trigger (real-time sync on Oblio's notification) while its payload is verified against more real-world traffic; the "Webhook" and "Both" sync modes are removed from settings, existing subscriptions on the Oblio side are cleaned up automatically, and the scheduled sync is unaffected. Sites that had "Webhook only" selected fall back to no automatic sync (was never a schedule they chose) and must pick "Programată" if they want stock kept in sync in the meantime; sites that had "Both" keep their schedule.

= 1.0.1 =
* Removed redundant order notes for document issue/failure events (the same information is already in the Oblio log).
* Added logging for previously-silent actions: manual document actions, bulk actions, settings save, connection test, nomenclature refresh, incoming webhooks, and customer document emails.
* Translated all log messages to English.

= 1.0.0 =
* First stable release. Complete rewrite: WP HTTP API client, Action Scheduler queues, document engine (invoice, proforma, delivery note, credit note), multi-warehouse stock sync, optional webhooks, status panel, import from the old plugin, HPOS and classic compatibility.

== Upgrading from a pre-1.1.0 beta ==

If you installed an earlier `oblio-fgwoo` beta build directly (not through this wp.org listing), note before updating:

* This release ships under a new plugin slug/folder (`fgsync-oblio`) and main file (`fgsync-oblio.php`). WordPress treats it as a different plugin, not an in-place update of the old folder.
* Deactivate and delete the old `oblio-fgwoo` (or similarly named) plugin folder first, then install this one. Your settings, order metadata, product fields, and issued-document history are preserved: they are all stored under the `oblio_fgwoo_` option/meta prefix, which this release deliberately did not rename.
* Any Action Scheduler job (document issue, refund/storno, stock sync, reconciliation, webhook processing) still queued or in-flight at the exact moment of the swap may need to be re-triggered manually (e.g. via "Sync now" or by re-saving the order), since the old plugin's runtime is gone when its folder is removed. Nothing already completed (issued invoices, stored settings) is lost.
* Any custom code hooking the plugin's filters/actions (e.g. `oblio_fgwoo_email_button_issue`) keeps working unchanged, since those hook names were intentionally not renamed.
