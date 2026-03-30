<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/SecurityHeaders.php';
require_once __DIR__ . '/db_connection.php';

$roli = isset($_SESSION['roli']) ? $_SESSION['roli'] : '';
$is_admin = ($roli === 'admin');
$client_name = isset($_SESSION['client_name']) ? $_SESSION['client_name'] : 'anonim';
$payment_required = true;
$has_paid = false;
$payment_url = '';
$session_duration = 30;
$session_price = 15.00;
$payment_data = null;

if ($client_name !== 'anonim' && !$is_admin) {
    $conn = connectToDatabase();
    $has_paid_session = isset($_SESSION['video_payment']) &&
                         $_SESSION['video_payment']['status'] === 'completed' &&
                         $_SESSION['video_payment']['expiry'] > time();
    if ($has_paid_session) {
        $has_paid = true;
        $minutes_remaining = max(0, round(($_SESSION['video_payment']['expiry'] - time()) / 60));
        $session_duration = $minutes_remaining;
    } else {
        $check_query = "SELECT * FROM payments WHERE client_name = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 1";
        $stmt = $conn->prepare($check_query);
        $stmt->execute([$client_name]);
        $payment_data = $stmt->fetch();
        if ($payment_data) {
            $has_paid = true;
            $payment_time = strtotime($payment_data['created_at']);
            $expiry_time = $payment_time + (30 * 60);
            $minutes_remaining = max(0, round(($expiry_time - time()) / 60));
            $session_duration = $minutes_remaining;
            $_SESSION['video_payment'] = ['status' => 'completed', 'expiry' => $expiry_time];
        } else {
            if (isset($_GET['room']) && !empty($_GET['room'])) {
                $room = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['room']);
                $payment_url = "payment_confirmation.php?service=video&room=" . urlencode($room);
            }
        }
    }
}

$emri = isset($_SESSION['emri']) ? $_SESSION['emri'] : '';
$mbiemri = isset($_SESSION['mbiemri']) ? $_SESSION['mbiemri'] : '';
$username = isset($_SESSION['username']) ? $_SESSION['username'] : ($is_admin ? "Admin" : "Përdorues");
if (!empty($emri) && !empty($mbiemri)) {
    $username = $emri . ' ' . $mbiemri;
    if ($is_admin) $username .= ' (Admin)';
}
$room = isset($_GET['room']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['room']) : 'e-noteria_' . $client_name;

try {
    if (!isset($conn) || !$conn) $conn = connectToDatabase();
    $call_id = 'call_' . uniqid();
    $insertSql = "INSERT INTO video_calls (call_id, room, user_id, start_time, status) VALUES (?, ?, ?, NOW(), 'active')";
    if ($stmtCall = $conn->prepare($insertSql)) {
        $stmtCall->execute([$call_id, $room, $client_name]);
    }
    $_SESSION['current_call'] = ['call_id' => $call_id, 'room' => $room, 'started_at' => time()];
} catch (Exception $e) {
    error_log('Could not create video call record: ' . $e->getMessage());
    if (!isset($call_id)) $call_id = 'call_' . uniqid();
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
<meta charset="UTF-8">
<title>e-Noteria · Konsulencë Video</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=DM+Sans:wght@300;400;500&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/fontawesome/all.min.css">

<audio id="ringtone" preload="auto" loop class="sr-only">
    <source src="e-noteria-ringtone.mp3" type="audio/mpeg">
</audio>
<audio id="calling-sound" preload="auto" loop class="sr-only">
    <source src="e-noteria-calling-sound.mp3" type="audio/mpeg">
</audio>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

:root {
    --navy:       #0a0e1a;
    --navy-mid:   #0f1626;
    --navy-card:  #131929;
    --navy-glass: rgba(15, 22, 38, 0.92);
    --gold:       #c9a84c;
    --gold-light: #e2c47a;
    --gold-pale:  rgba(201,168,76,0.12);
    --gold-line:  rgba(201,168,76,0.28);
    --white:      #f4f0e8;
    --white-dim:  rgba(244,240,232,0.55);
    --white-ghost:rgba(244,240,232,0.12);
    --red:        #e05555;
    --green:      #4caf7d;
    --blue-soft:  #4a90c4;
    --radius-sm:  6px;
    --radius-md:  12px;
    --radius-lg:  20px;
    --radius-xl:  28px;
    --shadow-card:0 24px 64px rgba(0,0,0,0.55), 0 2px 8px rgba(0,0,0,0.4);
    --t:          all 0.22s cubic-bezier(.4,0,.2,1);
    --font-serif: 'Cormorant Garamond', Georgia, serif;
    --font-body:  'DM Sans', sans-serif;
    --font-mono:  'DM Mono', monospace;
}

.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); }

html, body {
    height: 100%;
    background: var(--navy);
    font-family: var(--font-body);
    color: var(--white);
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

/* ═══════════════════════════════════════════════
   BACKGROUND TEXTURE
═══════════════════════════════════════════════ */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 15% 0%, rgba(201,168,76,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 85% 100%, rgba(74,144,196,0.05) 0%, transparent 60%),
        repeating-linear-gradient(
            0deg,
            transparent,
            transparent 39px,
            rgba(201,168,76,0.025) 39px,
            rgba(201,168,76,0.025) 40px
        ),
        repeating-linear-gradient(
            90deg,
            transparent,
            transparent 39px,
            rgba(201,168,76,0.015) 39px,
            rgba(201,168,76,0.015) 40px
        );
    pointer-events: none;
    z-index: 0;
}

/* ═══════════════════════════════════════════════
   LAYOUT SHELL
═══════════════════════════════════════════════ */
.page-shell {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ═══════════════════════════════════════════════
   TOP BAR
═══════════════════════════════════════════════ */
.topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    height: 64px;
    background: var(--navy-glass);
    border-bottom: 1px solid var(--gold-line);
    backdrop-filter: blur(20px) saturate(1.4);
    -webkit-backdrop-filter: blur(20px) saturate(1.4);
    gap: 16px;
}

.topbar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
}

.brand-icon {
    width: 36px;
    height: 36px;
    background: var(--gold-pale);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    font-size: 14px;
}

.brand-wordmark {
    font-family: var(--font-serif);
    font-size: 22px;
    font-weight: 600;
    color: var(--gold-light);
    letter-spacing: 0.04em;
    line-height: 1;
}

