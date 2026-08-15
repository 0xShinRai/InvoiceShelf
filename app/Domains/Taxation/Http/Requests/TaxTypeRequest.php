<?php

namespace App\Domains\Taxation\Http\Requests;

use App\Domains\Taxation\Models\TaxType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaxTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                Rule::unique('tax_types')
                    ->where('type', TaxType::TYPE_GENERAL)
                    ->where('company_id', $this->header('company')),
            ],
            'calculation_type' => [
                'required',
                Rule::in(['percentage', 'fixed']),
            ],
            'percent' => [
                'nullable',
                'numeric',
            ],
            'fixed_amount' => [
                'nullable',
                'numeric',
            ],
            'description' => [
                'nullable',
            ],
            'compound_tax' => [
                'sometimes',
                'boolean',
            ],
            'collective_tax' => [
                'nullable',
            ],
            'transaction_type' => [
                'sometimes',
                Rule::in([
                    TaxType::TRANSACTION_TYPE_SALES,
                    TaxType::TRANSACTION_TYPE_PURCHASES,
                ]),
            ],
            'tax_category_code' => [
                'sometimes',
                'required',
                'string',
                Rule::in(TaxType::TAX_CATEGORY_CODES),
            ],
            'tax_exemption_reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];

        if ($this->isMethod('PUT')) {
            $rules['name'] = [
                'required',
                Rule::unique('tax_types')
                    ->ignore($this->route('tax_type')->id)
                    ->where('type', TaxType::TYPE_GENERAL)
                    ->where('company_id', $this->header('company')),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->effectiveCompoundTax()) {
                return;
            }

            if (
                $this->effectiveCalculationType() !== 'percentage'
                || $this->effectiveTransactionType() !== TaxType::TRANSACTION_TYPE_SALES
            ) {
                $validator->errors()->add(
                    'compound_tax',
                    'Compound tax is only available for percentage sales taxes.'
                );
            }
        });

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->requiresTaxExemptionReason()) {
                return;
            }

            if (trim((string) $this->effectiveTaxExemptionReason()) === '') {
                $validator->errors()->add(
                    'tax_exemption_reason',
                    'An exemption reason is required for exempt tax category codes.'
                );
            }
        });
    }

    public function getTaxTypePayload()
    {
        $payload = collect($this->validated());

        if (! $payload->has('transaction_type')) {
            $payload->put(
                'transaction_type',
                $this->isUpdate()
                    ? $this->route('tax_type')->transaction_type
                    : TaxType::TRANSACTION_TYPE_SALES
            );
        }

        if (! $payload->has('compound_tax') && ! $this->isUpdate()) {
            $payload->put('compound_tax', false);
        }

        $payload->put('tax_category_code', $this->effectiveTaxCategoryCode());

        $payload->put(
            'tax_exemption_reason',
            $this->requiresTaxExemptionReason() ? $this->effectiveTaxExemptionReason() : null
        );

        return $payload
            ->merge([
                'company_id' => $this->header('company'),
                'type' => TaxType::TYPE_GENERAL,
            ])
            ->toArray();
    }

    private function effectiveCompoundTax(): bool
    {
        if ($this->has('compound_tax')) {
            return $this->boolean('compound_tax');
        }

        return $this->isUpdate()
            ? $this->route('tax_type')->compound_tax
            : false;
    }

    private function effectiveCalculationType(): string
    {
        if ($this->has('calculation_type')) {
            return $this->input('calculation_type');
        }

        return $this->isUpdate()
            ? $this->route('tax_type')->calculation_type
            : 'percentage';
    }

    /**
     * The Tax Category Code the request results in — the submitted one, the
     * stored one on updates that omit it, or the standard rate on creates.
     */
    private function effectiveTaxCategoryCode(): string
    {
        if ($this->has('tax_category_code')) {
            return (string) $this->input('tax_category_code');
        }

        return $this->isUpdate()
            ? ($this->route('tax_type')->tax_category_code ?? TaxType::TAX_CATEGORY_CODE_STANDARD)
            : TaxType::TAX_CATEGORY_CODE_STANDARD;
    }

    /**
     * The exemption reason the request results in — the submitted one, or the
     * stored one on updates that omit it.
     */
    private function effectiveTaxExemptionReason(): ?string
    {
        if ($this->has('tax_exemption_reason')) {
            return $this->input('tax_exemption_reason');
        }

        return $this->isUpdate()
            ? $this->route('tax_type')->tax_exemption_reason
            : null;
    }

    private function requiresTaxExemptionReason(): bool
    {
        return in_array($this->effectiveTaxCategoryCode(), TaxType::EXEMPT_TAX_CATEGORY_CODES, true);
    }

    private function effectiveTransactionType(): string
    {
        return $this->input('transaction_type')
            ?? ($this->isUpdate()
                ? $this->route('tax_type')->transaction_type
                : TaxType::TRANSACTION_TYPE_SALES);
    }

    /**
     * Whether the request updates an existing tax type rather than creating one.
     */
    private function isUpdate(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }
}
