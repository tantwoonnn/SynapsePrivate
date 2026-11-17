<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$learner = require_role('user');
$error = '';
$form = [
    'tutor_id' => (string) ($_GET['tutor_id'] ?? $_POST['tutor_id'] ?? ''),
    'course_id' => (string) ($_POST['course_id'] ?? ''),
    'booking_date' => (string) ($_POST['booking_date'] ?? ''),
    'session_time' => (string) ($_POST['session_time'] ?? ''),
    'end_time' => (string) ($_POST['end_time'] ?? ''),
    'meeting_place' => trim((string) ($_POST['meeting_place'] ?? '')),
    'meeting_latitude' => trim((string) ($_POST['meeting_latitude'] ?? '')),
    'meeting_longitude' => trim((string) ($_POST['meeting_longitude'] ?? '')),
    'contact_info' => trim((string) ($_POST['contact_info'] ?? '')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];
$tutors = db()->query("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name");
$courseCatalog = db()->query('SELECT id, course_code, course_name FROM courses ORDER BY course_code')->fetch_all();
$tutorSubjects = [];
$subjectResult = db()->query("SELECT tc.tutor_id, c.id, c.course_code, c.course_name, tc.proposed_rate FROM tutor_courses tc JOIN courses c ON c.id = tc.course_id WHERE tc.rate_status = 'APPROVED' AND tc.proposed_rate IS NOT NULL ORDER BY c.course_code");
while ($subject = $subjectResult->fetch_assoc()) {
    $tutorSubjects[(string) $subject['tutor_id']][] = [
        'id' => (int) $subject['id'],
        'label' => $subject['course_code'] . ' · ' . $subject['course_name'] . ' — PHP ' . number_format((float) $subject['proposed_rate'], 2) . '/hour',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tutorId = filter_var($form['tutor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $courseId = filter_var($form['course_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $form['booking_date']);
    $time = DateTimeImmutable::createFromFormat('!H:i', $form['session_time']);
    $endTimeInput = DateTimeImmutable::createFromFormat('!H:i', $form['end_time']);
    if (!$tutorId || !$courseId) $error = 'Choose a tutor and subject.';
    elseif (!$date || $date->format('Y-m-d') !== $form['booking_date'] || $date->format('Y-m-d') < date('Y-m-d')) $error = 'Choose a valid date today or later.';
    elseif (!$time || $time->format('H:i') !== $form['session_time']) $error = 'Choose a valid start time.';
    elseif (!$endTimeInput || $endTimeInput->format('H:i') !== $form['end_time'] || $form['end_time'] <= $form['session_time']) $error = 'Choose an end time later than the start time.';
    elseif ($form['meeting_place'] === '' || mb_strlen($form['meeting_place']) > 200) $error = 'Enter a meeting place name of 1 to 200 characters.';
    elseif (!is_numeric($form['meeting_latitude']) || !is_numeric($form['meeting_longitude'])) $error = 'Choose the meeting point on the map or use your current location.';
    elseif ((float) $form['meeting_latitude'] < -90 || (float) $form['meeting_latitude'] > 90 || (float) $form['meeting_longitude'] < -180 || (float) $form['meeting_longitude'] > 180) $error = 'The selected meeting coordinates are invalid.';
    elseif (strlen($form['notes']) > 500) $error = 'Notes must be 500 characters or fewer.';
    elseif (mb_strlen($form['contact_info']) > 150) $error = 'Contact details must be 150 characters or fewer.';
    elseif ($date->format('Y-m-d') === date('Y-m-d') && $time->format('H:i') <= date('H:i')) $error = 'Choose a start time later than now.';

    if ($error === '') {
        $stmt = db()->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'staff'");
        $stmt->bind_param('i', $tutorId);
        $stmt->execute();
        $validTutor = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $stmt = db()->prepare("SELECT c.id, c.course_code, c.course_name, tc.proposed_rate FROM tutor_courses tc JOIN courses c ON c.id = tc.course_id WHERE tc.tutor_id = ? AND c.id = ? AND tc.rate_status = 'APPROVED' AND tc.proposed_rate IS NOT NULL");
        $stmt->bind_param('ii', $tutorId, $courseId);
        $stmt->execute();
        $validCourse = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$validTutor || !$validCourse) $error = 'That tutor or subject is no longer available.';
    }

    if ($error === '') {
        $startTime = $time->format('H:i:s');
        $endTime = $endTimeInput->format('H:i:s');
        $weekday = (int) $date->format('w');
        $stmt = db()->prepare('SELECT start_time, end_time FROM tutor_schedules WHERE tutor_id = ? AND day_of_week = ? AND is_available = 1');
        $stmt->bind_param('ii', $tutorId, $weekday);
        $stmt->execute();
        $scheduleRows = $stmt->get_result();
        $available = false;
        while ($slot = $scheduleRows->fetch_assoc()) {
            if ($startTime >= $slot['start_time'] && $endTime <= $slot['end_time']) $available = true;
        }
        $stmt->close();
        if (!$available) $error = 'The selected time does not fit the tutor’s weekly availability.';
    }

    if ($error === '') {
        $stmt = db()->prepare("SELECT id FROM appointments WHERE tutor_id = ? AND booking_date = ? AND status IN ('PENDING', 'CONFIRMED') AND session_time < ? AND end_time > ?");
        $dateValue = $date->format('Y-m-d');
        $stmt->bind_param('isss', $tutorId, $dateValue, $endTime, $startTime);
        $stmt->execute();
        $busyRows = $stmt->get_result();
        if ($busyRows->num_rows > 0) $error = 'That time overlaps another request or confirmed session.';
        $stmt->close();
    }

    if ($error === '') {
        try {
            db()->begin_transaction();
            $notes = $form['notes'] === '' ? null : $form['notes'];
            $contactInfo = $form['contact_info'] === '' ? null : $form['contact_info'];
            $agreedRate = number_format((float) $validCourse['proposed_rate'], 2, '.', '');
            $meetingPlace = $form['meeting_place'];
            $meetingLatitude = (float) $form['meeting_latitude'];
            $meetingLongitude = (float) $form['meeting_longitude'];
            $stmt = db()->prepare("INSERT INTO appointments (learner_id, tutor_id, course_id, agreed_rate, booking_date, session_time, end_time, meeting_place, meeting_latitude, meeting_longitude, contact_info, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)");
            $stmt->bind_param('iiisssssddss', $learner['id'], $tutorId, $courseId, $agreedRate, $dateValue, $startTime, $endTime, $meetingPlace, $meetingLatitude, $meetingLongitude, $contactInfo, $notes);
            $stmt->execute();
            $appointmentId = (int) db()->insert_id;
            $stmt->close();
            log_activity((int) $learner['id'], 'BOOKING_REQUESTED', 'Requested appointment #' . $appointmentId);
            db()->commit();

            $safeTutorName = e($validTutor['full_name']);
            $safeLearnerName = e($learner['full_name']);
            $safeSubject = e($validCourse['course_code'] . ' · ' . $validCourse['course_name']);
            $safeWhen = e(format_date($dateValue) . ' · ' . format_time($startTime) . '–' . format_time($endTime));
            $safeMeetingPlace = e($meetingPlace);
            $meetingMapUrl = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $meetingLatitude) . '&mlon=' . rawurlencode((string) $meetingLongitude) . '#map=17/' . rawurlencode((string) $meetingLatitude) . '/' . rawurlencode((string) $meetingLongitude);
            $safeContact = $contactInfo === null ? 'Not provided' : e($contactInfo);
            $dashboardLink = e(url('staff/dashboard.php'));
            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto">'
                . '<h2>Hello ' . $safeTutorName . ',</h2>'
                . '<p>' . $safeLearnerName . ' requested a tutoring session. The request is waiting for your review.</p>'
                . '<p><strong>Subject:</strong> ' . $safeSubject . '<br>'
                . '<strong>When:</strong> ' . $safeWhen . '<br>'
                . '<strong>Meeting place:</strong> ' . $safeMeetingPlace . ' · <a href="' . e($meetingMapUrl) . '">View map pin</a><br>'
                . '<strong>Learner contact:</strong> ' . $safeContact . '</p>'
                . '<p><a href="' . $dashboardLink . '">Open your tutor dashboard</a></p></div>';
            $sent = send_html_email($validTutor['email'], $validTutor['full_name'], 'New tutoring session request', $html);
            flash($sent ? 'success' : 'warning', $sent
                ? 'Your request was sent to the tutor, and an email notification was sent.'
                : 'Your request was saved, but the tutor notification email could not be sent.');
            redirect('user/dashboard.php');
        } catch (Throwable $exception) {
            db()->rollback();
            error_log('Booking request failed: ' . $exception->getMessage());
            $error = 'Could not save your request. Please try again.';
        }
    }
}
$pageTitle = 'Request a Session';
$includeLeafletAssets = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><h1 class="page-heading h2">Request a tutoring session</h1><p class="text-muted mb-0">Choose a start and end time within the tutor’s posted availability. The tutor will approve or decline your request.</p></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($tutors->num_rows === 0 || !$courseCatalog): ?><div class="alert alert-warning">Add at least one tutor and one subject before requesting a session.</div><?php else: ?><form method="post" class="card" id="booking-form"><div class="card-body p-4"><?= csrf_field() ?><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Tutor</label><select class="form-select" name="tutor_id" required><option value="">Choose tutor</option><?php while ($tutor = $tutors->fetch_assoc()): ?><option value="<?= (int) $tutor['id'] ?>" <?= $form['tutor_id'] === (string) $tutor['id'] ? 'selected' : '' ?>><?= e($tutor['full_name']) ?></option><?php endwhile; ?></select></div>
    <div class="col-md-6"><label class="form-label">Subject</label><select class="form-select" id="course_id" name="course_id" required><option value="">Choose a tutor first</option></select></div>
    <div class="col-md-6"><label class="form-label">Date</label><input class="form-control" type="date" name="booking_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['booking_date']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Start time</label><input class="form-control" type="time" name="session_time" value="<?= e($form['session_time']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">End time</label><input class="form-control" type="time" name="end_time" value="<?= e($form['end_time']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Contact number or social account <span class="text-muted">(optional)</span></label><input class="form-control" type="text" name="contact_info" maxlength="150" value="<?= e($form['contact_info']) ?>" placeholder="Phone number, Messenger, Discord, etc."><div class="form-text">Share a contact method the tutor can use to coordinate the session.</div></div>
    <div class="col-12"><label class="form-label" for="meeting_place">Meeting place</label><input class="form-control" type="text" id="meeting_place" name="meeting_place" maxlength="200" required value="<?= e($form['meeting_place']) ?>" placeholder="Campus building, library, cafe, etc."><div class="form-text">Enter a place name the tutor will recognize, then click the map to set the meeting pin. You can drag the pin to adjust it.</div></div>
    <div class="col-12"><div class="d-flex flex-wrap gap-2 mb-2"><button class="btn btn-sm btn-outline-secondary" id="use-my-location" type="button">Use my location</button><button class="btn btn-sm btn-outline-secondary" id="clear-meeting-pin" type="button">Clear pin</button><span class="small text-muted align-self-center" id="meeting-pin-status" aria-live="polite">Choose the meeting point on the map.</span></div><div id="meeting-map" aria-label="Choose the session meeting point on the map"></div><div class="row g-2 mt-2"><div class="col-sm-6"><label class="form-label small" for="meeting_latitude">Latitude</label><input class="form-control form-control-sm" type="number" id="meeting_latitude" name="meeting_latitude" min="-90" max="90" step="any" required value="<?= e($form['meeting_latitude']) ?>"></div><div class="col-sm-6"><label class="form-label small" for="meeting_longitude">Longitude</label><input class="form-control form-control-sm" type="number" id="meeting_longitude" name="meeting_longitude" min="-180" max="180" step="any" required value="<?= e($form['meeting_longitude']) ?>"></div></div><div class="form-text">The map uses OpenStreetMap data. The map pin is shared with the tutor along with your request.</div></div>
    <div class="col-12"><label class="form-label">What would you like help with?</label><textarea class="form-control" name="notes" maxlength="500" rows="3"><?= e($form['notes']) ?></textarea></div>
</div><button class="btn btn-primary mt-4" type="submit">Send request</button></div></form><script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script><script>
if (window.L) {
    const meetingMap = L.map('meeting-map').setView([14.5995, 120.9842], 12);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(meetingMap);

    const latitudeInput = document.getElementById('meeting_latitude');
    const longitudeInput = document.getElementById('meeting_longitude');
    const pinStatus = document.getElementById('meeting-pin-status');
    let meetingMarker = null;
    function setMeetingPin(latitude, longitude, centerMap) {
        const point = L.latLng(Number(latitude), Number(longitude));
        if (!Number.isFinite(point.lat) || !Number.isFinite(point.lng) || !point.isValid()) return;
        if (!meetingMarker) {
            meetingMarker = L.marker(point, { draggable: true }).addTo(meetingMap);
            meetingMarker.bindPopup('Session meeting point');
            meetingMarker.on('dragend', () => {
                const moved = meetingMarker.getLatLng();
                setMeetingPin(moved.lat, moved.lng, false);
            });
        } else {
            meetingMarker.setLatLng(point);
        }
        latitudeInput.value = point.lat.toFixed(7);
        longitudeInput.value = point.lng.toFixed(7);
        pinStatus.textContent = 'Meeting pin selected. Drag it to adjust.';
        if (centerMap) meetingMap.setView(point, Math.max(meetingMap.getZoom(), 16));
    }
    meetingMap.on('click', event => setMeetingPin(event.latlng.lat, event.latlng.lng, false));
    if (latitudeInput.value !== '' && longitudeInput.value !== '') {
        setMeetingPin(latitudeInput.value, longitudeInput.value, true);
    }
    [latitudeInput, longitudeInput].forEach(input => input.addEventListener('change', () => {
        if (latitudeInput.value !== '' && longitudeInput.value !== '') setMeetingPin(latitudeInput.value, longitudeInput.value, true);
    }));
    document.getElementById('clear-meeting-pin').addEventListener('click', () => {
        if (meetingMarker) meetingMap.removeLayer(meetingMarker);
        meetingMarker = null;
        latitudeInput.value = '';
        longitudeInput.value = '';
        pinStatus.textContent = 'Choose the meeting point on the map.';
    });
    document.getElementById('use-my-location').addEventListener('click', () => {
        if (!navigator.geolocation) {
            pinStatus.textContent = 'Location detection is not available in this browser. Click the map to choose a pin.';
            return;
        }
        pinStatus.textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(
            position => setMeetingPin(position.coords.latitude, position.coords.longitude, true),
            () => { pinStatus.textContent = 'Could not get your location. Click the map to choose a pin.'; },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
} else {
    document.getElementById('meeting-pin-status').textContent = 'Map unavailable. Enter the meeting coordinates below to continue.';
}
</script><script>
const tutorSubjects = <?= json_encode($tutorSubjects, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const tutorSelect = document.querySelector('select[name="tutor_id"]');
const subjectSelect = document.getElementById('course_id');
const savedCourseId = <?= json_encode($form['course_id']) ?>;
function updateTutorSubjects() {
    const options = tutorSubjects[tutorSelect.value] || [];
    subjectSelect.replaceChildren(new Option(options.length ? 'Choose subject' : 'No subjects listed for this tutor', ''));
    for (const subject of options) {
        const option = new Option(subject.label, subject.id);
        if (String(subject.id) === savedCourseId) option.selected = true;
        subjectSelect.add(option);
    }
}
tutorSelect.addEventListener('change', updateTutorSubjects);
updateTutorSubjects();
</script><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
