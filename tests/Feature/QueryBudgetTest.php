<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The pages staff open all day, measured under realistic volume.
 *
 * A list that runs one query per row looks instant on a laptop with four
 * trainees and takes ten seconds once a branch has four hundred. The budget is
 * not about a number being elegant — it is that the query count must not grow
 * with the number of rows on the page, which is the difference between a system
 * that survives its second year and one that gets described as "slow" and worked
 * around.
 *
 * Each page is loaded twice, with different amounts of data, and the counts are
 * compared. A page whose cost is flat passes; one that scales with rows fails
 * with the two numbers in the message.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /** @return int number of queries the page ran */
    protected function countQueriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    protected function seedVolume(int $trainees): void
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $vehicle = Vehicle::factory()->create(['branch_id' => $this->branch->id]);
        $package = Package::factory()->create(['lessons_count' => 10, 'price' => 200]);

        for ($i = 0; $i < $trainees; $i++) {
            $trainee = Trainee::factory()->create([
                'branch_id' => $this->branch->id,
                'trainer_id' => $trainer->id,
            ]);

            app(\App\Services\PackageService::class)->assign($trainee, $package, ['discount_percent' => 0]);

            TrainingSession::create([
                'branch_id' => $this->branch->id,
                'trainee_id' => $trainee->id,
                'trainer_id' => $trainer->id,
                'vehicle_id' => $vehicle->id,
                'scheduled_date' => $this->workingDay(minimumOffset: 2)->toDateString(),
                'start_time' => '09:00',
                'end_time' => '09:45',
                'duration_minutes' => 45,
                'status' => 'scheduled',
            ]);
        }
    }

    /**
     * The daily pages, each measured at two volumes.
     *
     * Five rows then twenty-five. A flat page answers both in the same number of
     * queries; one with an N+1 answers the second in roughly twenty more.
     */
    public function test_the_daily_pages_do_not_query_per_row(): void
    {
        $this->actingAsUser($this->admin());

        $pages = [
            'admin.trainees.index' => route('admin.trainees.index'),
            'admin.sessions.index' => route('admin.sessions.index'),
            'admin.calendar.index' => route('admin.calendar.index'),
            'admin.dashboard' => route('admin.dashboard'),
            'admin.payments.index' => route('admin.payments.index'),
        ];

        $this->seedVolume(5);

        $small = [];
        foreach ($pages as $name => $url) {
            $small[$name] = $this->countQueriesFor($url);
        }

        $this->seedVolume(20);

        $failures = [];
        foreach ($pages as $name => $url) {
            $large = $this->countQueriesFor($url);

            // A little slack: a page may legitimately run one or two extra
            // queries as new kinds of data appear (a package where there was
            // none). Twenty more for twenty rows is an N+1, not slack.
            if ($large > $small[$name] + 5) {
                $failures[] = sprintf(
                    '%s: %d queries at 5 trainees, %d at 25 — the cost grows with the rows',
                    $name,
                    $small[$name],
                    $large,
                );
            }
        }

        $this->assertSame([], $failures, "\n".implode("\n", $failures)."\n");
    }

    /**
     * A trainee's own file, which is the heaviest single page in the system.
     *
     * It shows lessons, evaluations, payments, packages and documents at once,
     * so it is the page most likely to grow a per-row query unnoticed.
     */
    public function test_a_trainee_file_is_flat(): void
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $trainer->id,
        ]);

        app(\App\Services\PackageService::class)->assign(
            $trainee,
            Package::factory()->create(['lessons_count' => 30, 'price' => 600]),
            ['discount_percent' => 0],
        );

        $this->actingAsUser($this->admin());

        $url = route('admin.trainees.show', $trainee);

        $make = function (int $count) use ($trainee, $trainer) {
            for ($i = 0; $i < $count; $i++) {
                TrainingSession::create([
                    'branch_id' => $this->branch->id,
                    'trainee_id' => $trainee->id,
                    'trainer_id' => $trainer->id,
                    'scheduled_date' => now()->subDays($i + 1)->toDateString(),
                    'start_time' => '09:00',
                    'end_time' => '09:45',
                    'duration_minutes' => 45,
                    'status' => 'completed',
                ]);
            }
        };

        $make(3);
        $small = $this->countQueriesFor($url);

        $make(15);
        $large = $this->countQueriesFor($url);

        $this->assertLessThanOrEqual(
            $small + 5,
            $large,
            "the trainee file ran {$small} queries with 3 lessons and {$large} with 18",
        );
    }
}
