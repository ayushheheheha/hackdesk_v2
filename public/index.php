<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ParticipantAuth.php';
require_once __DIR__ . '/../core/helpers.php';

if (Auth::check()) {
    redirect(Auth::dashboardPathForRole(Auth::user()['role'] ?? null));
}

if (ParticipantAuth::check()) {
    redirect('portal/participant/dashboard.php');
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="landing-hero">
    <div class="landing-copy">
        <div class="landing-kicker">Hackathon Operations Platform</div>
        <h1>HackDesk</h1>
        <p class="page-subtitle">Run registrations, teams, check-in, judging, certificates, and reporting from one system built for VIT hackathons.</p>
    </div>
    <div class="landing-meta card">
        <div class="stat-label">What You Can Do</div>
        <div style="display:grid;gap:12px;margin-top:16px;">
            <div>
                <div style="font-weight:600;">Staff & Organizers</div>
                <div class="page-subtitle">Sign in to manage events, registrations, check-in, scoring, reports, and certificates.</div>
            </div>
            <div>
                <div style="font-weight:600;">Participants</div>
                <div class="page-subtitle">Register for the event, log in with OTP or magic link, create teams, and submit round work.</div>
            </div>
            <div>
                <div style="font-weight:600;">Public Visitors</div>
                <div class="page-subtitle">Verify certificate authenticity instantly using the public verification page.</div>
            </div>
        </div>
    </div>
</section>

<section class="landing-grid">
    <a class="card landing-card" href="<?= e(appPath('public/login.php')) ?>">
        <div class="landing-card-head">
            <div class="landing-card-icon">SA</div>
            <div>
                <h2>Staff & Organizer Login</h2>
                <p class="page-subtitle">Super admin, admin, jury, and staff accounts sign in here.</p>
            </div>
        </div>
        <div class="landing-card-footer">
            <span>Open role-based login</span>
            <span class="landing-arrow">→</span>
        </div>
    </a>

    <a class="card landing-card" href="<?= e(appPath('public/participant-login.php')) ?>">
        <div class="landing-card-head">
            <div class="landing-card-icon">PT</div>
            <div>
                <h2>Participant Login</h2>
                <p class="page-subtitle">Students sign in with email OTP or a fresh magic link.</p>
            </div>
        </div>
        <div class="landing-card-footer">
            <span>Open participant access</span>
            <span class="landing-arrow">→</span>
        </div>
    </a>

    <a class="card landing-card" href="<?= e(appPath('public/register.php')) ?>">
        <div class="landing-card-head">
            <div class="landing-card-icon">RG</div>
            <div>
                <h2>Student Registration</h2>
                <p class="page-subtitle">Choose internal or external registration and join your event.</p>
            </div>
        </div>
        <div class="landing-card-footer">
            <span>Start a registration</span>
            <span class="landing-arrow">→</span>
        </div>
    </a>

    <a class="card landing-card" href="<?= e(appPath('public/verify-cert.php')) ?>">
        <div class="landing-card-head">
            <div class="landing-card-icon">VC</div>
            <div>
                <h2>Certificate Verification</h2>
                <p class="page-subtitle">Verify the authenticity of a HackDesk-issued certificate.</p>
            </div>
        </div>
        <div class="landing-card-footer">
            <span>Open public verifier</span>
            <span class="landing-arrow">→</span>
        </div>
    </a>
</section>

<section class="card landing-note">
    <h2>Best Entry Point For Event Day</h2>
    <p class="page-subtitle" style="margin-top:10px;">Staff should use the scanner after signing in. Participants should use registration first, then OTP login. External participants receive a HackDesk ID card, while internal VIT students bring their campus ID for check-in.</p>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