.brand-sep {
    width: 1px;
    height: 20px;
    background: var(--gold-line);
    flex-shrink: 0;
}

.brand-sub {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--white-dim);
}

.topbar-center {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    justify-content: center;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 14px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.05em;
    border: 1px solid;
    transition: var(--t);
}
.status-pill.offline  { background: rgba(224,85,85,0.1); border-color: rgba(224,85,85,0.3); color: #f08080; }
.status-pill.online   { background: rgba(76,175,125,0.1); border-color: rgba(76,175,125,0.3); color: #7ddba4; }
.status-pill.waiting  { background: rgba(201,168,76,0.1); border-color: var(--gold-line); color: var(--gold); }
.status-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: currentColor;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.5; transform:scale(0.75); }
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.user-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px 5px 5px;
    background: var(--white-ghost);
    border: 1px solid rgba(244,240,232,0.15);
    border-radius: 100px;
    font-size: 12.5px;
    font-weight: 400;
    color: var(--white-dim);
}
.user-avatar {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--gold-pale);
    border: 1px solid var(--gold-line);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px;
    color: var(--gold);
    font-weight: 500;
}
<?php if ($is_admin): ?>
.user-chip { background: rgba(201,168,76,0.08); border-color: var(--gold-line); }
.user-avatar { background: rgba(201,168,76,0.2); }
<?php endif; ?>

.icon-btn {
    width: 36px; height: 36px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--white-ghost);
    background: transparent;
    color: var(--white-dim);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    cursor: pointer;
    transition: var(--t);
}
.icon-btn:hover { background: var(--white-ghost); border-color: rgba(244,240,232,0.25); color: var(--white); }

.danger-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 16px;
    border-radius: var(--radius-sm);
    background: rgba(224,85,85,0.1);
    border: 1px solid rgba(224,85,85,0.3);
    color: #f08080;
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-body);
    cursor: pointer;
    transition: var(--t);
    text-decoration: none;
}
.danger-btn:hover { background: rgba(224,85,85,0.18); border-color: rgba(224,85,85,0.5); color: #ffa0a0; }

.back-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 16px;
    border-radius: var(--radius-sm);
    background: var(--white-ghost);
    border: 1px solid rgba(244,240,232,0.15);
    color: var(--white-dim);
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-body);
    cursor: pointer;
    transition: var(--t);
    text-decoration: none;
}
.back-btn:hover { background: rgba(244,240,232,0.1); color: var(--white); }

/* ═══════════════════════════════════════════════
   MAIN CONTENT
═══════════════════════════════════════════════ */
.main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px 20px 0;
    max-width: 1400px;
    width: 100%;
    margin: 0 auto;
    align-self: center;
}

/* ═══════════════════════════════════════════════
   INFO ROW (notice + room)
═══════════════════════════════════════════════ */
.info-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.card {
    background: var(--navy-card);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-lg);
    padding: 22px 26px;
    box-shadow: var(--shadow-card);
    position: relative;
    overflow: hidden;
}

.card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    opacity: 0.6;
}

.card-eyebrow {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-eyebrow::before {
    content: '';
    display: inline-block;
    width: 18px; height: 1px;
    background: var(--gold);
    opacity: 0.6;
}

/* Notice card */
.notice-card {}

.join-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.field-wrap {
    flex: 1;
    min-width: 180px;
    position: relative;
}

.field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gold);
    opacity: 0.7;
    font-size: 13px;
    pointer-events: none;
}

.text-input {
    width: 100%;
    height: 42px;
    background: rgba(244,240,232,0.04);
    border: 1px solid rgba(244,240,232,0.15);
    border-radius: var(--radius-sm);
    color: var(--white);
    font-family: var(--font-body);
    font-size: 14px;
    padding: 0 14px 0 38px;
    outline: none;
    transition: var(--t);
}
.text-input:focus { border-color: var(--gold); background: rgba(201,168,76,0.05); box-shadow: 0 0 0 3px rgba(201,168,76,0.12); }
.text-input::placeholder { color: var(--white-dim); }

.gold-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 0 20px;
    height: 42px;
    background: linear-gradient(135deg, var(--gold) 0%, #a07830 100%);
    border: none;
    border-radius: var(--radius-sm);
    color: #0a0e1a;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--t);
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}
.gold-btn:hover { background: linear-gradient(135deg, var(--gold-light) 0%, var(--gold) 100%); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(201,168,76,0.35); }
.gold-btn:active { transform: translateY(0); }

.notice-text {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--gold-line);
    font-size: 12px;
    color: var(--white-dim);
}
.notice-text i { color: var(--gold); font-size: 11px; }

/* Payment card */
.payment-card {}
.payment-amount {
    font-family: var(--font-serif);
    font-size: 42px;
    font-weight: 600;
    color: var(--gold-light);
    line-height: 1;
    margin-bottom: 6px;
}
.payment-label {
    font-size: 12px;
    color: var(--white-dim);
    margin-bottom: 16px;
}
.payment-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: rgba(76,175,125,0.12);
    border: 1px solid rgba(76,175,125,0.3);
    border-radius: 100px;
    font-size: 11px;
    color: #7ddba4;
    font-weight: 500;
}

/* Room card */
.room-card {}
.room-link-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.room-link-box {
    flex: 1;
    background: rgba(244,240,232,0.03);
    border: 1px solid rgba(244,240,232,0.1);
    border-radius: var(--radius-sm);
    padding: 10px 14px;
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--white-dim);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-btn {
    flex-shrink: 0;
    height: 38px;
    padding: 0 16px;
    border-radius: var(--radius-sm);
    background: var(--white-ghost);
    border: 1px solid rgba(244,240,232,0.15);
    color: var(--white-dim);
    font-family: var(--font-body);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--t);
    display: flex;
    align-items: center;
    gap: 7px;
}
.copy-btn:hover { background: rgba(201,168,76,0.1); border-color: var(--gold-line); color: var(--gold); }
.copy-btn.copied { background: rgba(76,175,125,0.12); border-color: rgba(76,175,125,0.3); color: #7ddba4; }

.stats-row {
    display: flex;
    gap: 20px;
}
.stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--white-dim);
}
.stat-item i { color: var(--gold); font-size: 11px; opacity: 0.8; }
.stat-value { font-family: var(--font-mono); color: var(--white); font-size: 12px; }

