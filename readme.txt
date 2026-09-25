=== FGSync for Oblio ===
Contributors: rwky
Tags: woocommerce, invoicing, oblio, invoice, romania
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
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

Three modes, under Documents. "Prin coadă" (the default) queues the invoice the moment the order enters one of the selected statuses; it's issued within seconds, without ever slowing down that request. "Instant" issues it synchronously, in the same request that changed the order's status (checkout, a manual status edit, a payment gateway callback) — useful if you need the invoice to exist the instant the status changes, at the cost of adding a few seconds to that request; if the attempt fails for any reason, it automatically falls back to the queue, so nothing is lost. "Scheduled (batch)" issues invoices periodically at the interval you choose, useful when invoicing happens later, e.g. on delivery. In all three modes a reconciliation scan re-checks recent orders (the last 7 days by default, adjustable with a filter) and re-queues any invoice a missed hook or a brief Oblio outage skipped.

= My order shows a red "!" — what does that mean? =

The plugin tried to issue that order's invoice and it failed; hover the badge on the orders list (or open the order) to see the stored reason. Recoverable failures are retried automatically through the reconciliation scan and Action Scheduler's own backoff. If the reason points to something on the Oblio side (for example, insufficient stock in the linked gestiune), fix it there, then re-issue manually from the order screen.

= Why can't I delete an older invoice? =

Oblio only allows deleting the last document issued in a series. If a newer invoice already exists in the same series, the delete button is hidden for the older one; issue a storno (credit note) instead to reverse it. Proformas are the exception: they can always be deleted, since Oblio treats them separately from the numbered invoice series.

= How are WooCommerce Product Bundles invoiced? =

Under Avansat, "Linia produsului tip pachet" controls this. The default, "Sări peste linia pachetului", invoices only the bundle's individual components, not the bundle product itself, because the bundle product usually has no stock record of its own in Oblio, and deducting stock against it fails. If your store doesn't deduct stock through Oblio, "Include linia pachetului" is worth trying instead: it shows the bundle as a single line with its own name and price, which often reads more clearly on the invoice. If you ever see an error naming the bundle line while "skip" is selected, it means that particular bundle puts its price on the main line rather than on the components (a "fixed price" bundle); switch that store's setting to "include", or get in touch through the support channels above.

= What does the OSS EUR setting do? =

Under Avansat, "Facturare OSS: EUR pentru clienți din afara României" is for stores using the EU One-Stop-Shop VAT scheme: when enabled, documents billed to an address outside Romania are issued in EUR regardless of the order's own currency, with Oblio showing the RON equivalent alongside it. It's off by default, and only relevant to B2C sales under the OSS threshold; the setting's own description links to WooCommerce's tax settings for the VAT-rate side of OSS compliance.

= Why does my WooCommerce stock drop before an order is invoiced? =

If "Rezervă stoc pentru comenzi nefacturate" is enabled (Sincronizare stoc tab), the stock WooCommerce displays is reduced for pending/processing orders that haven't been invoiced with stock deduction yet, so the store doesn't oversell while waiting. The actual deduction inside Oblio only happens once an invoice is issued with stock deduction on; this setting only affects what WooCommerce shows as available in the meantime.

= What's the status dot in the admin toolbar? =

A shortcut to the FGSync Status screen, with a small colored dot: green means everything's normal, yellow means the processing queue has a large backlog, and red means the last attempt to reach Oblio failed (it clears as soon as a later call succeeds). It's on by default; turn it off from a checkbox on the Status screen itself.

= How do customers get the invoice by email? =

Under Email, pick a mode. "Standalone" sends a separate message from the plugin when the document is issued, using your subject/message templates. "Button" instead adds a button linking to the Oblio invoice inside WooCommerce's own order emails, for the order statuses you select (for example, the Completed order email). In "Button" mode, if the invoice has not been issued yet when that email is sent, the plugin issues it at that moment so the button always links to a real invoice; this happens even when automatic invoicing is off. Use the `oblio_fgwoo_email_button_issue` filter to disable that behaviour if you only want a button when an invoice already exists.

== Changelog ==

