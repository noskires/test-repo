<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPmReportRequest;
use App\Http\Resources\PmReportResource;
use App\Models\PmReport;
use App\Models\PmTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * PM Report revisions of a PM Task.
 */
class PmReportController extends Controller
{
    private const array RELATIONS = ['responses', 'materials', 'attachments'];

    /**
     * List every revision, newest (current) first.
     */
    public function index(PmTask $pmTask): AnonymousResourceCollection
    {
        Gate::authorize('view', $pmTask);

        $reports = $pmTask->reports()->with(self::RELATIONS)->orderByDesc('revision')->get();
        $currentRevision = $reports->max('revision');

        return PmReportResource::collection(
            $reports->each(fn (PmReport $report) => $report->setAttribute('is_current', $report->revision === $currentRevision)),
        );
    }

    /**
     * Submit the next revision. The task moves to For Validation.
     */
    public function store(SubmitPmReportRequest $request, PmTask $pmTask): JsonResponse
    {
        $report = PmReport::submit(
            task: $pmTask,
            submitter: $request->user(),
            workDoneDate: $request->validated('work_done_date'),
            findings: $request->validated('findings'),
            serviceReport: $request->serviceReport(),
            responses: $request->checklistResponses(),
            materials: $request->materials(),
            attachments: $request->generalAttachments(),
        );

        $report = $report->refresh()->load(self::RELATIONS)->setAttribute('is_current', true);

        return PmReportResource::make($report)
            ->response()
            ->setStatusCode(201);
    }
}
