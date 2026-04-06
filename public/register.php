<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/CSRF.php';

$pdo = Database::getConnection();
$hackathonId = filter_input(INPUT_GET, 'h', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'hackathon_id', FILTER_VALIDATE_INT);
$selectedType = normalizeParticipantType((string) ($_GET['type'] ?? $_POST['participant_type'] ?? ''));

$hackathon = null;
if ($hackathonId !== false && $hackathonId !== null) {
    $hackathonStmt = $pdo->prepare(
        'SELECT id, name, venue, starts_at, registration_deadline, status
         FROM hackathons
         WHERE id = ?
         LIMIT 1'
    );
    $hackathonStmt->execute([$hackathonId]);
    $hackathon = $hackathonStmt->fetch();
}

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token is invalid. Please refresh the page and try again.';
    }

    if ($selectedType === null) {
        $errors[] = 'Choose whether you are registering as an internal or external student.';
    }

    if ($hackathon === false || $hackathon === null) {
        $errors[] = 'The selected hackathon does not exist.';
    } elseif (($hackathon['status'] ?? '') !== 'registration_open' || isDeadlinePassed($hackathon['registration_deadline'] ?? null)) {
        $errors[] = 'Registration for this hackathon is currently closed.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $yearOfStudy = filter_input(INPUT_POST, 'year_of_study', FILTER_VALIDATE_INT);
    $college = trim((string) ($_POST['college'] ?? ''));
    $vitRegNo = normalizeVitRegNo((string) ($_POST['vit_reg_no'] ?? ''));

    if ($name === '' || $email === '' || $phone === '' || $department === '' || $yearOfStudy === false || $yearOfStudy === null) {
        $errors[] = 'All required fields must be completed.';
    }

    if ($selectedType === 'external' && $college === '') {
        $errors[] = 'College name is required for external participants.';
    }

    if ($selectedType === 'internal' && $vitRegNo === '') {
        $errors[] = 'VIT registration number is required for internal participants.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
        $errors[] = 'Phone number must be exactly 10 digits.';
    }

    if ($yearOfStudy !== false && $yearOfStudy !== null && ($yearOfStudy < 1 || $yearOfStudy > 6)) {
        $errors[] = 'Year of study must be between 1 and 6.';
    }

    if ($hackathon !== false && $hackathon !== null && $email !== '') {
        $duplicateStmt = $pdo->prepare('SELECT id FROM participants WHERE hackathon_id = ? AND email = ? LIMIT 1');
        $duplicateStmt->execute([(int) $hackathon['id'], $email]);
        if ($duplicateStmt->fetch() !== false) {
            $errors[] = 'This email is already registered for the selected hackathon.';
        }
    }

    if ($hackathon !== false && $hackathon !== null && $selectedType === 'internal' && $vitRegNo !== '') {
        $duplicateRegStmt = $pdo->prepare(
            'SELECT id
             FROM participants
             WHERE hackathon_id = ? AND participant_type = ? AND vit_reg_no = ?
             LIMIT 1'
        );
        $duplicateRegStmt->execute([(int) $hackathon['id'], 'internal', $vitRegNo]);
        if ($duplicateRegStmt->fetch() !== false) {
            $errors[] = 'This VIT registration number is already registered for the selected hackathon.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare(
                'INSERT INTO participants (
                    hackathon_id,
                    participant_type,
                    name,
                    email,
                    phone,
                    vit_reg_no,
                    college,
                    department,
                    year_of_study,
                    barcode_uid,
                    qr_token,
                    id_card_path,
                    id_card_sent_at,
                    check_in_status,
                    checked_in_at,
                    checked_in_by,
                    registration_confirmed
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $insertStmt->execute([
                (int) $hackathon['id'],
                $selectedType,
                $name,
                $email,
                $phone,
                $selectedType === 'internal' ? $vitRegNo : null,
                $selectedType === 'external' ? $college : 'VIT Vellore',
                $department,
                $yearOfStudy,
                generateBarcodeUID(),
                generateQRToken(),
                null,
                null,
                'not_checked_in',
                null,
                null,
                $selectedType === 'internal' ? 1 : 0,
            ]);

            $participantId = (int) $pdo->lastInsertId();
            $emailSent = sendParticipantRegistrationEmail($participantId);

            logActivity(
                $selectedType === 'internal' ? 'internal_self_registration' : 'external_registration',
                'participant',
                $participantId,
                ['email_sent' => $emailSent, 'participant_type' => $selectedType],
                (int) $hackathon['id']
            );

            $pdo->commit();

            if ($selectedType === 'internal') {
                $successMessage = $emailSent
                    ? 'Internal registration successful! Check your email for your portal access link, and bring your VIT ID card for check-in.'
                    : 'Internal registration successful. Your registration is saved, but we could not email your access link right now.';
            } else {
                $successMessage = $emailSent
                    ? 'External registration successful! Check your email for your HackDesk ID card and portal access link.'
                    : 'External registration successful. Your registration is saved, but we could not email your ID card right now.';
            }
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Public registration failed: ' . $throwable->getMessage());
            $errors[] = 'We could not complete your registration right now. Please try again.';
        }
    }
}

$registrationOpen = $hackathon !== false
    && $hackathon !== null
    && ($hackathon['status'] ?? '') === 'registration_open'
    && !isDeadlinePassed($hackathon['registration_deadline'] ?? null);

