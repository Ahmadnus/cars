<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Services\CashboxService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashboxController extends Controller
{
    public function __construct(
        protected CashboxService $cashboxes,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('cashbox.view');

        $branchIds = $this->branchContext->scopeIds($request->user()) ?: [0];

        $boxes = Cashbox::whereIn('branch_id', $branchIds)->with('branch:id,uuid,name')->get();

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now()->endOfMonth();

        $transactions = CashboxTransaction::query()
            ->whereIn('branch_id', $branchIds)
            ->with(['creator:id,name', 'cashbox:id,name'])
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $today = $boxes->isNotEmpty()
            ? $this->cashboxes->dailyTotals($boxes->first(), now())
            : ['in' => 0, 'out' => 0, 'net' => 0];

        return view('admin.cashbox.index', [
            'boxes' => $boxes,
            'balance' => round((float) $boxes->sum('current_balance'), 2),
            'transactions' => $transactions,
            'today' => $today,
            'from' => $from,
            'to' => $to,
            'periodIn' => round((float) CashboxTransaction::whereIn('branch_id', $branchIds)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->where('direction', 'in')->sum('amount'), 2),
            'periodOut' => round((float) CashboxTransaction::whereIn('branch_id', $branchIds)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->where('direction', 'out')->sum('amount'), 2),
            'closings' => \App\Models\CashboxClosing::whereIn('branch_id', $branchIds)
                ->with('closer:id,name')->orderByDesc('closing_date')->limit(10)->get(),
            'categories' => self::categories(),
        ]);
    }

    /** Record the daily cash count. */
    public function close(Request $request): RedirectResponse
    {
        $this->authorize('cashbox.manage');

        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'closing_date' => ['required', 'date', 'before_or_equal:today'],
            'counted_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'closing_date' => 'تاريخ الإقفال',
            'counted_balance' => 'الرصيد المعدود',
        ]);

        $cashbox = Cashbox::findOrFail($data['cashbox_id']);
        abort_unless($request->user()->canAccessBranch($cashbox->branch_id), 403);

        $closing = $this->cashboxes->close(
            $cashbox,
            Carbon::parse($data['closing_date']),
            (float) $data['counted_balance'],
            $data['notes'] ?? null,
        );

        $message = $closing->isBalanced()
            ? 'تم إقفال الصندوق ومطابقة الرصيد بنجاح.'
            : 'تم إقفال الصندوق مع فرق قدره '.money($closing->difference).'.';

        return back()->with('toast', [
            'type' => $closing->isBalanced() ? 'success' : 'warning',
            'message' => $message,
        ]);
    }

    /**
     * Post a manual adjustment.
     *
     * Deliberately a normal ledger entry rather than an edit: the ledger stays
     * append-only and the adjustment is visible as its own line.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $this->authorize('cashbox.manage');

        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
        ], [], [
            'direction' => 'نوع الحركة',
            'amount' => 'المبلغ',
            'description' => 'البيان',
            'transaction_date' => 'التاريخ',
        ]);

        $cashbox = Cashbox::findOrFail($data['cashbox_id']);
        abort_unless($request->user()->canAccessBranch($cashbox->branch_id), 403);

        \Illuminate\Support\Facades\DB::transaction(function () use ($cashbox, $data) {
            $this->cashboxes->post(
                cashbox: $cashbox,
                direction: $data['direction'],
                amount: (float) $data['amount'],
                category: 'adjustment',
                source: null,
                description: $data['description'],
                date: Carbon::parse($data['transaction_date']),
            );
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تسجيل حركة الصندوق.']);
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'payment' => 'دفعات متدربين',
            'expense' => 'مصاريف',
            'payroll' => 'رواتب',
            'trainer_compensation' => 'أجور مدربين',
            'adjustment' => 'تسوية',
            'opening' => 'رصيد افتتاحي',
        ];
    }
}
