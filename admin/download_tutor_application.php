<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tutor_application_files.php';
require_role('admin');

$applicationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$applicationId) {
    http_response_code(404);
    exit('File not found.');
}

$stmt = db()->prepare('SELECT attachment_path, attachment_name FROM tutor_applications WHERE id = ?');
$stmt->bind_param('i', $applicationId);
$stmt->execute();
$application = $stmt->get_result()->fetch_assoc();
$stmt->close();
$storedName = $application['attachment_path'] ?? '';
if (!$application || !$application['attachment_name'] || !preg_match('/^[a-f0-9]{32}\.(pdf|doc|docx|jpg|png)$/D', $storedName)) {
    http_response_code(404);
    exit('File not found.');
}

$directory = realpath(dirname(__DIR__) . '/storage/tutor_applications');
$file = realpath(dirname(__DIR__) . '/storage/tutor_applications/' . $storedName);
if ($directory === false || $file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
$allowed = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit('File not found.');
}

$downloadName = str_replace(["\r", "\n", '"'], ['', '', ''], basename((string) $application['attachment_name']));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
log_activity((int) $_SESSION['user']['id'], 'TUTOR_APPLICATION_FILE_DOWNLOADED', 'Downloaded attachment for application #' . $applicationId);
readfile($file);
exit;
