=== BORIS Stock – Inventory Management for WooCommerce ===
Contributors: borissystems
Tags: inventory-management, stock-management, warehouse-management, stock-locations, picklist
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 10.0
WC tested up to: 11.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WooCommerce inventory management for small shops with physical stock locations, warehouse stock movements, mobile pick lists and packing slips.

== Description ==

BORIS Stock provides free inventory management for small WooCommerce shops that need practical warehouse management. It organizes physical stock locations, records stock movements, provides mobile pick lists and produces customer packing slips, while WooCommerce remains responsible for total sellable stock.

= WooCommerce inventory and warehouse management =

The guided dashboard helps a new user create warehouse locations, import supported WooCommerce products and allocate the existing WooCommerce stock as the initial physical stock. Import and placement are idempotent: repeating either step does not duplicate or reset stock.

Products without a physical location remain valid and sellable in WooCommerce. Their orders remain visible in BORIS Stock and wait for sufficient physical location stock before they can be processed.

= Physical stock locations and stock movements =

Daily warehouse tools support receiving stock, booking out stock, moving stock between locations, smart receiving-location suggestions and protected pick-list processing. Completing a BORIS Stock pick list changes physical location stock only and never reduces WooCommerce stock a second time.

The product-centred inventory screen searches server-side by name, SKU, barcode/GTIN or WooCommerce product ID. It shows physical, reserved, available and WooCommerce stock and lets warehouse users receive, book out, move or safely edit the selected product without loading the complete catalogue into the browser.

Warehouse locations have live total, available and occupied counters, server-side search, availability filters, sorting and pagination. Compatible locations can be searched while receiving or moving stock, and a new location can be created without abandoning the current form.

= Mobile pick lists and packing slips =

The responsive pick-list screen works on phones and lets authorized warehouse users enter the actual picked quantities and complete the pick list safely. The same pick list can then be printed or saved as a PDF.

Pick lists and customer packing slips are separate documents. Both use the order's stored shipping address, with a billing-address fallback. Packing slips omit internal warehouse locations, while pick lists retain the locations, SKU and available barcode information needed by warehouse staff. The A4 print layout removes administration controls and avoids an unnecessary blank second page.

= Product and inventory tools =

Authorized warehouse users can also create a normal WooCommerce simple product from BORIS Stock, including product images, prices, descriptions, categories and shipping measurements, then divide its opening stock across existing warehouse locations. Product photos can be selected or uploaded together, assigned one main photo and ordered for the WooCommerce gallery. Optional hierarchical BORIS photo folders organize ordinary Media Library attachments without moving files or changing attachment URLs. WooCommerce remains the product catalogue and receives the total stock only once.

The plugin records reservations, actual picked quantities, physical shortages and an immutable inventory ledger. New receipts resolve the oldest physical shortages first using FIFO.

BORIS Stock deliberately does not support WooCommerce backorders, fractional quantities or variations that inherit stock from their parent product. Unsupported configurations are shown as persistent issues.

No external service, telemetry, tracking or BORIS Cloud connection is used.

Documentation: https://borissystems.com/boris-stock/

Official website: https://borissystems.com/

Support: support@borissystems.com

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate BORIS Stock – Inventory Management for WooCommerce.
3. Configure supported products with stock management enabled, a unique SKU and backorders set to "Do not allow".
4. Open BORIS Stock in the WordPress administration menu.
5. Follow the dashboard steps to create warehouse locations and import the WooCommerce catalogue.
6. Allocate the imported initial stock across physical locations.
7. Optionally use BORIS Stock > New product to create a normal WooCommerce product and allocate its opening stock directly.
8. Use Inventory for receiving, booking out and moving stock, and use Pick Lists to process orders.
9. Optionally configure the warehouse email address and automatic pick-list email under BORIS Stock > Settings.

== Privacy and Email ==

BORIS Stock does not send telemetry, analytics or tracking data and does not connect to BORIS Cloud or another fixed external API.

The plugin can send operational plain-text email through the standard WordPress `wp_mail()` function and the mail transport configured by the site owner. A warehouse email is sent only when an authorized user requests it, or when an authorized administrator has explicitly enabled automatic pick-list email. Automatic email is disabled by default. The recipient is the warehouse address configured under BORIS Stock > Settings, or an alternative address explicitly entered for a manual retry.

Warehouse emails contain the WooCommerce order number, BORIS pick-list number, product SKU, product name, reserved quantity, warehouse location and a protected WordPress administration link to the pick list. They do not contain the customer name, postal address or payment details.

If WordPress cannot confirm a warehouse email and an optional issue-notification address is configured, the plugin sends that address the pick-list number, the mail error and the protected administration link. The site owner controls the recipients and is responsible for the privacy and retention rules of the configured mail service.

== Frequently Asked Questions ==

= Does completing a pick list reduce WooCommerce stock? =

No. WooCommerce already owns and reduces sellable stock. Pick completion changes physical BORIS location stock only.

= Can I import the WooCommerce catalogue more than once? =

Yes. The initial stock level is recorded once per product. Importing again does not duplicate or reset previously imported stock.

= What happens to products without a warehouse location? =

