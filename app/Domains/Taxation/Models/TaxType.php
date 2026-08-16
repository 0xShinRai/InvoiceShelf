<?php

namespace App\Domains\Taxation\Models;

use App\Domains\Accounts\Models\Company;
use App\Support\SafeOrderBy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxType extends Model
{
    protected $table = 'tax_types';

    use HasFactory;

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'float',
            'fixed_amount' => 'integer',
            'compound_tax' => 'boolean',
        ];
    }

    public const TYPE_GENERAL = 'GENERAL';

    public const TYPE_MODULE = 'MODULE';

    public const TRANSACTION_TYPE_SALES = 'sales';

    public const TRANSACTION_TYPE_PURCHASES = 'purchases';

    /**
     * EN 16931 Tax Category Codes (UNTDID 5305 subset) classifying a tax rate.
     */
    public const TAX_CATEGORY_CODE_STANDARD = 'S';

    public const TAX_CATEGORY_CODE_ZERO_RATED = 'Z';

    public const TAX_CATEGORY_CODE_EXEMPT = 'E';

    public const TAX_CATEGORY_CODE_REVERSE_CHARGE = 'AE';

    public const TAX_CATEGORY_CODE_INTRA_COMMUNITY = 'K';

    public const TAX_CATEGORY_CODE_FREE_EXPORT = 'G';

    public const TAX_CATEGORY_CODE_OUTSIDE_SCOPE = 'O';

    public const TAX_CATEGORY_CODE_CANARY_ISLANDS = 'L';

    public const TAX_CATEGORY_CODE_CEUTA_MELILLA = 'M';

    /**
     * Every Tax Category Code the data model accepts.
     */
    public const TAX_CATEGORY_CODES = [
        self::TAX_CATEGORY_CODE_STANDARD,
        self::TAX_CATEGORY_CODE_ZERO_RATED,
        self::TAX_CATEGORY_CODE_EXEMPT,
        self::TAX_CATEGORY_CODE_REVERSE_CHARGE,
        self::TAX_CATEGORY_CODE_INTRA_COMMUNITY,
        self::TAX_CATEGORY_CODE_FREE_EXPORT,
        self::TAX_CATEGORY_CODE_OUTSIDE_SCOPE,
        self::TAX_CATEGORY_CODE_CANARY_ISLANDS,
        self::TAX_CATEGORY_CODE_CEUTA_MELILLA,
    ];

    /**
     * Tax Category Codes whose EN 16931 business rules demand an exemption
     * reason text (BT-120), e.g. § 19 UStG for the exempt category.
     */
    public const EXEMPT_TAX_CATEGORY_CODES = [
        self::TAX_CATEGORY_CODE_EXEMPT,
        self::TAX_CATEGORY_CODE_REVERSE_CHARGE,
        self::TAX_CATEGORY_CODE_INTRA_COMMUNITY,
        self::TAX_CATEGORY_CODE_FREE_EXPORT,
        self::TAX_CATEGORY_CODE_OUTSIDE_SCOPE,
    ];

    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeWhereCompany(Builder $query): void
    {
        $query->where('company_id', request()->header('company'));
    }

    public function scopeWhereTaxType(Builder $query, int $tax_type_id): void
    {
        $query->orWhere('id', $tax_type_id);
    }

    public function scopeWhereTransactionType(Builder $query, string $transaction_type): void
    {
        $query->where('transaction_type', $transaction_type);
    }

    public function scopeApplyFilters(Builder $query, array $filters): void
    {
        $filters = collect($filters);

        if ($filters->get('tax_type_id')) {
            $query->whereTaxType($filters->get('tax_type_id'));
        }

        if ($filters->get('company_id')) {
            $query->whereCompany($filters->get('company_id'));
        }

        if ($filters->get('transaction_type')) {
            $query->whereTransactionType($filters->get('transaction_type'));
        }

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        if ($filters->get('orderByField') || $filters->get('orderBy')) {
            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'payment_number';
            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
            $query->whereOrder($field, $orderBy);
        }
    }

    public function scopeWhereOrder(Builder $query, string $orderByField, string $orderBy): void
    {
        SafeOrderBy::apply($query, $orderByField, $orderBy);
    }

    public function scopeWhereSearch(Builder $query, string $search): void
    {
        $query->where('name', 'LIKE', '%'.$search.'%');
    }

    /**
     * @return Collection|LengthAwarePaginator
     */
    public function scopePaginateData(Builder $query, string $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }
}