$pageTitle = 'Student Registration';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Student Registration</h1>
        <p class="page-subtitle">Choose whether you are registering as a VIT student or an external participant.</p>
    </div>
</section>

<?php if ($hackathon === false || $hackathon === null): ?>
    <section class="card">
        <p class="empty-state">We could not find that hackathon. Please open the registration link provided by the event organizers.</p>
    </section>
<?php elseif (!$registrationOpen && $successMessage === null): ?>
    <section class="card">
        <h2><?= e((string) $hackathon['name']) ?></h2>
        <p class="page-subtitle">Registration is currently unavailable for this event.</p>
        <p class="empty-state" style="margin-top:12px;">This page only accepts registrations while the hackathon status is set to registration open and the deadline has not passed.</p>
    </section>
<?php else: ?>
    <section class="card" style="margin-bottom:24px;">
        <h2><?= e((string) $hackathon['name']) ?></h2>
        <p class="page-subtitle" style="margin:8px 0 0;">Venue: <?= e((string) ($hackathon['venue'] ?? 'TBA')) ?> | Date: <?= e(formatUtcToIst((string) $hackathon['starts_at'], 'd M Y')) ?></p>
        <div class="audience-toggle" style="margin-top:20px;">
            <a class="<?= $selectedType === 'internal' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(appPath('public/register.php?h=' . (int) $hackathon['id'] . '&type=internal')) ?>">Internal Student</a>
            <a class="<?= $selectedType === 'external' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(appPath('public/register.php?h=' . (int) $hackathon['id'] . '&type=external')) ?>">External Participant</a>
        </div>
    </section>

    <?php if ($successMessage !== null): ?>
        <section class="card">
            <h2>Registration Successful</h2>
            <p class="page-subtitle" style="margin-top:10px;"><?= e($successMessage) ?></p>
        </section>
    <?php elseif ($selectedType === null): ?>
        <section class="grid grid-2">
            <article class="card">
                <h2>Internal Student</h2>
                <p class="page-subtitle" style="margin-top:10px;">For VIT students attending with their campus ID card. Register with your VIT details, then bring your VIT ID for venue check-in.</p>
                <div style="margin-top:20px;">
                    <a class="btn-primary" href="<?= e(appPath('public/register.php?h=' . (int) $hackathon['id'] . '&type=internal')) ?>">Register As Internal</a>
                </div>
            </article>
            <article class="card">
                <h2>External Participant</h2>
                <p class="page-subtitle" style="margin-top:10px;">For students from other colleges. Register here to receive a HackDesk ID card by email for venue check-in.</p>
                <div style="margin-top:20px;">
                    <a class="btn-primary" href="<?= e(appPath('public/register.php?h=' . (int) $hackathon['id'] . '&type=external')) ?>">Register As External</a>
                </div>
            </article>
        </section>
    <?php else: ?>
        <section class="card" style="max-width:760px;">
            <h2><?= $selectedType === 'internal' ? 'Internal Student Registration' : 'External Participant Registration' ?></h2>
            <p class="page-subtitle" style="margin:10px 0 20px;">
                <?= e($selectedType === 'internal'
                    ? 'Use your VIT details below. You will log in later with your email OTP or magic link, and bring your VIT ID card for check-in.'
                    : 'Complete the public registration form below. You will receive your HackDesk ID card and login access by email.') ?>
            </p>

            <?php if ($errors !== []): ?>
                <div class="flash flash-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(appPath('public/register.php?h=' . (int) $hackathon['id'] . '&type=' . $selectedType)) ?>" novalidate>
                <?= CSRF::field() ?>
                <input type="hidden" name="hackathon_id" value="<?= e((string) $hackathon['id']) ?>">
                <input type="hidden" name="participant_type" value="<?= e($selectedType) ?>">

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input id="name" name="name" type="text" required value="<?= e((string) ($_POST['name'] ?? '')) ?>">
                </div>

                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" required value="<?= e((string) ($_POST['email'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="tel" pattern="\d{10}" maxlength="10" required value="<?= e((string) ($_POST['phone'] ?? '')) ?>">
                    </div>
                </div>

                <?php if ($selectedType === 'internal'): ?>
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label for="vit_reg_no">VIT Registration Number</label>
                            <input id="vit_reg_no" name="vit_reg_no" type="text" required value="<?= e((string) ($_POST['vit_reg_no'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label for="department">Department</label>
                            <input id="department" name="department" type="text" required value="<?= e((string) ($_POST['department'] ?? '')) ?>">
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label for="college">College Name</label>
                        <input id="college" name="college" type="text" required value="<?= e((string) ($_POST['college'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input id="department" name="department" type="text" required value="<?= e((string) ($_POST['department'] ?? '')) ?>">
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="year_of_study">Year of Study</label>
                    <select id="year_of_study" name="year_of_study" required>
                        <option value="">Select Year</option>
                        <?php for ($year = 1; $year <= 6; $year++): ?>
                            <option value="<?= $year ?>" <?= ((string) ($_POST['year_of_study'] ?? '') === (string) $year) ? 'selected' : '' ?>><?= e((string) $year) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="btn-primary">
                    <?= $selectedType === 'internal' ? 'Complete Internal Registration' : 'Complete External Registration' ?>
                </button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
