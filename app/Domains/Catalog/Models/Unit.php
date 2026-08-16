<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Accounts\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /**
     * UN/ECE Rec 20 code used when no Unit Code is given: C62 = piece.
     */
    public const DEFAULT_UNIT_CODE = 'C62';

    protected $table = 'units';

    use HasFactory;

    protected $fillable = ['name', 'unit_code', 'company_id'];

    protected $attributes = [
        'unit_code' => self::DEFAULT_UNIT_CODE,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeWhereCompany(Builder $query): void
    {
        $query->where('company_id', request()->header('company'));
    }

    public function scopeWhereUnit(Builder $query, int $unit_id): void
    {
        $query->orWhere('id', $unit_id);
    }

    public function scopeWhereSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'LIKE', '%'.$search.'%');
    }

    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        $filters = collect($filters);

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        if ($filters->get('unit_id')) {
            $query->whereUnit($filters->get('unit_id'));
        }

        if ($filters->get('company_id')) {
            $query->whereCompany($filters->get('company_id'));
        }

        return $query;
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
