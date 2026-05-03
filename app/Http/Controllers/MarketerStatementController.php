<?php

namespace App\Http\Controllers;

use App\Exports\MarketerStatementExport;
use App\Models\Marketer;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel export of a marketer's transaction history. Supports both the
 * admin route (any marketer) and the marketer-portal route (current
 * user's own marketer record).
 */
class MarketerStatementController extends Controller
{
    public function exportAdmin(Request $request, Marketer $marketer): BinaryFileResponse
    {
        return $this->stream($request, $marketer);
    }

    public function exportSelf(Request $request): BinaryFileResponse
    {
        $marketer = $request->user()?->marketer;
        if (! $marketer) {
            abort(403, 'No marketer record linked to your account.');
        }

        return $this->stream($request, $marketer);
    }

    private function stream(Request $request, Marketer $marketer): BinaryFileResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        AuditLogService::log(
            action: 'export',
            module: 'marketers',
            recordType: Marketer::class,
            recordId: $marketer->id,
            newValues: ['from' => $from, 'to' => $to],
        );

        $filename = sprintf(
            'marketer-%s-statement-%s.xlsx',
            $marketer->code,
            now()->format('Y-m-d'),
        );

        return Excel::download(
            new MarketerStatementExport($marketer, $from, $to),
            $filename,
        );
    }
}
