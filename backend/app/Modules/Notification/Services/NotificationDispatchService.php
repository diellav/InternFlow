<?php

namespace App\Modules\Notification\Services;

use App\Models\Conversation;
use App\Models\Internship;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use App\Shared\Enums\UserRole;
use App\Shared\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class NotificationDispatchService
{
    public function messageReceived(Conversation $conversation, Message $message): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Business notifications require an active transaction.');
        }
        try {
            DB::transaction(function () use ($conversation, $message): void {
                foreach ($conversation->participants->sortBy('id') as $recipient) {
                    if ($recipient->id === $message->sender_id) {
                        continue;
                    }
                    try {
                        app(MessagingService::class)->conversation($recipient, $conversation->id);
                    } catch (HttpException|ModelNotFoundException $denied) {
                        continue;
                    }
                    $payload = NotificationPayload::make(['category' => 'message_received', 'title' => 'Mesazh i ri',
                        'message' => 'Keni pranuar një mesazh të ri.', 'action_url' => '/messages/conversations/'.$conversation->id,
                        'related_type' => 'conversation', 'related_id' => $conversation->id]);
                    $timestamp = now()->toIso8601String();
                    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'message_received',
                        'notifiable_id' => $recipient->id, 'notifiable_type' => $recipient->getMorphClass(),
                        'data' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                }
            });
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function applicationSubmitted(Internship $internship): void
    {
        $this->send($internship, UserRole::ACADEMIC_COORDINATOR, 'application_submitted', 'Aplikimi u dorëzua',
            'Një aplikim i caktuar për praktikë është dorëzuar për shqyrtim.', '/coordinator/internships/'.$internship->id);
    }

    public function applicationDecided(Internship $internship): void
    {
        [$category, $title, $message] = match ($internship->status) {
            'APPROVED' => ['application_approved', 'Aplikimi u miratua', 'Aplikimi juaj për praktikë është miratuar.'],
            'REJECTED' => ['application_rejected', 'Aplikimi u refuzua', 'Aplikimi juaj për praktikë është refuzuar. Shikoni vendimin në aplikim.'],
            'REVISION_REQUIRED' => ['application_revision_required', 'Kërkohet korrigjim i aplikimit', 'Aplikimi juaj për praktikë kërkon korrigjime para ridorëzimit.'],
        };
        $this->send($internship, UserRole::STUDENT, $category, $title, $message, '/student/internships/'.$internship->id);
    }

    public function internshipActivated(Internship $internship): void
    {
        $this->send($internship, UserRole::STUDENT, 'internship_activated', 'Praktika u aktivizua',
            'Praktika juaj është aktive. Mund të regjistroni aktivitetet dhe të punoni në detyrat e caktuara.', '/student/internships/'.$internship->id);
    }

    public function taskAssigned(Internship $internship, Task $task): void
    {
        $this->send($internship, UserRole::STUDENT, 'task_assigned', 'Detyrë e re',
            'Ju është caktuar një detyrë e re në praktikë.', '/student/tasks/'.$task->id, $task);
    }

    public function taskSubmitted(Internship $internship, Task $task): void
    {
        $this->send($internship, UserRole::COMPANY_SUPERVISOR, 'task_submitted', 'Detyra u dorëzua',
            'Një version i ri i punës së detyrës është dorëzuar për shqyrtim.', '/supervisor/tasks/'.$task->id, $task);
    }

    public function taskReviewed(Internship $internship, Task $task): void
    {
        $approved = $task->status === 'APPROVED';
        $this->send($internship, UserRole::STUDENT, $approved ? 'task_approved' : 'task_revision_required',
            $approved ? 'Detyra u miratua' : 'Kërkohet korrigjim i detyrës',
            $approved ? 'Puna e dorëzuar për detyrën është miratuar.' : 'Puna e dorëzuar për detyrën kërkon korrigjime. Shikoni komentet në detyrë.',
            '/student/tasks/'.$task->id, $task);
    }

    public function finalEvaluationSubmitted(Internship $internship): void
    {
        $this->send($internship, UserRole::ACADEMIC_COORDINATOR, 'final_evaluation_submitted', 'Vlerësimi përfundimtar u dorëzua',
            'Vlerësimi përfundimtar i një praktike të caktuar është gati për shqyrtim.', '/coordinator/monitoring/internships/'.$internship->id);
    }

    public function internshipCompleted(Internship $internship): void
    {
        $this->send($internship, UserRole::STUDENT, 'internship_completed', 'Praktika përfundoi',
            'Përfundimi i praktikës suaj është konfirmuar. Vlerësimi përfundimtar dhe evidencat janë të disponueshme vetëm për lexim.', '/student/internships/'.$internship->id);
    }

    private function send(Internship $internship, UserRole $role, string $category, string $title, string $message, string $url, ?Task $task = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Business notifications require an active transaction.');
        }
        try {
            DB::transaction(function () use ($internship, $role, $category, $title, $message, $url, $task): void {
                $recipientId = match ($role) {
                    UserRole::STUDENT => $internship->student_id,
                    UserRole::COMPANY_SUPERVISOR => $internship->company_supervisor_id,
                    UserRole::ACADEMIC_COORDINATOR => $internship->coordinator_id,
                };
                if ($recipientId === null) {
                    return;
                }
                $query = User::whereKey($recipientId)->where('is_active', true)->whereHas('role', fn ($query) => $query->where('name', $role->value));
                if ($role === UserRole::STUDENT) {
                    $query->whereHas('studentProfile');
                } elseif ($role === UserRole::ACADEMIC_COORDINATOR) {
                    $query->whereHas('academicCoordinatorProfile');
                } else {
                    $query->whereHas('companySupervisorProfile', fn ($query) => $query->where('company_id', $internship->company_id)
                        ->where('verification_status', VerificationStatus::APPROVED)
                        ->whereHas('company', fn ($query) => $query->where('is_active', true)->where('verification_status', VerificationStatus::APPROVED)));
                }
                $recipient = $query->first();
                if ($recipient === null) {
                    return;
                }
                $payload = NotificationPayload::make(['title' => $title, 'message' => $message, 'category' => $category, 'action_url' => $url,
                    'related_type' => $task === null ? 'internship' : 'task', 'related_id' => $task?->id ?? $internship->id]);
                $timestamp = now()->toIso8601String();
                DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $category,
                    'notifiable_id' => $recipient->getKey(), 'notifiable_type' => $recipient->getMorphClass(),
                    'data' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            });
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
