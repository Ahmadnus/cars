<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PayrollPayment;
use App\Models\TrainerCompensationPayment;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side Arabic PDF generation.
 *
 * mPDF is used rather than dompdf because it shapes and joins Arabic glyphs and
 * handles RTL page direction natively — dompdf renders Arabic as disconnected
 * letters. Documents are rendered from Blade views so the receipt layout is
 * maintained the same way as the rest of the UI.
 */
class PdfService
{
    public function __construct(protected SettingsRepository $settings)
    {
    }

    /** Render a Blade view to raw PDF bytes. */
    public function render(string $view, array $data = [], array $options = []): string
    {
        $pdf = $this->engine($options);

        $pdf->SetTitle($options['title'] ?? 'مستند');
        $pdf->SetAuthor($this->settings->string('center.name', 'مركز تدريب القيادة'));
        $pdf->WriteHTML(View::make($view, $data)->render());

        return $pdf->Output('', 'S');
    }

    /** Render and return as a browser download. */
    public function download(string $view, array $data, string $filename, array $options = []): Response
    {
        return response($this->render($view, $data, $options), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Render and return for inline display. */
    public function stream(string $view, array $data, string $filename, array $options = []): Response
    {
        return response($this->render($view, $data, $options), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function paymentReceipt(Payment $payment): string
    {
        $payment->loadMissing(['trainee', 'paymentMethod', 'traineePackage', 'receiver', 'branch']);

        return $this->render('pdf.payment-receipt', [
            'payment' => $payment,
            'center' => $this->centerDetails($payment->branch_id),
        ], ['title' => 'إيصال قبض '.$payment->receipt_number]);
    }

    public function salaryReceipt(PayrollPayment $payment): string
    {
        $payment->loadMissing(['payroll.employee', 'payroll.advanceDeductions', 'paymentMethod', 'payer', 'branch']);

        return $this->render('pdf.salary-receipt', [
            'payment' => $payment,
            'payroll' => $payment->payroll,
            'center' => $this->centerDetails($payment->branch_id),
        ], ['title' => 'إيصال صرف راتب '.$payment->receipt_number]);
    }

    public function trainerPayoutReceipt(TrainerCompensationPayment $payment): string
    {
        $payment->loadMissing(['record.trainer', 'paymentMethod', 'payer', 'branch']);

        return $this->render('pdf.trainer-payout-receipt', [
            'payment' => $payment,
            'record' => $payment->record,
            'center' => $this->centerDetails($payment->branch_id),
        ], ['title' => 'إيصال صرف أجر مدرب '.$payment->receipt_number]);
    }

    /** Generic tabular report — used by every exportable report. */
    public function report(string $title, array $columns, array $rows, array $meta = [], array $totals = []): string
    {
        return $this->render('pdf.report', [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'meta' => $meta,
            'totals' => $totals,
            'center' => $this->centerDetails(),
        ], ['title' => $title, 'orientation' => count($columns) > 6 ? 'L' : 'P']);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function engine(array $options = []): Mpdf
    {
        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => ($options['format'] ?? 'A4').(($options['orientation'] ?? 'P') === 'L' ? '-L' : ''),
            'directionality' => 'rtl',
            'default_font' => 'dejavusans',
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_left' => 10,
            'margin_right' => 10,
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
    }

    /** Center details shown in every document header. */
    protected function centerDetails(?int $branchId = null): array
    {
        $branch = $branchId ? \App\Models\Branch::find($branchId) : null;

        return [
            'name' => $this->settings->string('center.name', 'مركز تدريب القيادة', $branchId),
            'phone' => $branch?->phone ?: $this->settings->string('center.phone', '', $branchId),
            'address' => $branch?->address ?: $this->settings->string('center.address', '', $branchId),
            'email' => $branch?->email ?: $this->settings->string('center.email', '', $branchId),
            'branch_name' => $branch?->name,
            'currency' => $this->settings->string('center.currency_label', 'د.أ', $branchId),
        ];
    }
}
