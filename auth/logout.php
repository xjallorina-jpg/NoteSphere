
<?php
// =========================================================
// NoteSphere - Secure Logout with Lifetime Satisfaction Rating
// =========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser from showing cached pages after logout.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// HELPER: CLEAR SESSION AND LOG OUT
// =========================================================

function nsFinishLogout(bool $jsonResponse = false): void
{
    // Clear all session data.
    $_SESSION = [];

    // Remove the session cookie.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    // Destroy the session.
    session_destroy();

    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success'  => true,
            'redirect' => 'login.php'
        ]);
        exit;
    }

    // logout.php is inside the auth folder.
    header('Location: login.php');
    exit;
}

// =========================================================
// SECURITY CHECK
// =========================================================

$isLoggedIn =
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role']);

if (!$isLoggedIn) {
    nsFinishLogout();
}

$userId = (int) $_SESSION['user_id'];
$userRole = (string) $_SESSION['role'];

// =========================================================
// DETERMINE WHETHER RATING IS REQUESTED
// =========================================================

$ratingRequested =
    isset($_GET['rate']) &&
    $_GET['rate'] === '1';

// Ordinary logout without ?rate=1.
if (!$ratingRequested && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logout.php?rate=1');
    exit;
}

// =========================================================
// CREATE LIFETIME RATING TABLE IF NEEDED
//
// Each account can have only ONE rating for its lifetime.
// The UNIQUE user_id index prevents duplicate ratings.
// =========================================================

$ratingTableReady = false;
$ratingDatabaseError = '';

// Only attempt table creation once per session to avoid repeated DDL.
if (empty($_SESSION['rating_table_checked'])) {
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_satisfaction_ratings (
            rating_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            user_role VARCHAR(30) NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comments TEXT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (rating_id),
            UNIQUE KEY uq_user_satisfaction_lifetime_user (user_id),
            KEY idx_satisfaction_rating (rating),
            KEY idx_satisfaction_submitted_at (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ratingTableReady = true;
    $_SESSION['rating_table_checked'] = true;
} catch (Throwable $e) {
    error_log('NoteSphere satisfaction table error: ' . $e->getMessage());
    $ratingDatabaseError = 'The satisfaction rating database is currently unavailable.';
}
} else {
    $ratingTableReady = true;
}

// =========================================================
// POST: SUBMIT RATING OR SKIP
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['logout_rating_csrf'] ?? '');

    if (
        $sessionToken === '' ||
        $submittedToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Your session verification failed. Please refresh the page and try again.'
        ]);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    // Skip rating and log out.
    if ($action === 'skip') {
        nsFinishLogout(true);
    }

    if ($action !== 'submit') {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid request.'
        ]);
        exit;
    }

    if (!$ratingTableReady) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $ratingDatabaseError
        ]);
        exit;
    }

    $rating = filter_var(
        $_POST['rating'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($rating === false || $rating < 1 || $rating > 5) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Please select a rating from 1 to 5 stars.'
        ]);
        exit;
    }


    try {
        // Check whether this account has already rated.
        $check = $conn->prepare("
            SELECT rating_id
            FROM user_satisfaction_ratings
            WHERE user_id = ?
            LIMIT 1
        ");
        $check->execute([$userId]);

        if ($check->fetch(PDO::FETCH_ASSOC)) {
            // Existing rating is never updated.
            nsFinishLogout(true);
        }

        // Save the lifetime rating for this account.
        // The UNIQUE user_id key makes a duplicate submission safe.
        $comments = trim((string) ($_POST['comments'] ?? ''));

        if (mb_strlen($comments) > 2000) {
            $comments = mb_substr($comments, 0, 2000);
        }

        $insert = $conn->prepare("
            INSERT INTO user_satisfaction_ratings
            (
                user_id,
                user_role,
                rating,
                comments
            )
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                rating_id = rating_id
        ");

        $insert->execute([
            $userId,
            $userRole,
            (int) $rating,
            $comments !== '' ? $comments : null
        ]);

        // Whether newly inserted or already submitted in a race,
        // the account is now done with this rating prompt.
        nsFinishLogout(true);

    } catch (Throwable $e) {
        error_log('NoteSphere rating submission error: ' . $e->getMessage());

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'We could not save your rating. Please try again or choose Skip & Logout.'
        ]);
        exit;
    }
}

