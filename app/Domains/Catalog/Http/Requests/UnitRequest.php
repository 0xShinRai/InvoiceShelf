<?php

namespace App\Domains\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
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
        $data = [
            'name' => [
                'required',
                Rule::unique('units')
                    ->where('company_id', $this->header('company')),
            ],
            // Omitting the Unit Code keeps the stored one (C62 for new units);
            // sending one means it must be a supported UN/ECE Rec 20 code.
            'unit_code' => [
                'sometimes',
                'required',
                'string',
                Rule::in($this->availableUnitCodes()),
            ],
        ];

        if ($this->getMethod() == 'PUT') {
            $data['name'] = [
                'required',
                Rule::unique('units')
                    ->ignore($this->route('unit'), 'id')
                    ->where('company_id', $this->header('company')),
            ];
        }

        return $data;
    }

    /**
     * The curated UN/ECE Rec 20 Unit Codes this installation accepts.
     *
     * @return list<string>
     */
    private function availableUnitCodes(): array
    {
        return array_column(config('invoiceshelf.unit_codes', []), 'value');
    }

    public function getUnitPayload()
    {
        return collect($this->validated())
            ->merge([
                'company_id' => $this->header('company'),
            ])
            ->toArray();
    }
}