= 1.3.0 =
* Fixed a set of rare concurrency issues that could surface under overlapping triggers — a manual action clicked while an automatic one was already running, two refunds on the same order close together, or a cancelled stock sync overlapping a new one. Document issuance, deletion, and refunds for a given order are now fully serialized, and an interrupted process can no longer leave another one's lock or in-progress state stuck or corrupted.
* Oblio responses that come back unreadable or missing required fields (e.g. a 200 OK with no invoice link) are now retried automatically instead of either being silently accepted as a real invoice or permanently marked as failed.
* Reconciliation (the periodic scan that catches missed invoices) no longer gets stuck rechecking the same batch of already-failed orders forever if that batch fills the scan window.
* Deleting a document imported from the old plugin now actually clears it; previously some leftover data could make the plugin think it still existed.
* The series/warehouse list (used by several settings) no longer blocks the settings page while waiting on Oblio when its weekly cache has expired, and a temporarily-unreachable Oblio account is no longer retried on every single page load.
* Stores sharing one Oblio account across multiple installs (e.g. staging and production) no longer risk colliding on the same invoice/refund identifier.
* Uninstalling now also clears the plugin's currently-active scheduled jobs, a gap left over from an earlier internal rename that meant only the old (unused) job group was being cleaned up.
* Fixed the stored plugin version not advancing on upgrade (cosmetic only; affected the admin's version display, not functionality).
* Added capability checks to bulk order actions and variation saving, for restricted/custom shop-manager roles.
* Added an "Instant" option (Documents tab) for invoice generation: issues the invoice synchronously the moment the order enters its trigger status, instead of a few seconds later via the queue — useful when the invoice must exist immediately. Falls back to the normal queue automatically if the synchronous attempt fails, so nothing is ever lost.
* Added a small status indicator to the WordPress admin toolbar: a colored dot linking to the FGSync Status screen, reflecting queue backlog and whether the last attempt to reach Oblio succeeded. On by default; can be turned off from the Status screen.
* WooCommerce Product Bundles: skipping the bundle's own line (the default) now fails with a clear, specific error if a particular bundle turns out to price itself on the main line rather than on its components, instead of silently under-invoicing it or blocking with a confusing "order value 0.00" error.
* Expanded the FAQ with entries on the failed-invoice badge, why an older invoice can't be deleted, Product Bundles invoicing, the OSS EUR setting, and stock reservation.
* The admin toolbar status dot now reads a small, separately-cached queue summary instead of re-running the full Status-page report on every single page load.
* The connection-health indicator no longer rewrites its stored state on every successful request to Oblio - only when recovering from a failure or after a few minutes, so busy stores no longer write to the database on nearly every page load.
* The background refresh of the series/warehouse list (used by several settings) now always runs as a single proper background job, instead of occasionally running twice in the same request.
* The "Vezi factura" email button now falls back to the normal queue if it can't issue the invoice inline (e.g. Oblio briefly unreachable), instead of only retrying the next time an email happens to go out for that order.
* "Sincronizează acum" (manual stock sync) now runs on the same background queue as the scheduled sync, with the browser just watching its progress, instead of driving the work itself over a long series of requests. Removed the "products per segment" setting, which only applied to the old approach.

= 1.2.0 =
* Renamed several remaining internal identifiers that still used a bare `oblio` prefix to the plugin's own prefix, for consistency. Two of these are visible in URLs and need attention on upgrade: the admin page moved from `?page=oblio` to `?page=fgsync-oblio` (rebookmark it), and the My Account invoices endpoint changed from `/oblio-facturi/` to `/oblio-fgwoo-facturi/` (update any bookmarked customer link).
* Fixed a race condition that could issue two invoices for the same order (e.g. a manual "Issue invoice" click overlapping with an already-queued automatic issue) by serializing document issuance per order with a short-lived lock.
* The orders list now flags a failed invoice with a red "!" badge (hover for the reason) instead of plain text, and the order screen's Oblio box shows the last failure reason under the issue button so you can see why a document isn't out without checking the log.
* The order screen's Oblio box no longer offers "Issue proforma" once the invoice is already issued, and no longer shows the "can't delete, not last in series" note on every older document — deleting stays available only where it's actually allowed.
* A couple of status colors (error/success) now match WooCommerce's own admin color scheme where it's loaded, falling back to the plugin's own colors elsewhere.
* Fixed the orders list "Emitere eșuată" filter showing orders that had already been invoiced (and even stornoed) since — it now only lists orders that failed and still have no invoice.
* Added an optional setting (Avansat tab) to issue documents in EUR whenever the billing address is outside Romania, for stores using the EU One Stop Shop (OSS) VAT scheme. Off by default; stores that don't need OSS are unaffected.
* Added an optional setting (Avansat tab) for how WooCommerce Product Bundles are invoiced. By default, the bundle's own order line is skipped and only its individual components — which have real stock records in Oblio — are billed and deducted from stock; can be switched to invoice the bundle line itself instead.
* Fixed the plugin's internal version constant, stuck at 1.0.2 since the 1.1.0 rename; only affected browser cache-busting on the admin CSS/JS, not functionality.

= 1.1.0 =
* Rebrand: renamed the plugin to "FGSync for Oblio" (slug/text domain `fgsync-oblio`, was `oblio-fgwoo`), an independent open-source integration. Oblio no longer appears as author, contributor, or support provider; author is now Eduard Doloc. Plugin URI and Author URI now point to neutral, developer-controlled pages instead of oblio.eu.
* Added an explicit in-plugin and readme disclosure that this is an independent integration, not developed, endorsed, maintained, or supported by Oblio.eu.
* Removed the bundled Oblio logo image; the admin menu and settings header now use a generic icon.
* Internal-only changes (PHP namespace, compile-time constants, script/style handles): renamed to the new brand. All persisted data — settings, order/product meta, scheduled jobs, rate-limit state — keeps its existing `oblio_fgwoo_...` storage keys unchanged, so upgrading in place does not reset your configuration or lose data.
* Because the plugin slug and main file changed, this version is registered with WordPress as a different plugin from the old `oblio-fgwoo` builds used during the beta. Sites running a pre-1.1.0 beta build must deactivate/remove the old plugin folder and install this one; see the "Upgrading from a pre-1.1.0 beta" note below. Settings and order data are preserved because they are stored under the unchanged `oblio_fgwoo_` option/meta prefix; only the plugin's own active-plugin registration, and any queued/in-flight Action Scheduler jobs that were already running under the old install at the moment of swap, are affected.
* Minor: moved a handful of directly-printed inline `style=""` attributes into the stylesheet.

= 1.0.1 =
* Removed redundant order notes for document issue/failure events (the same information is already in the Oblio log).
* Added logging for previously-silent actions: manual document actions, bulk actions, settings save, connection test, nomenclature refresh, and customer document emails.
* Translated all log messages to English.

= 1.0.0 =
* First stable release. Complete rewrite: WP HTTP API client, Action Scheduler queues, document engine (invoice, proforma, delivery note, credit note), multi-warehouse stock sync, status panel, import from the old plugin, HPOS and classic compatibility.

== Upgrading from a pre-1.1.0 beta ==

If you installed an earlier `oblio-fgwoo` beta build directly (not through this wp.org listing), note before updating:

* This release ships under a new plugin slug/folder (`fgsync-oblio`) and main file (`fgsync-oblio.php`). WordPress treats it as a different plugin, not an in-place update of the old folder.
* Deactivate and delete the old `oblio-fgwoo` (or similarly named) plugin folder first, then install this one. Your settings, order metadata, product fields, and issued-document history are preserved: they are all stored under the `oblio_fgwoo_` option/meta prefix, which this release deliberately did not rename.
* Any Action Scheduler job (document issue, refund/storno, stock sync, reconciliation) still queued or in-flight at the exact moment of the swap may need to be re-triggered manually (e.g. via "Sync now" or by re-saving the order), since the old plugin's runtime is gone when its folder is removed. Nothing already completed (issued invoices, stored settings) is lost.
* Any custom code hooking the plugin's filters/actions (e.g. `oblio_fgwoo_email_button_issue`) keeps working unchanged, since those hook names were intentionally not renamed.
