Yes. Since your current class-speedaf-customer-emails.php already handles the customer notifications correctly, do not replace the whole 1,138-line file. Add the vendor layer to the existing class. This avoids disturbing the working customer email system.

1. Modify handleShipmentCreated()

In your current method, immediately after:

if (empty($billCode)) {
    return;
}

add:

/*
 * Send the Speedaf shipment-created notification to the
 * relevant Dokan vendor(s).
 *
 * This is independent of the customer email. Therefore,
 * a missing customer email must not prevent the vendor
 * notification from being sent.
 */
$this->sendVendorShipmentCreatedEmails(
    $order,
    $billCode
);

Then leave the existing customer-email code exactly as it is.

So that portion becomes:

if (empty($billCode)) {
    return;
}

/*
 * Send the Speedaf shipment-created notification to the
 * relevant Dokan vendor(s).
 */
$this->sendVendorShipmentCreatedEmails(
    $order,
    $billCode
);

/*
 * Prevent duplicate email.
 */
if ($this->wasSent($order, '_sefrelshop_email_shipment_created')) {
    return;
}

$customerEmail = $this->getCustomerEmail($order);

if (!$customerEmail) {
    return;
}
2. Modify handleTrackingEvent()

The important point here is that vendor notifications must not depend on the customer email.

Find this section:

$metaKey = '_sefrelshop_email_speedaf_status_' . $status;

/*
 * Never send the same status notification twice.
 */
if ($this->wasSent($order, $metaKey)) {
    return;
}

$customerEmail = $this->getCustomerEmail($order);

if (!$customerEmail) {
    return;
}

Replace it with:

$metaKey = '_sefrelshop_email_speedaf_status_' . $status;

/*
 * Send the Speedaf status notification to the relevant
 * Dokan vendor(s).
 *
 * Vendor notifications use their own per-vendor status
 * flags and are independent of the customer notification.
 */
$this->sendVendorTrackingEmails(
    $order,
    $status,
    $event
);

/*
 * Never send the same customer status notification twice.
 */
if ($this->wasSent($order, $metaKey)) {
    return;
}

$customerEmail = $this->getCustomerEmail($order);

if (!$customerEmail) {
    return;
}

This preserves your existing customer notification logic.

3. Add the vendor methods

Add the following methods inside the SpeedafCustomerEmails class, preferably immediately before your existing normaliseSpeedafStatus() method.

/**
 * Send Speedaf shipment-created emails to all relevant vendors.
 *
 * Supports multi-vendor orders. If the same vendor has multiple
 * products in the order, the vendor receives only one email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 */
private function sendVendorShipmentCreatedEmails(
    WC_Order $order,
    string $billCode
): void {
    $vendors = $this->getOrderVendors($order);

    if (empty($vendors)) {
        return;
    }

    foreach ($vendors as $vendor) {
        $sellerId = $vendor['id'];
        $email    = $vendor['email'];
        $shopName = $vendor['shop_name'];

        if (!$email) {
            continue;
        }

        /*
         * Per-vendor duplicate protection.
         */
        $metaKey = '_sefrelshop_vendor_email_shipment_created_' . $sellerId;

        if ($this->wasSent($order, $metaKey)) {
            continue;
        }

        $subject = sprintf(
            'Speedaf shipment created — Order #%s',
            $order->get_order_number()
        );

        $message = $this->buildVendorShipmentCreatedEmail(
            $order,
            $billCode,
            $shopName
        );

        if ($this->sendEmail($email, $subject, $message)) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf shipment notification email sent to vendor #%d (%s).',
                    $sellerId,
                    $email
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf shipment notification email FAILED for vendor #%d (%s). Check WordPress mail/SMTP configuration.',
                    $sellerId,
                    $email
                )
            );
        }
    }
}


/**
 * Send Speedaf tracking-status emails to all relevant vendors.
 *
 * @param WC_Order $order
 * @param string   $status
 * @param array    $event
 */
private function sendVendorTrackingEmails(
    WC_Order $order,
    string $status,
    array $event
): void {
    $vendors = $this->getOrderVendors($order);

    if (empty($vendors)) {
        return;
    }

    $billCode = (string) $order->get_meta(
        '_speedaf_bill_code',
        true
    );

    foreach ($vendors as $vendor) {
        $sellerId = $vendor['id'];
        $email    = $vendor['email'];
        $shopName = $vendor['shop_name'];

        if (!$email) {
            continue;
        }

        /*
         * Each vendor gets an independent status flag.
         *
         * Example:
         * _sefrelshop_vendor_email_speedaf_status_2_123
         */
        $metaKey = sprintf(
            '_sefrelshop_vendor_email_speedaf_status_%s_%d',
            $status,
            $sellerId
        );

        if ($this->wasSent($order, $metaKey)) {
            continue;
        }

        if (in_array($status, ['5', '16'], true)) {
            $subject = sprintf(
                'Speedaf marked order #%s as delivered',
                $order->get_order_number()
            );

            $message = $this->buildVendorDeliveredEmail(
                $order,
                $billCode,
                $event,
                $shopName
            );
        } else {
            $subject = $this->getVendorStatusSubject(
                $order,
                $status
            );

            $message = $this->buildVendorTrackingEmail(
                $order,
                $status,
                $billCode,
                $event,
                $shopName
            );
        }

        if ($this->sendEmail($email, $subject, $message)) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s vendor email sent to vendor #%d (%s).',
                    $status,
                    $sellerId,
                    $email
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s vendor email FAILED for vendor #%d (%s). Check WordPress mail/SMTP configuration.',
                    $status,
                    $sellerId,
                    $email
                )
            );
        }
    }
}


/**
 * Get all unique Dokan vendors represented in an order.
 *
 * @param WC_Order $order
 * @return array
 */
private function getOrderVendors(WC_Order $order): array
{
    $vendors = [];

    foreach ($order->get_items('line_item') as $item) {
        $productId = $item->get_product_id();

        if (!$productId) {
            continue;
        }

        /*
         * Dokan's native vendor lookup.
         */
        if (!function_exists('dokan_get_vendor_by_product')) {
            continue;
        }

        $vendor = dokan_get_vendor_by_product($productId);

        if (!$vendor || !is_object($vendor)) {
            continue;
        }

        if (!method_exists($vendor, 'get_id')) {
            continue;
        }

        $sellerId = absint($vendor->get_id());

        if (!$sellerId) {
            continue;
        }

        /*
         * Avoid sending multiple emails to the same vendor
         * when the order contains multiple products from them.
         */
        if (isset($vendors[$sellerId])) {
            continue;
        }

        $user = get_userdata($sellerId);

        if (!$user || !is_email($user->user_email)) {
            continue;
        }

        /*
         * Get shop name from Dokan when available.
         */
        $shopName = '';

        if (method_exists($vendor, 'get_shop_name')) {
            $shopName = (string) $vendor->get_shop_name();
        }

        if ($shopName === '') {
            $shopName = (string) $user->display_name;
        }

        if ($shopName === '') {
            $shopName = 'Vendor';
        }

        $vendors[$sellerId] = [
            'id'        => $sellerId,
            'email'     => sanitize_email($user->user_email),
            'shop_name' => sanitize_text_field($shopName),
        ];
    }

    return array_values($vendors);
}


