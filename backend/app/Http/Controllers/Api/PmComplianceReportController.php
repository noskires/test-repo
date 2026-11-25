<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Reports\PmComplianceReport;
use App\Reports\XlsxExport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Check-up compliance report (issue 29) as JSON and as an .xlsx download. Behind the `view-reports` gate.
 */
class PmComplianceReportController extends Controller
{
    public function index(ReportRequest $request): JsonResponse
    {
        return response()->json($this->report($request));
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        $report = $this->report($request);
        [$from, $to] = $request->period(today()->toImmutable());

        return XlsxExport::download(
            PmComplianceReport::filename($from, $to),
            array_values(PmComplianceReport::COLUMNS),
            PmComplianceReport::exportRows($report['rows']),
        );
    }

    /**
     * @return array{period: array{from: string, to: string}, summary: array{due: int, on_time: int, compliance: float|null}, rows: list<array<string, mixed>>}
     */
    private function report(ReportRequest $request): array
    {
        $today = today()->toImmutable();
        [$from, $to] = $request->period($today);

        return PmComplianceReport::for($request->user(), $request->filters(), $from, $to, $today);
    }
}
