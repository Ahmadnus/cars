<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();   // cash | cashbox | bank_transfer | cliq | card | cheque | other
            $table->string('label_ar');
            // Cash-like methods move the branch cashbox balance; others do not.
            $table->boolean('affects_cashbox')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('cashboxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('opening_balance', 14, 2)->default(0);
            // Denormalised running balance, only ever moved by CashboxService
            // inside the same transaction that writes the ledger row.
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['branch_id', 'name']);
        });

        // Append-only cash ledger. Rows are never updated or deleted; a mistake
        // is corrected by posting a reversing row.
        Schema::create('cashbox_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cashbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('category', 60);          // payment | expense | payroll | trainer_compensation | adjustment | opening | closing
            $table->string('sourceable_type')->nullable();
            $table->unsignedBigInteger('sourceable_id')->nullable();
            $table->string('description')->nullable();
            $table->date('transaction_date');
            $table->foreignId('reverses_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cashbox_id', 'transaction_date']);
            $table->index(['branch_id', 'transaction_date']);
            $table->index(['sourceable_type', 'sourceable_id']);
        });

        Schema::table('cashbox_transactions', function (Blueprint $table) {
            $table->foreign('reverses_id')->references('id')->on('cashbox_transactions')->nullOnDelete();
        });

        Schema::create('cashbox_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('closing_date');
            $table->decimal('opening_balance', 14, 2);
            $table->decimal('total_in', 14, 2);
            $table->decimal('total_out', 14, 2);
            $table->decimal('expected_balance', 14, 2);
            $table->decimal('counted_balance', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cashbox_id', 'closing_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('trainee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainee_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['package', 'extra_lesson', 'other'])->default('package');
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference_number', 80)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'paid_on', 'status']);
            $table->index(['trainee_id', 'status']);
            $table->index('paid_on');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name_ar');
            // Fixes the category into the P&L so profit lines stay stable even
            // when a center renames its own categories.
            $table->string('profit_bucket', 40)->default('other');
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('spent_on');
            $table->string('beneficiary')->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->text('notes')->nullable();

            // Links back to whatever generated this expense (payroll run,
            // trainer payout, recurring template, vehicle maintenance…).
            $table->string('sourceable_type')->nullable();
            $table->unsignedBigInteger('sourceable_id')->nullable();

            $table->enum('status', ['recorded', 'cancelled'])->default('recorded');
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'spent_on', 'status']);
            $table->index(['expense_category_id', 'spent_on']);
            $table->index('spent_on');
            $table->index(['sourceable_type', 'sourceable_id']);
        });

        Schema::table('vehicle_maintenances', function (Blueprint $table) {
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->enum('frequency', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('last_generated_on')->nullable();
            // Generated rows land as drafts for review rather than posting silently.
            $table->boolean('auto_post')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('utility_bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('service_type', ['electricity', 'water', 'internet', 'telephone', 'rent']);
            $table->string('billing_month', 7);   // YYYY-MM
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->date('paid_on')->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['unpaid', 'paid', 'overdue'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['branch_id', 'service_type', 'billing_month']);
            $table->index(['branch_id', 'status', 'due_date']);
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('granted_on');
            $table->unsignedSmallInteger('installments_count')->default(1);
            $table->decimal('installment_amount', 12, 2);
            $table->decimal('deducted_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'settled', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('period', 7);          // YYYY-MM
            $table->decimal('base_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('advance_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['employee_id', 'period']);
            $table->index(['branch_id', 'period', 'status']);
        });

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('reference_number', 80)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payroll_id', 'paid_on']);
        });

        // Installment deductions applied to a specific payroll run.
        Schema::create('advance_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['employee_advance_id', 'payroll_id']);
        });

        Schema::create('trainer_compensation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->enum('model', [
                'monthly_salary',
                'per_lesson',
                'revenue_percentage',
                'salary_plus_percentage',
                'salary_plus_per_lesson',
            ]);
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('per_lesson_rate', 12, 2)->default(0);
            $table->decimal('revenue_percentage', 5, 2)->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trainer_id', 'effective_from']);
        });

        Schema::create('trainer_compensation_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->restrictOnDelete();
            $table->foreignId('trainer_compensation_rule_id')->nullable();
            $table->foreign('trainer_compensation_rule_id', 'tcr_rule_fk')
                ->references('id')->on('trainer_compensation_rules')->nullOnDelete();
            $table->string('period', 7);          // YYYY-MM
            $table->string('model', 40);
            $table->unsignedSmallInteger('lessons_count')->default(0);
            $table->unsignedInteger('training_minutes')->default(0);
            $table->decimal('attributed_revenue', 14, 2)->default(0);
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('lesson_earnings', 12, 2)->default(0);
            $table->decimal('percentage_earnings', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->enum('status', ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['trainer_id', 'period']);
            $table->index(['branch_id', 'period', 'status']);
        });

        Schema::create('trainer_compensation_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trainer_compensation_record_id')->constrained('trainer_compensation_records', 'id', 'tcp_record_fk')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('reference_number', 80)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_maintenances', function (Blueprint $table) {
            $table->dropForeign(['expense_id']);
        });
        Schema::dropIfExists('trainer_compensation_payments');
        Schema::dropIfExists('trainer_compensation_records');
        Schema::dropIfExists('trainer_compensation_rules');
        Schema::dropIfExists('advance_deductions');
        Schema::dropIfExists('payroll_payments');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('utility_bills');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cashbox_closings');
        Schema::table('cashbox_transactions', function (Blueprint $table) {
            $table->dropForeign(['reverses_id']);
        });
        Schema::dropIfExists('cashbox_transactions');
        Schema::dropIfExists('cashboxes');
        Schema::dropIfExists('payment_methods');
    }
};