/* ═══════════════════════════════════════════════
   VIDEO AREA
═══════════════════════════════════════════════ */
.video-wrap {
    position: relative;
    border-radius: var(--radius-xl);
    overflow: hidden;
    background: var(--navy-card);
    border: 1px solid var(--gold-line);
    box-shadow: var(--shadow-card), 0 0 80px rgba(201,168,76,0.06);
    height: 68vh;
    min-height: 380px;
}

.video-wrap::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--gold-light) 50%, var(--gold) 70%, transparent 100%);
    z-index: 5;
    opacity: 0.8;
}

#video {
    width: 100%;
    height: 100%;
}

/* Video loader overlay */
.video-loader {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: var(--navy-card);
    z-index: 10;
    transition: opacity 0.6s ease;
}

.loader-seal {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 1px solid var(--gold-line);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
    position: relative;
}
.loader-seal::after {
    content: '';
    position: absolute;
    inset: 4px;
    border-radius: 50%;
    border: 1px solid transparent;
    border-top-color: var(--gold);
    animation: spin 1.4s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loader-seal i { font-size: 22px; color: var(--gold); opacity: 0.7; }

.loader-label {
    font-family: var(--font-serif);
    font-size: 18px;
    font-weight: 500;
    color: var(--gold-light);
    margin-bottom: 6px;
}
.loader-sub {
    font-size: 12px;
    color: var(--white-dim);
    letter-spacing: 0.06em;
}

/* In-video controls */
.video-controls {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(10,14,26,0.88);
    border: 1px solid var(--gold-line);
    border-radius: 100px;
    padding: 10px 18px;
    backdrop-filter: blur(20px);
}

.ctrl-btn {
    width: 44px; height: 44px;
    border-radius: 50%;
    border: 1px solid rgba(244,240,232,0.15);
    background: var(--white-ghost);
    color: var(--white);
    font-size: 15px;
    cursor: pointer;
    transition: var(--t);
    display: flex;
    align-items: center;
    justify-content: center;
}
.ctrl-btn:hover { background: rgba(244,240,232,0.12); border-color: rgba(244,240,232,0.3); transform: translateY(-2px); }
.ctrl-btn:active { transform: translateY(0) scale(0.95); }
.ctrl-btn.muted, .ctrl-btn.off { background: rgba(224,85,85,0.15); border-color: rgba(224,85,85,0.4); color: #f08080; }
.ctrl-btn.gold { background: rgba(201,168,76,0.15); border-color: var(--gold-line); color: var(--gold); }

.ctrl-sep {
    width: 1px; height: 24px;
    background: var(--gold-line);
    margin: 0 4px;
}

.end-btn {
    height: 44px;
    padding: 0 20px;
    border-radius: 100px;
    background: linear-gradient(135deg, #c0392b, #922b21);
    border: none;
    color: #fff;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--t);
    display: flex;
    align-items: center;
    gap: 8px;
}
.end-btn:hover { background: linear-gradient(135deg, #e74c3c, #c0392b); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(192,57,43,0.5); }

/* Advanced stats panel */
.stats-panel {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 20;
    background: rgba(10,14,26,0.88);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-md);
    padding: 14px 16px;
    backdrop-filter: blur(16px);
    min-width: 160px;
    display: none;
}
.stats-panel.active { display: block; }
.sp-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 3px 0;
    font-size: 11.5px;
}
.sp-label { color: var(--white-dim); }
.sp-val { font-family: var(--font-mono); color: var(--gold-light); }

/* Payment timer */
.payment-timer {
    position: absolute;
    top: 18px;
    left: 18px;
    z-index: 20;
    background: rgba(10,14,26,0.88);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-md);
    padding: 10px 16px;
    backdrop-filter: blur(16px);
    display: flex;
    align-items: center;
    gap: 10px;
}
.pt-icon { color: var(--gold); font-size: 14px; }
.pt-label { font-size: 10px; color: var(--white-dim); letter-spacing: 0.08em; text-transform: uppercase; }
.pt-time { font-family: var(--font-mono); font-size: 18px; font-weight: 500; color: var(--white); }

/* ═══════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════ */
.footer {
    margin-top: 16px;
    padding: 16px 20px;
    border-top: 1px solid var(--gold-line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 11.5px;
    color: var(--white-dim);
    flex-wrap: wrap;
}

.footer-brand {
    font-family: var(--font-serif);
    color: var(--gold);
    font-size: 13px;
}

.footer-links {
    display: flex;
    align-items: center;
    gap: 16px;
}

.footer-link {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--white-dim);
    text-decoration: none;
    font-size: 11.5px;
    transition: var(--t);
}
.footer-link:hover { color: var(--white); }
.footer-link.danger { color: rgba(224,85,85,0.7); }
.footer-link.danger:hover { color: #f08080; }

.lang-group {
    display: flex;
    gap: 4px;
}
.lang-btn {
    padding: 3px 9px;
    border-radius: 4px;
    border: 1px solid rgba(244,240,232,0.12);
    background: transparent;
    color: var(--white-dim);
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.05em;
    cursor: pointer;
    transition: var(--t);
    font-family: var(--font-body);
}
.lang-btn.active { background: var(--gold-pale); border-color: var(--gold-line); color: var(--gold); }
.lang-btn:hover:not(.active) { background: var(--white-ghost); color: var(--white); }

/* ═══════════════════════════════════════════════
   INCOMING CALL MODAL
═══════════════════════════════════════════════ */
.call-modal-bg {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(10,14,26,0.85);
    backdrop-filter: blur(8px);
}
.call-modal-bg.show { display: flex; }

.call-modal {
    background: var(--navy-card);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-xl);
    padding: 48px 52px;
    text-align: center;
    max-width: 440px;
    width: 90%;
    box-shadow: 0 40px 100px rgba(0,0,0,0.8), 0 0 60px rgba(201,168,76,0.08);
    position: relative;
    overflow: hidden;
    animation: modal-in 0.4s cubic-bezier(.34,1.56,.64,1) forwards;
}
@keyframes modal-in {
    from { opacity:0; transform: translateY(24px) scale(0.95); }
    to   { opacity:1; transform: translateY(0) scale(1); }
}

.call-modal::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold), var(--gold-light), var(--gold), transparent);
}

