<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/CSRF.php';

Middleware::requireRole('super_admin');

$pdo = Database::getConnection();
$organizersStmt = $pdo->prepare(
    'SELECT id, name, email, role
     FROM users
     WHERE role IN (?, ?) AND is_active = 1
     ORDER BY FIELD(role, "admin", "super_admin"), name ASC'
);
$organizersStmt->execute(['admin', 'super_admin']);
$organizers = $organizersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session token is invalid. Please try again.');
        redirect('portal/super-admin/create-hackathon.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $tagline = trim((string) ($_POST['tagline'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $venue = trim((string) ($_POST['venue'] ?? ''));
    $startsAt = istInputToUtc((string) ($_POST['starts_at'] ?? ''));
    $endsAt = istInputToUtc((string) ($_POST['ends_at'] ?? ''));
    $registrationDeadline = istInputToUtc((string) ($_POST['registration_deadline'] ?? ''));
    $psSelectionDeadline = istInputToUtc((string) ($_POST['ps_selection_deadline'] ?? ''));
    $minTeamSize = filter_input(INPUT_POST, 'min_team_size', FILTER_VALIDATE_INT);
    $maxTeamSize = filter_input(INPUT_POST, 'max_team_size', FILTER_VALIDATE_INT);
    $leaderboardVisible = isset($_POST['leaderboard_visible']) && $_POST['leaderboard_visible'] === '1' ? 1 : 0;
    $status = trim((string) ($_POST['status'] ?? 'draft'));
    $bannerPath = trim((string) ($_POST['banner_path'] ?? ''));
    $createdBy = filter_input(INPUT_POST, 'created_by', FILTER_VALIDATE_INT);

    $allowedStatuses = ['draft', 'registration_open', 'ongoing', 'judging', 'completed', 'cancelled'];
    $errors = [];

    if ($name === '' || $startsAt === null || $endsAt === null || $createdBy === false || $createdBy === null) {
        $errors[] = 'Name, organizer, start time, and end time are required.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Select a valid hackathon status.';
    }

    if ($minTeamSize === false || $minTeamSize === null || $maxTeamSize === false || $maxTeamSize === null) {
        $errors[] = 'Enter valid minimum and maximum team sizes.';
    } elseif ($minTeamSize < 1 || $maxTeamSize < $minTeamSize) {
        $errors[] = 'Maximum team size must be greater than or equal to minimum team size.';
    }

    if ($registrationDeadline !== null && $startsAt !== null && strtotime($registrationDeadline) > strtotime($startsAt)) {
        $errors[] = 'Registration deadline must be before the hackathon start time.';
    }

    if ($psSelectionDeadline !== null && $endsAt !== null && strtotime($psSelectionDeadline) > strtotime($endsAt)) {
        $errors[] = 'Problem statement deadline must be before the hackathon end time.';
    }

    $organizerCheckStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role IN (?, ?) LIMIT 1');
    $organizerCheckStmt->execute([$createdBy, 'admin', 'super_admin']);
    if ($organizerCheckStmt->fetch() === false) {
        $errors[] = 'Select a valid organizer account.';
    }

    if ($errors !== []) {
        flash('error', implode(' ', $errors));
        redirect('portal/super-admin/create-hackathon.php');
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO hackathons (
            created_by,
            name,
            tagline,
            description,
            venue,
            starts_at,
            ends_at,
            registration_deadline,
            ps_selection_deadline,
            min_team_size,
            max_team_size,
            leaderboard_visible,
            status,
            banner_path
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $createdBy,
        $name,
        $tagline !== '' ? $tagline : null,
        $description !== '' ? $description : null,
        $venue !== '' ? $venue : null,
        $startsAt,
        $endsAt,
        $registrationDeadline,
        $psSelectionDeadline,
        $minTeamSize,
        $maxTeamSize,
        $leaderboardVisible,
        $status,
        $bannerPath !== '' ? $bannerPath : null,
    ]);

    $newHackathonId = (int) $pdo->lastInsertId();
    logActivity('hackathon_created', 'hackathon', $newHackathonId, [
        'name' => $name,
        'status' => $status,
        'created_by' => $createdBy,
    ], $newHackathonId);
    flash('success', 'Hackathon created successfully.');
    redirect('portal/super-admin/hackathons.php?view=' . $newHackathonId);
}

$pageTitle = 'Create Hackathon';
require_once __DIR__ . '/../../includes/header.php';
?>
<section class="page-header">
    <div>
        <h1>Create Hackathon</h1>
        <p class="page-subtitle">Launch a new hackathon with full event details from the super admin portal.</p>
    </div>
</section>

<section class="card" style="max-width:900px;">
    <form method="post" action="<?= e(appPath('portal/super-admin/create-hackathon.php')) ?>">
        <?= CSRF::field() ?>
        <div class="grid grid-2">
            <div class="form-group">
                <label for="created_by">Organizer Account</label>
                <select id="created_by" name="created_by" required>
                    <option value="">Select Organizer</option>
                    <?php foreach ($organizers as $organizer): ?>
                        <option value="<?= e((string) $organizer['id']) ?>"><?= e((string) $organizer['name']) ?> (<?= e((string) $organizer['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <option value="draft">Draft</option>
                    <option value="registration_open">Registration Open</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="judging">Judging</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="name">Hackathon Name</label>
            <input id="name" name="name" type="text" required>
        </div>
        <div class="form-group">
            <label for="tagline">Tagline</label>
            <input id="tagline" name="tagline" type="text">
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="5"></textarea>
        </div>
        <div class="form-group">
            <label for="venue">Venue</label>
            <input id="venue" name="venue" type="text">
        </div>
        <div class="grid grid-2">
            <div class="form-group">
                <label for="starts_at">Starts At</label>
                <input id="starts_at" name="starts_at" type="datetime-local" required>
            </div>
            <div class="form-group">
                <label for="ends_at">Ends At</label>
                <input id="ends_at" name="ends_at" type="datetime-local" required>
            </div>
        </div>
        <div class="grid grid-2">
            <div class="form-group">
                <label for="registration_deadline">Registration Deadline</label>
                <input id="registration_deadline" name="registration_deadline" type="datetime-local">
            </div>
            <div class="form-group">
                <label for="ps_selection_deadline">Problem Statement Deadline</label>
                <input id="ps_selection_deadline" name="ps_selection_deadline" type="datetime-local">
            </div>
        </div>
        <div class="grid grid-3">
            <div class="form-group">
                <label for="min_team_size">Min Team Size</label>
                <input id="min_team_size" name="min_team_size" type="number" min="1" value="2" required>
            </div>
            <div class="form-group">
                <label for="max_team_size">Max Team Size</label>
                <input id="max_team_size" name="max_team_size" type="number" min="1" value="4" required>
            </div>
            <div class="form-group">
                <label for="leaderboard_visible">Participant Leaderboard Visibility</label>
                <select id="leaderboard_visible" name="leaderboard_visible">
                    <option value="0">Hidden</option>
                    <option value="1">Visible</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="banner_path">Banner Path</label>
            <input id="banner_path" name="banner_path" type="text" placeholder="Optional upload path or URL">
        </div>
        <button type="submit" class="btn-primary">Create Hackathon</button>
    </form>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
