<?php
declare(strict_types=1);

const TUTOR_APPLICATION_MAX_FILE_SIZE = 5242880;

/** Validate and store an optional applicant file under a randomized name. */
function store_tutor_application_file(?array $upload): ?array
{
    if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file upload did not complete. Choose the file again and retry.');
    }
    if (!isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('The uploaded file could not be verified. Choose the file again.');
    }
    if ((int) $upload['size'] < 1 || (int) $upload['size'] > TUTOR_APPLICATION_MAX_FILE_SIZE) {
        throw new RuntimeException('The attachment must be no larger than 5 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Attach a PDF, Word document, JPG, or PNG file.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'application.' . $extensions[$mime])));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $originalName) ?? '';
    $originalName = mb_substr($originalName, 0, 255);
    if ($originalName === '') $originalName = 'application.' . $extensions[$mime];

    $directory = dirname(__DIR__) . '/storage/tutor_applications';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The server could not prepare secure file storage.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('The server could not save the attachment.');
    }

    return ['path' => $storedName, 'name' => $originalName];
}

/** Remove only files created by this feature. */
function delete_tutor_application_file(?string $storedName): void
{
    if ($storedName === null || !preg_match('/^[a-f0-9]{32}\.(pdf|doc|docx|jpg|png)$/D', $storedName)) return;
    $path = dirname(__DIR__) . '/storage/tutor_applications/' . $storedName;
    if (is_file($path)) @unlink($path);
}