/**
 * Get Dokan vendor order dashboard URL.
 *
 * @param WC_Order $order
 * @return string
 */
private function getVendorOrderUrl(WC_Order $order): string
{
    if (function_exists('dokan_get_navigation_url')) {
        $baseUrl = dokan_get_navigation_url('orders');

        if ($baseUrl) {
            return add_query_arg(
                'order_id',
                $order->get_id(),
                $baseUrl
            );
        }
    }

    /*
     * Fallback.
     */
    return $order->get_view_order_url()
        ?: home_url('/');
}


/**
 * Get vendor tracking-status subject.
 *
 * @param WC_Order $order
 * @param string   $status
 * @return string
 */
private function getVendorStatusSubject(
    WC_Order $order,
    string $status
): string {
    $subjects = [
        '1'  => 'Speedaf picked up your SefrelShop order',
        '2'  => 'Your SefrelShop order is in transit',
        '3'  => 'Your SefrelShop order has arrived at pickup point',
        '4'  => 'Your SefrelShop order is out for delivery',
        '5'  => 'Speedaf delivered your SefrelShop order',
        '16' => 'Speedaf delivered your SefrelShop order',
    ];

    return sprintf(
        '%s — Order #%s',
        $subjects[$status]
            ?? 'Speedaf delivery update',
        $order->get_order_number()
    );
}


/**
 * Build vendor shipment-created email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 * @param string   $shopName
 * @return string
 */