// =========================================================
// GET: CHECK WHETHER THIS ACCOUNT HAS ALREADY RATED
// =========================================================

if (!$ratingTableReady) {
    // Keep the user on the rating page so they can retry or skip.
    $hasRated = false;
} else {
    try {
        $check = $conn->prepare("
            SELECT rating_id
            FROM user_satisfaction_ratings
            WHERE user_id = ?
            LIMIT 1
        ");
        $check->execute([$userId]);

        $hasRated = (bool) $check->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log('NoteSphere rating lookup error: ' . $e->getMessage());
        $hasRated = false;
        $ratingDatabaseError = 'We could not check your rating status. You may retry or skip the rating.';
    }
}

// If already rated, do not show the popup again.
if ($hasRated) {
    nsFinishLogout();
}

// Create CSRF token for the rating form.
if (
    empty($_SESSION['logout_rating_csrf']) ||
    !is_string($_SESSION['logout_rating_csrf'])
) {
    $_SESSION['logout_rating_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['logout_rating_csrf'];
$displayName = (string) ($_SESSION['fullname'] ?? 'there');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>NoteSphere - Rate Your Experience</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #f8fafc;
            background:
                linear-gradient(rgba(8, 8, 8, .88), rgba(8, 8, 8, .96)),
                linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) center / cover fixed,
                #080808;
        }

        .rating-card {
            width: 100%;
            max-width: 520px;
            padding: 32px;
            border: 1px solid #1f293d;
            border-radius: 22px;
            background: #0c0c0c;
            text-align: center;
            box-shadow: 0 20px 70px rgba(0, 0, 0, .4);
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 25px;
            color: #39ff14;
            font-size: 1.4rem;
            font-weight: 800;
        }

        .brand img {
            width: 38px;
            height: auto;
        }

        .rating-icon {
            margin-bottom: 12px;
            font-size: 2.5rem;
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(1.35rem, 4vw, 1.8rem);
            line-height: 1.35;
        }

        .description {
            margin: 0 auto 24px;
            max-width: 390px;
            color: #94a3b8;
            font-size: .92rem;
            line-height: 1.7;
        }

        .stars {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 20px 0 8px;
        }

        .star {
            padding: 3px;
            border: 0;
            background: transparent;
            color: #475569;
            font-size: clamp(2.1rem, 8vw, 2.8rem);
            cursor: pointer;
            transition: transform .15s ease, color .15s ease;
        }

        .star:hover {
            transform: scale(1.12);
        }

        .star.selected {
            color: #fbbf24;
        }

        .rating-label {
            min-height: 24px;
            margin-bottom: 22px;
            color: #fbbf24;
            font-size: .85rem;
            font-weight: 700;
        }

        textarea {
            width: 100%;
            min-height: 110px;
            padding: 13px 14px;
            resize: vertical;
            border: 1px solid #273449;
            border-radius: 12px;
            outline: none;
            background: #111827;
            color: #f8fafc;
            font-size: .9rem;
            line-height: 1.6;
        }

        textarea:focus {
            border-color: #39ff14;
        }

        .comment-label {
            display: block;
            margin-bottom: 9px;
            text-align: left;
            font-size: .85rem;
            font-weight: 700;
        }

        .optional {
            color: #94a3b8;
            font-weight: 500;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 22px;
        }

        .actions button {
            flex: 1;
            min-height: 46px;
            padding: 12px;
            border-radius: 12px;
            font-size: .88rem;
            font-weight: 800;
            cursor: pointer;
        }

        .submit-btn {
            border: 1px solid #39ff14;
            background: #39ff14;
            color: #071007;
        }

        .submit-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .skip-btn {
            border: 1px solid #334155;
            background: transparent;
            color: #cbd5e1;
        }

        .privacy-note {
            margin-top: 20px;
            color: #64748b;
            font-size: .75rem;
            line-height: 1.6;
        }

        @media (max-width: 480px) {
            .rating-card {
                padding: 24px 18px;
                border-radius: 18px;
            }

            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <main class="rating-card">
        <div class="brand">
            <img src="../assets/cdmlogo.png" alt="Colegio de Montalban">
            <span>NoteSphere</span>
        </div>

        <div class="rating-icon">💚</div>

        <h1>How was your NoteSphere experience?</h1>

        <p class="description">
            Hello, <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>!
            Before you log out, please rate your experience.
            Your feedback helps us improve NoteSphere.
        </p>

        <?php if ($ratingDatabaseError !== ''): ?>
            <p style="color:#fca5a5;font-size:.82rem;line-height:1.6;margin-bottom:18px;">
                <?= htmlspecialchars($ratingDatabaseError, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form id="ratingForm">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
            >

            <input type="hidden" name="rating" id="ratingValue" value="">

            <div class="stars" role="group" aria-label="Choose a rating from one to five stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button
                        type="button"
                        class="star"
                        data-rating="<?= $i ?>"
                        aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"
                        title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"
                    >★</button>
                <?php endfor; ?>
            </div>

            <div class="rating-label" id="ratingLabel">
                Select your rating
            </div>

            <label class="comment-label" for="comments">
                Comments <span class="optional">(Optional)</span>
            </label>

            <textarea
                name="comments"
                id="comments"
                maxlength="5000"
                placeholder="Tell us what you liked or what we can improve..."
            ></textarea>

            <div class="actions">
                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    Submit Rating & Logout
                </button>

                <button type="button" class="skip-btn" id="skipBtn">
                    Skip & Logout
                </button>
            </div>
        </form>

        <p class="privacy-note">
            You can submit one satisfaction rating per account.
            After submitting, your rating cannot be changed.
        </p>
    </main>

    <script>
        const form = document.getElementById('ratingForm');
        const ratingValue = document.getElementById('ratingValue');
        const ratingLabel = document.getElementById('ratingLabel');
        const submitBtn = document.getElementById('submitBtn');
        const skipBtn = document.getElementById('skipBtn');
        const starButtons = document.querySelectorAll('.star');

        const ratingLabels = {
            1: 'Very Dissatisfied',
            2: 'Dissatisfied',
            3: 'Neutral',
            4: 'Satisfied',
            5: 'Very Satisfied'
        };

        starButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const selectedRating = Number(this.dataset.rating);

                ratingValue.value = selectedRating;
                ratingLabel.textContent = ratingLabels[selectedRating];
                submitBtn.disabled = false;

                starButtons.forEach(function(star) {
                    const starRating = Number(star.dataset.rating);
                    star.classList.toggle(
                        'selected',
                        starRating <= selectedRating
                    );
                    star.setAttribute(
                        'aria-pressed',
                        starRating === selectedRating ? 'true' : 'false'
                    );
                });
            });
        });

        async function sendLogoutRequest(action) {
            const data = new FormData();

            data.append('action', action);
            data.append(
                'csrf_token',
                form.querySelector('[name="csrf_token"]').value
            );

            if (action === 'submit') {
                data.append('rating', ratingValue.value);
                data.append(
                    'comments',
                    document.getElementById('comments').value
                );
            }

            const response = await fetch('logout.php?rate=1', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Something went wrong. Please try again.'
                );
            }

            window.location.replace(result.redirect || 'login.php');
        }

        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            if (!ratingValue.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select a rating',
                    text: 'Please select from 1 to 5 stars before submitting.',
                    confirmButtonColor: '#16a34a'
                });
                return;
            }

            submitBtn.disabled = true;
            skipBtn.disabled = true;

            try {
                await sendLogoutRequest('submit');
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unable to save rating',
                    text: error.message,
                    confirmButtonColor: '#16a34a'
                });

                submitBtn.disabled = false;
                skipBtn.disabled = false;
            }
        });

        skipBtn.addEventListener('click', async function() {
            const confirmation = await Swal.fire({
                title: 'Skip rating?',
                text: 'Your feedback will not be submitted. Do you still want to log out?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Skip & Logout',
                cancelButtonText: 'Go Back',
                reverseButtons: true,
                background: '#0c0c0c',
                color: '#f8fafc',
                confirmButtonColor: '#ff4d4d',
                cancelButtonColor: '#475569'
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            submitBtn.disabled = true;
            skipBtn.disabled = true;

            try {
                await sendLogoutRequest('skip');
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unable to log out',
                    text: error.message,
                    confirmButtonColor: '#16a34a'
                });

                submitBtn.disabled = false;
                skipBtn.disabled = false;
            }
        });
    </script>
</body>
</html>

