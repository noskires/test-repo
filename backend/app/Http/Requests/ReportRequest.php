<?php

namespace App\Http\Requests;

use App\Dashboard\DashboardKpis;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The filters every report takes (issue 29, reused by 52-54): site_id, equipment_category_id, vendor_id and an
 * optional from/to period validated as on the dashboard (issue 42). Access is checked by the `view-reports` gate on
 * the route, and Vendor scoping by the report itself.
 */
class ReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. The route's `can:view-reports` middleware decides.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. from/to are Y-m-d Manila dates, both or neither.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'string', 'max:255'],
            'equipment_category_id' => ['sometimes', 'string', 'max:255'],
            'vendor_id' => ['sometimes', 'integer'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * The validated filters, before Vendor scoping.
     *
     * @return array{site_id?: string, equipment_category_id?: string, vendor_id?: int}
     */
    public function filters(): array
    {
        $filters = $this->safe()->only(['site_id', 'equipment_category_id', 'vendor_id']);
        if (isset($filters['vendor_id'])) {
            $filters['vendor_id'] = (int) $filters['vendor_id'];
        }

        return $filters;
    }

    /**
     * The inclusive period: the given from/to, or the dashboard's default (the current Manila month).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function period(CarbonImmutable $today): array
    {
        $from = $this->validated('from');
        $to = $this->validated('to');

        return $from !== null && $to !== null
            ? [Carbon::createFromFormat('!Y-m-d', $from)->toImmutable(), Carbon::createFromFormat('!Y-m-d', $to)->toImmutable()]
            : DashboardKpis::defaultPeriod($today);
    }
}
