<?php

namespace App\Http\Requests;

use App\Enums\ECallPriority;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\Models\SiteSetting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Any signed-in user may file an E-Call. The Site must be known to the directory and have a Site Vendor and EIC,
 * and the optional Equipment must be at that Site.
 */
class StoreECallRequest extends FormRequest
{
    public const string NO_SITE_VENDOR_MESSAGE = 'This Site has no Site Vendor assigned, so the E-Call cannot be routed. Ask an administrator to assign one.';

    public const string NO_EIC_MESSAGE = 'This Site has no EIC assigned, so the E-Call cannot be routed. Ask an administrator to assign one.';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. Attachment limits come from config/pss.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensions = implode(',', config('pss.e_call_attachments.extensions'));

        return [
            'site_id' => [
                'bail',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->directory()->findSite($value) === null) {
                        $fail('The selected site is not known to the site directory.');

                        return;
                    }

                    $setting = $this->siteSetting($value);

                    if ($setting?->vendor_id === null) {
                        $fail(self::NO_SITE_VENDOR_MESSAGE);
                    } elseif ($setting->eic_employee_id === null) {
                        $fail(self::NO_EIC_MESSAGE);
                    }
                },
            ],
            'equipment_id' => [
                'bail',
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->directory()->findEquipment($value)?->siteId !== $this->input('site_id')) {
                        $fail('The selected equipment is not at the selected site.');
                    }
                },
            ],
            'nature_of_issue' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(ECallPriority::class)],
            'pm_task_id' => ['nullable', 'integer', 'min:1'],
            'attachments' => ['sometimes', 'array', 'max:'.config('pss.e_call_attachments.max_files')],
            'attachments.*' => [
                'file',
                'mimes:'.$extensions,
                'extensions:'.$extensions,
                'max:'.config('pss.e_call_attachments.max_kilobytes'),
            ],
        ];
    }

    public function site(): Site
    {
        return $this->directory()->findSite($this->validated('site_id'));
    }

    public function equipment(): ?Equipment
    {
        $equipmentId = $this->validated('equipment_id');

        return $equipmentId === null ? null : $this->directory()->findEquipment($equipmentId);
    }

    /**
     * The Equipment Category's current name, falling back to its ID when the directory does not list it.
     */
    public function equipmentCategoryName(Equipment $equipment): string
    {
        foreach ($this->directory()->listEquipmentCategories() as $category) {
            if ($category->id === $equipment->categoryId) {
                return $category->name;
            }
        }

        return $equipment->categoryId;
    }

    /**
     * The Site's settings row holding its current Site Vendor and EIC.
     */
    public function routing(): SiteSetting
    {
        return $this->siteSetting($this->validated('site_id'));
    }

    /**
     * @return array{nature_of_issue: string, description: string, priority: string, pm_task_id: int|null}
     */
    public function eCallDetails(): array
    {
        return [
            'nature_of_issue' => $this->validated('nature_of_issue'),
            'description' => $this->validated('description'),
            'priority' => $this->validated('priority'),
            'pm_task_id' => $this->validated('pm_task_id'),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    private function siteSetting(string $siteId): ?SiteSetting
    {
        return SiteSetting::query()->firstWhere('site_id', $siteId);
    }

    private function directory(): MasterDataDirectory
    {
        return $this->container->make(MasterDataDirectory::class);
    }
}
