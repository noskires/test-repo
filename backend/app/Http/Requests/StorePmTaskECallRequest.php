<?php

namespace App\Http\Requests;

use App\Enums\ECallPriority;
use App\Enums\PmStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\Models\PmTask;
use App\Models\SiteSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Site's current EIC files an E-Call from a PM Task under review (For Validation or Returned). The Site and
 * Equipment come from the task and are re-read from the directory; routing follows StoreECallRequest.
 */
class StorePmTaskECallRequest extends FormRequest
{
    public const string NOT_UNDER_REVIEW_MESSAGE = 'An E-Call can only be filed from a PM Task that is For Validation or Returned.';

    public const string UNKNOWN_SITE_MESSAGE = "The PM Task's Site is not known to the site directory.";

    public const string EQUIPMENT_NOT_AT_SITE_MESSAGE = "The PM Task's Equipment is no longer at its Site in the equipment directory.";

    /**
     * The PM Task statuses in which the EIC is reviewing the findings.
     *
     * @var list<PmStatus>
     */
    public const array FILEABLE_STATUSES = [PmStatus::ForValidation, PmStatus::Returned];

    /**
     * Determine if the user is authorized to make this request: the Site's current EIC (PmTaskPolicy::validate).
     */
    public function authorize(): bool
    {
        return Gate::allows('validate', $this->pmTask());
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
            'nature_of_issue' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(ECallPriority::class)],
            'attachments' => ['sometimes', 'array', 'max:'.config('pss.e_call_attachments.max_files')],
            'attachments.*' => [
                'file',
                'mimes:'.$extensions,
                'extensions:'.$extensions,
                'max:'.config('pss.e_call_attachments.max_kilobytes'),
            ],
        ];
    }

    /**
     * Check the task's status, its Site's routing and that its Equipment is still at the Site.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $task = $this->pmTask();

                if (! in_array($task->status, self::FILEABLE_STATUSES, true)) {
                    $validator->errors()->add('pm_task', self::NOT_UNDER_REVIEW_MESSAGE);

                    return;
                }

                if ($this->directory()->findSite($task->site_id) === null) {
                    $validator->errors()->add('site', self::UNKNOWN_SITE_MESSAGE);

                    return;
                }

                $setting = $this->routingOrNull();

                if ($setting?->vendor_id === null) {
                    $validator->errors()->add('site', StoreECallRequest::NO_SITE_VENDOR_MESSAGE);

                    return;
                }

                if ($setting->eic_employee_id === null) {
                    $validator->errors()->add('site', StoreECallRequest::NO_EIC_MESSAGE);

                    return;
                }

                $equipment = $this->directory()->findEquipment($task->equipment_id);

                if ($equipment?->siteId !== $task->site_id) {
                    $validator->errors()->add('equipment', self::EQUIPMENT_NOT_AT_SITE_MESSAGE);
                } elseif (! $equipment->isActive) {
                    $validator->errors()->add('equipment', StoreECallRequest::INACTIVE_EQUIPMENT_MESSAGE);
                }
            },
        ];
    }

    public function pmTask(): PmTask
    {
        /** @var PmTask */
        return $this->route('pmTask');
    }

    public function site(): Site
    {
        return $this->directory()->findSite($this->pmTask()->site_id);
    }

    public function equipment(): Equipment
    {
        return $this->directory()->findEquipment($this->pmTask()->equipment_id);
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
        return $this->routingOrNull();
    }

    /**
     * @return array{nature_of_issue: string, description: string, priority: string, pm_task_id: int}
     */
    public function eCallDetails(): array
    {
        return [
            'nature_of_issue' => $this->validated('nature_of_issue'),
            'description' => $this->validated('description'),
            'priority' => $this->validated('priority'),
            'pm_task_id' => $this->pmTask()->id,
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    private function routingOrNull(): ?SiteSetting
    {
        return SiteSetting::query()->firstWhere('site_id', $this->pmTask()->site_id);
    }

    private function directory(): MasterDataDirectory
    {
        return $this->container->make(MasterDataDirectory::class);
    }
}
