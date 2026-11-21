<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistTemplateResource;
use App\Http\Resources\ChecklistTemplateVersionResource;
use App\Models\ChecklistTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only PM Checklist templates. The MVP ships a fixed set loaded by `checklists:load`; there is no write API.
 */
class ChecklistTemplateController extends Controller
{
    /**
     * List templates with their current version number. Filtering by Equipment Category also returns the
     * current version with its items, and an empty list when the category has no template.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'equipment_category_id' => ['sometimes', 'string', 'max:255'],
        ]);

        $templates = ChecklistTemplate::query()
            ->when(
                $request->filled('equipment_category_id'),
                fn ($query) => $query
                    ->where('equipment_category_id', $request->string('equipment_category_id')->value())
                    ->where('is_active', true)
                    ->with('currentVersion.items'),
                fn ($query) => $query->with('currentVersion'),
            )
            ->orderBy('name')
            ->get();

        return ChecklistTemplateResource::collection($templates);
    }

    /**
     * Show a template with its current version and items.
     */
    public function show(ChecklistTemplate $checklistTemplate): ChecklistTemplateResource
    {
        return ChecklistTemplateResource::make($checklistTemplate->load('currentVersion.items'));
    }

    /**
     * Show one (possibly old) version of a template with its items.
     */
    public function version(ChecklistTemplate $checklistTemplate, int $versionNumber): ChecklistTemplateVersionResource
    {
        $version = $checklistTemplate->versions()
            ->where('version_number', $versionNumber)
            ->with('items')
            ->firstOrFail();

        return ChecklistTemplateVersionResource::make($version);
    }
}
