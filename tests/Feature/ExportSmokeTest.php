<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Services\PackageService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Report exports must actually produce valid PDF, XLSX and CSV bytes. */
class ExportSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_export_to_pdf_excel_and_csv(): void
    {
        $this->seedFoundation();
        $this->actingAsUser($this->admin());

        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $enrolment = app(PackageService::class)->assign($trainee, Package::factory()->create());

        app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => PaymentMethod::where('code', 'cash')->value('id'),
            'amount' => 75,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        // PDF: check the magic bytes, not just the status.
        $pdf = $this->get('/reports/revenue/export/pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        // XLSX is a zip container, served as a file download.
        $xlsx = $this->get('/reports/revenue/export/xlsx');
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', file_get_contents($xlsx->getFile()->getPathname()));

        // CSV must carry the UTF-8 BOM or Excel mangles the Arabic.
        $csv = $this->get('/reports/revenue/export/csv');
        $csv->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent());
        $this->assertStringContainsString($trainee->full_name, $csv->streamedContent());
    }

    public function test_payment_and_salary_receipts_render_as_pdf(): void
    {
        $this->seedFoundation();
        $this->actingAsUser($this->admin());

        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $enrolment = app(PackageService::class)->assign($trainee, Package::factory()->create());

        $payment = app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => PaymentMethod::where('code', 'cash')->value('id'),
            'amount' => 75,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $receipt = $this->get("/payments/{$payment->uuid}/receipt");
        $receipt->assertOk();
        $this->assertStringStartsWith('%PDF-', $receipt->getContent());

        $employee = \App\Models\Employee::factory()->create(['branch_id' => $this->branch->id]);
        $payrollService = app(\App\Services\PayrollService::class);
        $payroll = $payrollService->generate($employee, now()->format('Y-m'));
        $salaryPayment = $payrollService->pay(
            $payroll,
            (float) $payroll->net_salary,
            now()->toDateString(),
            PaymentMethod::where('code', 'cash')->value('id'),
        );

        $salaryReceipt = $this->get("/payroll/payments/{$salaryPayment->uuid}/receipt");
        $salaryReceipt->assertOk();
        $this->assertStringStartsWith('%PDF-', $salaryReceipt->getContent());
    }
}