private function buildVendorShipmentCreatedEmail(
    WC_Order $order,
    string $billCode,
    string $shopName
): string {
    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        'Speedaf shipment created',
        sprintf(
            '<p>Hello %s,</p>

            <p>A Speedaf shipment has been created for an order
            associated with your SefrelShop store
            <strong>%s</strong>.</p>

            <p>
                <strong>Order:</strong> #%s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>
                The shipment has been successfully created and
                is now being handled by Speedaf.
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($shopName),
            esc_html($order->get_order_number()),
            esc_html($customerName),
            esc_html($billCode),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}


/**
 * Build vendor tracking-status email.
 *
 * @param WC_Order $order
 * @param string   $status
 * @param string   $billCode
 * @param array    $event
 * @param string   $shopName
 * @return string
 */
private function buildVendorTrackingEmail(
    WC_Order $order,
    string $status,
    string $billCode,
    array $event,
    string $shopName
): string {
    $messages = [
        '1' => [
            'heading' => 'Speedaf has picked up the parcel',
            'message' => 'The parcel associated with this order has been picked up by Speedaf.',
        ],
        '2' => [
            'heading' => 'Your parcel is in transit',
            'message' => 'The parcel associated with this order is currently in transit.',
        ],
        '3' => [
            'heading' => 'Your parcel has arrived',
            'message' => 'The parcel has arrived at a Speedaf pickup point.',
        ],
        '4' => [
            'heading' => 'Your parcel is out for delivery',
            'message' => 'The parcel is currently out for delivery.',
        ],
    ];

    $heading = $messages[$status]['heading']
        ?? 'Speedaf delivery update';

    $message = $messages[$status]['message']
        ?? 'The Speedaf delivery status for this order has been updated.';

    $eventMessage = $this->getEventMessage($event);

    $latestUpdate = '';

    if ($eventMessage !== '') {
        $latestUpdate = sprintf(
            '<p>
                <strong>Latest Speedaf update:</strong><br>
                %s
            </p>',
            esc_html($eventMessage)
        );
    }

    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        $heading,
        sprintf(
            '<p>Hello %s,</p>

            <p>
                There is a Speedaf delivery update for an order
                associated with your store
                <strong>%s</strong>.
            </p>

            <p>%s</p>

            %s

            <p>
                <strong>Order:</strong> #%s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($shopName),
            esc_html($message),
            $latestUpdate,
            esc_html($order->get_order_number()),
            esc_html($customerName),
            esc_html($billCode ?: 'Not available'),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}


/**
 * Build vendor delivered email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 * @param array    $event
 * @param string   $shopName
 * @return string
 */
private function buildVendorDeliveredEmail(
    WC_Order $order,
    string $billCode,
    array $event,
    string $shopName
): string {
    $eventMessage = $this->getEventMessage($event);

    $latestUpdate = '';

    if ($eventMessage !== '') {
        $latestUpdate = sprintf(
            '<p>
                <strong>Delivery update:</strong><br>
                %s
            </p>',
            esc_html($eventMessage)
        );
    }

    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        'Speedaf has marked the order as delivered',
        sprintf(
            '<p>Hello %s,</p>

            <p>
                Speedaf has marked order
                <strong>#%s</strong>
                as delivered.
            </p>

            %s

            <p>
                <strong>Store:</strong> %s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>
                Please review the order from your vendor dashboard
                if any further action is required.
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($order->get_order_number()),
            $latestUpdate,
            esc_html($shopName),
            esc_html($customerName),
            esc_html($billCode ?: 'Not available'),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}
4. Important placement

Your class should therefore have this general structure:

class SpeedafCustomerEmails
{
    public function registerHooks(): void
    {
        // existing hooks
    }

    public function handleShipmentCreated(...)
    {
        // existing code
    }

    public function handleTrackingEvent(...)
    {
        // existing code
    }

    public function handleSpeedafStatusMetaChange(...)
    {
        // existing code
    }

    // existing receipt/review methods...

    private function sendVendorShipmentCreatedEmails(...)
    {
        // NEW
    }

    private function sendVendorTrackingEmails(...)
    {
        // NEW
    }

    private function getOrderVendors(...)
    {
        // NEW
    }

    private function getVendorOrderUrl(...)
    {
        // NEW
    }

    private function getVendorStatusSubject(...)
    {
        // NEW
    }

    private function buildVendorShipmentCreatedEmail(...)
    {
        // NEW
    }

    private function buildVendorTrackingEmail(...)
    {
        // NEW
    }

    private function buildVendorDeliveredEmail(...)
    {
        // NEW
    }

    // existing normaliseSpeedafStatus()
    // existing getCustomerEmail()
    // existing wasSent()
    // existing markSent()
    // existing sendEmail()
    // existing customer email builders...
}
One important behaviour

For a multi-vendor order such as:

Order #27538

Vendor A → Product 1
Vendor A → Product 2
Vendor B → Product 3
Vendor C → Product 4

the system will send:

Vendor A → 1 email
Vendor B → 1 email
Vendor C → 1 email
Customer → 1 email

rather than sending Vendor A two emails.

The vendor status flags are also independent:

_sefrelshop_vendor_email_shipment_created_123

_sefrelshop_vendor_email_speedaf_status_1_123
_sefrelshop_vendor_email_speedaf_status_2_123
_sefrelshop_vendor_email_speedaf_status_3_123
_sefrelshop_vendor_email_speedaf_status_4_123
_sefrelshop_vendor_email_speedaf_status_5_123

where 123 is the Dokan vendor/user ID.

This is important because your existing customer status flags are global to the order; using the same flags for vendors would incorrectly suppress notifications when multiple vendors are involved. Your current class already uses global customer flags such as _sefrelshop_email_speedaf_status_{status}, so the vendor flags need to remain separate.

No change is required to sefrelshop-speedaf.php because your SpeedafCustomerEmails class is already loaded and registered.

Pasted code(9).php
File
Here is the code. Check if it's correct


Yes. I checked the uploaded class-speedaf-customer-emails.php implementation, including the newly added vendor-notification logic.

Verdict

The PHP syntax is correct — I ran a PHP syntax check and it returned:

No syntax errors detected

The vendor notification implementation is also structurally correct.

What is correctly implemented
Vendor shipment-created email
Triggered independently of the customer email.
Sends to every unique vendor in the order.
Prevents duplicate emails per vendor.
Includes order number, customer, Speedaf waybill and vendor dashboard button.
Vendor tracking emails
Statuses 1, 2, 3, 4, 5, 16 are supported.
Each vendor has an independent sent flag, e.g.:
_sefrelshop_vendor_email_speedaf_status_2_123
This is important for multi-vendor orders.
Multi-vendor handling
It loops through order line items.
Uses dokan_get_vendor_by_product().
Deduplicates vendors by seller ID, so a vendor with three products receives one email, not three.
Vendor email address
Correctly obtains the vendor's WordPress user account and validates user_email.
Customer emails remain independent
The vendor emails are sent before the customer duplicate check, so a missing customer email will not prevent vendor notifications.
Likewise, vendor tracking notifications are independent of customer tracking notifications.
Same mail transport
Both customer and vendor emails use the same wp_mail() pipeline and sender address.
One thing I would change

There is one architectural issue I recommend fixing before production testing.

Your vendor functions are currently declared inside the class, but their indentation is inconsistent:

private function sendVendorShipmentCreatedEmails(...)

and the subsequent methods are positioned at the class level rather than following the indentation style of the rest of the class. This does not break PHP; it is purely formatting/maintainability.

More importantly, the fallback in getVendorOrderUrl():

return $order->get_view_order_url()
    ?: home_url('/');

means that if Dokan's navigation URL isn't available, the vendor could receive a customer-facing order URL.

I would rather make the fallback the vendor dashboard/orders page, not the customer's order page.

Another point to test

Your status handling intentionally treats both 5 and 16 as delivered:

if (in_array($status, ['5', '16'], true))

and the vendor email has separate duplicate flags for each status.

Therefore, if Speedaf sends status 5 and later status 16 for the same shipment, the vendor can receive two delivered emails.

If your Speedaf integration uses 5 and 16 as alternative delivered-status codes rather than sequential events, I would change the duplicate logic so that once a vendor has received a delivered notification, another delivered notification is not sent.

Overall

I would not reject this code. It is functional and syntactically valid.

The core workflow is now:

Speedaf shipment created → vendor email + customer email

Speedaf status 1 → vendor + customer

Status 2 → vendor + customer

Status 3 → vendor + customer

Status 4 → vendor + customer

Status 5/16 → vendor + customer

with separate duplicate protection for vendors and customers.

The next thing I would do is test it with an actual multi-vendor order, because that will verify the most important part that cannot be proven by static code inspection: whether dokan_get_vendor_by_product() resolves the correct seller for your actual SefrelShop/Dokan order structure.

I will test it soon. At this stage, I want us to track where we are from the start of this project: what we've achieved, what's left, and what we need to consider for improvement. And develop a robust README.md for the project. 
Analysis paused

Yes. We have reached the point where we should stop treating this as a collection of individual features and document it as a complete logistics system.

I reconstructed the project from its early Speedaf API work through the current customer/vendor workflow. The key conclusion is:

The core integration is substantially built. The next phase is reliability, end-to-end validation, marketplace edge cases, and preparing the architecture for SefrelGO.

Where we are now

Completed

Speedaf API connectivity and encryption/signing.
Shipment creation and bill-code persistence.
WooCommerce → logistics → Speedaf architecture.
Category and weight validation foundations.
Speedaf tracking callback.
Status processing for 1, 2, 3, 4, 5, 16.
Customer tracking.
Delivered order workflow.
72-hour inspection/review window.
Customer receipt confirmation.
Problem reporting.
Verified WooCommerce product reviews.
Native Dokan vendor-review creation.
Customer shipment/tracking emails.
Vendor shipment/tracking emails.
Multi-vendor vendor resolution/deduplication.
Event-driven hooks between the major components.
The delivery-confirmation display bug has been fixed.
The product-review event placement has been corrected.
The major things still to prove/harden
Full end-to-end production test
Duplicate shipment/idempotency protection
Status 5/16 delivered-event deduplication
Real multi-vendor order testing
Correct vendor dashboard URL fallback
Speedaf callback authentication/security
API timeout/retry/recovery behaviour
Provider-success/local-save-failure recovery
Structured event logging
Clear multi-vendor shipment model
Failed-delivery and returns architecture
Provider-independent SefrelGO abstractions

One particularly important architectural issue is that marketplace logistics is not the same as ordinary WooCommerce shipping. We need to establish whether an order containing Vendor A + Vendor B becomes one Speedaf shipment or separate vendor shipments/bill codes. That decision will affect tracking, vendor notifications, returns, delivery confirmation and accounting.

I have also documented the longer-term SefrelGO direction, where Speedaf becomes the first provider implementation rather than the architecture itself.

README

I've created the first comprehensive project README covering:

project history;
architecture;
current components;
API integration;
shipment lifecycle;
callback lifecycle;
customer tracking;
delivery confirmation;
product reviews;
Dokan vendor reviews;
customer/vendor notifications;
current files;
known technical debt;
security considerations;
idempotency;
multi-vendor logistics;
testing strategy;
production-readiness criteria;
deployment checklist;
SefrelGO evolution;
development rules;
immediate next sprint;
change log.

Download the README.md

I recommend we treat this README as the baseline document from this point forward: after each significant change, we update the relevant section rather than allowing the architecture and documentation to drift apart.

The next practical step should be to use the README's test plan as our formal QA checklist while you run the current implementation.

README.md
Document
Chat paused until usage resets at 11:08 PM
You’ve reached the limit for chats that include files or images. Start a new text-only chat or upgrade to continue now.
New chat
Upgrade

Library
/
README.md
SefrelShop Speedaf Logistics Plugin

Project: SefrelShop → Speedaf Express integration
Long-term direction: SefrelGO logistics infrastructure/API platform
Current role: Speedaf is the first logistics connector; SefrelShop is the first production client.

1. Project Overview

The SefrelShop Speedaf plugin connects the SefrelShop WooCommerce marketplace to Speedaf Express so that orders can move from checkout through shipment creation, tracking, delivery confirmation, customer communication, and vendor communication.

The project has evolved beyond a simple shipping plugin.

The long-term architecture is intended to become SefrelGO: a logistics infrastructure/API layer capable of connecting SefrelShop and, eventually, other commerce platforms to multiple logistics providers.

Current strategic position
SefrelShop
    │
    ▼
SefrelShop Logistics Layer
    │
    ├── Speedaf Provider
    │      ├── Rate / shipping integration
    │      ├── Shipment creation
    │      ├── Tracking
    │      └── Delivery events
    │
    └── Future Providers
           ├── Provider B
           ├── Provider C
           └── Provider N

Future:
SefrelGO Logistics Infrastructure / API

Speedaf should therefore be treated as a provider implementation, not as the entire logistics architecture.

2. Current Project Status
Overall status

Core integration: substantially implemented
Production API connectivity: working
Shipment creation: working
Speedaf callbacks: working
Customer tracking: working
Delivery confirmation/review workflow: implemented
Dokan vendor review integration: implemented
Customer email automation: implemented
Vendor email automation: implemented
End-to-end production validation: pending final testing

The project is currently at the stage where the emphasis should shift from adding major features to:

end-to-end testing;
edge-case handling;
duplicate/event protection;
multi-vendor validation;
security and reliability;
operational monitoring;
documentation;
preparing the architecture for additional logistics providers.
3. Development History
Phase 1 — Speedaf API Foundation

The first objective was to establish direct communication with Speedaf's API.

Completed
Speedaf production/sandbox API configuration.
API client architecture.
DES encryption/decryption handling.
MD5 signing.
Base64 processing.
Request construction.
Response decryption.
API error handling foundation.
Create-order API integration.
Tracking/API connectivity foundation.
API base
https://apis.speedaf.com/
Shipment creation endpoint
POST /open-api/express/order/createOrder
Important successful test

A successful Speedaf order was created during development:

Speedaf billCode:
NG020001769120

Speedaf customer code:
NG000025

Earlier development testing also successfully produced:

billCode:
NG020001648948

customerOrderNo:
TEST-1783274442

These results established that the application could successfully construct, sign, encrypt, submit, and process Speedaf shipment requests.

4. Phase 2 — Logistics Architecture

The project was deliberately separated into reusable layers rather than placing Speedaf-specific logic throughout WooCommerce code.

Core architecture

Important classes include:

SefrelShopPlugin
SpeedafConfig
SpeedafEncryption
SpeedafApi

ShippingProvider
SpeedafProvider
LogisticsManager
ShippingRouter

CategoryMapper
OrderBuilder
WeightValidator

OrderProcessor
Responsibility overview
Component	Responsibility
SefrelShopPlugin	Plugin bootstrap/orchestration
SpeedafConfig	Speedaf configuration
SpeedafEncryption	Request encryption/signing and response handling
SpeedafApi	HTTP/API communication
ShippingProvider	Provider abstraction
SpeedafProvider	Speedaf implementation
LogisticsManager	Logistics orchestration
ShippingRouter	Provider/routing decisions
CategoryMapper	WooCommerce → logistics category mapping
OrderBuilder	Builds provider shipment payload
WeightValidator	Shipment weight validation
OrderProcessor	Connects WooCommerce order lifecycle to shipment creation

This separation is important for the future SefrelGO architecture.

5. Product/Category Restrictions

A major business requirement is that Speedaf must not receive unsupported WooCommerce categories.

In particular, unsupported categories such as:

food;
consumables;
other restricted/non-supported categories;

must not accidentally be submitted to Speedaf.

The category mapping/validation layer therefore exists before provider shipment creation.

This must remain a hard validation boundary.

6. Phase 3 — WooCommerce Order Integration

The next stage connected the logistics layer to WooCommerce.

The current flow is conceptually:

WooCommerce Order
       │
       ▼
Order Processor
       │
       ▼
Validate order
       │
       ├── Validate products/categories
       ├── Validate weight
       ├── Validate addresses
       └── Build shipment payload
       │
       ▼
Logistics Manager
       │
       ▼
Speedaf Provider
       │
       ▼
Speedaf API
       │
       ▼
Shipment created
       │
       ▼
Save Speedaf bill code

The shipment-created event is exposed through:

do_action(
    'sefrelshop_speedaf_shipment_created',
    $order,
    $billCode
);

This event is important because downstream systems such as customer/vendor notifications should not have to be embedded inside the shipment creation logic.

7. Shipment Metadata

The WooCommerce order stores Speedaf-related information so that the shipment can be tracked independently from the original API request.

Important metadata includes:

_speedaf_bill_code
_speedaf_status

Additional order metadata is used by the delivery/review/email workflows.

Raw API data and operational metadata should be treated carefully because the order record is also an operational audit trail.

8. Phase 4 — Speedaf Tracking Callback

Speedaf callbacks were implemented so that Speedaf can notify SefrelShop about shipment status changes.

Callback endpoint
https://sefrelshop.com/wp-json/sefrelshop/v1/speedaf/tracking

The callback processing pipeline is:

Speedaf
   │
   ▼
REST callback
   │
   ▼
Validate callback
   │
   ▼
Resolve WooCommerce order
   │
   ▼
Normalize status/event
   │
   ▼
Update order tracking state
   │
   ▼
Fire tracking event
   │
   ├── Customer notifications
   ├── Vendor notifications
   └── Other downstream workflows

After successful processing, the plugin fires:

do_action(
    'sefrelshop_speedaf_tracking_event_processed',
    $order,
    $event
);
9. Confirmed Speedaf Callback Statuses

The following statuses have been tested successfully:

Speedaf status	Meaning
1	Picked
2	Departed / In transit
3	Arrived at pickup point
4	Out for delivery
5	Delivered variant
16	Delivered variant

The distinction between statuses 5 and 16 requires special handling because both currently represent delivery completion variants.

Important future consideration

If 5 and 16 represent alternative delivered states rather than two separate delivery events, notification deduplication should treat them as one logical state.

Otherwise an order could potentially generate:

Delivered email — status 5
Delivered email — status 16

even though the customer only needs one delivery notification.

10. Phase 5 — Customer Tracking

Customer-facing Speedaf tracking was implemented on the WooCommerce order experience.

The customer can see the Speedaf shipment information associated with the order rather than having to manually search for the waybill.

The tracking layer uses the saved Speedaf bill code and status information.

This provides the foundation for a more complete customer logistics experience.

11. Phase 6 — Delivery Confirmation

A dedicated delivery confirmation workflow was implemented.

Class:

includes/class-speedaf-delivery-confirmation.php

A custom WooCommerce status is used:

wc-delivered

The delivery workflow includes:

Speedaf says Delivered
        │
        ▼
Order becomes Delivered
        │
        ▼
Customer receives delivery experience
        │
        ├── Confirm order received
        │
        ├── Report a problem
        │
        └── Submit product review
Inspection window

The current inspection/review window is:

3 days / 72 hours

After the inspection period, the order can automatically progress if no unresolved issue remains.

12. Customer Product Reviews

The delivery workflow is connected to WooCommerce's native product review system.

Product reviews use:

comment_type = review

Review metadata includes:

rating
verified
_sefrelshop_order_id
_sefrelshop_verified_purchase

The order also records reviewed products using:

_sefrelshop_reviewed_products

The system can identify verified purchases and associate reviews with the originating order.

Review rating behaviour

The current implementation treats:

rating >= 2

as a positive-enough review for the early completion workflow.

This business rule should remain documented and should be reconsidered later if product/customer support requirements change.

13. Review Event Architecture

After a WooCommerce product review is successfully created, the system fires:

do_action(
    'sefrelshop_product_review_submitted',
    $order,
    $purchased_product_id,
    $rating,
    $review_id
);

This event is intentionally placed after the review/order metadata has been saved and before the positive-review completion redirect.

This allows other modules to react to a verified product review without modifying the delivery-confirmation class directly.

14. Phase 7 — Dokan Vendor Review Integration

Dokan's Store Review module is enabled.

Dokan uses:

post_type:
dokan_store_reviews

with metadata:

store_id
rating

The plugin now automatically creates a native Dokan vendor/store review when a customer submits a verified WooCommerce product review.

Class:

includes/class-sefrelshop-dokan-vendor-review.php
Integration flow
Customer submits WooCommerce product review
             │
             ▼
Verified purchase review event
             │
             ▼
Find product vendor
             │
             ▼
Create native Dokan store review
             │
             ├── store_id
             ├── rating
             ├── order reference
             ├── product reference
             └── verified purchase metadata
             │
             ▼
Invalidate Dokan review cache
             │
             ▼
Dokan vendor rating updates

The implementation also protects against duplicate vendor reviews.

15. Phase 8 — Customer Email Automation

Customer email automation was added as a separate event-driven layer.

Class:

includes/class-speedaf-customer-emails.php

The class listens to events including:

sefrelshop_speedaf_shipment_created
sefrelshop_speedaf_tracking_event_processed
updated_post_meta
added_post_meta
sefrelshop_order_receipt_confirmed
sefrelshop_product_review_submitted
sefrelshop_review_reminder
Customer communication lifecycle
Shipment created
      │
      ▼
Customer shipment email
      │
      ▼
Tracking status changes
      │
      ├── Picked
      ├── In transit
      ├── Pickup point
      ├── Out for delivery
      └── Delivered
      │
      ▼
Customer confirms receipt
      │
      ▼
Review invitation
      │
      ▼
Review reminder(s)

Customer emails include the Speedaf waybill/tracking experience where appropriate.

16. Phase 9 — Vendor Email Automation

Vendor notifications were subsequently added to the same email infrastructure.

The vendor notification system is designed to work independently of customer notifications.

Vendor shipment-created notification

When a Speedaf shipment is successfully created, vendors associated with the order can receive a shipment-created email.

Vendor tracking notifications

Vendors can receive status updates for:

1 — Picked
2 — In transit
3 — Arrived at pickup point
4 — Out for delivery
5 — Delivered
16 — Delivered
Multi-vendor support

The system resolves vendors from order line items using:

dokan_get_vendor_by_product($productId)

Vendor IDs are deduplicated so that a vendor appearing on multiple products in the same order does not receive multiple identical notifications for the same event.

17. Vendor Notification Architecture

The vendor email system currently includes methods for:

sendVendorShipmentCreatedEmails()
sendVendorTrackingEmails()
getOrderVendors()
getVendorOrderUrl()
getVendorStatusSubject()
buildVendorShipmentCreatedEmail()
buildVendorTrackingEmail()
buildVendorDeliveredEmail()

Vendor information is resolved from the WordPress/Dokan vendor account.

The vendor's:

seller/user ID;
email address;
shop name;

are used to construct the notification.

18. Current Event-Driven Architecture

The project now follows an event-driven pattern in several important areas.

                         ┌────────────────────┐
                         │ WooCommerce Order  │
                         └─────────┬──────────┘
                                   │
                                   ▼
                         ┌────────────────────┐
                         │  Order Processor   │
                         └─────────┬──────────┘
                                   │
                                   ▼
                         ┌────────────────────┐
                         │ Logistics Manager  │
                         └─────────┬──────────┘
                                   │
                                   ▼
                         ┌────────────────────┐
                         │ Speedaf Provider   │
                         └─────────┬──────────┘
                                   │
                                   ▼
                         ┌────────────────────┐
                         │   Speedaf API      │
                         └─────────┬──────────┘
                                   │
                         Shipment Created
                                   │
                                   ▼
                    sefrelshop_speedaf_shipment_created
                                   │
                  ┌────────────────┼────────────────┐
                  ▼                ▼                ▼
             Customer Email   Vendor Email      Other hooks


Speedaf Callback
       │
       ▼
Tracking Callback
       │
       ▼
Status Normalisation
       │
       ▼
sefrelshop_speedaf_tracking_event_processed
       │
       ├──────────────┬───────────────┐
       ▼              ▼               ▼
 Customer Email   Vendor Email   Future Automation

This architecture should be preserved as the system grows.

19. Current Important Files

The following files/classes are currently central to the integration.

sefrelshop-speedaf.php

includes/
├── class-plugin.php
├── class-order-processor.php
├── class-speedaf-api.php
├── class-speedaf-config.php
├── class-speedaf-encryption.php
├── class-speedaf-tracking-sync.php
├── class-speedaf-tracking-callback.php
├── class-speedaf-customer-tracking.php
├── class-speedaf-delivery-confirmation.php
├── class-speedaf-customer-emails.php
├── class-sefrelshop-dokan-vendor-review.php
│
└── logistics/
    ├── class-shipping-provider.php
    ├── class-speedaf-provider.php
    ├── class-shipping-router.php
    ├── class-logistics-manager.php
    ├── class-order-builder.php
    ├── class-category-mapper.php
    └── class-weight-validator.php

The exact filename set should be verified against the deployed plugin before future refactoring.

20. Main Plugin Bootstrap

The main plugin currently loads the Dokan vendor review integration:

require_once __DIR__ . '/includes/class-sefrelshop-dokan-vendor-review.php';

and registers it during WordPress initialization.

The customer email class is also loaded and registered during plugin initialization.

This means the integration is modular rather than being manually invoked from individual pages.

21. Bug Fixes Already Completed
Delivery confirmation display bug

A bug was found where:

✓ Order Received

could appear before the customer had actually confirmed receipt.

Cause

The HTML/PHP conditional structure in the delivery confirmation class was malformed/misplaced.

Fix

The confirmation branch was corrected so that:

Before confirmation:
    Confirm receipt
    Report a problem
    Review workflow

After confirmation:
    ✓ Order Received

The PHP syntax was checked after the correction.

22. Review Hook Placement Fix

The product-review event hook was initially important enough to verify carefully.

It is now placed after:

$order->save();

and before the positive-review completion redirect.

This ensures that the Dokan vendor-review integration receives the review event for all applicable ratings, including ratings from 2–5 stars.

23. Current Known Considerations

These are not necessarily failures. They are areas that must be verified or hardened.

23.1 Delivered status deduplication

Statuses 5 and 16 may both represent delivery completion.

The notification system should treat them as a logical delivered state where appropriate.

Recommended logical state:

DELIVERED = {5, 16}

Then protect against duplicate delivery emails.

23.2 Vendor dashboard URL fallback

The vendor email system currently prefers the Dokan orders URL with:

order_id

If that URL cannot be generated, the fallback currently needs careful consideration because a customer-facing WooCommerce order URL is not equivalent to a vendor dashboard order URL.

This should be corrected before considering the vendor notification system completely hardened.

23.3 Multi-vendor production test

Static code review cannot prove that every real order composition resolves correctly.

A real test should include:

Vendor A → Product A
Vendor B → Product B
Vendor A → Product C

Then verify:

Vendor A receives one notification per logical event.
Vendor B receives one notification per logical event.
Customer receives one notification per logical event.
No vendor receives another vendor's information.
Correct vendor dashboard link is generated.
23.4 Duplicate event protection

Speedaf callbacks and WordPress metadata hooks can potentially expose the same logical event through more than one path.

The project therefore needs systematic idempotency.

For every external event, ask:

Have we already processed this event?

The answer should be stored in a reliable event/delivery record or equivalent metadata.

23.5 API failure handling

Production-grade handling should cover:

connection failures;
timeout;
malformed response;
Speedaf API error;
encryption/decryption failure;
invalid credentials;
invalid signature;
duplicate order submission;
partial provider response;
WordPress database failure after provider success.

The last case is particularly important:

Speedaf accepts order
        │
        ▼
WordPress fails before saving bill code

A retry could accidentally create a second shipment unless the system can identify the original request.

24. Duplicate Shipment Protection

This is one of the most important remaining reliability considerations.

Before creating a shipment, the system should be able to determine:

Has this WooCommerce order already been submitted successfully?

Possible identity keys include:

WooCommerce order ID
+
provider
+
provider customerOrderNo

The system should never create another provider shipment simply because the first response was lost.

A provider request should be idempotent wherever the Speedaf API permits it.

25. Security Considerations

The integration handles:

customer names;
phone numbers;
addresses;
order information;
logistics tracking data;
API credentials.

Therefore:

Credentials

Never expose API credentials in:

frontend JavaScript;
emails;
logs;
public REST responses;
Git repositories.
Logging

Logs should avoid unnecessarily storing:

full customer addresses;
phone numbers;
authentication credentials;
complete sensitive payloads.
REST callback

The callback endpoint should validate that incoming data is genuinely from Speedaf according to the provider's supported authentication/verification mechanism.

IP allowlisting should be considered where Speedaf's infrastructure and deployment model support it.

26. Order State Model

The project should maintain a clear distinction between:

WooCommerce state
Pending
Processing
Delivered
Completed
Problem / support state
Speedaf state
Created
Picked
In transit
Pickup point
Out for delivery
Delivered

These states should not be treated as interchangeable.

A logistics provider status is an external event.

A WooCommerce order status is a business workflow state.

The integration layer maps between them.

27. Customer Problem / Exception Workflow

The current delivery workflow allows a customer to report a problem after delivery.

This should eventually become a formal exception workflow.

At minimum, the system should distinguish:

Delivered — customer has no issue

Delivered — customer reported issue

Delivered — issue resolved

Delivered — issue unresolved

Completed

This will become increasingly important if SefrelShop scales.

28. Vendor vs Customer Responsibility

A marketplace order introduces a key distinction:

Customer
    │
    ▼
SefrelShop marketplace
    │
    ├── Vendor A
    ├── Vendor B
    └── Vendor C
         │
         ▼
      Logistics

Future improvements should clearly define:

who owns the shipment;
who pays shipping;
who is responsible for incorrect products;
who handles failed delivery;
who handles returns;
who handles damaged goods;
what happens when one order contains multiple vendors;
whether vendors receive separate shipments/bill codes;
how partial delivery is represented.

This is especially important before scaling beyond single-vendor test orders.

29. Multi-Vendor Shipment Architecture

This is a major architectural consideration.

A WooCommerce order may contain products from multiple vendors.

There are two different concepts:

Model A — One shipment
Order #123
 ├── Vendor A
 ├── Vendor B
 └── Vendor C
       │
       ▼
One Speedaf shipment
Model B — Multiple shipments
Order #123
 ├── Vendor A → Speedaf Bill A
 ├── Vendor B → Speedaf Bill B
 └── Vendor C → Speedaf Bill C

The system must explicitly define which model is supported.

The current vendor email layer can identify multiple vendors, but that does not by itself mean Speedaf shipment creation is correctly partitioned per vendor.

This should be verified before treating multi-vendor logistics as production-complete.

30. Returns and Failed Delivery

A mature logistics platform must eventually support:

Delivery failed
      │
      ├── Customer unavailable
      ├── Incorrect address
      ├── Refused shipment
      └── Other provider exception

and:

Return requested
      │
      ▼
Return approved
      │
      ▼
Return shipment
      │
      ▼
Vendor receives returned product

These workflows are not yet the primary completed scope and should be treated as future logistics capabilities.

31. Testing Strategy

The project should move from feature testing to scenario testing.

Test 1 — Single-vendor successful order

Verify:

WooCommerce order;
Speedaf shipment creation;
bill code;
tracking;
customer email;
vendor email;
delivered event;
customer receipt confirmation;
product review;
Dokan vendor review;
order completion.
Test 2 — Multi-vendor order

Verify:

vendor resolution;
vendor deduplication;
shipment ownership;
bill code ownership;
vendor emails;
customer emails;
vendor dashboard links.
Test 3 — Duplicate callback

Send the same Speedaf tracking event more than once.

Expected:

One logical event
=
One state transition
=
One notification
Test 4 — Status 5 → 16

Verify that a delivered shipment does not generate two customer/vendor delivery notifications if both statuses refer to the same delivery.

Test 5 — API timeout

Simulate an API timeout.

Verify:

order is not incorrectly marked shipped;
retry behaviour is safe;
no duplicate shipment is created;
useful diagnostic information is recorded.
Test 6 — Provider success / WordPress save failure

This is a critical resilience test.

Simulate:

Speedaf accepts request
        ↓
Speedaf creates shipment
        ↓
WordPress fails to save response

Verify recovery without creating a duplicate shipment.

Test 7 — Unsupported category

Attempt to submit an order containing an unsupported product category.

Expected:

Shipment rejected before provider submission.
Test 8 — Invalid weight

Verify that invalid/unsupported shipment weight cannot reach Speedaf.

Test 9 — Customer reports a problem

Verify:

problem state;
customer messaging;
vendor visibility where appropriate;
automatic completion is prevented while the issue is unresolved.
Test 10 — Review duplication

Submit/retry the same review flow.

Expected:

One WooCommerce review
One Dokan store review
32. Operational Monitoring

Before large-scale production use, the plugin should have a clear operational monitoring strategy.

Important events to monitor:

Shipment creation success
Shipment creation failure
Tracking callback success
Tracking callback failure
Unknown Speedaf status
Duplicate event
Duplicate shipment attempt
Delivery confirmation
Customer problem report
Product review
Dokan vendor review creation
Customer email failure
Vendor email failure

Where possible, these should be represented as structured logs/events rather than only human-readable order notes.

33. Recommended Future Logging Model

A future event log could contain:

event_id
order_id
provider
provider_event_id
event_type
provider_status
processed_at
processing_result
error_code

This would provide true idempotency and an audit trail.

A dedicated event table becomes increasingly attractive as order volume grows.

34. SefrelGO Evolution

The current plugin should not become permanently tied to Speedaf.

The desired architecture is:

                   SefrelGO
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
       Speedaf     Provider B   Provider C
          │
          ▼
     SefrelShop

The provider interface should abstract operations such as:

getRates()
createShipment()
cancelShipment()
trackShipment()
getShipment()
handleWebhook()

Not every provider will support every capability.

Therefore the provider contract should allow capability discovery.

Example:

supports('rates')
supports('tracking')
supports('cancellation')
supports('returns')
supports('webhooks')
35. Recommended SefrelGO Provider Interface

Conceptually:

interface ShippingProviderInterface
{
    public function getRates(array $shipment): array;

    public function createShipment(array $shipment): ProviderShipmentResult;

    public function trackShipment(string $trackingNumber): TrackingResult;

    public function cancelShipment(string $shipmentId): ProviderResult;

    public function handleWebhook(array $payload): ProviderEvent;

    public function supports(string $capability): bool;
}

The exact implementation should follow the project's existing class architecture rather than being introduced blindly.

36. Data Normalisation

Each provider may use different terminology.

For example:

Speedaf:
billCode

Provider B:
trackingNumber

Provider C:
waybill

SefrelGO should expose a normalized internal representation:

shipment_id
tracking_number
provider
status
status_code
status_label
event_time
location
raw_provider_status

This allows the SefrelShop frontend to remain provider-agnostic.

37. Status Normalisation

The same principle should apply to statuses.

Instead of exposing only:

Speedaf status 1
Speedaf status 2
Speedaf status 3

the internal system should eventually map them to normalized states:

CREATED
PICKED_UP
IN_TRANSIT
AT_PICKUP_POINT
OUT_FOR_DELIVERY
DELIVERED
FAILED
RETURNED
CANCELLED

The original provider status should still be preserved for debugging/audit purposes.

38. Current Architecture Strengths

The project already has several strong foundations:

provider abstraction;
Speedaf-specific provider implementation;
encryption/signing separation;
order payload construction separated from API transport;
tracking callback separation;
event-driven notifications;
customer/vendor communication separation;
WooCommerce native review integration;
Dokan native store review integration;
multi-vendor vendor resolution;
duplicate protection at several workflow levels;
delivery confirmation workflow;
customer tracking experience.

The next stage should therefore focus heavily on reliability and operational correctness, not simply adding more UI.

39. Current Technical Debt / Improvement Areas

Prioritise these areas:

High priority
End-to-end production test.
Duplicate shipment/idempotency protection.
Delivered-status (5/16) deduplication.
Multi-vendor shipment model verification.
Vendor dashboard URL fallback correction.
Callback authentication/validation.
API failure/retry handling.
Provider-success / local-save-failure recovery.
Structured event logging.
Medium priority
Normalized internal logistics statuses.
Formal shipment model.
Better exception/problem states.
Returns workflow.
Failed-delivery workflow.
Provider capability system.
Automated integration tests.
Long-term
SefrelGO provider registry.
Additional logistics providers.
SefrelGO external API.
Unified shipment tracking across providers.
Provider performance analytics.
Logistics cost/rate optimisation.
40. Definition of "Production Ready"

The Speedaf integration should not be considered fully production-ready merely because the API successfully creates a shipment.

A production-ready implementation should satisfy:

API connectivity
        +
Correct shipment payload
        +
Validation
        +
Idempotency
        +
Tracking
        +
Callback security
        +
Status normalisation
        +
Customer communication
        +
Vendor communication
        +
Exception handling
        +
Delivery workflow
        +
Review workflow
        +
Multi-vendor correctness
        +
Operational logging
        +
Recovery from failures
41. Deployment Checklist

Before production deployment:

Production Speedaf credentials verified.

API signing verified.

Encryption/decryption verified.

Callback URL verified.

Callback authentication/validation verified.

WooCommerce order creation tested.

Unsupported category validation tested.

Weight validation tested.

Shipment creation tested.

Bill code saved correctly.

Customer tracking tested.

Status 1 tested.

Status 2 tested.

Status 3 tested.

Status 4 tested.

Status 5 tested.

Status 16 tested.

Delivered notification deduplication tested.

Customer emails tested.

Vendor emails tested.

Multi-vendor order tested.

Vendor dashboard link tested.

Delivery confirmation tested.

Problem report tested.

Product review tested.

Dokan vendor review tested.

Duplicate review protection tested.

72-hour inspection/completion behaviour tested.

API timeout tested.

Duplicate shipment scenario tested.

Callback duplication tested.

Error logging verified.

Production credentials excluded from source control.

Backup/rollback plan confirmed.

42. Current Project File Structure

A target structure is:

sefrelshop-speedaf-plugin/
│
├── sefrelshop-speedaf.php
├── README.md
│
├── includes/
│   ├── class-plugin.php
│   ├── class-order-processor.php
│   ├── class-speedaf-api.php
│   ├── class-speedaf-config.php
│   ├── class-speedaf-encryption.php
│   ├── class-speedaf-tracking-sync.php
│   ├── class-speedaf-tracking-callback.php
│   ├── class-speedaf-customer-tracking.php
│   ├── class-speedaf-delivery-confirmation.php
│   ├── class-speedaf-customer-emails.php
│   ├── class-sefrelshop-dokan-vendor-review.php
│   │
│   └── logistics/
│       ├── class-shipping-provider.php
│       ├── class-speedaf-provider.php
│       ├── class-shipping-router.php
│       ├── class-logistics-manager.php
│       ├── class-order-builder.php
│       ├── class-category-mapper.php
│       └── class-weight-validator.php
│
└── tests/
    └── (future automated/integration tests)

The actual repository/deployment tree should be kept synchronized with this documentation.

43. Development Rules Going Forward
Rule 1 — Keep provider-specific code isolated

Do not spread Speedaf-specific API assumptions throughout WooCommerce code.

Rule 2 — Prefer events over direct coupling

If a component can react to:

do_action(...)

instead of being hard-coded into another class, prefer the event architecture.

Rule 3 — Make external events idempotent

Every callback or provider event should be safe to receive more than once.

Rule 4 — Preserve provider raw data

Normalize statuses for application use, but preserve the original provider status/event for diagnostics.

Rule 5 — Never trust external provider data blindly

Validate:

order identifiers;
tracking identifiers;
status;
timestamps;
signatures/authentication;
payload structure.
Rule 6 — Protect customer and vendor data

Only expose data to the party who should receive it.

Rule 7 — Test multi-vendor scenarios

Marketplace logistics is fundamentally different from single-store logistics.

Rule 8 — Document architectural decisions

When a significant design decision is made, update this README.

44. Immediate Next Sprint

The recommended immediate sequence is:

Step 1 — Run the current test

Test the current implementation without changing the architecture unnecessarily.

Step 2 — Record actual results

For each test record:

Test
Expected result
Actual result
Pass/Fail
Error
Relevant order ID
Speedaf bill code
Provider status
Step 3 — Fix only confirmed issues

Avoid refactoring working code based solely on theoretical concerns.

Step 4 — Harden idempotency

Address duplicate shipment and duplicate event scenarios.

Step 5 — Validate multi-vendor behaviour

This is essential for SefrelShop because it is a marketplace.

Step 6 — Harden callback security

Confirm the exact Speedaf callback authentication requirements and implement them.

Step 7 — Add structured operational logging

Make production diagnosis possible without exposing sensitive customer information.

Step 8 — Freeze a stable Speedaf provider

Once the Speedaf integration is stable, treat it as the reference implementation for SefrelGO.

45. Project Definition
What has been built

A functional logistics integration that connects:

WooCommerce
   ↓
SefrelShop Logistics Layer
   ↓
Speedaf
   ↓
Shipment creation
   ↓
Tracking
   ↓
Delivery
   ↓
Customer confirmation/review
   ↓
Dokan vendor review
   ↓
Customer/vendor communication
What remains

The central remaining challenge is no longer proving that Speedaf can communicate with SefrelShop.

The remaining challenge is proving that the entire system behaves correctly, securely, idempotently, and predictably under real marketplace conditions.

That distinction should guide the next development phase.

46. Change Log
Current milestone
Speedaf Integration — Core + Customer/Vendor Workflow

Implemented:

Speedaf API connectivity.
Request encryption/signing.
Shipment creation.
Bill-code persistence.
Tracking callback.
Tracking status processing.
Customer tracking.
Delivered workflow.
Customer receipt confirmation.
Customer problem reporting.
Product review workflow.
Verified purchase metadata.
Dokan vendor review synchronization.
Customer shipment/tracking emails.
Vendor shipment/tracking emails.
Multi-vendor vendor notification resolution.
Duplicate protection for several notification/review workflows.
Delivery confirmation bug fix.
Review hook placement correction.
Pending validation
Full production end-to-end test.
Multi-vendor logistics validation.
Delivered status 5/16 deduplication.
Complete shipment idempotency.
Callback security validation.
Failure/retry recovery.
Structured event logging.
Production hardening.
47. Notes for Future Developers

Do not assume that a successful Speedaf API response means the workflow is complete.

Always trace the full lifecycle:

Order
→ validation
→ provider request
→ provider response
→ local persistence
→ tracking callback
→ normalized status
→ customer communication
→ vendor communication
→ delivery
→ customer confirmation
→ review
→ vendor review
→ completion

When modifying one stage, check the downstream events it triggers.

In particular, changes to:

order metadata
tracking callbacks
Speedaf status handling
review events
vendor resolution
email events

can affect several independent modules.

The project should therefore be maintained as a logistics workflow system, not simply as a shipping API wrapper.