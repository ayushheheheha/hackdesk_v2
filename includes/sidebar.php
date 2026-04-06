<?php

declare(strict_types=1);

$role = $currentUser['role'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$navigation = [
    'super_admin' => [
        'Platform' => [
            'Dashboard' => 'portal/super-admin/dashboard.php',
            'Hackathons' => 'portal/super-admin/hackathons.php',
            'Users' => 'portal/super-admin/users.php',
            'Activity Log' => 'portal/super-admin/activity-log.php',
            'Settings' => 'portal/super-admin/settings.php',
        ],
    ],
    'admin' => [
        '' => [
            'Dashboard' => 'portal/admin/dashboard.php',
        ],
        'Event Setup' => [
            'Event Details' => 'portal/admin/event-setup.php',
            'Rounds' => 'portal/admin/rounds.php',
            'Problem Statements' => 'portal/admin/problem-statements.php',
        ],
        'Participants' => [
            'Participants' => 'portal/admin/participants.php',
            'Teams' => 'portal/admin/teams.php',
        ],
        'Judging' => [
            'Jury Management' => 'portal/admin/jury.php',
            'Submissions' => 'portal/admin/submissions.php',
            'Judging & Scores' => 'portal/admin/judging.php',
            'Leaderboard' => 'portal/admin/leaderboard.php',
        ],
        'Close' => [
            'Certificates' => 'portal/admin/certificates.php',
            'Reports & Export' => 'portal/admin/reports.php',
            'Settings' => 'portal/admin/settings.php',
        ],
    ],
    'participant' => [
        'Workspace' => [
            'Dashboard' => 'portal/participant/dashboard.php',
            'My Team' => 'portal/participant/team.php',
            'Problem Statement' => 'portal/participant/problem-statement.php',
            'Submissions' => 'portal/participant/submissions.php',
            'Certificates' => 'portal/participant/certificates.php',
        ],
    ],
    'jury' => [
        'Judging' => [
            'Dashboard' => 'portal/jury/dashboard.php',
            'My Assignments' => 'portal/jury/dashboard.php',
        ],
    ],
    'staff' => [
        'Operations' => [
            'Check-In Scanner' => 'portal/staff/checkin.php',
        ],
    ],
];
?>
<aside class="sidebar">
    <div class="sidebar-logo"><?= e(APP_NAME) ?></div>
    <?php foreach ($navigation[$role] ?? [] as $section => $links): ?>
        <?php if ($section !== ''): ?>
            <div class="sidebar-section-label"><?= e($section) ?></div>
        <?php endif; ?>
        <?php foreach ($links as $label => $href): ?>
            <?php $isActive = str_ends_with($path, $href); ?>
            <a class="sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= e(appPath($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="sidebar-section-label">Account</div>
    <a class="sidebar-link" href="<?= e(appPath('public/logout.php')) ?>">Logout</a>
</aside>
