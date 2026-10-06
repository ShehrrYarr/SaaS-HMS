<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\FbrRegister;
use App\Support\DocumentAssets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** CSV / PDF download of the FBR patient visit register. Every export is audit-logged. */
class FbrExportController extends Controller
{
    public function __invoke(Request $request, FbrRegister $register, string $format)
    {
        [$from, $to] = FbrRegister::range($request->query('from'), $request->query('to'));
        $name = 'patient-visits-'.hospital()->slug.'-'.$from.'-to-'.$to;

        if ($format === 'pdf') {
            $total = $register->query($from, $to)->getCountForPagination();
            abort_if($total > FbrRegister::PDF_MAX_ROWS, 422, 'This range has '.number_format($total).' visits, more than a PDF can hold ('.number_format(FbrRegister::PDF_MAX_ROWS).'). Choose a shorter range or download the CSV.');

            $rows = $register->rows($register->query($from, $to)->get());
            $this->audit('pdf', $from, $to, $rows->count());

            return Pdf::loadView('pdf.fbr-register', [
                'rows' => $rows, 'from' => $from, 'to' => $to, 'hospital' => hospital(), 'logo' => DocumentAssets::logo(),
            ])->setPaper('a4', 'landscape')
                ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
                ->download($name.'.pdf');
        }

        $this->audit('csv', $from, $to, $register->query($from, $to)->getCountForPagination());

        return response()->streamDownload(function () use ($register, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names and "×" correctly
            fputcsv($out, ['Date', 'Time', 'Visit type', 'Reference', 'Patient', 'MR / UHID', 'CNIC', 'Age / Sex', 'Phone', 'Address',
                'Department', 'Doctor', 'Diagnosis', 'Lab tests', 'Imaging', 'Medicines', 'Surgery']);
            $register->each($from, $to, function (array $r) use ($out) {
                $s = $r['services'];
                fputcsv($out, csv_safe([
                    $r['at']->format('Y-m-d'), $r['at']->format('H:i'), $r['type_label'], $r['ref'],
                    $r['patient']['name'], $r['patient']['uhid'], $r['patient']['cnic'], $r['patient']['age_gender'], $this->textPhone($r['patient']['phone']), $r['patient']['address'],
                    $r['department'], $r['doctor'], $r['diagnosis'],
                    implode(', ', $s['Lab tests'] ?? []), implode(', ', $s['Imaging'] ?? []), implode(', ', $s['Medicines'] ?? []), implode(', ', $s['Surgery'] ?? []),
                ]));
            });
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "0300 1234567": local format with a space, so Excel keeps it as text (not 9.23E+11 or a formula). */
    protected function textPhone(?string $phone): ?string
    {
        return $phone && preg_match('/^\+92(\d{3})(\d+)$/', $phone, $m) ? "0{$m[1]} {$m[2]}" : $phone;
    }

    protected function audit(string $format, string $from, string $to, int $rows): void
    {
        AuditLog::record('fbr_exported', null, [], ['format' => $format, 'from' => $from, 'to' => $to, 'rows' => $rows],
            'FBR downloaded the patient visit register ('.strtoupper($format).', '.fmt_date($from).' – '.fmt_date($to).", {$rows} visits)");
    }
}
