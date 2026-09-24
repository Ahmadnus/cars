<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The scheduling calendar: day, week and month views over training sessions.
 *
 * Everything is rendered server-side; Alpine only toggles the view switcher, so
 * a large branch schedule never ships thousands of rows to the browser.
 */
class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingSession::class);

        $view = in_array($request->input('view'), ['day', 'week', 'month'], true)
            ? $request->input('view')
            : 'week';

        $anchor = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : now();

        [$start, $end] = $this->range($view, $anchor);

        $sessions = TrainingSession::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name,trainee_number', 'trainer:id,uuid,full_name', 'vehicle:id,uuid,name'])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->when($request->filled('trainer_id'), fn ($q) => $q->where('trainer_id', $request->integer('trainer_id')))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (TrainingSession $s) => $s->scheduled_date->toDateString());

        return view('admin.calendar.index', [
            'view' => $view,
            'anchor' => $anchor,
            'start' => $start,
            'end' => $end,
            'sessions' => $sessions,
            'days' => $this->days($start, $end),
            'trainers' => Trainer::query()->visibleTo($request->user())->active()
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'vehicles' => Vehicle::query()->visibleTo($request->user())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => TrainingSessionController::statuses(),
            'previous' => $this->shift($view, $anchor, -1),
            'next' => $this->shift($view, $anchor, 1),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function range(string $view, Carbon $anchor): array
    {
        return match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            // The working week here starts on Sunday, as it does in Jordan.
            'month' => [
                $anchor->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY),
                $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY),
            ],
            default => [
                $anchor->copy()->startOfWeek(Carbon::SUNDAY),
                $anchor->copy()->endOfWeek(Carbon::SATURDAY),
            ],
        };
    }

    /** @return array<int, Carbon> */
    protected function days(Carbon $start, Carbon $end): array
    {
        $days = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }

    protected function shift(string $view, Carbon $anchor, int $direction): string
    {
        return match ($view) {
            'day' => $anchor->copy()->addDays($direction)->toDateString(),
            'month' => $anchor->copy()->addMonthsNoOverflow($direction)->toDateString(),
            default => $anchor->copy()->addWeeks($direction)->toDateString(),
        };
    }
}
