<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Address;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\EInvoiceAttachmentResolver;
use App\Domains\Sales\Application\EInvoiceSettings;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use App\Platform\Pdf\Rendering\FacturXAttachment;
use Illuminate\Support\Facades\Artisan;
use Tests\Conformance\Support\ConformanceEnvironment;

/**
 * Independent proof that what InvoiceShelf sends a recipient really is a
 * ZUGFeRD e-invoice.
 *
 * Every other test of this feature asserts on the request the PDF pipeline
 * composes, because that is what can be checked without a service. This one
 * closes the loop: a seeded invoice is rendered by a **real Gotenberg**, and
 * the finished document is handed to the two validators that have the last word
 * on it —
 *
 * - **Mustang CLI** extracts the embedded `factur-x.xml` and validates it
 *   against the EN 16931 XSD and Schematron, together with the container and
 *   its Factur-X XMP metadata;
 * - **veraPDF**, the PDF/A reference implementation, confirms the container is
 *   PDF/A-3b — the conformance without which no file may be embedded at all.
 *
 * It therefore needs a Gotenberg service and two Java tools, which is why it is
 * in the `conformance` group that phpunit.xml excludes from every ordinary run.
 * The dedicated CI job provisions all three and selects the group by name; the
 * standard test suite stays service-free.
 *
 * @see .github/workflows/einvoice-conformance.yaml
 * @see docs/architecture/0002-einvoice-gotenberg-container-horstoeko-xml.md
 */

/**
 * The Hybrid PDF under test, built once for the whole file.
 *
 * Generation is a round trip to Gotenberg, and all three assertions below are
 * about the same document, so it is produced once and kept on disk — where the
 * CI job also picks it up as an artifact, so a failed conformance run can be
 * opened in a validator by hand. The invoice is therefore read on the first
 * call only; the later tests judge the file that call wrote.
 */
function conformanceHybridPdf(Invoice $invoice): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    // The delivery path an ordinary download takes, so the file validated here
    // is the file a recipient gets.
    $pdf = $invoice->getGeneratedPDFOrStream('invoice')->getContent();

    $path = ConformanceEnvironment::artifactDirectory().'/hybrid-invoice.pdf';

    file_put_contents($path, $pdf);

    return $path;
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $host = ConformanceEnvironment::gotenbergHost();

    // A Gotenberg service in CI answers on a loopback or container address,
    // which PrivateNetworkGuard blocks unless the operator names it — exactly
    // what a sidecar deployment does. Naming it here is that declaration.
    config([
        'pdf.driver' => 'gotenberg',
        'pdf.connections.gotenberg.host' => $host,
        'pdf.connections.gotenberg.allowed_private_host' => $host,
        // The e-invoice dictates PDF/A-3b on its own; leave the instance-wide
        // setting out of it.
        'pdf.connections.gotenberg.pdfa' => null,
    ]);

    $this->company = Company::where('slug', 'acme-inc')->firstOrFail();

    // A German seller issuing under the German mandate — the case the feature
    // exists for, and the one the validators are strictest about.
    $this->company->update(['vat_id' => 'DE123456789', 'tax_id' => null]);
    $this->company->address->update([
        'address_street_1' => 'Marienplatz 1',
        'address_street_2' => null,
        'city' => 'München',
        'state' => 'Bayern',
        'zip' => '80331',
        'country_id' => Country::where('code', 'DE')->value('id'),
    ]);

    CompanySetting::setSettings([
        EInvoiceSettings::ENABLED => 'YES',
        EInvoiceSettings::IBAN => 'DE02120300000000202051',
        EInvoiceSettings::BIC => 'BYLADEM1001',
        EInvoiceSettings::BANK_NAME => 'Deutsche Kreditbank',
    ], $this->company->id);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Muster GmbH',
    ]);

    $customer->addresses()->create([
        'name' => 'Muster GmbH',
        'address_street_1' => 'Hauptstrasse 5',
        'city' => 'Berlin',
        'state' => 'Berlin',
        'zip' => '10115',
        'country_id' => Country::where('code', 'DE')->value('id'),
        'type' => Address::BILLING_TYPE,
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 19%',
        'percent' => 19,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_STANDARD,
    ]);

    // 250.00 net, 19% VAT of 47.50, 297.50 to pay.
    $this->invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'currency_id' => Currency::where('code', 'EUR')->value('id'),
        'recurring_invoice_id' => null,
        'type' => Invoice::TYPE_INVOICE,
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'invoice_number' => 'INV-000042',
        'reference_number' => 'PO-4711',
        'notes' => null,
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_UNPAID,
        'tax_per_item' => 'NO',
        'discount_per_item' => 'NO',
        'tax_included' => false,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'exchange_rate' => 1,
        'sub_total' => 25000,
        'tax' => 4750,
        'total' => 29750,
        'due_amount' => 29750,
        'base_discount_val' => 0,
        'base_sub_total' => 25000,
        'base_tax' => 4750,
        'base_total' => 29750,
        'base_due_amount' => 29750,
    ]);

    $this->invoice->items()->create([
        'company_id' => $this->company->id,
        'item_id' => null,
        'recurring_invoice_id' => null,
        'name' => 'Beratung',
        'description' => null,
        'unit_name' => null,
        'price' => 12500,
        'quantity' => 2,
        'total' => 25000,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'exchange_rate' => 1,
        'base_price' => 12500,
        'base_discount_val' => 0,
        'base_tax' => 0,
        'base_total' => 25000,
    ]);

    Tax::create([
        'tax_type_id' => $taxType->id,
        'company_id' => $this->company->id,
        'currency_id' => $this->invoice->currency_id,
        'invoice_id' => $this->invoice->id,
        'name' => $taxType->name,
        'percent' => 19,
        'calculation_type' => $taxType->calculation_type,
        'fixed_amount' => $taxType->fixed_amount,
        'compound_tax' => false,
        'amount' => 4750,
        'base_amount' => 4750,
    ]);
});

/**
 * The document itself. Asserting that the invoice produced an e-invoice — that
 * the Fallback did not apply — before looking at the bytes is what keeps a
 * green conformance run from meaning "an ordinary PDF passed a check nobody
 * made".
 */
test('a real gotenberg turns the seeded invoice into a hybrid pdf', function () {
    $attachment = app(EInvoiceAttachmentResolver::class)->resolve($this->invoice);

    expect($attachment)->toBeInstanceOf(FacturXAttachment::class)
        ->and($attachment->profile)->toBe(FacturXAttachment::PROFILE_EN_16931);

    $pdf = (string) file_get_contents(conformanceHybridPdf($this->invoice));

    expect($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

test('mustang validates it as an EN 16931 conformant zugferd e-invoice', function () {
    $report = ConformanceEnvironment::validateWithMustang(conformanceHybridPdf($this->invoice));

    expect($report->isValid())->toBeTrue($report->summary())
        ->and($report->profile())->toBe('urn:cen.eu:en16931:2017', $report->summary());
});

test('verapdf confirms the container is PDF/A-3', function () {
    $report = ConformanceEnvironment::validateWithVeraPdf(conformanceHybridPdf($this->invoice));

    expect($report->isCompliant())->toBeTrue($report->summary())
        ->and($report->profileName())->toContain('PDF/A-3');
});
