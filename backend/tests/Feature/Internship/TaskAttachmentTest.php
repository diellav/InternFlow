<?php

namespace Tests\Feature\Internship;

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmissionFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PharData;
use Tests\TestCase;

class TaskAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('task_attachments');
    }

    public function test_text_only_and_url_with_multiple_files_keep_safe_history(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        $this->postJson($this->submit($task), ['submission_text' => 'Text only'])->assertOk();
        $this->getJson($this->submit($task))->assertJsonPath('data.0.files', []);
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        $this->postJson($this->submit($task), ['submission_text' => 'With files', 'resource_url' => 'https://example.com/project', 'files' => [$this->pdf('Report.pdf'), $this->pdf('Notes.pdf')]])->assertOk();
        $response = $this->getJson($this->submit($task))->assertOk()->assertJsonCount(2, 'data.0.files')->assertJsonPath('data.0.files.0.original_name', 'Report.pdf')->assertJsonMissingPath('data.0.files.0.storage_path');
        $this->assertSame('/api/task-submission-files/'.$response->json('data.0.files.0.id').'/download', $response->json('data.0.files.0.download_path'));
        foreach ($task->submissions()->sole()->files as $file) {
            Storage::disk('task_attachments')->assertExists($file->storage_path);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.bin$/', $file->storage_path);
            $this->assertSame('application/pdf', $file->mime_type);
            $this->assertGreaterThan(0, $file->size_bytes);
        }
    }

    public function test_resubmission_files_are_version_specific_and_review_history_is_immutable(): void
    {
        $task = $this->task();
        $student = $task->internship->student->user;
        $supervisor = $task->internship->companySupervisor->user;
        $this->actingAs($student)->postJson($this->submit($task), ['submission_text' => 'Version 1', 'files' => [$this->pdf('Report_v1.pdf')]])->assertOk();
        $first = $task->submissions()->sole();
        $originalFile = $first->files()->sole()->getAttributes();
        $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['decision' => 'REVISION_REQUIRED', 'comment' => 'Add tests', 'expected_submission_id' => $first->id])->assertOk();
        $originalFeedback = $first->fresh()->feedback->getAttributes();
        $this->actingAs($student)->postJson('/api/student/tasks/'.$task->id.'/resubmit', ['submission_text' => 'Version 2', 'expected_submission_id' => $first->id, 'files' => [$this->pdf('Report_v2.pdf')]])->assertOk();
        $latest = $task->submissions()->orderByDesc('version_no')->first();
        $this->assertSame(2, $latest->version_no);
        $this->assertSame('Report_v2.pdf', $latest->files()->sole()->original_name);
        $this->assertSame($originalFile, $first->files()->sole()->getAttributes());
        $this->assertSame($originalFeedback, $first->fresh()->feedback->getAttributes());
        $this->actingAs($supervisor)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['decision' => 'APPROVED', 'expected_submission_id' => $latest->id])->assertOk();
        $this->getJson('/api/supervisor/tasks/'.$task->id.'/submissions')->assertJsonPath('data.0.files.0.original_name', 'Report_v2.pdf')->assertJsonPath('data.1.files.0.original_name', 'Report_v1.pdf');
        $this->actingAs($student)->getJson($this->download($first->files()->sole()))->assertOk();
    }

    public function test_resubmission_without_files_does_not_copy_previous_attachments(): void
    {
        $task = $this->task();
        $student = $task->internship->student->user;
        $this->actingAs($student)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf()]])->assertOk();
        $first = $task->submissions()->sole();
        $this->actingAs($task->internship->companySupervisor->user)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['decision' => 'REVISION_REQUIRED', 'comment' => 'Revise', 'expected_submission_id' => $first->id])->assertOk();
        $this->actingAs($student)->postJson('/api/student/tasks/'.$task->id.'/resubmit', ['submission_text' => 'Revision', 'expected_submission_id' => $first->id])->assertOk();
        $this->getJson($this->submit($task))->assertJsonPath('data.0.files', [])->assertJsonCount(1, 'data.1.files');
    }

    public function test_supported_content_and_office_containers_are_accepted(): void
    {
        foreach (['docx', 'xlsx', 'zip'] as $extension) {
            $task = $this->task();
            $path = tempnam(sys_get_temp_dir(), 'if-');
            $archivePath = $path.'.zip';
            try {
                $archive = new PharData($archivePath);
                $archive->addFromString('[Content_Types].xml', '<Types/>');
                $archive->addFromString($extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml', '<document/>');
                unset($archive);
                $upload = UploadedFile::fake()->createWithContent('work.'.$extension, file_get_contents($archivePath));
                $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$upload]])->assertOk();
            } finally {
                unlink($archivePath);
                unlink($path);
            }
        }
    }

    public function test_invalid_extensions_mime_sizes_count_and_uploads_store_nothing(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        $cases = [
            [$this->pdf('work.exe')], [$this->pdf('work.php')], [$this->pdf('work.html')], [$this->pdf('work.svg')],
            [UploadedFile::fake()->createWithContent('work.pdf', '<?php echo 1;')],
            [$this->pdf('work.png')], [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')],
            array_map(fn () => $this->pdf(), range(1, 6)), ['not a file'],
        ];
        foreach ($cases as $index => $files) {
            $response = $this->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => $files]);
            $this->assertSame(422, $response->status(), 'Invalid case '.$index.': '.$response->getContent());
        }
        $this->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf()], 'storage_path' => '../unsafe'])->assertUnprocessable();
        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_submission_files', 0);
        $this->assertSame([], Storage::disk('task_attachments')->allFiles());
    }

    public function test_database_failure_cleans_files_and_rolls_back_status_and_history(): void
    {
        $task = $this->task();
        $dispatcher = TaskSubmissionFile::getEventDispatcher();
        TaskSubmissionFile::setEventDispatcher(clone $dispatcher);
        $created = 0;
        TaskSubmissionFile::creating(function (TaskSubmissionFile $file) use (&$created): void {
            if (++$created === 2) {
                $file->mime_type = null;
            }
        });
        try {
            $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf(), $this->pdf()]])->assertServerError();
        } finally {
            TaskSubmissionFile::setEventDispatcher($dispatcher);
        }
        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_submission_files', 0);
        $this->assertSame([], Storage::disk('task_attachments')->allFiles());
    }

    public function test_stale_resubmission_cleans_only_new_uploads_and_preserves_old_files(): void
    {
        $task = $this->task();
        $student = $task->internship->student->user;
        $this->actingAs($student)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf()]])->assertOk();
        $submission = $task->submissions()->sole();
        $original = $submission->files()->sole();
        $this->actingAs($task->internship->companySupervisor->user)->postJson('/api/supervisor/tasks/'.$task->id.'/review', ['decision' => 'REVISION_REQUIRED', 'comment' => 'Revise', 'expected_submission_id' => $submission->id])->assertOk();
        $this->actingAs($student)->postJson('/api/student/tasks/'.$task->id.'/resubmit', ['submission_text' => 'Correction', 'expected_submission_id' => $submission->id + 100, 'files' => [$this->pdf()]])->assertConflict();
        $this->assertSame([$original->storage_path], Storage::disk('task_attachments')->allFiles());
        $this->assertSame('REVISION_REQUIRED', $task->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('task_submission_files', 1);
        $this->assertDatabaseCount('task_feedback', 1);
    }

    public function test_second_file_storage_failure_cleans_first_and_never_mutates_task(): void
    {
        $task = $this->task();
        $disk = Storage::disk('task_attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $calls = 0;
        $mock->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($disk, &$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Simulated storage failure');
            }

            return $disk->putFileAs(...$arguments);
        });
        Storage::set('task_attachments', $mock);
        $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf(), $this->pdf()]])->assertServerError();
        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
    }

    public function test_authorized_downloads_have_safe_headers_and_sanitized_names(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf("..\\unsafe\r\nname.pdf")]])->assertOk();
        $file = TaskSubmissionFile::sole();
        $this->assertSame('unsafename.pdf', $file->original_name);
        foreach ([$task->internship->student->user, $task->internship->companySupervisor->user] as $user) {
            $response = $this->actingAs($user)->getJson($this->download($file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/octet-stream');
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        }
        $file->update(['storage_path' => '../unsafe']);
        $this->getJson($this->download($file))->assertNotFound();
    }

    public function test_foreign_roles_guests_and_ineligible_supervisors_cannot_download(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf()]])->assertOk();
        $file = TaskSubmissionFile::sole();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->download($file))->assertUnauthorized();
        $other = $this->task();
        foreach ([$other->internship->student->user, $other->internship->companySupervisor->user, $this->user('ADMIN'), $this->user('ACADEMIC_COORDINATOR')] as $actor) {
            $this->actingAs($actor)->getJson($this->download($file))->assertNotFound();
        }
        $supervisor = $task->internship->companySupervisor->user;
        foreach (['PENDING', 'REJECTED'] as $status) {
            $supervisor->companySupervisorProfile()->update(['verification_status' => $status]);
            $this->actingAs($supervisor->fresh())->getJson($this->download($file))->assertNotFound();
        }
        $supervisor->companySupervisorProfile()->update(['verification_status' => 'APPROVED']);
        $task->internship->company->update(['is_active' => false]);
        $this->actingAs($supervisor->fresh())->getJson($this->download($file))->assertNotFound();
        $supervisor->update(['is_active' => false]);
        $this->actingAs($supervisor->fresh())->getJson($this->download($file))->assertForbidden();
    }

    public function test_history_eager_loads_files_without_per_submission_queries(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user->load('role'));
        $this->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf()]])->assertOk();
        $count = function () use ($task): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($this->submit($task))->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $count();
        for ($version = 2; $version <= 5; $version++) {
            $record = $task->submissions()->create(['version_no' => $version, 'submission_text' => 'History', 'submitted_at' => now()]);
            $record->files()->create(['original_name' => 'History.pdf', 'storage_path' => 'history-'.$version.'.bin', 'mime_type' => 'application/pdf', 'size_bytes' => 20]);
        }
        $this->assertSame($small, $count());
    }

    public function test_image_content_is_accepted_and_missing_private_file_is_404(): void
    {
        $task = $this->task();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
        $jpeg = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
        $this->actingAs($task->internship->student->user)->postJson($this->submit($task), ['submission_text' => 'Images', 'files' => [UploadedFile::fake()->createWithContent('image.png', $png), UploadedFile::fake()->createWithContent('image.jpg', $jpeg)]])->assertOk();
        $this->assertSame(['image/png', 'image/jpeg'], $task->submissions()->sole()->files()->pluck('mime_type')->all());
        $file = $task->submissions()->sole()->files()->first();
        Storage::disk('task_attachments')->delete($file->storage_path);
        $this->getJson($this->download($file))->assertNotFound();
    }

    public function test_malformed_upload_and_incorrect_office_container_are_rejected(): void
    {
        $task = $this->task();
        $this->actingAs($task->internship->student->user);
        $path = tempnam(sys_get_temp_dir(), 'if-');
        try {
            $invalid = new UploadedFile($path, 'Report.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
            $this->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$invalid]])->assertUnprocessable();
            $this->postJson($this->submit($task), ['submission_text' => 'Work', 'files' => [$this->pdf('Report.docx')]])->assertUnprocessable();
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertSame([], Storage::disk('task_attachments')->allFiles());
    }

    private function pdf(string $name = 'Report.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function task(): Task
    {
        $student = $this->user('STUDENT');
        $student->studentProfile()->create(['student_number' => fake()->uuid(), 'study_program' => 'CS', 'study_year' => 2]);
        $company = Company::create(['name' => fake()->company(), 'verification_status' => 'APPROVED']);
        $supervisor = $this->user('COMPANY_SUPERVISOR');
        $supervisor->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']);
        $internship = Internship::create(['student_id' => $student->id, 'company_id' => $company->id, 'company_supervisor_id' => $supervisor->id, 'position_title' => 'Web internship', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'ACTIVE']);

        return Task::create(['internship_id' => $internship->id, 'assigned_by' => $supervisor->id, 'title' => 'Build form', 'description' => 'Test inputs', 'priority' => 'MEDIUM', 'status' => 'IN_PROGRESS']);
    }

    private function submit(Task $task): string
    {
        return '/api/student/tasks/'.$task->id.'/submissions';
    }

    private function download(TaskSubmissionFile $file): string
    {
        return '/api/task-submission-files/'.$file->id.'/download';
    }
}