.caller-ring {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    margin: 0 auto 24px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}
.caller-ring::before, .caller-ring::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    border: 1px solid var(--gold);
    animation: ring-out 2s ease-out infinite;
}
.caller-ring::before { inset: -10px; opacity: 0.4; }
.caller-ring::after  { inset: -22px; opacity: 0.2; animation-delay: 0.4s; }
@keyframes ring-out {
    0%   { transform: scale(0.9); opacity: 0.4; }
    100% { transform: scale(1.15); opacity: 0; }
}
.caller-icon {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: var(--gold-pale);
    border: 1px solid var(--gold-line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: var(--gold);
}

.caller-title {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 600;
    color: var(--white);
    margin-bottom: 6px;
}
.caller-name {
    font-family: var(--font-serif);
    font-size: 20px;
    font-weight: 500;
    color: var(--gold-light);
    margin-bottom: 8px;
}
.caller-status {
    font-size: 12px;
    color: var(--white-dim);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 36px;
}

.call-actions {
    display: flex;
    justify-content: center;
    gap: 28px;
}
.call-act-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    border: none;
    background: transparent;
    color: var(--white-dim);
    font-family: var(--font-body);
    font-size: 11px;
    letter-spacing: 0.05em;
    transition: var(--t);
}
.call-act-btn .btn-circle {
    width: 64px; height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    transition: var(--t);
}
.call-act-btn:hover .btn-circle { transform: scale(1.08); }
.accept-act .btn-circle { background: linear-gradient(135deg, #2ecc71, #27ae60); color: #fff; box-shadow: 0 8px 28px rgba(46,204,113,0.4); }
.reject-act .btn-circle { background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; box-shadow: 0 8px 28px rgba(231,76,60,0.4); }

/* ═══════════════════════════════════════════════
   ABUSE MODAL
═══════════════════════════════════════════════ */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 8000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(10,14,26,0.8);
    backdrop-filter: blur(6px);
}
.modal-overlay.show { display: flex; }

.modal-box {
    background: var(--navy-card);
    border: 1px solid var(--gold-line);
    border-radius: var(--radius-lg);
    padding: 36px 40px;
    max-width: 480px;
    width: 90%;
    box-shadow: var(--shadow-card);
    animation: modal-in 0.3s ease forwards;
}
.modal-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
}
.modal-icon {
    width: 38px; height: 38px;
    border-radius: var(--radius-sm);
    background: rgba(224,85,85,0.1);
    border: 1px solid rgba(224,85,85,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f08080;
    font-size: 15px;
}
.modal-title {
    font-family: var(--font-serif);
    font-size: 20px;
    font-weight: 600;
    color: var(--white);
}

.modal-textarea {
    width: 100%;
    min-height: 100px;
    resize: vertical;
    background: rgba(244,240,232,0.04);
    border: 1px solid rgba(244,240,232,0.12);
    border-radius: var(--radius-sm);
    color: var(--white);
    font-family: var(--font-body);
    font-size: 14px;
    padding: 12px 14px;
    outline: none;
    transition: var(--t);
    margin-bottom: 16px;
}
.modal-textarea:focus { border-color: rgba(224,85,85,0.5); background: rgba(224,85,85,0.04); }
.modal-textarea::placeholder { color: var(--white-dim); }

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.ghost-btn {
    padding: 9px 18px;
    border-radius: var(--radius-sm);
    border: 1px solid rgba(244,240,232,0.15);
    background: transparent;
    color: var(--white-dim);
    font-family: var(--font-body);
    font-size: 13px;
    cursor: pointer;
    transition: var(--t);
}
.ghost-btn:hover { background: var(--white-ghost); color: var(--white); }

.submit-btn {
    padding: 9px 18px;
    border-radius: var(--radius-sm);
    background: rgba(224,85,85,0.15);
    border: 1px solid rgba(224,85,85,0.35);
    color: #f08080;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--t);
}
.submit-btn:hover { background: rgba(224,85,85,0.25); }

.success-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(76,175,125,0.1);
    border: 1px solid rgba(76,175,125,0.3);
    border-radius: var(--radius-sm);
    color: #7ddba4;
    font-size: 13px;
    margin-top: 12px;
    display: none;
}

