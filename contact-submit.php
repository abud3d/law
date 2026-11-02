<?php
declare(strict_types=1);

/*
 * Hissah Law Office contact handler.
 * Deploy on the CWP PHP host (not GitHub Pages).
 * Uploaded files are never stored in the public web root.
 * ClamAV (clamdscan or clamscan) is mandatory; uploads fail closed if unavailable.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function reply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
session_name('hissah_contact');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if (isset($_GET['token'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        reply(405, ['ok' => false, 'message' => 'طريقة الطلب غير مسموحة.']);
    }
    $_SESSION['contact_token'] = bin2hex(random_bytes(32));
    $_SESSION['contact_token_created'] = time();
    reply(200, ['ok' => true, 'token' => $_SESSION['contact_token']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    reply(405, ['ok' => false, 'message' => 'طريقة الطلب غير مسموحة.']);
}

/* Reject cross-origin submissions when the browser supplies Origin. */
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $requestHost = strtolower((string)preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($originHost === '' || !hash_equals($requestHost, $originHost)) {
        reply(403, ['ok' => false, 'message' => 'تعذر التحقق من مصدر الطلب.']);
    }
}

/* One-use CSRF token prevents replay and accidental duplicate submissions. */
$submittedToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['contact_token'] ?? '');
$tokenCreated = (int)($_SESSION['contact_token_created'] ?? 0);
if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)
    || $tokenCreated < 1 || time() - $tokenCreated > 7200) {
    reply(403, ['ok' => false, 'message' => 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مجدداً.']);
}
unset($_SESSION['contact_token'], $_SESSION['contact_token_created']);

/* Honeypot: real visitors never see or fill this field. */
if (!empty($_POST['website'])) {
    reply(400, ['ok' => false, 'message' => 'تعذر قبول الطلب.']);
}
if ($tokenCreated > 0 && time() - $tokenCreated < 2) {
    reply(429, ['ok' => false, 'message' => 'يرجى الانتظار قليلاً ثم إعادة المحاولة.']);
}

$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$topic = trim((string)($_POST['topic'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

$allowedTopics = ['طلب موعد استشارة', 'استفسار عن خدمة', 'استفسار عام'];
if ($name === '' || preg_match('/[A-Za-z\\p{Latin}]/u', $name) || !preg_match('/\\p{Arabic}/u', $name)
    || preg_match('/[^\\p{Arabic}\\p{M}\\sـ-]/u', $name)) {
    reply(422, ['ok' => false, 'message' => 'يرجى إدخال الاسم باللغة العربية.']);
}
if (!preg_match('/^[0-9٠-٩+()\\s-]{7,20}$/u', $phone)) {
    reply(422, ['ok' => false, 'message' => 'يرجى إدخال رقم تواصل صحيح.']);
}
if (!in_array($topic, $allowedTopics, true)) {
    reply(422, ['ok' => false, 'message' => 'نوع الطلب غير صحيح.']);
}
$messageLength = preg_match_all('/./us', $message, $unused);
if ($messageLength === false || $messageLength < 10 || $messageLength > 2000
    || !preg_match('/\\p{Arabic}/u', $message)
    || preg_match('/\\p{Latin}/u', $message)
    || !preg_match('/^[\\p{Arabic}\\p{M}\\s0-9٠-٩،؛؟!.,:()\\-ـ]+$/u', $message)) {
    reply(422, ['ok' => false, 'message' => 'اكتب نص الرسالة بالعربية فقط، من 10 إلى 2000 حرف.']);
}

/* Attachments are optional. If supplied, validate and scan before mailing. */
$hasAttachment = isset($_FILES['attachment']) && is_array($_FILES['attachment'])
    && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$file = null;
$mime = null;
$extension = null;
if ($hasAttachment) {
    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')
        || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 5 * 1024 * 1024) {
        reply(422, ['ok' => false, 'message' => 'تعذر قبول المرفق. الحد الأقصى 5 ميجابايت. يمكنك الإرسال دون مرفق.']);
    }
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png']
    ];
    if (!isset($allowed[$extension])) {
        reply(415, ['ok' => false, 'message' => 'نوع الملف غير مسموح. الأنواع المقبولة PDF وJPG وPNG فقط.']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$extension], true)) {
        reply(415, ['ok' => false, 'message' => 'امتداد الملف لا يطابق محتواه الفعلي.']);
    }
    $handle = fopen($file['tmp_name'], 'rb');
    $signature = $handle ? fread($handle, 8) : '';
    if ($handle) fclose($handle);
    $signatureOk = ($mime === 'application/pdf' && strncmp($signature, '%PDF-', 5) === 0)
        || ($mime === 'image/png' && substr($signature, 0, 8) === "\x89PNG\r\n\x1a\n")
        || ($mime === 'image/jpeg' && substr($signature, 0, 3) === "\xFF\xD8\xFF");
    if (!$signatureOk) {
        reply(415, ['ok' => false, 'message' => 'تعذر التحقق من سلامة صيغة المرفق.']);
    }

    /* Scan uploaded files only. A clean result is exit code 0; infected is 1; scanner error is 2. */
    $scanner = null;
    foreach (['/usr/bin/clamscan', '/usr/local/bin/clamscan', '/usr/bin/clamdscan', '/usr/local/bin/clamdscan'] as $candidate) {
        if (is_executable($candidate)) { $scanner = $candidate; break; }
    }
    if ($scanner === null || !function_exists('proc_open')) {
        reply(503, ['ok' => false, 'message' => 'فحص المرفقات غير متاح حالياً. يمكنك إعادة الإرسال دون إرفاق ملف.']);
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open([$scanner, '--no-summary', $file['tmp_name']], $descriptors, $pipes);
    if (!is_resource($process)) {
        reply(503, ['ok' => false, 'message' => 'تعذر تشغيل فحص المرفق. يمكنك إعادة الإرسال دون إرفاق ملف.']);
    }
    fclose($pipes[0]);
    $scanOutput = stream_get_contents($pipes[1]);
    $scanError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $scanCode = proc_close($process);
    if ($scanCode === 1) {
        error_log('Hissah contact attachment detected as infected.');
        reply(422, ['ok' => false, 'message' => 'اكتشف الفحص خطراً في المرفق، لذلك لم يتم إرساله. يمكنك الإرسال دون مرفق.']);
    }
    if ($scanCode !== 0) {
        error_log('Hissah contact attachment scan error; scanner exit=' . $scanCode . '; ' . substr((string)$scanError, 0, 500));
        reply(503, ['ok' => false, 'message' => 'تعذر إكمال الفحص الأمني للمرفق. يمكنك إعادة الإرسال دون إرفاق ملف.']);
    }
}

/* Basic IP rate limit: at most 3 accepted attempts in 10 minutes. */
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'hissah-contact-' . hash('sha256', $ip) . '.json';
$rateHandle = @fopen($rateFile, 'c+');
if (!$rateHandle || !flock($rateHandle, LOCK_EX)) {
    if (is_resource($rateHandle)) fclose($rateHandle);
    reply(503, ['ok' => false, 'message' => 'تعذر التحقق من معدل الإرسال. يرجى المحاولة لاحقاً.']);
}
$raw = stream_get_contents($rateHandle);
$times = json_decode($raw ?: '[]', true);
if (!is_array($times)) $times = [];
$times = array_values(array_filter($times, static function ($t) { return is_int($t) && $t > time() - 600; }));
if (count($times) >= 3) {
    flock($rateHandle, LOCK_UN);
    fclose($rateHandle);
    reply(429, ['ok' => false, 'message' => 'تم تجاوز عدد المحاولات المسموح. يرجى الانتظار عشر دقائق.']);
}
$times[] = time();
rewind($rateHandle);
ftruncate($rateHandle, 0);
fwrite($rateHandle, json_encode($times));
fflush($rateHandle);
flock($rateHandle, LOCK_UN);
fclose($rateHandle);

/* Send a plain message when no file is attached; use MIME only for a scanned attachment. */
$to = 'info@hissah.sa';
$subject = 'طلب تواصل جديد من الموقع';
$bodyText = "طلب تواصل جديد\n\nالاسم: {$name}\nرقم التواصل: {$phone}\nنوع الطلب: {$topic}\n\nالرسالة:\n{$message}\n";
$headers = [
    'From: Hissah Website <info@hissah.sa>',
    'MIME-Version: 1.0'
];
if ($hasAttachment) {
    $boundary = '=_Hissah_' . bin2hex(random_bytes(18));
    $cleanName = 'attachment.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $bodyText . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}; name=\"{$cleanName}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$cleanName}\"\r\n\r\n";
    $body .= chunk_split(base64_encode((string)file_get_contents($file['tmp_name']))) . "\r\n";
    $body .= "--{$boundary}--\r\n";
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
} else {
    $body = $bodyText;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
}
$sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
if (!$sent) {
    error_log('Hissah contact mail() failed.');
    reply(502, ['ok' => false, 'message' => 'تعذر تسليم الطلب للبريد حالياً. يرجى المحاولة لاحقاً.']);
}
reply(200, ['ok' => true, 'message' => 'تم إرسال طلبك بنجاح. سيقوم المكتب بمراجعته.']);
