<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TaskSubmissionFile;
use App\Modules\Monitoring\Services\CoordinatorMonitoringService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskSubmissionFileController extends Controller
{
    public function download(Request $request, int $file): StreamedResponse
    {
        $user = $request->user();
        $record = TaskSubmissionFile::with('submission.task.internship')->findOrFail($file);
        $internship = $record->submission->task->internship;
        $allowed = false;
        if ($user->hasRole(UserRole::STUDENT)) {
            $allowed = $user->studentProfile()->exists() && $internship->student_id === $user->id;
        } elseif ($user->hasRole(UserRole::COMPANY_SUPERVISOR)) {
            $profile = $user->companySupervisorProfile()->with('company')->first();
            $allowed = $profile !== null && $internship->company_supervisor_id === $user->id
                && $profile->company_id === $internship->company_id && $profile->verification_status === VerificationStatus::APPROVED
                && $profile->company?->is_active && $profile->company->verification_status === VerificationStatus::APPROVED;
        } elseif ($user->hasRole(UserRole::ACADEMIC_COORDINATOR)) {
            $allowed = app(CoordinatorMonitoringService::class)->internships($user)->whereKey($internship->id)->exists();
        }
        abort_unless($allowed, 404);
        abort_unless(preg_match('/^[0-9a-f-]{36}\.bin$/D', $record->storage_path), 404);
        $disk = Storage::disk('task_attachments');
        abort_unless($disk->exists($record->storage_path), 404);

        return $disk->download($record->storage_path, $record->original_name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