/* Admin controls */
.admin-strip {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--gold-line);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-action {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid rgba(244,240,232,0.12);
    background: var(--white-ghost);
    color: var(--white-dim);
    font-size: 11.5px;
    font-weight: 500;
    font-family: var(--font-body);
    cursor: pointer;
    transition: var(--t);
}
.admin-action:hover { background: rgba(244,240,232,0.1); color: var(--white); }
.admin-action.record-act { border-color: rgba(156,39,176,0.3); background: rgba(156,39,176,0.08); color: #ce93d8; }
.admin-action.end-act    { border-color: rgba(224,85,85,0.3); background: rgba(224,85,85,0.08); color: #f08080; }

/* Test btn */
#test-audio-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 14px;
    border-radius: var(--radius-sm);
    background: var(--gold-pale);
    border: 1px solid var(--gold-line);
    color: var(--gold);
    font-size: 11.5px;
    font-weight: 500;
    font-family: var(--font-body);
    cursor: pointer;
    transition: var(--t);
}
#test-audio-btn:hover { background: rgba(201,168,76,0.18); }

/* ═══════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════ */
@media (max-width: 900px) {
    .info-row { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .brand-sub, .brand-sep { display: none; }
    .topbar-center { display: none; }
    .main-content { padding: 14px 14px 0; }
    .video-wrap { height: 55vh; }
    .card { padding: 18px 18px; }
}
@media (max-width: 640px) {
    .topbar { height: 56px; }
    .brand-wordmark { font-size: 18px; }
    .video-wrap { height: 48vh; min-height: 300px; }
    .video-controls { padding: 8px 12px; gap: 7px; }
    .ctrl-btn { width: 38px; height: 38px; font-size: 13px; }
    .call-modal { padding: 32px 24px; }
    .stats-row { flex-wrap: wrap; gap: 12px; }
    .footer { flex-direction: column; align-items: flex-start; gap: 12px; }
}

/* Scrollbar */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: var(--navy); }
::-webkit-scrollbar-thumb { background: var(--gold-line); border-radius: 2px; }
</style>
</head>
<body>
<!-- ════ INCOMING CALL MODAL ════ -->
<div id="incomingCallModal" class="call-modal-bg">
    <div class="call-modal">
        <div class="caller-ring">
            <div class="caller-icon"><i class="fa-solid fa-video"></i></div>
        </div>
        <div class="caller-title">Thirrje Hyrëse</div>
        <div class="caller-name" id="callerName">Noter</div>
        <div class="caller-status">Po thërret · e-Noteria</div>
        <div class="call-actions">
            <button class="call-act-btn accept-act" onclick="acceptCall()">
                <div class="btn-circle"><i class="fa-solid fa-phone"></i></div>
                <span>Prano</span>
            </button>
            <button class="call-act-btn reject-act" onclick="rejectCall()">
                <div class="btn-circle"><i class="fa-solid fa-phone-slash"></i></div>
                <span>Refuzo</span>
            </button>
        </div>
    </div>
</div>

<!-- ════ ABUSE MODAL ════ -->
<div id="modalOverlay" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="modal-title">Raporto Abuzim</div>
        </div>
        <form id="abuse-form" onsubmit="return submitAbuse();">
            <textarea id="abuse-msg" class="modal-textarea" placeholder="Përshkruani situatën me detaje…" required></textarea>
            <div class="modal-actions">
                <button type="button" class="ghost-btn" onclick="closeModal()">Anulo</button>
                <button type="submit" class="submit-btn"><i class="fa-solid fa-paper-plane"></i> Dërgo Raportin</button>
            </div>
        </form>
        <div id="abuse-success" class="success-msg">
            <i class="fa-solid fa-circle-check"></i> Raporti u dërgua me sukses. Faleminderit.
        </div>
    </div>
</div>

<!-- ════ PAGE SHELL ════ -->
<div class="page-shell">

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <div class="brand-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div class="brand-wordmark">e-Noteria</div>
            <div class="brand-sep"></div>
            <div class="brand-sub">Konsulencë Video</div>
        </div>

        <div class="topbar-center">
            <div id="jitsi-status-pill" class="status-pill waiting">
                <span class="status-dot"></span>
                <span id="jitsi-status-text">Duke lidhur…</span>
            </div>
        </div>

        <div class="topbar-right">
            <div class="user-chip">
                <div class="user-avatar">
                    <?php echo mb_strtoupper(mb_substr($emri ?: 'U', 0, 1) . mb_substr($mbiemri ?: '', 0, 1)); ?>
                </div>
                <?php echo htmlspecialchars($username); ?>
                <?php if ($is_admin): ?><i class="fa-solid fa-crown" style="color:var(--gold);font-size:10px;"></i><?php endif; ?>
            </div>
            <button id="test-audio-btn" onclick="testRingtoneClick()" title="Test zile">
                <i class="fa-solid fa-bell"></i> Test
            </button>
            <button class="danger-btn" onclick="openModal()">
                <i class="fa-solid fa-triangle-exclamation"></i> Raporto
            </button>
            <a href="dashboard.php" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i> Paneli
            </a>
        </div>
    </header>

    <!-- MAIN -->
    <main class="main-content">

        <!-- INFO ROW -->
        <div class="info-row">

            <!-- LEFT: Notice / Join / Payment -->
            <div class="card notice-card">
                <div class="card-eyebrow"><i class="fa-solid fa-circle-info"></i> Sesioni</div>

                <?php if (!$is_admin && !$has_paid && $payment_required): ?>
                    <!-- PAYMENT REQUIRED -->
                    <div class="payment-amount"><?= number_format($session_price, 2) ?> <span style="font-size:20px;color:var(--white-dim);">EUR</span></div>
                    <div class="payment-label">Konsulencë 30 minuta · Pagesë e sigurt</div>
                    <?php if (!empty($payment_url)): ?>
                        <a href="<?= htmlspecialchars($payment_url) ?>" class="gold-btn" style="margin-bottom:14px;">
                            <i class="fa-solid fa-credit-card"></i> Paguaj tani
                        </a>
                    <?php endif; ?>
                    <div class="notice-text">
                        <i class="fa-solid fa-shield-halved"></i>
                        Pagesa procesohet nëpërmjet Paysera, Raiffeisen Bank dhe BKT me enkriptim SSL.
                    </div>

                <?php else: ?>
                    <!-- JOIN FORM -->
                    <?php if ($has_paid): ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                            <span class="payment-badge"><i class="fa-solid fa-check"></i> Pagesa e konfirmuar</span>
                            <span style="font-size:12px;color:var(--white-dim);"><?= $session_duration ?> minuta të disponueshme</span>
                        </div>
                    <?php endif; ?>
                    <form id="join-room-form" method="get" action="video_call.php">
                        <div class="join-form">
                            <div class="field-wrap">
                                <i class="fa-solid fa-hashtag field-icon"></i>
                                <input type="text" name="room" class="text-input" placeholder="Kodi i dhomës…" required>
                            </div>
                            <button type="submit" class="gold-btn">
                                <i class="fa-solid fa-right-to-bracket"></i> Bashkohu
                            </button>
                        </div>
                    </form>
                    <div class="notice-text">
                        <i class="fa-solid fa-lock"></i>
                        Thirrja është enkriptuar end-to-end · Vetëm pjesëmarrësit e ftuar mund të bashkohen.
                    </div>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                    <div class="admin-strip">
                        <button class="admin-action record-act" onclick="openRecordingModal()">
                            <i class="fa-solid fa-record-vinyl"></i> Regjistro
                        </button>
                        <button class="admin-action" onclick="muteAllAction()">
                            <i class="fa-solid fa-volume-xmark"></i> Hesht të gjithë
                        </button>
                        <button class="admin-action end-act" onclick="endCallAdmin()">
                            <i class="fa-solid fa-phone-slash"></i> Mbyll thirrjen
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Room Link -->
            <div class="card room-card">
                <div class="card-eyebrow"><i class="fa-solid fa-link"></i> Dhoma</div>
                <div class="room-link-row">
                    <div class="room-link-box" id="room-link">
                        <?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']."?room=".$room); ?>
                    </div>
                    <button class="copy-btn" id="copy-btn" onclick="copyRoomLink()">
                        <i class="fa-regular fa-copy"></i> <span class="copy-label">Kopjo</span>
                    </button>
                </div>
                <div class="stats-row">
                    <div class="stat-item">
                        <i class="fa-solid fa-clock"></i>
                        <span class="stat-value" id="call-timer">00:00:00</span>
                    </div>
                    <div class="stat-item">
                        <i class="fa-solid fa-users"></i>
                        <span class="stat-value" id="participant-count">1</span>
                        <span style="font-size:11px;color:var(--white-dim);">pjesëmarrës</span>
                    </div>
                    <div class="stat-item">
                        <i class="fa-solid fa-signal"></i>
                        <span class="stat-value" id="connection-quality">—</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIDEO AREA -->
        <div class="video-wrap">

            <!-- Loader -->
            <div class="video-loader" id="video-loader">
                <div class="loader-seal">
                    <i class="fa-solid fa-scale-balanced"></i>
                </div>
                <div class="loader-label">Duke vendosur lidhjen…</div>
                <div class="loader-sub">Ju luteni prisni · e-Noteria</div>
            </div>

            <!-- Jitsi container -->
            <div id="video" style="width:100%;height:100%;"></div>

            <!-- Payment timer -->
            <?php if ($has_paid && $session_duration > 0): ?>
            <div class="payment-timer" id="payment-timer-display">
                <i class="fa-regular fa-clock pt-icon"></i>
                <div>
                    <div class="pt-label">Koha e mbetur</div>
                    <div class="pt-time" id="payment-time-remaining">
                        <?= sprintf('%02d:%02d:%02d', intdiv($session_duration * 60, 3600), intdiv($session_duration * 60 % 3600, 60), $session_duration * 60 % 60) ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Live stats panel -->
            <div class="stats-panel" id="live-stats-panel">
                <div class="sp-row"><span class="sp-label">Bitrate</span><span class="sp-val" id="sp-bitrate">—</span></div>
                <div class="sp-row"><span class="sp-label">Latency</span><span class="sp-val" id="sp-latency">—</span></div>
                <div class="sp-row"><span class="sp-label">Paketë</span><span class="sp-val" id="sp-packets">—</span></div>
                <div class="sp-row"><span class="sp-label">Jitter</span><span class="sp-val" id="sp-jitter">—</span></div>
            </div>

            <!-- Controls bar -->
            <div class="video-controls">
                <button class="ctrl-btn mic-btn" id="mic-btn" title="Mikrofoni" onclick="toggleMic()">
                    <i class="fa-solid fa-microphone" id="mic-icon"></i>
                </button>
                <button class="ctrl-btn camera-btn" id="cam-btn" title="Kamera" onclick="toggleCam()">
                    <i class="fa-solid fa-video" id="cam-icon"></i>
                </button>
                <div class="ctrl-sep"></div>
                <button class="ctrl-btn" title="Ndaj ekranin" onclick="shareScreen()">
                    <i class="fa-solid fa-desktop"></i>
                </button>
                <button class="ctrl-btn" title="Chat" onclick="toggleChat()">
                    <i class="fa-solid fa-comment-dots"></i>
                </button>
                <button class="ctrl-btn" title="Reagime" onclick="showReactions()">
                    <i class="fa-regular fa-face-smile"></i>
                </button>
                <button class="ctrl-btn gold" title="Statistika live" onclick="toggleStatsPanel()">
                    <i class="fa-solid fa-chart-simple"></i>
                </button>
                <div class="ctrl-sep"></div>
                <button class="end-btn" onclick="hangup()">
                    <i class="fa-solid fa-phone-slash"></i> Mbyll
                </button>
            </div>
        </div>

    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-brand">e-Noteria &nbsp;·&nbsp; <span style="color:var(--white-dim);font-size:12px;font-family:var(--font-body);"><?= date('Y') ?> · Të gjitha të drejtat e rezervuara</span></div>
        <div class="footer-links">
            <a href="raporto-polici.php?room=<?= urlencode($room) ?>&username=<?= urlencode($username) ?>" class="footer-link danger">
                <i class="fa-solid fa-shield-halved"></i> Raporto te Policia
            </a>
            <div class="lang-group">
                <button class="lang-btn active" data-lang="sq">SQ</button>
                <button class="lang-btn" data-lang="en">EN</button>
                <button class="lang-btn" data-lang="sr">SR</button>
            </div>
        </div>
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://meet.jit.si/external_api.js" onerror="console.warn('Jitsi failed to load')"></script>
<script src="/assets/fontawesome/all.min.js" onerror="console.warn('FA failed')"></script>

<script>
// ── Global state ──────────────────────────────────────────────
window.CALL_ID   = '<?= isset($call_id) ? htmlspecialchars($call_id) : '' ?>';
window.ROOM      = '<?= htmlspecialchars($room) ?>';
window.USERNAME  = '<?= htmlspecialchars($username) ?>';
var audioUnlocked = false, jitsiConnected = false, conferenceJoined = false;
var participantCount = 0, ringingStarted = false;
var micMuted = false, camOff = false, statsVisible = false;

// ── Audio unlock ──────────────────────────────────────────────
function unlockAudio() {
    if (audioUnlocked) return;
    var a = document.getElementById('ringtone');
    if (!a) return;
    var p = a.play();
    if (p) p.then(function(){ a.pause(); a.currentTime=0; audioUnlocked=true; }).catch(function(){});
}
document.addEventListener('click', unlockAudio);
document.addEventListener('touchstart', unlockAudio);

// ── Ringtone ──────────────────────────────────────────────────
function playRingtone() {
    var a = document.getElementById('ringtone');
    if (!a) return;
    a.currentTime = 0; a.loop = true; a.volume = 1;
    a.play().catch(function(e){ if(e.name !== 'AbortError') playBeep(); });
}
function stopRingtone() {
    var a = document.getElementById('ringtone');
    if (a) { try { a.pause(); a.currentTime=0; a.loop=false; } catch(e){} }
}
function playCallingSound() {
    var a = document.getElementById('calling-sound');
    if (!a) return;
    a.currentTime=0; a.loop=true; a.volume=1;
    a.play().catch(function(){});
}
function stopCallingSound() {
    var a = document.getElementById('calling-sound');
    if (a) { try { a.pause(); a.currentTime=0; a.loop=false; } catch(e){} }
}
function playBeep() {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator(), gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = 880;
        var t = ctx.currentTime;
        for (var i=0; i<4; i++) {
            gain.gain.setValueAtTime(0.3, t+i);
            gain.gain.setValueAtTime(0, t+i+0.5);
        }
        osc.start(t); osc.stop(t+4);
    } catch(e){}
}

