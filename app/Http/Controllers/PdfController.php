<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\IpdAdmission;
use App\Models\LabOrder;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Payroll;
use App\Models\PharmacySale;
use App\Models\Prescription;
use App\Models\RadiologyOrder;
use App\Models\SubscriptionInvoice;
use App\Support\DocumentAssets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Printable documents. Records are resolved through tenant-scoped models, so a
 * hospital can never render another hospital's document. Portal variants also
 * verify that the record belongs to the signed-in patient.
 */
class PdfController extends Controller
{
    protected function render(string $view, array $data, string $filename, string|array $paper = 'a4', string $orientation = 'portrait')
    {
        $pdf = Pdf::loadView("pdf.{$view}", $data + ['hospital' => hospital(), 'logo' => DocumentAssets::logo()])
            ->setPaper($paper, $orientation)
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true]);

        return $pdf->stream($filename.'.pdf');
    }

    // ---------------------------------------------------------------- platform
    public function subscriptionInvoice(int $invoiceId)
    {
        $invoice = SubscriptionInvoice::with(['plan'])->findOrFail($invoiceId);
        $hospital = Hospital::findOrFail($invoice->hospital_id);

        $pdf = Pdf::loadView('pdf.subscription-invoice', [
            'invoice' => $invoice, 'customer' => $hospital,
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('assets/images/light-logo.png'))),
        ])->setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true]);

        return $pdf->stream($invoice->number.'.pdf');
    }

    // ---------------------------------------------------------------- patients
    public function patientCard(int $patientId)
    {
        $patient = Patient::findOrFail($patientId);

        return $this->render('patient-card', [
            'patient' => $patient,
            'barcode' => DocumentAssets::barcode($patient->uhid, 2, 36),
            'photo' => DocumentAssets::image($patient->photo_path),
        ], 'card-'.$patient->uhid, [0, 0, 243, 153], 'portrait');
    }

    public function opdSlip(int $visitId)
    {
        $visit = OpdVisit::with(['patient', 'doctor.department', 'invoice'])->findOrFail($visitId);
        $room = $visit->doctor->schedules()->where('day_of_week', $visit->visit_date->dayOfWeek)->value('room');

        return $this->render('opd-slip', ['visit' => $visit, 'room' => $room, 'barcode' => DocumentAssets::barcode($visit->visit_no, 1, 30)], $visit->visit_no, [0, 0, 226, 400]);
    }

    public function prescription(int $prescriptionId)
    {
        return $this->renderPrescription(Prescription::findOrFail($prescriptionId));
    }

    public function portalPrescription(Request $request, int $prescriptionId)
    {
        return $this->renderPrescription($request->user()->patient->prescriptions()->findOrFail($prescriptionId));
    }

    protected function renderPrescription(Prescription $rx)
    {
        $rx->load(['patient.allergies', 'patient.latestVital', 'doctor.user', 'items', 'visitable']);
        $diagnoses = $rx->visitable ? $rx->visitable->diagnoses()->get() : collect();

        return $this->render('prescription', [
            'rx' => $rx,
            'diagnoses' => $diagnoses,
            'signature' => DocumentAssets::image($rx->doctor->user?->signature_path),
            'qr' => DocumentAssets::qr($rx->prescription_no.'|'.$rx->patient->uhid),
        ], $rx->prescription_no);
    }

    public function dischargeSummary(int $admissionId)
    {
        $a = IpdAdmission::with(['patient.allergies', 'doctor.user', 'department', 'bed.ward', 'diagnoses', 'prescriptions.items', 'surgeries', 'labOrders.items.test'])->findOrFail($admissionId);
        abort_unless($a->status === 'discharged', 422, 'Patient is not discharged yet.');

        return $this->render('discharge-summary', ['a' => $a, 'signature' => DocumentAssets::image($a->doctor->user?->signature_path)], 'discharge-'.$a->admission_no);
    }

    // ---------------------------------------------------------------- pharmacy
    public function pharmacyReceipt(int $saleId)
    {
        $sale = PharmacySale::with(['items.medicine', 'items.batch', 'patient', 'seller', 'account'])->findOrFail($saleId);

        return $this->render('receipt', ['sale' => $sale], $sale->sale_no, [0, 0, 226, 600]);
    }

    // ---------------------------------------------------------------- lab
    public function labReport(Request $request, int $orderId)
    {
        $order = LabOrder::findOrFail($orderId);

        return $request->boolean('signed') ? $this->signedLabReport($order) : $this->renderLabReport($order);
    }

    public function portalLabReport(Request $request, int $orderId)
    {
        return $this->renderLabReport($request->user()->patient->labOrders()->where('status', 'approved')->findOrFail($orderId));
    }

    protected function labReportData(LabOrder $order): array
    {
        abort_unless($order->items()->where('status', 'approved')->exists(), 422, 'No approved results yet.');
        if (! $order->verification_code) {
            $order->update(['verification_code' => \Illuminate\Support\Str::random(32)]);
        }
        $order->load(['patient', 'doctor', 'items' => fn ($q) => $q->where('status', 'approved'), 'items.test.parameters', 'items.results', 'items.approver', 'items.enteredBy', 'approver']);
        $approver = $order->approver ?? $order->items->first()?->approver;

        return [
            'order' => $order,
            'approver' => $approver,
            'signature' => DocumentAssets::image($approver?->signature_path),
            'qr' => DocumentAssets::qr(route('verify.lab', $order->verification_code)),
            'verifyUrl' => route('verify.lab', $order->verification_code),
        ];
    }

    protected function renderLabReport(LabOrder $order)
    {
        return $this->render('lab-report', $this->labReportData($order), 'report-'.$order->order_no);
    }

    /** Cryptographically signed PDF (PKCS#12 certificate configured per hospital). */
    protected function signedLabReport(LabOrder $order)
    {
        $hospital = hospital();
        abort_unless($hospital->lab_cert_path, 422, 'No signing certificate configured.');
        $p12 = \Illuminate\Support\Facades\Storage::disk('local')->get($hospital->lab_cert_path);
        $certs = [];
        abort_unless(openssl_pkcs12_read($p12, $certs, (string) $hospital->lab_cert_password), 500, 'Signing certificate could not be opened.');

        $html = view('pdf.lab-report-tcpdf', $this->labReportData($order) + ['hospital' => $hospital, 'logo' => DocumentAssets::logo()])->render();

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator(config('app.name'));
        $pdf->SetAuthor($hospital->name);
        $pdf->SetTitle('Lab report '.$order->order_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->setSignature($certs['cert'], $certs['pkey'], (string) $hospital->lab_cert_password, '', 2, [
            'Name' => $hospital->name, 'Location' => $hospital->city, 'Reason' => 'Laboratory report approval', 'ContactInfo' => $hospital->email,
        ]);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->setSignatureAppearance(150, 270, 45, 15);

        return response($pdf->Output('report-'.$order->order_no.'-signed.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$order->order_no.'-signed.pdf"',
        ]);
    }

    public function labLabels(int $orderId)
    {
        $order = LabOrder::with(['patient', 'items.test'])->findOrFail($orderId);
        $labels = $order->items->whereNotNull('sample_barcode')->map(fn ($i) => ['item' => $i, 'barcode' => DocumentAssets::barcode($i->sample_barcode, 1, 34)]);

        return $this->render('lab-labels', ['order' => $order, 'labels' => $labels], 'labels-'.$order->order_no, [0, 0, 144, 72], 'portrait');
    }

    // ---------------------------------------------------------------- radiology
    public function radiologyReport(int $orderId)
    {
        $o = RadiologyOrder::with(['patient', 'doctor', 'test', 'radiologist', 'performer'])->findOrFail($orderId);
        abort_unless(in_array($o->status, ['reported', 'approved']), 422, 'Report not available yet.');

        return $this->render('radiology-report', ['o' => $o, 'signature' => DocumentAssets::image($o->radiologist?->signature_path)], 'imaging-'.$o->order_no);
    }

    // ---------------------------------------------------------------- billing
    public function invoice(int $invoiceId)
    {
        return $this->renderInvoice(Invoice::findOrFail($invoiceId));
    }

    public function portalInvoice(Request $request, int $invoiceId)
    {
        return $this->renderInvoice($request->user()->patient->invoices()->findOrFail($invoiceId));
    }

    protected function renderInvoice(Invoice $invoice)
    {
        $invoice->load(['patient', 'items.doctor', 'payments.account', 'tpa', 'admission.bed.ward', 'creator']);

        return $this->render('invoice', ['invoice' => $invoice, 'qr' => DocumentAssets::qr($invoice->invoice_no.'|'.rupees($invoice->total))], $invoice->invoice_no);
    }

    // ---------------------------------------------------------------- HR
    public function payslip(int $payrollId)
    {
        $p = Payroll::with(['staff.department', 'commissions'])->findOrFail($payrollId);

        return $this->render('payslip', ['p' => $p], 'payslip-'.$p->staff->employee_code.'-'.$p->month);
    }
}
