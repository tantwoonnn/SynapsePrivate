<?php
declare(strict_types=1);

const TUTOR_APPLICATION_MAX_FILE_SIZE = 5242880;

function tutor_application_storage_configured(): bool
{
    return project_env('SUPABASE_URL') !== '' && (project_env('SUPABASE_SECRET_KEY') !== '' || project_env('SUPABASE_SERVICE_ROLE_KEY') !== '');
}

function tutor_application_storage_request(string $method, string $objectPath, ?string $body = null, ?string $mime = null): array
{
    $baseUrl = rtrim(project_env('SUPABASE_URL'), '/');
    $serviceKey = project_env('SUPABASE_SECRET_KEY', project_env('SUPABASE_SERVICE_ROLE_KEY'));
    $bucket = project_env('SUPABASE_STORAGE_BUCKET', 'tutor-applications');
    if ($baseUrl === '' || $serviceKey === '') {
        throw new RuntimeException('Private application file storage is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The server does not have the required HTTP client enabled.');
    }

    $url = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($objectPath));
    $headers = ['apikey: ' . $serviceKey, 'Accept: application/json'];
    // Legacy service_role keys are JWTs. New sb_secret keys belong only in apikey.
    if (!str_starts_with($serviceKey, 'sb_secret_')) $headers[] = 'Authorization: Bearer ' . $serviceKey;
    if ($body !== null) $headers[] = 'Content-Type: ' . ($mime ?? 'application/octet-stream');
    if ($method === 'POST') $headers[] = 'x-upsert: false';

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Supabase Storage request failed: ' . $curlError);
        throw new RuntimeException('The application file service could not be reached. Please try again.');
    }
    if ($status < 200 || $status >= 300) {
        error_log('Supabase Storage returned HTTP ' . $status . ' for ' . $method . ' request.');
        throw new RuntimeException('The application file could not be processed. Check the private storage settings.');
    }
    return ['body' => $responseBody, 'status' => $status];
}

/** Validate and store an optional applicant file in Supabase Storage when configured, or local XAMPP storage. */
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

    $storedName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (tutor_application_storage_configured()) {
        $bytes = file_get_contents($upload['tmp_name']);
        if ($bytes === false) throw new RuntimeException('The uploaded attachment could not be read.');
        tutor_application_storage_request('POST', $storedName, $bytes, $mime);
    } else {
        if (getenv('VERCEL') === '1') {
            throw new RuntimeException('Private application file storage is not configured for this deployment.');
        }
        $directory = dirname(__DIR__) . '/storage/tutor_applications';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('The server could not prepare secure file storage.');
        }
        if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storedName)) {
            throw new RuntimeException('The server could not save the attachment.');
        }
    }

    return ['path' => $storedName, 'name' => $originalName];
}

/** Remove only files created by this feature. */
function delete_tutor_application_file(?string $storedName): void
{
    if ($storedName === null || !preg_match('/^[a-f0-9]{32}\.(pdf|doc|docx|jpg|png)$/D', $storedName)) return;
    try {
        if (tutor_application_storage_configured()) {
            $bucket = project_env('SUPABASE_STORAGE_BUCKET', 'tutor-applications');
            $baseUrl = rtrim(project_env('SUPABASE_URL'), '/');
            $serviceKey = project_env('SUPABASE_SECRET_KEY', project_env('SUPABASE_SERVICE_ROLE_KEY'));
            $url = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket);
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_POSTFIELDS => json_encode(['prefixes' => [$storedName]], JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array_merge(
                    ['apikey: ' . $serviceKey, 'Content-Type: application/json'],
                    str_starts_with($serviceKey, 'sb_secret_') ? [] : ['Authorization: Bearer ' . $serviceKey]
                ),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            $result = curl_exec($curl);
            if ($result === false || (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) >= 300) {
                error_log('Could not remove replaced tutor application attachment from Supabase Storage.');
            }
            curl_close($curl);
            return;
        }
        $path = dirname(__DIR__) . '/storage/tutor_applications/' . $storedName;
        if (is_file($path)) @unlink($path);
    } catch (Throwable $exception) {
        error_log('Could not remove tutor application attachment: ' . $exception->getMessage());
    }
}

/** Return a validated local or private-bucket attachment for an administrator download. */
function load_tutor_application_file(string $storedName): array|false
{
    if (!preg_match('/^[a-f0-9]{32}\.(pdf|doc|docx|jpg|png)$/D', $storedName)) return false;
    if (tutor_application_storage_configured()) {
        try {
            $response = tutor_application_storage_request('GET', $storedName);
            $data = $response['body'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
            $allowed = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
            return in_array($mime, $allowed, true) ? ['data' => $data, 'mime' => $mime, 'size' => strlen($data)] : false;
        } catch (Throwable $exception) {
            error_log('Could not load tutor application attachment: ' . $exception->getMessage());
            return false;
        }
    }

    $directory = realpath(dirname(__DIR__) . '/storage/tutor_applications');
    $file = realpath(dirname(__DIR__) . '/storage/tutor_applications/' . $storedName);
    if ($directory === false || $file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    $allowed = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
    return in_array($mime, $allowed, true) ? ['file' => $file, 'mime' => $mime, 'size' => filesize($file)] : false;
}