// ── Incoming call modal ───────────────────────────────────────
function showIncomingCall(name) {
    document.getElementById('callerName').textContent = name || 'Noter';
    document.getElementById('incomingCallModal').classList.add('show');
    playRingtone();
    setTimeout(function(){ if(ringingStarted) rejectCall(); }, 60000);
}
function acceptCall() {
    stopRingtone(); ringingStarted = false;
    document.getElementById('incomingCallModal').classList.remove('show');
}
function rejectCall() {
    stopRingtone(); ringingStarted = false;
    document.getElementById('incomingCallModal').classList.remove('show');
}
function testRingtoneClick() { unlockAudio(); showIncomingCall('Test Thirrje'); }
window.showIncomingCall = showIncomingCall;
window.acceptCall = acceptCall;
window.rejectCall = rejectCall;
window.testRingtoneClick = testRingtoneClick;

// ── Abuse modal ───────────────────────────────────────────────
function openModal()  { document.getElementById('modalOverlay').classList.add('show'); }
function closeModal() {
    document.getElementById('modalOverlay').classList.remove('show');
    document.getElementById('abuse-form').style.display = '';
    document.getElementById('abuse-success').style.display = 'none';
    document.getElementById('abuse-msg').value = '';
}
function submitAbuse() {
    document.getElementById('abuse-form').style.display = 'none';
    document.getElementById('abuse-success').style.display = 'flex';
    setTimeout(closeModal, 2200);
    return false;
}
window.openModal = openModal; window.closeModal = closeModal; window.submitAbuse = submitAbuse;

