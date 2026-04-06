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
</section>

<section class="landing-grid">
    <a class="card landing-card" href="<?= e(appPath('public/login.php')) ?>">
        <div class="landing-card-head">
            <div class="landing-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19h16"></path>
                    <path d="M7 19v-4.5a2.5 2.5 0 0 1 2.5-2.5h5A2.5 2.5 0 0 1 17 14.5V19"></path>
                    <circle cx="12" cy="7.5" r="3"></circle>
                </svg>
            </div>
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
            <div class="landing-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="3"></circle>
                    <path d="M6.5 19a5.5 5.5 0 0 1 11 0"></path>
                </svg>
            </div>
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
            <div class="landing-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 5.5h8"></path>
                    <path d="M8 9.5h8"></path>
                    <path d="M8 13.5h5"></path>
                    <path d="M6 3.5h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2Z"></path>
                </svg>
            </div>
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
            <div class="landing-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 12.5l3 3 7-8"></path>
                    <path d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4Z"></path>
                </svg>
            </div>
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

<!-- <section class="card landing-note">
    <h2>Best Entry Point For Event Day</h2>
    <p class="page-subtitle" style="margin-top:10px;">Staff should use the scanner after signing in. Participants should use registration first, then OTP login. External participants receive a HackDesk ID card, while internal VIT students bring their campus ID for check-in.</p>
</section> -->
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
