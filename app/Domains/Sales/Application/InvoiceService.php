<?php

namespace App\Domains\Sales\Application;

use App;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Metadata\Contracts\CustomFieldValueWriter;
use App\Domains\Metadata\Models\CustomField;
use App\Domains\Sales\Contracts\DocumentExchangeRateRecorder;
use App\Domains\Sales\Contracts\InvoiceEmailSender;
use App\Domains\Sales\Contracts\InvoicePdfAttachmentResolver;
use App\Domains\Sales\Contracts\InvoicePdfDataProvider;
use App\Domains\Sales\Mail\SendInvoiceMail;
use App\Domains\Sales\Models\Estimate;
use App\Domains\Sales\Models\Invoice;
use App\Facades\Hashids;
use App\Platform\Mail\Contracts\MailConfigurator;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\PdfMetadata;
use App\Platform\Pdf\Rendering\PdfTemplateUtils;
use App\Support\Hashids\HashidConnection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceService implements InvoicePdfDataProvider
{
    public function __construct(
        private readonly DocumentItemService $documentItemService,
        private readonly CreditNoteService $creditNoteService,
        private readonly MailConfigurator $mailConfigurator,
        private readonly CustomFieldValueWriter $customFieldValueWriter,
        private readonly DocumentExchangeRateRecorder $exchangeRateRecorder,
        private readonly InvoiceEmailSender $invoiceEmailSender,
        private readonly InvoicePdfAttachmentResolver $eInvoiceAttachments,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>|null  $taxes
     */
    public function create(
        array $attributes,
        array $items,
        ?array $taxes = null,
        ?iterable $customFields = null,
    ): Invoice {
        $invoice = Invoice::create($attributes);

        $serial = (new SerialNumberService)
            ->setModel($invoice)
            ->setCompany($invoice->company_id)
            ->setCustomer($invoice->customer_id)
            ->setSequenceScope(['type' => Invoice::TYPE_INVOICE])
            ->setNextNumbers();

        $invoice->sequence_number = $serial->nextSequenceNumber;
        $invoice->customer_sequence_number = $serial->nextCustomerSequenceNumber;
        $invoice->unique_hash = Hashids::connection(HashidConnection::Invoice->value)->encode($invoice->id);
        $invoice->save();

        $this->documentItemService->createItems($invoice, $items);

        $companyCurrency = CompanySetting::getSetting('currency', $invoice->company_id);

        if ((string) $attributes['currency_id'] !== $companyCurrency) {
            $this->exchangeRateRecorder->record($invoice);
        }

        if ($taxes) {
            $this->documentItemService->createTaxes($invoice, $taxes);
        }

        if ($customFields) {
            $this->customFieldValueWriter->attach($invoice, $customFields);
        }

        return Invoice::with([
            'items',
            'items.fields',
            'items.fields.customField',
            'customer',
            'taxes',
            'creditNotes',
        ])->findOrFail($invoice->id);
    }

    /**
     * @throws ValidationException
     */
    public function update(
        Invoice $invoice,
        array $attributes,
        array $items,
        ?array $taxes = null,
        ?iterable $customFields = null,
    ): Invoice {
        $serial = (new SerialNumberService)
            ->setModel($invoice)
            ->setCompany($invoice->company_id)
            ->setCustomer($attributes['customer_id'])
            ->setSequenceScope(['type' => Invoice::TYPE_INVOICE])
            ->setModelObject($invoice->id)
            ->setNextNumbers();

        $oldTotal = $invoice->total;

        $totalPaidAmount = $invoice->total - $invoice->due_amount;

        if ($totalPaidAmount > 0 && (int) $invoice->customer_id !== (int) $attributes['customer_id']) {
            throw ValidationException::withMessages([
                'customer_id' => ['customer_cannot_be_changed_after_payment_is_added'],
            ]);
        }

        if ($attributes['total'] >= 0 && $attributes['total'] < $totalPaidAmount) {
            throw ValidationException::withMessages([
                'total' => ['total_invoice_amount_must_be_more_than_paid_amount'],
            ]);
        }

        if ($oldTotal != $attributes['total']) {
            $oldTotal = (int) round($attributes['total']) - (int) $oldTotal;
        } else {
            $oldTotal = 0;
        }

        $attributes['due_amount'] = ($invoice->due_amount + $oldTotal);
        $attributes['base_due_amount'] = $attributes['due_amount'] * $attributes['exchange_rate'];
        $attributes['customer_sequence_number'] = $serial->nextCustomerSequenceNumber;

        $invoice->update($attributes);

        $statusData = $invoice->getInvoiceStatusByAmount($attributes['due_amount']);
        if (! empty($statusData)) {
            $invoice->update($statusData);
        }

        $companyCurrency = CompanySetting::getSetting('currency', $invoice->company_id);

        if ((string) $attributes['currency_id'] !== $companyCurrency) {
            $this->exchangeRateRecorder->record($invoice);
        }

        $invoice->items->map(function ($item) {
            $fields = $item->fields()->get();

            $fields->map(function ($field) {
                $field->delete();
            });
        });

        $invoice->items()->delete();
        $invoice->taxes()->delete();

        $this->documentItemService->createItems($invoice, $items);

        if ($taxes) {
            $this->documentItemService->createTaxes($invoice, $taxes);
        }

        if ($customFields) {
            $this->customFieldValueWriter->update($invoice, $customFields);
        }

        return Invoice::with([
            'items',
            'items.fields',
            'items.fields.customField',
            'customer',
            'taxes',
            'creditNotes',
        ])->findOrFail($invoice->id);
    }

    public function delete(Collection $ids): bool
    {
        // Invoices that lose a credit note in this batch and survive it. Their
        // balances are recomputed once, after every deletion has landed, so a
        // batch deleting several credit notes of the same invoice settles on
        // the right figure instead of one per deleted document.
        $creditedInvoiceIds = [];

        foreach ($ids as $id) {
            $invoice = Invoice::find($id);

            if ($invoice->allocations()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => ['invoice_has_payment_allocations'],
                ]);
            }

            if ($invoice->transactions()->exists()) {
                $invoice->transactions()->delete();
            }

            if ($invoice->isCreditNote() && $invoice->related_invoice_id && ! $ids->contains($invoice->related_invoice_id)) {
                $creditedInvoiceIds[$invoice->related_invoice_id] = $invoice->related_invoice_id;
            }

            $invoice->delete();
        }

        // There is no DB-level foreign key on related_invoice_id by convention,
        // so the cascade lives here: nothing that survives the batch may keep
        // pointing at a row that just went away.
        Invoice::whereIn('related_invoice_id', $ids)->update(['related_invoice_id' => null]);

        // Deleting a credit note gives back the amount it had credited off its
        // original invoice (mirror of the create-side adjustment; same symmetry
        // PR #536 implemented). The balance is recomputed from the payments and
        // the credit notes that remain rather than restored from a snapshot, so
        // it is exact whether the invoice was partly paid, partly credited, or
        // both.
        foreach ($creditedInvoiceIds as $creditedInvoiceId) {
            $original = Invoice::find($creditedInvoiceId);

            if ($original) {
                $this->creditNoteService->recalculateBalance($original);
            }
        }

        return true;
    }

    public function sendInvoiceData(Invoice $invoice, array $data): array
    {
        $data['invoice'] = $invoice->toArray();
        $data['customer'] = $invoice->customer->toArray();
        $data['company'] = Company::find($invoice->company_id);
        $data['subject'] = $invoice->getEmailString($data['subject']);
        $data['body'] = $invoice->getEmailString($data['body']);
        $data['attach']['data'] = ($invoice->getEmailAttachmentSetting()) ? $this->getPdfData($invoice) : null;

        return $data;
    }

    public function preview(Invoice $invoice, array $data): array
    {
        $data = $this->sendInvoiceData($invoice, $data);

        return [
            'type' => 'preview',
            'view' => new SendInvoiceMail($data),
        ];
    }

    public function send(Invoice $invoice, array $data): array
    {
        $data = $this->sendInvoiceData($invoice, $data);

        $this->mailConfigurator->applyCompanyConfig($invoice->company_id);

        $this->invoiceEmailSender->send($data, $invoice->isCreditNote());

        if ($invoice->status == Invoice::STATUS_DRAFT) {
            $invoice->status = Invoice::STATUS_SENT;
            $invoice->sent = true;
            $invoice->save();
        }

        return [
            'success' => true,
            'type' => 'send',
        ];
    }

    public function getPdfData(Invoice $invoice): mixed
    {
        $taxes = collect();

        if ($invoice->tax_per_item === 'YES') {
            foreach ($invoice->items as $item) {
                foreach ($item->taxes as $tax) {
                    $found = $taxes->filter(function ($item) use ($tax) {
                        return $item->tax_type_id == $tax->tax_type_id;
                    })->first();

                    if ($found) {
                        $found->amount += $tax->amount;
                    } else {
                        $taxes->push($tax);
                    }
                }
            }
        }

        $invoiceTemplate = Invoice::find($invoice->id)->template_name;

        // Cheap either way: relatedInvoice is null for regular invoices and
        // creditNotes is empty for credit notes. Eager-loaded here so the
        // invoice templates can reference the paired document.
        $invoice->loadMissing(['relatedInvoice', 'creditNotes']);

        $company = Company::find($invoice->company_id);
        $locale = CompanySetting::getSetting('language', $company->id);
        $customFields = CustomField::where('model_type', 'Item')->get();

        App::setLocale($locale);

        $logo = $company->logo_path;

        view()->share([
            'invoice' => $invoice,
            'customFields' => $customFields,
            'company_address' => $invoice->getCompanyAddress(),
            'shipping_address' => $invoice->getCustomerShippingAddress(),
            'billing_address' => $invoice->getCustomerBillingAddress(),
            'notes' => $invoice->getNotes(),
            'logo' => $logo ?? null,
            'taxes' => $taxes,
        ]);

        $templatePath = PdfTemplateUtils::resolveView('invoice', $invoiceTemplate, 'invoice1');

        if (request()->has('preview')) {
            return view($templatePath);
        }

        // Every delivery path — download/stream, email attachment and the
        // customer portal — reaches the PDF pipeline through this method, so
        // resolving the e-invoice here is what makes all of them produce the
        // same document. Null is the Fallback: the ordinary PDF.
        return Pdf::loadView(
            $templatePath,
            PdfMetadata::forDocument(
                __($invoice->isCreditNote() ? 'pdf_credit_note_label' : 'pdf_invoice_label'),
                $invoice->invoice_number,
                $company,
            ),
            null,
            $this->eInvoiceAttachments->resolve($invoice),
        );
    }

    public function clone(Invoice $invoice): Invoice
    {
        $date = Carbon::now();

        $serial = (new SerialNumberService)
            ->setModel($invoice)
            ->setCompany($invoice->company_id)
            ->setCustomer($invoice->customer_id)
            ->setSequenceScope(['type' => Invoice::TYPE_INVOICE])
            ->setNextNumbers();

        $dueDate = null;
        $dueDateEnabled = CompanySetting::getSetting(
            'invoice_set_due_date_automatically',
            $invoice->company_id
        );

        if ($dueDateEnabled === 'YES') {
            $dueDateDays = intval(CompanySetting::getSetting(
                'invoice_due_date_days',
                $invoice->company_id
            ));
            $dueDate = Carbon::now()->addDays($dueDateDays)->format('Y-m-d');
        }

        $exchangeRate = $invoice->exchange_rate;

        $newInvoice = Invoice::create([
            'invoice_date' => $date->format('Y-m-d'),
            'due_date' => $dueDate,
            'invoice_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
            'reference_number' => $invoice->reference_number,
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'template_name' => $invoice->template_name,
            'status' => Invoice::STATUS_DRAFT,
            'paid_status' => Invoice::STATUS_UNPAID,
            'sub_total' => $invoice->sub_total,
            'discount' => $invoice->discount,
            'discount_type' => $invoice->discount_type,
            'discount_val' => $invoice->discount_val,
            'total' => $invoice->total,
            'due_amount' => $invoice->total,
            'tax_per_item' => $invoice->tax_per_item,
            'discount_per_item' => $invoice->discount_per_item,
            'tax' => $invoice->tax,
            'notes' => $invoice->notes,
            'exchange_rate' => $exchangeRate,
            'base_total' => $invoice->total * $exchangeRate,
            'base_discount_val' => $invoice->discount_val * $exchangeRate,
            'base_sub_total' => $invoice->sub_total * $exchangeRate,
            'base_tax' => $invoice->tax * $exchangeRate,
            'base_due_amount' => $invoice->total * $exchangeRate,
            'currency_id' => $invoice->currency_id,
            'sales_tax_type' => $invoice->sales_tax_type,
            'sales_tax_address_type' => $invoice->sales_tax_address_type,
        ]);

        $newInvoice->unique_hash = Hashids::connection(HashidConnection::Invoice->value)->encode($newInvoice->id);
        $newInvoice->save();

        $invoice->load('items.taxes');
        $this->documentItemService->createItems($newInvoice, $invoice->items->toArray());

        if ($invoice->taxes) {
            $this->documentItemService->createTaxes($newInvoice, $invoice->taxes->toArray());
        }

        if ($invoice->fields()->exists()) {
            $customFields = [];

            foreach ($invoice->fields as $data) {
                $customFields[] = [
                    'id' => $data->custom_field_id,
                    'value' => $data->defaultAnswer,
                ];
            }

            $this->customFieldValueWriter->attach($newInvoice, $customFields);
        }

        return $newInvoice;
    }

    public function convertToEstimate(Invoice $invoice): Estimate
    {
        $invoice->load(['items', 'items.taxes', 'customer', 'taxes']);

        $serial = (new SerialNumberService)
            ->setModel(new Estimate)
            ->setCompany($invoice->company_id)
            ->setCustomer($invoice->customer_id)
            ->setNextNumbers();

        $exchangeRate = $invoice->exchange_rate;

        $estimate = Estimate::create([
            'creator_id' => $invoice->creator_id,
            'estimate_date' => Carbon::now()->format('Y-m-d'),
            'expiry_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'estimate_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
            'reference_number' => $serial->getNextNumber(),
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'template_name' => $invoice->getEstimateTemplateName(),
            'status' => Estimate::STATUS_DRAFT,
            'sub_total' => $invoice->sub_total,
            'discount' => $invoice->discount,
            'discount_type' => $invoice->discount_type,
            'discount_val' => $invoice->discount_val,
            'total' => $invoice->total,
            'tax_per_item' => $invoice->tax_per_item,
            'discount_per_item' => $invoice->discount_per_item,
            'tax' => $invoice->tax,
            'notes' => $invoice->notes,
            'exchange_rate' => $exchangeRate,
            'base_discount_val' => $invoice->discount_val * $exchangeRate,
            'base_sub_total' => $invoice->sub_total * $exchangeRate,
            'base_total' => $invoice->total * $exchangeRate,
            'base_tax' => $invoice->tax * $exchangeRate,
            'currency_id' => $invoice->currency_id,
            'sales_tax_type' => $invoice->sales_tax_type,
            'sales_tax_address_type' => $invoice->sales_tax_address_type,
        ]);

        $estimate->unique_hash = Hashids::connection(HashidConnection::Estimate->value)->encode($estimate->id);
        $estimate->save();

        $this->documentItemService->createItems($estimate, $invoice->items->toArray());

        if ($invoice->taxes) {
            $this->documentItemService->createTaxes($estimate, $invoice->taxes->toArray());
        }

        if ($invoice->fields()->exists()) {
            $customFields = [];

            foreach ($invoice->fields as $data) {
                $customFields[] = [
                    'id' => $data->custom_field_id,
                    'value' => $data->defaultAnswer,
                ];
            }

            $this->customFieldValueWriter->attach($estimate, $customFields);
        }

        return $estimate;
    }

    public function changeStatus(Invoice $invoice, string $status): void
    {
        if ($status == Invoice::STATUS_SENT) {
            $invoice->status = Invoice::STATUS_SENT;
            $invoice->sent = true;
            $invoice->save();
        } elseif ($status == Invoice::STATUS_COMPLETED) {
            $paid = (int) $invoice->allocations()->sum('amount');
            $credited = $this->creditNoteService->creditedTotal($invoice);
            $outstanding = max(0, (int) $invoice->total - $paid - $credited);

            if (
                $outstanding !== 0
                || (int) $invoice->due_amount !== 0
                || (int) $invoice->base_due_amount !== 0
            ) {
                throw ValidationException::withMessages([
                    'status' => ['invoice_must_be_settled_before_completion'],
                ]);
            }

            $invoice->changeInvoiceStatus((int) $invoice->due_amount);
        }
    }
}