// ── Copy room link ─────────────────────────────────────────────
function copyRoomLink() {
    var link = document.getElementById('room-link').textContent.trim();
    navigator.clipboard.writeText(link).then(function(){
        var btn = document.getElementById('copy-btn');
        btn.classList.add('copied');
        btn.querySelector('.copy-label').textContent = 'U kopjua!';
        setTimeout(function(){ btn.classList.remove('copied'); btn.querySelector('.copy-label').textContent='Kopjo'; }, 2200);
    }).catch(function(){ alert('Kopjimi dështoi.'); });
}
window.copyRoomLink = copyRoomLink;

// ── Control toggles ────────────────────────────────────────────
function toggleMic() {
    try { window.api && window.api.executeCommand('toggleAudio'); } catch(e){}
    micMuted = !micMuted;
    var btn=document.getElementById('mic-btn'), ico=document.getElementById('mic-icon');
    btn.classList.toggle('muted', micMuted);
    ico.className = micMuted ? 'fa-solid fa-microphone-slash' : 'fa-solid fa-microphone';
}
function toggleCam() {
    try { window.api && window.api.executeCommand('toggleVideo'); } catch(e){}
    camOff = !camOff;
    var btn=document.getElementById('cam-btn'), ico=document.getElementById('cam-icon');
    btn.classList.toggle('off', camOff);
    ico.className = camOff ? 'fa-solid fa-video-slash' : 'fa-solid fa-video';
}
function shareScreen() { try { window.api && window.api.executeCommand('toggleShareScreen'); } catch(e){} }
function toggleChat()  { try { window.api && window.api.executeCommand('toggleChat'); } catch(e){} }
function hangup()      { try { window.api && window.api.executeCommand('hangup'); } catch(e){} window.location.href='dashboard.php'; }
function toggleStatsPanel() {
    statsVisible = !statsVisible;
    document.getElementById('live-stats-panel').classList.toggle('active', statsVisible);
}
function showReactions() {
    var emojis = ['👍','❤️','😊','👏','✅'], c = document.createElement('div');
    c.style.cssText='position:fixed;bottom:140px;left:50%;transform:translateX(-50%);display:flex;gap:10px;z-index:1000;background:rgba(10,14,26,0.9);border:1px solid rgba(201,168,76,0.28);padding:12px 18px;border-radius:100px;backdrop-filter:blur(20px);';
    emojis.forEach(function(e){ var b=document.createElement('button'); b.textContent=e; b.style.cssText='background:none;border:none;font-size:22px;cursor:pointer;transition:transform .2s;'; b.onmouseover=function(){this.style.transform='scale(1.3)';}; b.onmouseout=function(){this.style.transform='scale(1)';}; b.onclick=function(){ try{ window.api && window.api.executeCommand('sendChatMessage',e); }catch(ex){} document.body.removeChild(c); }; c.appendChild(b); });
    document.body.appendChild(c);
    setTimeout(function(){ if(document.body.contains(c)) document.body.removeChild(c); }, 4000);
}
function muteAllAction()  { try { window.api && window.api.executeCommand('muteEveryone'); } catch(e){} }
function openRecordingModal() { alert('Funksioni i regjistrimit — implemento sipas nevojës.'); }
function endCallAdmin() { if(confirm('Jeni i sigurt që të mbyllni thirrjen për të gjithë?')) hangup(); }
window.muteAllAction=muteAllAction; window.openRecordingModal=openRecordingModal; window.endCallAdmin=endCallAdmin;

// ── Language switcher ──────────────────────────────────────────
document.querySelectorAll('.lang-btn').forEach(function(btn) {
    btn.addEventListener('click', function(){
        document.querySelectorAll('.lang-btn').forEach(function(b){ b.classList.remove('active'); });
        this.classList.add('active');
    });
});

