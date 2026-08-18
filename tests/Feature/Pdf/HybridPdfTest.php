<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Address;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\EInvoice\EInvoiceSettings;
use App\Domains\Sales\Application\InvoiceService;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use App\Platform\Mail\Models\EmailLog;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\FacturXAttachment;
use App\Platform\Pdf\Rendering\GotenbergPdfDriver;
use App\Platform\Pdf\Rendering\PdfDriver;
use App\Platform\Pdf\Rendering\PdfPageSetup;
use App\Platform\Pdf\Rendering\ResponseStream;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\get;

/**
 * The tracer bullet to the recipient: an invoice of an E-Invoice-enabled
 * company leaves the PDF pipeline as a Hybrid PDF, on every delivery path.
 *
 * Every assertion here is on the Gotenberg request the shared generation path
 * composes — the same multipart body the driver would POST — because that is
 * what decides whether the recipient gets an e-invoice. Nothing asserts on
 * internal calls, and no Gotenberg service is involved: the standing driver is
 * replaced by one that composes the real request through
 * {@see GotenbergPdfDriver::buildRequest()} and keeps it.
 *
 * @see docs/architecture/0002-einvoice-gotenberg-container-horstoeko-xml.md
 */

/**
 * A PDF driver that composes exactly what the Gotenberg driver would send and
 * records it instead of POSTing it.
 */
function hybridPdfRecorder(): object
{
    return new class implements PdfDriver
    {
        /** @var list<string> */
        public array $requests = [];

        /** @var list<FacturXAttachment|null> */
        public array $attachments = [];

        public function loadView(
            string $template,
            array $metadata = [],
            ?PdfPageSetup $page = null,
            ?FacturXAttachment $eInvoice = null,
        ): ResponseStream {
            $this->attachments[] = $eInvoice;
            $this->requests[] = (string) (new GotenbergPdfDriver)
                ->buildRequest($template, $metadata, $page, $eInvoice)
                ->getBody();

            return new class implements ResponseStream
            {
                public function stream(string $filename = 'document.pdf'): Response
                {
                    return response()->make($this->output(), 200, ['Content-Type' => 'application/pdf']);
                }

                public function download(string $filename = 'document.pdf'): Response
                {
                    return $this->stream($filename);
                }

                public function output(): string
                {
                    return '%PDF-1.7 recorded';
                }
            };
        }
    };
}

/**
 * The single request a generated document produced.
 */
