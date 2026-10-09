<?php

namespace App\Modules\Task\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Phar;
use PharData;
use Throwable;

class TaskAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The attachment must be a valid uploaded file.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        $types = [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath());
        if (! in_array($mime, $types[$extension] ?? [], true)) {
            $fail('Use a PDF, DOCX, XLSX, PNG, JPEG or ZIP file whose contents match its extension.');

            return;
        }
        if (in_array($extension, ['docx', 'xlsx'], true)) {
            try {
                $archive = new PharData($value->getRealPath(), 0, null, Phar::ZIP);
                $entry = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
                if (! isset($archive['[Content_Types].xml'], $archive[$entry])) {
                    $fail('The attachment is not a valid Office document.');
                }
            } catch (Throwable) {
                $fail('The attachment is not a valid Office document.');
            }
        }
    }
}