They remain valid and sellable in WooCommerce. BORIS Stock keeps related order lines visible, but does not make them executable until sufficient physical location stock has been placed.

= Are refunds physical returns? =

No. Refunds and cancellations after completed picking create a visible return or control issue. Returned goods must be booked explicitly.

= How are physical shortages handled? =

A shortage confirmed during picking creates a BORIS backorder. New receipts resolve the oldest open BORIS backorders FIFO before any remainder becomes sellable.

= Does BORIS Stock connect to a BORIS server? =

No. Version 1.1.0 stores its warehouse data locally in WordPress and has no BORIS Cloud or fixed external API dependency.

== Developer Notes ==

The plugin loads `assets/css/admin.css` directly. This file is the readable, non-minified source stylesheet; there is no generated minified counterpart and no CSS build step.

== Changelog ==

= 1.1.0 =

* Fixed MariaDB-compatible warehouse-location validation so active empty locations are accepted during product creation.
* Clarified recovery notices when a submitted product form was preserved before WooCommerce created a draft.
* Added bounded server-side product search by name, SKU, barcode/GTIN and WooCommerce product ID for large catalogues.
* Added a product-centred inventory view with physical, reserved, available and WooCommerce stock.
* Added safe editing of existing WooCommerce product details without changing stock, reservations, locations or inventory history.
* Added server-side location search, availability filters, counters, sorting and pagination.
* Added inline location creation while receiving, moving and allocating stock.
* Added separate pick-list and customer packing-slip views with HPOS-safe stored order addresses.
* Added mobile and A4 print improvements that prevent an unnecessary blank trailing page.
* Added isolated regression and scale validation for 20,000 products and 5,000 locations.

= 1.0.2 =

* Added an expandable BORIS photo-folder tree inside the New product media picker.
* Added exact per-folder filtering while keeping All media available.
* Added safe root-folder and subfolder creation from the media picker.
* Added automatic logical folder assignment for every successful upload to a selected BORIS folder.
* Added bulk logical moves for existing Media Library images without moving or renaming upload files.
* Added safe folder deletion that preserves WordPress attachments and prevents silent subtree changes.
* Added a preview-first WooCommerce catalogue organizer that can build logical category and product photo folders in safe batches.
* Added deterministic category choices, preservation of manual photo placements and idempotent handling of shared product images.

= 1.0.1 =

* Added creation of normal WooCommerce simple products from BORIS Stock.
* Added WordPress Media Library support for a main product image and ordered gallery.
* Added virtual hierarchical BORIS photo folders without moving or renaming WordPress upload files.
* Added multi-photo selection, main-photo choice and drag-to-order WooCommerce galleries.
* Added prices, descriptions, categories, GTIN, weight and dimensions to the new-product form.
* Added safe opening-stock allocation across one or more BORIS warehouse locations without increasing WooCommerce stock twice.
* Added recoverable drafts and duplicate-submission protection for product creation.

= 1.0.0 =

* Added guided dashboard onboarding.
* Added warehouse locations and physical location inventory.
* Added WooCommerce product import with the existing WooCommerce stock recorded as the initial stock level.
* Added creation of normal WooCommerce simple products with Media Library images and BORIS opening-stock allocation.
* Added safe allocation of initial stock across locations without double counting.
* Kept products without a location valid and sellable while their order lines wait for a physical location.
* Added receiving, booking out and moving stock.
* Added smart location suggestions based on current and previously used locations.
* Added protected pick lists, actual picked quantities, physical-shortage handling and FIFO backorder fulfilment.
* Added security, privacy, internationalization and WordPress.org submission improvements.

= 0.1.2 =

* Added guided onboarding, separate Locations and WooCommerce Import pages, and safe initial-stock placement.
* Added smart receiving-location suggestions and visibility for products without a physical location.
* Made pick-list views read-only and moved the READY-to-PICKING transition to an explicit, protected POST action.
* Shipped the admin stylesheet as readable source and documented operational email data.

= 0.1.1 =

* Corrected all public BORIS Systems links to https://borissystems.com/.
* Added the BORIS Systems logo to the warehouse administration.
* Added optional, idempotent warehouse email for complete pick lists, manual resend and visible failure handling.

= 0.1.0 =

* Initial implementation with locations, full allocation, pick lists, physical shortages, FIFO backorder fulfilment, ledger, recovery and HPOS compatibility declaration.

== Upgrade Notice ==

= 1.1.0 =

Adds scalable product-centred inventory, safe product editing, location search and separate pick/packing documents. No BORIS database migration is required; existing products, locations, stock, reservations, history and WooCommerce links are preserved.

= 1.0.2 =

Adds the BORIS media tree to the product-photo picker and an optional preview-first organizer for existing WooCommerce product images. Existing attachments, URLs, products, galleries, BORIS stock data and warehouse operations are preserved.

= 1.0.1 =

Adds the BORIS Stock new-product workflow while preserving existing products, mappings, locations, physical stock, history, settings, orders and pick lists.

= 1.0.0 =

Upgrading from 0.1.1 or 0.1.2 preserves BORIS Stock products, WooCommerce links, warehouse locations, location stock, import state, inventory movements, orders, pick lists, settings and assigned capabilities. Back up the WordPress database and files before any production update.