function hybridPdfRequest(object $recorder): string
{
    expect($recorder->requests)->toHaveCount(1);

    return $recorder->requests[0];
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    // E-Invoicing exists only on the Gotenberg driver (ADR 0002), and the
    // driver needs a reachable-looking host to compose a request against.
    config([
        'pdf.driver' => 'gotenberg',
        'pdf.connections.gotenberg.host' => 'http://gotenberg.example.com:3000',
        'pdf.connections.gotenberg.pdfa' => null,
    ]);

    $this->recorder = hybridPdfRecorder();
    Pdf::swap($this->recorder);

    $this->company = Company::where('slug', 'acme-inc')->firstOrFail();

    CompanySetting::setSettings([
        EInvoiceSettings::ENABLED => 'YES',
        EInvoiceSettings::IBAN => 'DE02 1203 0000 0000 2020 51',
        EInvoiceSettings::BIC => 'BYLADEM1001',
        EInvoiceSettings::BANK_NAME => 'Deutsche Kreditbank',
        // The email path only attaches a document when this is on, and it is
        // the delivery path this suite has to see a Hybrid PDF travel down.
        'invoice_email_attachment' => 'YES',
        // Opening the customer portal link would otherwise notify the seller,
        // which has nothing to do with what is being asserted here.
        'notify_invoice_viewed' => 'NO',
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

test('an enabled company sends the factur-x xml and asks for PDF/A-3b', function () {
    app(InvoiceService::class)->getPdfData($this->invoice);

    expect(hybridPdfRequest($this->recorder))
        ->toContain('name="facturxXml"')
        ->toContain('filename="factur-x.xml"')
        ->toContain('name="facturxConformanceLevel"')
        ->toContain('EN 16931')
        ->toContain('name="pdfa"')
        ->toContain('PDF/A-3b');
});

test('the embedded xml is this invoice, as EN 16931 CII', function () {
    app(InvoiceService::class)->getPdfData($this->invoice);

    expect($this->recorder->attachments[0])->toBeInstanceOf(FacturXAttachment::class);

    $xml = $this->recorder->attachments[0]->xml;

    expect($xml)
        ->toContain('CrossIndustryInvoice')
        ->toContain('urn:cen.eu:en16931:2017')
        ->toContain('INV-000042');
});

test('a company that has not enabled e-invoicing gets the ordinary pdf', function () {
    CompanySetting::setSettings([EInvoiceSettings::ENABLED => 'NO'], $this->company->id);

    app(InvoiceService::class)->getPdfData($this->invoice);

    expect($this->recorder->attachments[0])->toBeNull()
        ->and(hybridPdfRequest($this->recorder))->not->toContain('facturx');
});

/**
 * E-Invoicing is unavailable on any driver that cannot build a PDF/A-3
 * container, whatever the company switched on. The document is composed here
 * as a Gotenberg request regardless, so that the assertion is about the
 * e-invoice being absent rather than about which driver ran.
 */
test('a non-gotenberg driver gets the ordinary pdf even with the switch on', function () {
    config(['pdf.driver' => 'dompdf']);

    app(InvoiceService::class)->getPdfData($this->invoice);

    expect($this->recorder->attachments[0])->toBeNull()
        ->and(hybridPdfRequest($this->recorder))->not->toContain('facturx');
});

/**
 * The Fallback: the builder reports missing requirements — here the seller
 * IBAN the E-Invoice settings carry — so the ordinary PDF is produced instead
 * of one claiming to be an e-invoice.
 */
test('an invoice with missing requirements falls back to the ordinary pdf', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => ''], $this->company->id);

    app(InvoiceService::class)->getPdfData($this->invoice);

    expect($this->recorder->attachments[0])->toBeNull()
        ->and(hybridPdfRequest($this->recorder))->not->toContain('facturx');
});

/**
 * The three delivery paths the ticket names. They differ in what they do with
 * the finished document — stream it, attach it to a mail, hand it to the
 * customer portal — but all of them reach the pipeline through the invoice's
 * one generation path, which is what makes the result identical.
 */
test('every delivery path produces the same hybrid pdf', function () {
    // Download / stream, from the admin side.
    $this->invoice->getGeneratedPDFOrStream('invoice');

    // Email attachment.
    $data = app(InvoiceService::class)->sendInvoiceData($this->invoice, [
        'subject' => 'Invoice',
        'body' => 'Please find the invoice attached.',
    ]);

    expect($data['attach']['data'])->toBeInstanceOf(ResponseStream::class);

    // Customer portal, reached through the tokenised public link.
    $emailLog = new EmailLog([
        'from' => 'billing@acme.test',
        'to' => 'buchhaltung@muster.test',
        'subject' => 'Invoice',
        'body' => 'Please find the invoice attached.',
        'token' => 'hybrid-pdf-delivery-token',
    ]);
    $emailLog->mailable()->associate($this->invoice);
    $emailLog->save();

    get('/customer/invoices/view/hybrid-pdf-delivery-token?pdf=1')->assertOk();

    expect($this->recorder->requests)->toHaveCount(3);

    foreach ($this->recorder->requests as $request) {
        expect($request)
            ->toContain('name="facturxXml"')
            ->toContain('filename="factur-x.xml"')
            ->toContain('PDF/A-3b');
    }

    // The same document, not merely three e-invoices.
    expect(array_unique(array_map(
        static fn (FacturXAttachment $attachment): string => $attachment->xml,
        $this->recorder->attachments
    )))->toHaveCount(1);
});
