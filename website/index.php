<?php
$base = '/NoteSphere';
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NoteSphere | Colegio de Montalban</title>
    <link rel="icon" type="image/png" href="<?= $base ?>/assets/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#05070d; --bg-soft:#080b12; --panel:#0b0f17; --text:#f7f9fb; --muted:#9aa4b2; --muted-strong:#c3cad4; --green:#39ff14; --green-text:#39ff14; --green-fill:#39ff14; --green-fill-hover:#52ff32; --green-fill-text:#020402; --green-soft:rgba(57,255,20,.12); --green-line:rgba(57,255,20,.35); --line:rgba(255,255,255,.08); --line-strong:rgba(255,255,255,.14); --radius:22px; --radius-sm:14px; --container:1180px; --font:'Plus Jakarta Sans',system-ui,sans-serif; }
        *{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{min-height:100vh;background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.6;overflow-x:hidden;position:relative}
        body::before{content:'';position:fixed;inset:0;background:linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px);z-index:0}
        .blob{position:fixed;border-radius:50%;filter:blur(70px);opacity:.45;z-index:0;animation:float 9s ease-in-out infinite}
        .blob.b1{width:360px;height:360px;background:#1f6f3a;top:-100px;left:-80px}
        .blob.b2{width:300px;height:300px;background:#39ff14;bottom:-80px;right:-60px;animation-delay:2s;opacity:.35}
        .blob.b3{width:240px;height:240px;background:#0f4d2a;top:45%;left:62%;animation-delay:4s}
        @keyframes float{0%,100%{transform:translate(0,0)}50%{transform:translate(20px,-30px)}}
        .container{width:min(100%-48px,var(--container));margin-inline:auto}
        main{position:relative;z-index:2}
        .hero{padding:100px 0 70px;text-align:center}
        .eyebrow{display:inline-flex;align-items:center;gap:9px;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:rgba(255,255,255,.03);color:var(--muted-strong);font-size:.66rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
        .eyebrow-dot{width:7px;height:7px;border-radius:50%;background:var(--green-text);box-shadow:0 0 0 4px var(--green-soft)}
        .hero h1{margin:22px 0 22px;font-size:clamp(2.8rem,5.2vw,4.8rem);font-weight:800;letter-spacing:-.055em;line-height:.98}
        .accent{color:var(--green-text)}
        .hero-lead{max-width:560px;margin:0 auto 30px;color:var(--muted-strong);font-size:1rem;line-height:1.75}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:50px;padding:14px 22px;border:1px solid transparent;border-radius:12px;font-size:.88rem;font-weight:800;cursor:pointer;transition:transform .2s ease,background .2s ease,box-shadow .2s ease;text-decoration:none}
        .btn-primary{background:var(--green-fill);color:var(--green-fill-text);box-shadow:0 14px 40px rgba(57,255,20,.18)}
        .btn-primary:hover{background:var(--green-fill-hover);transform:translateY(-2px);box-shadow:0 18px 44px rgba(57,255,20,.28)}
        .btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
        .section{padding:104px 0}
        .section-alt{border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:radial-gradient(circle at 85% 15%,rgba(57,255,20,.06),transparent 34%),var(--bg-soft)}
        .section-head{max-width:690px;margin:0 auto 48px;text-align:center}
        .section-head h2{margin:18px 0 16px;font-size:clamp(2rem,3.8vw,3.2rem);font-weight:800;letter-spacing:-.045em;line-height:1.06}
        .section-head p{max-width:620px;margin:0 auto;color:var(--muted);font-size:.94rem;line-height:1.75}
        .feature-grid{display:flex;flex-wrap:wrap;justify-content:center;gap:16px}
        .feature-card{width:calc(33.333% - 11px);min-width:260px}
        .feature-card{position:relative;overflow:hidden;padding:27px 25px;border:1px solid var(--line);border-radius:var(--radius);background:linear-gradient(160deg,rgba(255,255,255,.035),rgba(255,255,255,.012));transition:transform .25s ease,border-color .25s ease,background .25s ease;text-align:center}
        .feature-card .feature-icon{margin:0 auto 16px}
        .feature-card:hover{border-color:rgba(57,255,20,.26);background:linear-gradient(160deg,rgba(57,255,20,.055),rgba(255,255,255,.012));transform:translateY(-4px)}
        .feature-icon{display:grid;place-items:center;width:48px;height:48px;margin-bottom:20px;border:1px solid rgba(57,255,20,.22);border-radius:13px;background:rgba(57,255,20,.08);color:var(--green-text)}
        .feature-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .feature-card h3{margin-bottom:10px;font-size:1.02rem;font-weight:800;letter-spacing:-.025em;line-height:1.3}
        .feature-card p{color:var(--muted);font-size:.82rem;line-height:1.7}
        .feature-card ol{margin-top:10px;color:var(--muted);font-size:.82rem;line-height:1.7;padding-left:18px;text-align:left}
        .feature-card details>summary{list-style:none;cursor:pointer}
        .feature-card details>summary::-webkit-details-marker{display:none}
        .role-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:stretch}
        .role-card{display:flex;flex-direction:column;padding:28px 26px;border:1px solid var(--line);border-radius:var(--radius);background:rgba(255,255,255,.02)}
        .role-card-featured{border-color:rgba(57,255,20,.3);background:radial-gradient(circle at 50% 0%,rgba(57,255,20,.1),transparent 55%),rgba(255,255,255,.025);box-shadow:0 24px 60px rgba(0,0,0,.3)}
        .role-card-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:22px}
        .role-avatar{display:grid;place-items:center;width:46px;height:46px;border:1px solid var(--line);border-radius:13px;background:rgba(255,255,255,.04);color:var(--green-text)}
        .role-avatar svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .role-badge{padding:5px 9px;border:1px solid var(--line);border-radius:999px;color:var(--muted-strong);font-size:.58rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
        .role-badge-lock{border-color:rgba(248,201,78,.3);background:rgba(248,201,78,.08);color:#f8d97e}
        .role-card h3{margin-bottom:11px;font-size:1.35rem;font-weight:800;letter-spacing:-.035em}
        .role-description{min-height:96px;margin-bottom:19px;color:var(--muted);font-size:.82rem;line-height:1.7}
        .role-list{display:grid;gap:10px;margin-bottom:25px}
        .role-list li{position:relative;padding-left:23px;color:var(--muted-strong);font-size:.76rem;line-height:1.55}
        .role-list li::before{content:"\2713";position:absolute;top:1px;left:0;color:var(--green-text);font-size:.72rem;font-weight:800}
        .site-footer{border-top:1px solid var(--line);background:#04060a}
        .site-footer .container{text-align:center;padding:2rem 0;color:var(--muted);font-size:.9rem}
        .brand-mini{display:inline-flex;align-items:center;gap:10px;margin-bottom:24px}
        .brand-mini img{width:34px;height:34px;border-radius:8px}
        .brand-mini strong{font-size:1.05rem;font-weight:800}
        @media (max-width:900px){.feature-grid,.role-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="blob b1"></div>
    <div class="blob b2"></div>
    <div class="blob b3"></div>
    <main>
        <section class="hero">
            <div class="container" style="text-align:center;">

                <span class="eyebrow"><span class="eyebrow-dot"></span>Academic Workspace</span>
                <h1>Your academic workspace, <span class="accent">all in one sphere.</span></h1>
                <p class="hero-lead">NoteSphere brings class notes, professor announcements, messaging, and student support together for the Colegio de Montalban community.</p>
                <a class="btn btn-primary" href="<?= $base ?>/portal.php">
                    Open System
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
                </a>
                <p style="margin-top:14px;color:var(--muted);font-size:.8rem;">📱 Installable as a PWA — access anytime on any device.</p>
            </div>
        </section>

        <section class="section">
            <div class="container">
                <div class="section-head">
                    <span class="eyebrow"><span class="eyebrow-dot"></span>Core features</span>
                    <h2>Everything you need, in one place.</h2>
                    <p>Click a feature to learn what it does and how to use it step by step.</p>
                </div>
                <div class="feature-grid">
                    <?php
                    $features = [
                        ['title'=>'Notes worth sharing','desc'=>'Upload, review, preview, download, and rate approved notes.','icon'=>'<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><polyline points="14 3 14 7 18 7"></polyline>','steps'=>['Go to View Notes and choose Institute.', 'Choose Upload button,fill in title, description.','Submit for admin review.','Once approved, browse, preview, download and rate.']],
                        ['title'=>'Announcements','desc'=>'Professors post updates by course and section.','icon'=>'<path d="M3 11l18-5v12L3 13v-2z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>','steps'=>['Professors create an announcement.','Select target institute, course and section.','Students get notified instantly.','View and dismiss when read.']],
                        ['title'=>'Messaging','desc'=>'Direct and group conversations with attachments.','icon'=>'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>','steps'=>['Open Messages.','Start a direct or group chat.','Send text and attachments.']],
                        ['title'=>'Help board','desc'=>'Ask questions and get notified on replies.','icon'=>'<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>','steps'=>['Post a question on the Help Board.','Professors and peers reply.','Mark resolved when done.']],
                        ['title'=>'Feedback','desc'=>'Ratings and satisfaction feedback for admins.','icon'=>'<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>','steps'=>['Rate a note after reading.','Submit satisfaction survey.','Admins review aggregated results.']],
                    ];
                    foreach ($features as $f): ?>
                    <details class="feature-card">
                        <summary>
                            <div class="feature-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $f['icon'] ?></svg></div>
                            <h3><?= htmlspecialchars($f['title']) ?></h3>
                        </summary>
                        <p><?= htmlspecialchars($f['desc']) ?></p>
                        <ol><?php foreach ($f['steps'] as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ol>
                    </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section section-alt">
            <div class="container">
                <div class="section-head">
                    <span class="eyebrow"><span class="eyebrow-dot"></span>Role-based access</span>
                    <h2>Built for every role.</h2>
                    <p>Students can access on any device; professors on PC or mobile; admins on desktop.</p>
                </div>
                <div class="role-grid">
                    <div class="role-card">
                        <div class="role-card-top">
                            <div class="role-avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></div>
                            <span class="role-badge">Any device</span>
                        </div>
                        <h3>Student</h3>
                        <p class="role-description">Access notes, announcements, and messaging on the go or on desktop.</p>
                        <ul class="role-list">
                            <li>View announcements</li>
                            <li>Browse and rate notes</li>
                            <li>Message professors</li>
                        </ul>
                    </div>
                    <div class="role-card role-card-featured">
                        <div class="role-card-top">
                            <div class="role-avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                            <span class="role-badge">PC or mobile</span>
                        </div>
                        <h3>Professor</h3>
                        <p class="role-description">Publish and manage academic content.</p>
                        <ul class="role-list">
                            <li>Publish announcements</li>
                            <li>Review notes</li>
                            <li>Set deadlines</li>
                        </ul>
                    </div>
                    <div class="role-card">
                        <div class="role-card-top">
                            <div class="role-avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
                            <span class="role-badge role-badge-lock">Desktop only</span>
                        </div>
                        <h3>Administrator</h3>
                        <p class="role-description">Full control and moderation.</p>
                        <ul class="role-list">
                            <li>Manage accounts</li>
                            <li>Approve content</li>
                            <li>Moderate board</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <footer class="site-footer">
        <div class="container">&copy; <?= $year ?> NoteSphere &middot; Colegio de Montalban</div>
    </footer>
</body>
</html>