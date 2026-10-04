<?php

namespace App\Modules\Auth\DTOs;

readonly class StudentRegistrationDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $password,
        public ?string $phone,
        public string $studentNumber,
        public string $studyProgram,
        public ?int $studyYear,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            email: $validated['email'],
            password: $validated['password'],
            phone: $validated['phone'] ?? null,
            studentNumber: $validated['student_number'],
            studyProgram: $validated['study_program'],
            studyYear: isset($validated['study_year']) ? (int) $validated['study_year'] : null,
        );
    }
}
