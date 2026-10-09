<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    public static function actors(): array
    {
        return [['student'], ['supervisor'], ['coordinator']];
    }

    #[DataProvider('actors')]
    public function test_inclusive_open_ended_ranges_use_activity_date_not_creation_timestamp(string $actor): void
    {
        [$parent, $rows] = $this->fixture();
        $rows[0]->forceFill(['created_at' => '2026-12-01 00:00:00'])->save();
        $this->signIn($parent, $actor);
        foreach ([
            [['date_from' => '2026-10-02'], [$rows[3]->id, $rows[2]->id, $rows[1]->id], '2.70'],
            [['date_to' => '2026-10-02'], [$rows[1]->id, $rows[0]->id], '0.30'],
            [['date_from' => '2026-10-02', 'date_to' => '2026-10-02'], [$rows[1]->id], '0.20'],
        ] as [$filters, $ids, $hours]) {
            $response = $this->getJson($this->path($parent, $actor, $filters))->assertOk()
                ->assertJsonPath('total_recorded_hours', '2.80')->assertJsonPath('filtered_recorded_hours', $hours);
            $this->assertSame($ids, array_column($response->json('data'), 'id'));
        }
    }

    #[DataProvider('actors')]
    public function test_case_insensitive_title_description_and_trimmed_search(string $actor): void
    {
        [$parent, $rows] = $this->fixture();
        $this->signIn($parent, $actor);
        $this->getJson($this->path($parent, $actor, ['search' => "\u{00a0}uI 100%\u{00a0}"]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rows[0]->id)->assertJsonPath('filtered_recorded_hours', '0.10');
        $this->getJson($this->path($parent, $actor, ['search' => 'BeTa']))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('filtered_recorded_hours', '2.70')->assertJsonPath('total_recorded_hours', '2.80');
    }

    #[DataProvider('actors')]
    public function test_wildcards_backslashes_sql_text_and_zero_are_literal_searches(string $actor): void
    {
        [$parent, $rows] = $this->fixture();
        $this->signIn($parent, $actor);
        foreach (['%', '_', '\\', 'under_score', '100%'] as $search) {
            $this->getJson($this->path($parent, $actor, ['search' => $search]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rows[0]->id)->assertJsonPath('filtered_recorded_hours', '0.10');
        }
        $this->getJson($this->path($parent, $actor, ['search' => '0']))->assertJsonCount(2, 'data')->assertJsonPath('filtered_recorded_hours', '0.30');
        $this->getJson($this->path($parent, $actor, ['search' => "%' OR 1=1 --"]))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('filtered_recorded_hours', '0.00')->assertJsonPath('total_recorded_hours', '2.80');
    }

    #[DataProvider('actors')]
    public function test_combined_filters_apply_before_pagination_and_preserve_totals_and_stable_order(string $actor): void
    {
        [$parent, $rows] = $this->fixture();
        $this->signIn($parent, $actor);
        $filters = ['date_from' => '2026-10-03', 'date_to' => '2026-10-03', 'search' => 'API', 'per_page' => 1];
        foreach ([$rows[3], $rows[2]] as $index => $row) {
            $response = $this->getJson($this->path($parent, $actor, [...$filters, 'page' => $index + 1]))->assertOk()
                ->assertJsonPath('data.0.id', $row->id)->assertJsonPath('meta.total', 2)
                ->assertJsonPath('total_recorded_hours', '2.80')->assertJsonPath('filtered_recorded_hours', '2.50');
            $query = [];
            parse_str(parse_url($response->json('links.first'), PHP_URL_QUERY), $query);
            $this->assertSame('2026-10-03', $query['date_from']);
            $this->assertSame('API', $query['search']);
        }
        $this->getJson($this->path($parent, $actor, $filters))->assertJsonPath('data.0.hours', null);
    }

    #[DataProvider('actors')]
    public function test_unfiltered_blank_and_no_match_summaries_preserve_existing_behavior(string $actor): void
    {
        [$parent] = $this->fixture();
        $this->signIn($parent, $actor);
        foreach ([[], ['search' => '  ', 'date_from' => '', 'date_to' => '']] as $filters) {
            $this->getJson($this->path($parent, $actor, $filters))->assertOk()->assertJsonCount(4, 'data')
                ->assertJsonPath('total_recorded_hours', '2.80')->assertJsonPath('filtered_recorded_hours', '2.80');
        }
        $this->getJson($this->path($parent, $actor, ['date_from' => '2026-11-01']))->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('filtered_recorded_hours', '0.00')->assertJsonPath('total_recorded_hours', '2.80');
        $this->getJson($this->path($parent, $actor, ['search' => 'API notes']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hours', null)->assertJsonPath('filtered_recorded_hours', '0.00');
    }

    #[DataProvider('actors')]
    public function test_invalid_calendar_dates_reversed_ranges_and_search_types_are_422(string $actor): void
    {
        [$parent] = $this->fixture();
        $this->signIn($parent, $actor);
        foreach ([
            ['date_from' => '2026-02-30'], ['date_to' => '2026-04-31'], ['date_from' => '2026-13-01'],
            ['date_to' => '2026-10-01T12:00:00Z'], ['date_from' => '0000-01-01'], ['date_from' => ['2026-10-01']],
            ['date_from' => '2026-10-03', 'date_to' => '2026-10-02'], ['search' => str_repeat('x', 256)],
            ['search' => ['API']], ['page' => 0], ['per_page' => 101],
        ] as $filters) {
            $this->getJson($this->path($parent, $actor, $filters))->assertUnprocessable();
        }
    }

    #[DataProvider('actors')]
    public function test_foreign_parent_authorization_precedes_filter_validation_and_summary_queries(string $actor): void
    {
        [$parent] = $this->fixture();
        [$foreign] = $this->fixture();
        $this->signIn($foreign, $actor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($this->path($parent, $actor, ['date_from' => 'bad', 'search' => ['unsafe']]))->assertNotFound();
            $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($query) => str_contains(strtolower($query['query']), 'sum(')));
        } finally {
            DB::disableQueryLog();
        }
        if ($actor === 'supervisor') {
            $sameCompany = $this->supervisor($parent->company_id);
            $this->actingAs($sameCompany)->getJson($this->path($parent, $actor, ['date_to' => 'bad']))->assertNotFound();
        }
        $this->signIn($parent, $actor);
        $this->getJson($this->path($parent, $actor, ['search' => 'API', 'student_id' => $foreign->student_id, 'company_id' => $foreign->company_id]))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('total_recorded_hours', '2.80');
    }

    #[DataProvider('actors')]
    public function test_filtered_queries_remain_constant_as_record_count_grows(string $actor): void
    {
        [$parent] = $this->fixture();
        $this->signIn($parent, $actor);
        $count = function () use ($parent, $actor): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->path($parent, $actor, ['search' => 'API', 'date_from' => '2026-10-02']))->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        for ($i = 0; $i < 5; $i++) {
            $parent->activityLogs()->create(['activity_date' => '2026-10-03', 'title' => 'API diary', 'description' => 'More', 'hours' => '0.10']);
        }
        $this->assertSame($small, $count());
    }

    public function test_totals_refresh_after_student_creation_and_editing_for_both_roles(): void
    {
        [$parent] = $this->fixture();
        $this->signIn($parent, 'student');
        $id = $this->postJson('/api/student/internships/'.$parent->id.'/activities', ['activity_date' => '2026-10-02', 'title' => 'API work', 'description' => 'Additional work', 'hours' => '1.10'])
            ->assertCreated()->json('data.id');
        foreach (['student', 'supervisor'] as $actor) {
            $this->signIn($parent, $actor);
            $this->getJson($this->path($parent, $actor, ['date_to' => '2026-10-02']))->assertJsonPath('total_recorded_hours', '3.90')->assertJsonPath('filtered_recorded_hours', '1.40');
        }
        $this->signIn($parent, 'student');
        $this->patchJson('/api/student/activities/'.$id, ['hours' => '1.25', 'activity_date' => '2026-10-03'])->assertOk();
        foreach (['student', 'supervisor'] as $actor) {
            $this->signIn($parent, $actor);
            $this->getJson($this->path($parent, $actor, ['date_to' => '2026-10-02']))->assertJsonPath('total_recorded_hours', '4.05')->assertJsonPath('filtered_recorded_hours', '0.30');
        }
    }

    private function fixture(): array
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED', 'is_active' => true]);
        $supervisor = $this->supervisor($company->id);
        $coordinator = $this->user('ACADEMIC_COORDINATOR');
        $coordinator->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']);
        $parent = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'coordinator_id' => $coordinator->id, 'company_supervisor_id' => $supervisor->companySupervisorProfile->getKey(),
            'position_title' => 'Filter internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE']);
        $rows = [];
        foreach ([
            ['2026-10-01', 'UI 100% coverage', 'Version alpha under_score C:\\reports', '0.10'],
            ['2026-10-02', 'API 100x coverage', 'Version BETA underXscore', '0.20'],
            ['2026-10-03', 'API Integration', 'Version beta C:/reports', '2.50'],
            ['2026-10-03', 'API notes', 'beta', null],
        ] as [$date, $title, $description, $hours]) {
            $rows[] = $parent->activityLogs()->create(['activity_date' => $date, 'title' => $title, 'description' => $description, 'hours' => $hours]);
        }

        return [$parent, $rows];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
    }

    private function supervisor(int $company): User
    {
        $user = $this->user('COMPANY_SUPERVISOR');
        $user->companySupervisorProfile()->create(['company_id' => $company, 'verification_status' => 'APPROVED']);

        return $user;
    }

    private function signIn(Internship $parent, string $actor): void
    {
        $user = match ($actor) {
            'student' => $parent->student->user,
            'coordinator' => $parent->coordinator->user,
            default => $parent->companySupervisor->user,
        };
        $this->actingAs($user->load(['role', 'companySupervisorProfile.company']));
    }

    private function path(Internship $parent, string $actor, array $filters = []): string
    {
        return '/api/'.($actor === 'coordinator' ? 'coordinator/monitoring' : $actor).'/internships/'.$parent->id.'/activities?'.http_build_query($filters);
    }
}