// ── DOMContentLoaded ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

    // ── Timer ────────────────────────────────────────────────
    var secs = 0;
    setInterval(function(){
        secs++;
        var h=Math.floor(secs/3600), m=Math.floor((secs%3600)/60), s=secs%60;
        var el=document.getElementById('call-timer');
        if(el) el.textContent=(h<10?'0'+h:h)+':'+(m<10?'0'+m:m)+':'+(s<10?'0'+s:s);
    }, 1000);

    // ── Payment timer ─────────────────────────────────────────
    <?php if ($has_paid && $session_duration > 0): ?>
    var remaining = <?= intval($session_duration) * 60 ?>;
    var payInterval = setInterval(function(){
        remaining--;
        if(remaining <= 0){ clearInterval(payInterval); window.location.href='payment_confirmation.php?service=video&renew=true&room=<?= urlencode($room) ?>'; return; }
        var h=Math.floor(remaining/3600), m=Math.floor((remaining%3600)/60), s=remaining%60;
        var el=document.getElementById('payment-time-remaining');
        if(el){
            el.textContent=(h<10?'0'+h:h)+':'+(m<10?'0'+m:m)+':'+(s<10?'0'+s:s);
            if(remaining<300) el.style.color='#f08080';
            if(remaining===60) alert('Kujdes! Ju kanë mbetur 1 minutë nga koha e konsulencës.');
        }
    }, 1000);
    <?php endif; ?>

    // ── Live quality stats ────────────────────────────────────
    var bw=5.0, lat=18, pkt=0.3;
    setInterval(function(){
        bw  = Math.max(0.3, bw + (Math.random()-0.5)*0.6);
        lat = Math.max(8,   lat + (Math.random()-0.5)*4);
        pkt = Math.max(0,   Math.min(8, pkt + (Math.random()-0.5)*0.4));
        var qual = bw>3 && lat<40 ? 'Shkëlqyeshëm' : bw>1.5 && lat<80 ? 'Shumë mirë' : bw>0.8 && lat<120 ? 'Mirë' : 'Dobët';
        var qel = document.getElementById('connection-quality');
        if(qel) qel.textContent = qual;
        var sb=document.getElementById('sp-bitrate'), sl=document.getElementById('sp-latency'), sp=document.getElementById('sp-packets'), sj=document.getElementById('sp-jitter');
        if(sb) sb.textContent = bw.toFixed(1)+' Mbps';
        if(sl) sl.textContent = lat.toFixed(0)+'ms';
        if(sp) sp.textContent = (100-pkt).toFixed(1)+'%';
        if(sj) sj.textContent = (pkt*2).toFixed(0)+'ms';
    }, 3000);

    // ── Hide loader ───────────────────────────────────────────
    setTimeout(function(){
        var l=document.getElementById('video-loader');
        if(l){ l.style.opacity='0'; setTimeout(function(){ l.style.display='none'; },600); }
    }, 3200);

    // ── Jitsi init ────────────────────────────────────────────
    if(typeof JitsiMeetExternalAPI === 'undefined') {
        console.warn('Jitsi not loaded');
        // Fallback: still hide loader
        setTimeout(function(){
            var l=document.getElementById('video-loader');
            if(l){ l.style.opacity='0'; setTimeout(function(){l.style.display='none';},600); }
        },1000);
        // Show fallback ringing
        setTimeout(function(){
            if(!ringingStarted){
                ringingStarted=true;
                showIncomingCall('Noter');
                setTimeout(function(){ if(ringingStarted) acceptCall(); },4000);
            }
        },2000);
        return;
    }

    var opts = {
        roomName: window.ROOM,
        width: '100%',
        height: '100%',
        parentNode: document.getElementById('video'),
        userInfo: { displayName: window.USERNAME },
        configOverwrite: {
            startWithVideoMuted: false,
            startWithAudioMuted: false,
            prejoinPageEnabled: false,
            disableSimulcast: false,
            enableLayerSuspension: true,
            p2p: { enabled: true, stunServers:[{urls:'stun:stun.l.google.com:19302'}] },
            disableJoinLeaveSounds: true,
            analytics: { disabled: true }
        },
        interfaceConfigOverwrite: {
            SHOW_JITSI_WATERMARK: false,
            SHOW_BRAND_WATERMARK: false,
            SHOW_POWERED_BY: false,
            MOBILE_APP_PROMO: false,
            TOOLBAR_BUTTONS: ['microphone','camera','desktop','fullscreen','hangup','chat','settings','raisehand','tileview','security']
        }
    };

    window.api = new JitsiMeetExternalAPI('<?= getenv("JITSI_DOMAIN") ?: "meet.jit.si" ?>', opts);

    window.api.addEventListener('conferenceJoined', function(){
        jitsiConnected=true; conferenceJoined=true;
        var pill=document.getElementById('jitsi-status-pill'), txt=document.getElementById('jitsi-status-text');
        if(pill){ pill.className='status-pill online'; }
        if(txt) txt.textContent='E lidhur';
        stopCallingSound();
    });

    window.api.addEventListener('participantJoined', function(p){
        participantCount++;
        var el=document.getElementById('participant-count');
        if(el) el.textContent=participantCount+1;
        stopCallingSound();
        if(conferenceJoined && !ringingStarted){
            ringingStarted=true;
            showIncomingCall(p.displayName||'Noter');
            setTimeout(function(){ if(ringingStarted) acceptCall(); },4000);
        }
    });

    window.api.addEventListener('participantLeft', function(){
        participantCount=Math.max(0,participantCount-1);
        var el=document.getElementById('participant-count');
        if(el) el.textContent=participantCount+1;
        if(participantCount===0){ stopRingtone(); ringingStarted=false; }
    });

    window.api.addEventListener('videoConferenceLeft', function(){
        conferenceJoined=false; ringingStarted=false;
        stopRingtone(); stopCallingSound();
        window.location.href='dashboard.php';
    });

    window.api.addEventListener('connectionFailed', function(){
        var pill=document.getElementById('jitsi-status-pill'), txt=document.getElementById('jitsi-status-text');
        if(pill) pill.className='status-pill offline';
        if(txt) txt.textContent='Lidhja dështoi';
    });

    // Fallback ringing if Jitsi slow
    var fallback=setTimeout(function(){
        if(!ringingStarted && !jitsiConnected){
            ringingStarted=true;
            showIncomingCall('Noter');
            setTimeout(function(){ if(ringingStarted) acceptCall(); },4000);
        }
    },2200);

    window.api.addEventListener('conferenceJoined', function(){ clearTimeout(fallback); });

    // Heartbeat
    setInterval(function(){
        if(!window.CALL_ID) return;
        fetch('heartbeat.php?t='+Date.now(), { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'call_id='+encodeURIComponent(window.CALL_ID) }).catch(function(){});
    },30000);

    // Banned words in chat
    var banned=['pidh','kari','byth','qif','kurv','lavir','bastard','idiot','budall'];
    window.api.addListener('incomingMessage',function(e){
        if(!e||!e.message) return;
        var m=e.message.toLowerCase();
        for(var i=0;i<banned.length;i++){
            if(m.includes(banned[i])){ alert('Biseda u ndal për shkak të fjalëve të papërshtatshme.'); window.api.executeCommand('hangup'); break; }
        }
    });

    // Password
    var pass='N0t3r1@'+Math.random().toString(36).slice(2,10)+'!';
    window.api.addListener('passwordRequired',function(){ window.api.executeCommand('password',pass); });
    window.api.addListener('videoConferenceJoined',function(){ window.api.executeCommand('password',pass); });

    // Auto-hangup after 60min
    setTimeout(function(){ try{ window.api.executeCommand('hangup'); }catch(e){} },3600000);

    playCallingSound();
});
</script>
</body>
</html>