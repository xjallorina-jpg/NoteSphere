<?php

// =========================================================

// NoteSphere - Professor Announcements / Student Class Stream

// Database-compatible version

// =========================================================

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $pdo */

date_default_timezone_set('Asia/Manila');

// =========================================================

// STUDENT SECURITY CHECK

// =========================================================

if (

    !isset($_SESSION['user_id']) ||

    ($_SESSION['role'] ?? '') !== 'student'

) {

    header("Location: ../auth/login.php");

    exit();

}

// =========================================================

// CURRENT STUDENT / PROFESSOR

// =========================================================

$student_id = (int) $_SESSION['user_id'];

$prof_id = isset($_GET['prof_id'])

    ? (int) $_GET['prof_id']

    : 2;

// =========================================================

// HELPER: MARK ANNOUNCEMENT AS READ

// =========================================================

function markAnnouncementAsRead(

    PDO $pdo,

    int $student_id,

    int $ann_id

): void {

    // Check if already marked as read

    $check_exist = $pdo->prepare("

        SELECT 1

        FROM announcement_reads

        WHERE student_id = ?

          AND announcement_id = ?

        LIMIT 1

    ");

    $check_exist->execute([

        $student_id,

        $ann_id

    ]);

    // Insert only if not yet read

    if (!$check_exist->fetch()) {

        $insert_read = $pdo->prepare("

            INSERT INTO announcement_reads

            (

                student_id,

                announcement_id

            )

            VALUES (?, ?)

        ");

        $insert_read->execute([

            $student_id,

            $ann_id

        ]);

    }

    // Announcement History page reads from announcement_history.
    // Keep it in sync without overwriting the original read time.

    try {

        $insert_history = $pdo->prepare("

            INSERT INTO announcement_history

            (

                student_id,

                announcement_id

            )

            VALUES (?, ?)

            ON DUPLICATE KEY UPDATE

                student_id = VALUES(student_id)

        ");

        $insert_history->execute([

            $student_id,

            $ann_id

        ]);

    } catch (Throwable $history_error) {

        // Reading announcements must never fail because of history logging.

        error_log(
            'NoteSphere announcement history error: '
            . $history_error->getMessage()
        );

    }

}

/** Escape untrusted values for HTML output. */
function ns_student_stream_escape($value = null): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Render professor text and make plain HTTP(S) URLs open their original destination. */
function ns_student_stream_render_message($value = null): string
{
    $raw = (string)($value ?? '');
    $parts = preg_split('~(https?://[^\s<>"\']+)~iu', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return nl2br(ns_student_stream_escape($raw), false);

    $html = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 1 && preg_match('~^https?://~i', $part)) {
            $url = rtrim($part, '.,!?;:)]}');
            $ending = substr($part, strlen($url));
            $parsed = parse_url($url);
            $scheme = strtolower((string)($parsed['scheme'] ?? ''));
            if (in_array($scheme, ['http', 'https'], true) && !empty($parsed['host'])) {
                $safeUrl = ns_student_stream_escape($url);
                $html .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">'
                    . ns_student_stream_escape($url) . '</a>' . ns_student_stream_escape($ending);
            } else {
                $html .= ns_student_stream_escape($part);
            }
        } else {
            $html .= ns_student_stream_escape($part);
        }
    }
    return nl2br($html, false);
}

/** Get the old single attachment and the newer multiple attachment records. */
function ns_student_stream_get_attachments(PDO $pdo, int $announcementId, $legacyPath = null): array
{
    $items = [];
    $legacy = trim((string)($legacyPath ?? ''));
    if ($legacy !== '') {
        $items[] = ['file_path' => $legacy, 'original_name' => basename(str_replace('\\', '/', $legacy))];
    }

    try {
        $stmt = $pdo->prepare('SELECT file_path, original_name FROM announcement_attachments WHERE announcement_id = ? ORDER BY id ASC');
        $stmt->execute([$announcementId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $path = trim((string)($row['file_path'] ?? ''));
            if ($path === '') continue;
            $alreadyAdded = false;
            foreach ($items as $existing) {
                if ((string)($existing['file_path'] ?? '') === $path) { $alreadyAdded = true; break; }
            }
            if (!$alreadyAdded) {
                $row['original_name'] = $row['original_name'] ?? basename(str_replace('\\', '/', $path));
                $items[] = $row;
            }
        }
    } catch (Throwable $e) {
        // Legacy installations without announcement_attachments remain supported.
    }
    return $items;
}

/** Resolve stored project-relative paths while permitting safe HTTP(S) URLs. */
function ns_student_stream_attachment_url(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if (preg_match('~^https?://~i', $path)) {
        $parsed = parse_url($path);
        if (in_array(strtolower((string)($parsed['scheme'] ?? '')), ['http', 'https'], true) && !empty($parsed['host'])) return $path;
        return '#';
    }
    $path = preg_replace('~^(?:\./|(?:\.\./))+~', '', $path);
    return '../' . ltrim((string)$path, '/');
}

function ns_student_stream_video_mime(string $extension): ?string
{
    $types = ['mp4'=>'video/mp4', 'm4v'=>'video/mp4', 'webm'=>'video/webm', 'ogv'=>'video/ogg', 'ogg'=>'video/ogg', 'mov'=>'video/quicktime'];
    return $types[strtolower($extension)] ?? null;
}


// =========================================================
// FULL-PAGE ATTACHMENT PREVIEW
// =========================================================
if (isset($_GET['preview_attachment'])) {
    $previewAnnouncementId = (int)($_GET['preview_attachment'] ?? 0);
    $previewFileIndex = max(0, (int)($_GET['preview_file'] ?? 0));

    try {
        $previewAnnouncementStmt = $pdo->prepare("
            SELECT id, professor_id, title, attachment, status
            FROM announcements
            WHERE id = ? AND professor_id = ?
              AND (status = 'active' OR status IS NULL)
            LIMIT 1
        ");
        $previewAnnouncementStmt->execute([$previewAnnouncementId, $prof_id]);
        $previewAnnouncement = $previewAnnouncementStmt->fetch();

        if (!$previewAnnouncement) {
            http_response_code(404);
            exit('This announcement or its attachment is not available.');
        }

        $previewAttachments = ns_student_stream_get_attachments(
            $pdo,
            $previewAnnouncementId,
            $previewAnnouncement['attachment'] ?? null
        );
        if (!isset($previewAttachments[$previewFileIndex])) {
            http_response_code(404);
            exit('The requested attachment could not be found.');
        }

        $previewAttachment = $previewAttachments[$previewFileIndex];
        $previewStoredPath = trim((string)($previewAttachment['file_path'] ?? ''));
        $previewUrl = ns_student_stream_attachment_url($previewStoredPath);
        $previewName = trim((string)($previewAttachment['original_name'] ?? basename(str_replace('\\', '/', $previewStoredPath))));
        if ($previewName === '') $previewName = 'Attachment';
        $previewUrlPath = parse_url($previewStoredPath, PHP_URL_PATH);
        $previewExtension = strtolower(pathinfo((string)($previewUrlPath ?: $previewStoredPath), PATHINFO_EXTENSION));
        $previewVideoMime = ns_student_stream_video_mime($previewExtension);
        $previewIsRemote = (bool)preg_match('~^https?://~i', $previewStoredPath);
        $previewIsImage = in_array($previewExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'svg'], true);
        $previewIsAudio = in_array($previewExtension, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], true);
        $previewIsPdf = $previewExtension === 'pdf';
        $previewIsText = in_array($previewExtension, ['txt', 'log', 'md', 'json', 'xml', 'csv'], true);
        $previewIsDocx = $previewExtension === 'docx';
        $previewIsSheet = in_array($previewExtension, ['xlsx', 'xls', 'csv'], true);
        $previewIsPptx = $previewExtension === 'pptx';
        $previewCanRender = $previewIsImage || $previewIsAudio || (bool)$previewVideoMime || $previewIsPdf || $previewIsText || $previewIsDocx || $previewIsSheet || $previewIsPptx;
        $previewTitle = (string)($previewAnnouncement['title'] ?? 'Announcement');
        // Closing the viewer returns to this exact announcement card.
        $previewBackUrl = 'professor_announcements.php?prof_id=' . $prof_id . '#announcement-' . $previewAnnouncementId;
        $escapedPreviewUrl = ns_student_stream_escape($previewUrl);
        $escapedPreviewName = ns_student_stream_escape($previewName);
        $escapedPreviewTitle = ns_student_stream_escape($previewTitle);
        $escapedPreviewBackUrl = ns_student_stream_escape($previewBackUrl);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= $escapedPreviewName ?> | NoteSphere Preview</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <style>
                :root{--bg:#080808;--surface:#232923;--text:#f4f7f4;--muted:#9aa59b;--border:#2a332b;--accent:#39ff14;--accent-text:#071007}
                body.light-mode{--bg:#ffffff;--surface:#ffffff;--text:#000000;--muted:#334155;--border:#e2e8f0;--accent:#087f3e;--accent-text:#fff}
                *{box-sizing:border-box}
                html,body{margin:0;min-height:100%;font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text)}
                body{min-height:100vh}
                .preview-topbar{min-height:58px;padding:9px 16px;display:flex;align-items:center;gap:11px;border-bottom:1px solid var(--border);background:var(--surface)}
                .preview-close{width:34px;height:34px;flex:0 0 34px;border:1px solid var(--border);border-radius:7px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:var(--text);font-size:23px;line-height:1;background:transparent}
                .preview-close:hover{border-color:#dc2626;color:#dc2626}
                .preview-heading{min-width:0;flex:1}
                .preview-filename{font-size:.9rem;font-weight:700;white-space:normal;overflow-wrap:anywhere}
                .preview-subtitle{margin-top:2px;color:var(--muted);font-size:.72rem;overflow-wrap:anywhere}
                .preview-page{width:100%;max-width:1240px;margin:0 auto;padding:14px 16px 24px}
                .preview-stage{width:100%;min-height:60vh;border:1px solid var(--border);border-radius:6px;background:var(--surface);display:flex;align-items:center;justify-content:center;overflow:hidden}
                .preview-pdf{display:block;width:100%;height:calc(100vh - 185px);min-height:480px;border:0;background:#fff}
                .preview-image{display:block;max-width:100%;max-height:calc(100vh - 190px);width:auto;height:auto;object-fit:contain;margin:auto}
                .preview-video-wrap{width:100%;padding:10px;display:flex;justify-content:center;background:#080808}
                .preview-video{display:block;width:100%;max-height:calc(100vh - 200px);background:#000}
                .preview-audio{width:min(760px,calc(100% - 32px));margin:40px auto}
                .preview-text{width:100%;max-height:calc(100vh - 190px);overflow:auto;margin:0;padding:18px;white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace}
                .office-preview{width:100%;min-height:60vh;max-height:none;padding:16px;overflow:auto;background:var(--surface);color:var(--text)}
                .office-preview img{max-width:100%;height:auto}
                .office-preview table{border-collapse:collapse;width:100%;font-size:13px;background:var(--surface)}
                .office-preview td,.office-preview th{border:1px solid var(--border);padding:6px;text-align:left}
                .office-preview tr:nth-child(even){background:rgba(127,127,127,.07)}
                .spreadsheet-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
                .spreadsheet-tab{padding:7px 11px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);cursor:pointer}
                .spreadsheet-tab.active{border-color:var(--accent);color:var(--accent)}
                .pptx-slide-stack{display:grid;gap:16px}
                .pptx-slide-frame{padding:8px;border:1px solid var(--border);border-radius:6px}
                .pptx-slide-frame canvas{display:block;width:100%;height:auto;background:#fff}
                .pptx-slide-caption{font-size:.75rem;color:var(--muted);margin-bottom:8px}
                .preview-status{padding:26px 16px;text-align:center;color:var(--muted);font-size:.88rem;line-height:1.6}
                .preview-status a{color:var(--accent)}
                .preview-actions{display:flex;flex-direction:column;align-items:flex-start;gap:9px;padding:12px 0 0}
                .preview-help{font-size:.76rem;color:var(--muted);line-height:1.5}
                .download-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:37px;padding:8px 13px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap}
                .download-btn:hover{border-color:var(--accent);color:var(--accent)}
                @media(max-width:650px){.preview-topbar{padding:8px 10px;gap:8px}.preview-page{padding:9px 8px 18px}.preview-stage{min-height:52vh}.preview-pdf{height:calc(100vh - 150px);min-height:400px}.preview-image{max-height:calc(100vh - 155px)}.preview-video-wrap{padding:4px}.preview-text{padding:11px;font-size:12px}.office-preview{padding:9px}.preview-actions{align-items:flex-start}.download-btn{width:auto;max-width:100%}}
            </style>
        </head>
        <body>
            <header class="preview-topbar">
                <a class="preview-close" href="<?= $escapedPreviewBackUrl ?>" title="Close preview and return to announcement" aria-label="Close preview and return to announcement">×</a>
                <div class="preview-heading">
                    <div class="preview-filename"><?= $escapedPreviewName ?></div>
                    <div class="preview-subtitle"><?= $escapedPreviewTitle ?><?= $previewExtension !== '' ? ' · ' . ns_student_stream_escape(strtoupper($previewExtension)) : '' ?></div>
                </div>
            </header>
            <main class="preview-page">
                <section class="preview-stage" aria-label="File preview">
                    <?php if ($previewIsPdf): ?>
                        <iframe class="preview-pdf" src="<?= $escapedPreviewUrl ?>#toolbar=1&navpanes=0&view=FitH" title="<?= $escapedPreviewName ?>"></iframe>
                    <?php elseif ($previewIsImage): ?>
                        <img class="preview-image" src="<?= $escapedPreviewUrl ?>" alt="<?= $escapedPreviewName ?>">
                    <?php elseif ($previewVideoMime): ?>
                        <div class="preview-video-wrap"><video class="preview-video" controls playsinline preload="metadata"><source src="<?= $escapedPreviewUrl ?>" type="<?= ns_student_stream_escape($previewVideoMime) ?>">Your browser cannot preview this video format.</video></div>
                    <?php elseif ($previewIsAudio): ?>
                        <audio class="preview-audio" controls preload="metadata" src="<?= $escapedPreviewUrl ?>">Your browser cannot preview this audio format.</audio>
                    <?php elseif ($previewIsText): ?>
                        <pre class="preview-text" id="textPreview" data-url="<?= $escapedPreviewUrl ?>">Loading preview…</pre>
                    <?php elseif ($previewIsDocx): ?>
                        <div class="office-preview" id="officePreview" data-kind="docx" data-url="<?= $escapedPreviewUrl ?>"><div class="preview-status">Loading Word document…</div></div>
                    <?php elseif ($previewIsSheet): ?>
                        <div class="office-preview" id="officePreview" data-kind="sheet" data-url="<?= $escapedPreviewUrl ?>"><div class="preview-status">Loading spreadsheet…</div></div>
                    <?php elseif ($previewIsPptx): ?>
                        <div class="office-preview" id="officePreview" data-kind="pptx" data-url="<?= $escapedPreviewUrl ?>" data-name="<?= $escapedPreviewName ?>"><div class="preview-status">Loading presentation…</div></div>
                    <?php else: ?>
                        <div class="preview-status">
                            <strong>Preview isn't available for this file type.</strong><br>
                            You can download the file below to open it in a compatible application.
                            <?php if ($previewIsRemote): ?><p><a href="<?= $escapedPreviewUrl ?>" target="_blank" rel="noopener noreferrer">Open original link</a></p><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <div class="preview-actions">
                    <div class="preview-help">You are viewing the file. Download it only if you need a copy.</div>
                    <?php if (!$previewIsRemote && $previewUrl !== '#'): ?>
                        <a class="download-btn" href="<?= $escapedPreviewUrl ?>" download="<?= $escapedPreviewName ?>"><span aria-hidden="true">↓</span> Download</a>
                    <?php elseif ($previewIsRemote): ?>
                        <a class="download-btn" href="<?= $escapedPreviewUrl ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">↗</span> Open original link</a>
                    <?php endif; ?>
                </div>
            </main>
            <script>
            (function(){
                const textTarget=document.getElementById('textPreview');
                if(textTarget){fetch(textTarget.dataset.url,{credentials:'same-origin'}).then(r=>{if(!r.ok)throw new Error();return r.text()}).then(t=>{textTarget.textContent=t.length>1000000?t.slice(0,1000000)+'\n\n[Preview truncated.]':t}).catch(()=>{textTarget.textContent='Could not load preview. Please use Download.';});}
                const target=document.getElementById('officePreview');if(!target)return;
                function loadScript(src,globalName){return new Promise((resolve,reject)=>{if(window[globalName])return resolve(window[globalName]);const script=document.createElement('script');script.src=src;script.async=true;script.onload=()=>window[globalName]?resolve(window[globalName]):reject(new Error('Library unavailable'));script.onerror=reject;document.head.appendChild(script);});}
                async function run(){
                    try{
                        if(target.dataset.kind==='docx'){
                            const mammoth=await loadScript('https://cdn.jsdelivr.net/npm/mammoth@1.13.0/mammoth.browser.min.js','mammoth');
                            const DOMPurify=await loadScript('https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js','DOMPurify');
                            const res=await fetch(target.dataset.url,{credentials:'same-origin'});if(!res.ok)throw new Error();const result=await mammoth.convertToHtml({arrayBuffer:await res.arrayBuffer()});target.innerHTML=DOMPurify.sanitize(result.value,{USE_PROFILES:{html:true}});target.querySelectorAll('a').forEach(a=>{a.target='_blank';a.rel='noopener noreferrer'});
                        }else if(target.dataset.kind==='sheet'){
                            const XLSX=await loadScript('https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js','XLSX');
                            const DOMPurify=await loadScript('https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js','DOMPurify');
                            const res=await fetch(target.dataset.url,{credentials:'same-origin'});if(!res.ok)throw new Error();const wb=XLSX.read(await res.arrayBuffer(),{type:'array'});target.innerHTML='';const tabs=document.createElement('div');tabs.className='spreadsheet-tabs';const out=document.createElement('div');
                            wb.SheetNames.forEach((name,i)=>{const b=document.createElement('button');b.type='button';b.className='spreadsheet-tab'+(i===0?' active':'');b.textContent=name;b.onclick=()=>{tabs.querySelectorAll('button').forEach(x=>x.classList.toggle('active',x===b));out.innerHTML=DOMPurify.sanitize(XLSX.utils.sheet_to_html(wb.Sheets[name]),{USE_PROFILES:{html:true}})};tabs.appendChild(b)});target.append(tabs,out);tabs.querySelector('button')?.click();
                        }else if(target.dataset.kind==='pptx'){
                            const {PptxRenderer}=await import('https://cdn.jsdelivr.net/npm/pptx-browser@4.1.5/+esm');
                            const res=await fetch(target.dataset.url,{credentials:'same-origin'});if(!res.ok)throw new Error();const bytes=await res.arrayBuffer();const file=new File([bytes],target.dataset.name||'presentation.pptx',{type:'application/vnd.openxmlformats-officedocument.presentationml.presentation'});const renderer=new PptxRenderer();await renderer.load(file);target.innerHTML='';const stack=document.createElement('div');stack.className='pptx-slide-stack';target.appendChild(stack);
                            for(let i=0;i<renderer.slideCount;i++){const frame=document.createElement('div');frame.className='pptx-slide-frame';const caption=document.createElement('div');caption.className='pptx-slide-caption';caption.textContent='Slide '+(i+1)+' of '+renderer.slideCount;const canvas=document.createElement('canvas');frame.append(caption,canvas);stack.appendChild(frame);await renderer.renderSlide(i,canvas,1440);}
                        }
                    }catch(e){target.innerHTML='<div class="preview-status">Could not load this preview. Check your internet connection or download the file below.</div>';}
                }
                run();
            })();
            (function(){const theme=localStorage.getItem('notesphere_theme')||localStorage.getItem('theme')||'dark';document.body.classList.toggle('light-mode',String(theme).toLowerCase()==='light');window.addEventListener('storage',e=>{if((e.key==='notesphere_theme'||e.key==='theme')&&e.newValue)document.body.classList.toggle('light-mode',String(e.newValue).toLowerCase()==='light');});})();
            </script>
</body>
        </html>
        <?php
        exit();
    } catch (Throwable $previewError) {
        error_log('NoteSphere attachment preview: ' . $previewError->getMessage());
        http_response_code(500);
        exit('Unable to open this attachment preview. Please return to announcements and try again.');
    }
}

// =========================================================

// HANDLE TASK SUBMISSION

// =========================================================

if (

    $_SERVER['REQUEST_METHOD'] === 'POST' &&

    isset($_POST['submit_task'])

) {

    $ann_id = isset($_POST['announcement_id'])

        ? (int) $_POST['announcement_id']

        : 0;

    // The database column is submission_text

    $student_note = trim(

        $_POST['student_note'] ?? ''

    );

    if ($ann_id <= 0) {

        header(

            "Location: professor_announcements.php?prof_id=" .

            $prof_id

        );

        exit();

    }

    // =====================================================

    // CHECK DEADLINE

    // =====================================================

    $stmt_check_dl = $pdo->prepare("

        SELECT deadline

        FROM announcements

        WHERE id = ?

        LIMIT 1

    ");

    $stmt_check_dl->execute([

        $ann_id

    ]);

    $dl_data = $stmt_check_dl->fetch();

    if (

        $dl_data &&

        !empty($dl_data['deadline'])

    ) {

        $deadline_timestamp = strtotime(

            $dl_data['deadline']

        );

        if (

            $deadline_timestamp !== false &&

            time() > $deadline_timestamp

        ) {

            header(

                "Location: professor_announcements.php?prof_id=" .

                $prof_id .

                "&error=deadline_passed"

            );

            exit();

        }

    }

    // =====================================================

    // FILE UPLOAD

    // =====================================================

    $file_path = null;

    // Files may arrive directly from Cloudinary (Vercel) or as a normal PHP
    // upload (local XAMPP).
    $cloudSubmission = ns_cloud_upload_from_request('submission_file');
    $localSubmission = $_FILES['submission_file'] ?? null;
    $hasLocalSubmission = is_array($localSubmission)
        && (int) ($localSubmission['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if ($cloudSubmission !== null || $hasLocalSubmission) {

        $allowed_extensions = [
            'pdf', 'docx', 'doc', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt'
        ];

        $original_name = $cloudSubmission !== null
            ? (string) $cloudSubmission['name']
            : (string) ($localSubmission['name'] ?? '');

        $file_extension = strtolower(
            pathinfo($original_name, PATHINFO_EXTENSION)
        );

        if (in_array($file_extension, $allowed_extensions, true)) {

            if ($cloudSubmission !== null) {

                $file_path = $cloudSubmission['path'];

            } else {

                $upload_dir = __DIR__ . '/../uploads/submissions/';

                if (!is_dir($upload_dir)) {

                    mkdir($upload_dir, 0777, true);

                }

                $file_name =
                    'sub_' .
                    $ann_id .
                    '_' .
                    $student_id .
                    '_' .
                    time() .
                    '.' .
                    $file_extension;

                $target_file = $upload_dir . $file_name;

                if (
                    move_uploaded_file(
                        (string) ($localSubmission['tmp_name'] ?? ''),
                        $target_file
                    )
                ) {

                    // Store a project-relative URL in the database, not the XAMPP absolute disk path.
                    $file_path = 'uploads/submissions/' . $file_name;

                }
            }
        }
    }

    // =====================================================

    // CHECK EXISTING SUBMISSION

    // =====================================================

    $check_stmt = $pdo->prepare("

        SELECT

            id,

            file_path,

            submission_text

        FROM announcement_submissions

        WHERE announcement_id = ?

          AND student_id = ?

        LIMIT 1

    ");

    $check_stmt->execute([

        $ann_id,

        $student_id

    ]);

    $existing = $check_stmt->fetch();

    // =====================================================

    // UPDATE EXISTING SUBMISSION

    // =====================================================

    if ($existing) {

        // Keep old file if no new file was uploaded

        if ($file_path === null) {

            $file_path =

                $existing['file_path'];

        }

        $update_stmt = $pdo->prepare("

            UPDATE announcement_submissions

            SET

                submission_text = ?,

                file_path = ?,

                submitted_at = NOW()

            WHERE id = ?

        ");

        $update_stmt->execute([

            $student_note,

            $file_path,

            $existing['id']

        ]);

    }

    // =====================================================

    // INSERT NEW SUBMISSION

    // =====================================================

    else {

        $insert_stmt = $pdo->prepare("

            INSERT INTO announcement_submissions

            (

                announcement_id,

                student_id,

                submission_text,

                file_path,

                submitted_at

            )

            VALUES (?, ?, ?, ?, NOW())

        ");

        $insert_stmt->execute([

            $ann_id,

            $student_id,

            $student_note,

            $file_path

        ]);

    }

    // =====================================================

    // MARK ANNOUNCEMENT AS READ AFTER SUBMISSION

    // =====================================================

    markAnnouncementAsRead(

        $pdo,

        $student_id,

        $ann_id

    );

    // =====================================================

    // REDIRECT AFTER SUBMISSION

    // =====================================================

    header(

        "Location: professor_announcements.php?prof_id=" .

        $prof_id .

        "&submitted=1"

    );

    exit();

}

// =========================================================

// HANDLE MARK AS READ

// =========================================================

if (isset($_GET['mark_done'])) {

    $ann_id = (int) $_GET['mark_done'];

    if ($ann_id > 0) {

        markAnnouncementAsRead(

            $pdo,

            $student_id,

            $ann_id

        );

    }

    header(

        "Location: professor_announcements.php?prof_id=" .

        $prof_id

    );

    exit();

}

// =========================================================

// FETCH PROFESSOR NAME

// =========================================================

// IMPORTANT:

// users table uses user_id, NOT id.

// =========================================================

$stmt_prof = $pdo->prepare("

    SELECT full_name

    FROM users

    WHERE user_id = ?

    LIMIT 1

");

$stmt_prof->execute([

    $prof_id

]);

$prof_data = $stmt_prof->fetch(

    PDO::FETCH_ASSOC

);

$prof_name = $prof_data['full_name']

    ?? 'Professor';

// =========================================================

// FETCH ACTIVE SEMESTER / ACADEMIC YEAR

// =========================================================

$stmt_settings = $pdo->query("

    SELECT *

    FROM system_settings

    LIMIT 1

");

$current_settings = $stmt_settings->fetch();

$current_settings = $current_settings ?: [];

$active_semester =

    $current_settings['current_semester']

    ?? $current_settings['setting_value']

    ?? '1st Semester';

$active_year =

    $current_settings['current_academic_year']

    ?? '2026-2027';

// =========================================================

// FETCH UNREAD ANNOUNCEMENTS

// =========================================================

//

// announcement_submissions columns:

//

// id

// announcement_id

// student_id

// submission_text

// file_path

// grade

// feedback

// submitted_at

//

// announcement_reads handles the Read state.

// =========================================================

$stmt_ann = $pdo->prepare("

    SELECT

        a.*,

        s.submission_text,

        s.file_path,

        s.grade,

        s.feedback,

        s.submitted_at AS sub_date,

        CASE

            WHEN s.id IS NOT NULL THEN 1

            ELSE 0

        END AS has_submission

    FROM announcements a

    LEFT JOIN announcement_submissions s

        ON a.id = s.announcement_id

        AND s.student_id = ?

    WHERE a.professor_id = ?

      AND NOT EXISTS (

          SELECT 1

          FROM announcement_reads ar

          WHERE ar.announcement_id = a.id

            AND ar.student_id = ?

      )

      AND (

          a.status = 'active'

          OR a.status IS NULL

      )

    ORDER BY a.created_at DESC

");

$stmt_ann->execute([

    $student_id,

    $prof_id,

    $student_id

]);

$announcements = $stmt_ann->fetchAll();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta

        name="viewport"

        content="width=device-width, initial-scale=1.0"

    >

    <title>

        Prof. <?= ns_student_stream_escape($prof_name) ?> <a href="professor_announcement_history.php" style="margin-left:10px;font-size:12px;">History</a>

        Stream | NoteSphere

    </title>

    <link

        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"

        rel="stylesheet"

    >

    <style>

        /* =====================================================

           THEME VARIABLES

        ===================================================== */

        :root {

            --neon: #39FF14;

            --neon-glow: rgba(57, 255, 20, 0.12);

            --bg: #080808;

            --card-bg: #0c0c0c;

            --text: #f8fafc;

            --sub-text: #94a3b8;

            --border: #1f293d;

        }

        body.light-mode {

            --card-bg: #ffffff;

            --text: #0f172a;

            --sub-text: #64748b;

            --border: #e2e8f0;

            --neon: #087f3e;

            --neon-glow: rgba(8, 127, 62, 0.15);
            --bg: #ffffff;

        }

        /* =====================================================

           RESET

        ===================================================== */

        * {

            margin: 0;

            padding: 0;

            box-sizing: border-box;

            font-family:

                'Plus Jakarta Sans',

                sans-serif;

        }

        /* =====================================================

           BODY

        ===================================================== */

        body {

            background: var(--bg);

            color: var(--text);

            display: flex;

            justify-content: center;

            min-height: 100vh;

            overflow-x: hidden;

            transition:

                background 0.3s ease,

                color 0.3s ease;

        }

        body::before {

            content: '';

            position: fixed;

            top: 0;

            left: 0;

            width: 100vw;

            height: 100vh;

            z-index: -1;

            background:

                linear-gradient(

                    rgba(11, 11, 11, 0.85),

                    rgba(11, 11, 11, 0.92)

                ),

                none

                no-repeat

                center

                center;

            background-size: cover;

            pointer-events: none;

        }

        body.light-mode::before {

            

            background-size: cover;

        }

        /* =====================================================

           MAIN CONTAINER

        ===================================================== */

        .main-container {

            width: 100%;

            max-width: 900px;

            padding: 40px 20px;

        }

        /* =====================================================

           TOP ACTION BAR

        ===================================================== */

        .top-action-bar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 20px;

            flex-wrap: wrap;

            gap: 10px;

        }

        .header-actions {

            display: flex;

            gap: 10px;

        }

        /* =====================================================

           ICON BUTTON

        ===================================================== */

        .icon-btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            width: 40px;

            height: 40px;

            background: var(--card-bg);

            border:

                1px solid

                var(--border);

            color: var(--text);

            border-radius: 12px;

            font-size: 1rem;

            text-decoration: none;

            transition: 0.3s;

        }

        .icon-btn:hover {

            border-color: var(--neon);

            color: var(--neon);

            background: var(--neon-glow);

            box-shadow:

                0 0 10px

                var(--neon-glow);

        }

        /* =====================================================

           CLOSE BUTTON

        ===================================================== */

        .close-btn:hover {

            border-color: #ff4d4d !important;

            color: #ff4d4d !important;

            background:

                rgba(

                    255,

                    77,

                    77,

                    0.08

                ) !important;

            box-shadow: none !important;

        }

        /* =====================================================

           CLASS BANNER

        ===================================================== */

        .class-banner {

            background: var(--card-bg);

            border:

                1px solid

                var(--border);

            border-radius: 16px;

            padding: 30px;

            color: var(--text);

            margin-bottom: 30px;

            position: relative;

            box-shadow:

                0 4px 20px

                rgba(0, 0, 0, 0.05);

        }

        .class-banner h1 {

            font-size: 1.8rem;

            font-weight: 800;

            color: var(--neon);

            margin-bottom: 6px;

        }

        .class-banner p {

            color: var(--sub-text);

            font-size: 0.9rem;

        }

        .class-banner .sem-tag {

            position: absolute;

            top: 20px;

            right: 20px;

            background: var(--neon-glow);

            color: var(--neon);

            border:

                1px solid

                var(--neon);

            padding: 6px 14px;

            border-radius: 20px;

            font-size: 0.8rem;

            font-weight: 700;

        }

        /* =====================================================

           ANNOUNCEMENT CARD

        ===================================================== */

        .g-card {

            background: var(--card-bg);

            border:

                1px solid

                var(--border);

            border-radius: 16px;

            padding: 24px;

            margin-bottom: 20px;

            transition:

                border-color 0.3s ease;

        }

        .g-card:hover {

            border-color: var(--neon);

        }

        .g-card-header {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            margin-bottom: 16px;

            flex-wrap: wrap;

            gap: 10px;

        }

        .g-icon-circle {

            width: 44px;

            height: 44px;

            border-radius: 50%;

            background: var(--neon-glow);

            color: var(--neon);

            border:

                1px solid

                var(--neon);

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 800;

            flex-shrink: 0;

        }

        .ann-content {

            font-size: 0.95rem;

            line-height: 1.7;

            margin-bottom: 16px;

            white-space: pre-wrap;

            color: var(--text);

            word-break: break-word;

        }

        .ann-content a {
            color: var(--neon);
            text-decoration: underline;
            overflow-wrap: anywhere;
            font-weight: 700;
        }
        .announcement-resources {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin: 10px 0 18px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(127, 127, 127, 0.035);
        }
        .resource-section-title {
            width: 100%;
            color: var(--sub-text);
            font-size: .78rem;
            font-weight: 800;
        }
        .resource-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            width: 100%;
            min-width: 0;
        }
        .resource-pill {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            max-width: 100%;
            overflow-wrap: anywhere;
        }
        .resource-name { font-weight: 500; opacity: .9; overflow-wrap: anywhere; }
        .attachment-video {
            display: block;
            width: min(100%, 900px);
            max-height: 520px;
            background: #000;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .attachment-audio {
            display: block;
            width: min(100%, 720px);
        }
        .attachment-preview {
            width: 100%;
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(127, 127, 127, .035);
            padding: 10px;
        }
        .attachment-pdf-preview {
            display: block;
            width: 100%;
            height: min(76vh, 760px);
            min-height: 420px;
            border: 0;
            border-radius: 8px;
            background: #fff;
        }
        .attachment-image-preview {
            display: block;
            max-width: 100%;
            max-height: 72vh;
            width: auto;
            height: auto;
            margin: 0 auto;
            object-fit: contain;
            border-radius: 8px;
        }
        .attachment-text-preview {
            max-height: 520px;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            padding: 14px;
            color: var(--text);
            font: .88rem/1.6 Consolas, monospace;
        }
        .office-preview-content {
            max-height: 680px;
            overflow: auto;
            background: #fff;
            color: #172033;
            padding: 18px;
            border-radius: 8px;
        }
        .office-preview-content img { max-width: 100%; height: auto; }
        .office-preview-content table {
            border-collapse: collapse;
            width: 100%;
            font-size: .84rem;
        }
        .office-preview-content td, .office-preview-content th {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            vertical-align: top;
        }
        .office-preview-content tr:nth-child(even) { background: #f8fafc; }
        .spreadsheet-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .spreadsheet-tab {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #1e293b;
            border-radius: 8px;
            padding: 7px 10px;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 700;
        }
        .spreadsheet-tab.active { background: #dcfce7; border-color: #16a34a; }
        .pptx-slide-stack { display: grid; gap: 14px; }
        .pptx-slide-frame { padding: 8px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 10px; }
        .pptx-slide-frame canvas { display: block; width: 100%; height: auto; background: #fff; }
        .pptx-slide-caption { color: #334155; font-size: .78rem; font-weight: 800; padding: 4px 2px 8px; }
        .preview-note { color: var(--sub-text); font-size: .82rem; line-height: 1.55; padding: 14px; }
        .resource-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .download-btn { border-color: var(--neon) !important; color: var(--neon) !important; }
        .download-btn:hover { background: var(--neon-glow) !important; }
        .preview-status { padding: 10px; font-size: .82rem; color: #64748b; }

        /* =====================================================

           BUTTONS

        ===================================================== */

        .outline-btn {

            background: transparent;

            border:

                1px solid

                var(--border);

            color: var(--text);

            padding: 8px 14px;

            border-radius: 8px;

            font-weight: 700;

            cursor: pointer;

            text-decoration: none;

            display: inline-block;

            transition: 0.3s;

            font-size: 0.85rem;

        }

        .outline-btn:hover {

            border-color: var(--neon);

            color: var(--neon);

            background: var(--neon-glow);

        }

        .g-btn {

            background: var(--neon);

            color: #050505;

            border: none;

            padding: 10px 20px;

            border-radius: 8px;

            font-weight: 800;

            cursor: pointer;

            transition: 0.3s;

            font-size: 0.9rem;

        }

        .g-btn:hover {

            opacity: 0.85;

        }

        /* =====================================================

           SUBMISSION FORM

        ===================================================== */

        .submission-collapsible {

            display: none;

            margin-top: 15px;

            border-top:

                1px solid

                var(--border);

            padding-top: 15px;

        }

        .submission-collapsible.active {

            display: block;

        }

        .sub-form input,

        .sub-form textarea {

            width: 100%;

            background: var(--bg);

            border:

                1px solid

                var(--border);

            color: var(--text);

            padding: 10px;

            border-radius: 8px;

            margin-top: 5px;

            outline: none;

            font-size: 0.9rem;

        }

        .sub-form textarea {

            min-height: 100px;

            resize: vertical;

        }

        .sub-form input:focus,

        .sub-form textarea:focus {

            border-color: var(--neon);

        }

        /* =====================================================

           STATUS BADGES

        ===================================================== */

        .status-badge {

            font-size: 0.75rem;

            padding: 4px 10px;

            border-radius: 6px;

            font-weight: 700;

            border:

                1px solid

                var(--neon);

            color: var(--neon);

            background:

                var(--neon-glow);

        }

        /* =====================================================

           ALERTS

        ===================================================== */

        .alert-box {

            margin-bottom: 20px;

            padding: 12px 16px;

            border-radius: 10px;

            background:

                rgba(239, 68, 68, 0.10);

            border:

                1px solid

                rgba(239, 68, 68, 0.35);

            color: #ef4444;

            font-size: 0.85rem;

            font-weight: 700;

        }

        .success-box {

            margin-bottom: 20px;

            padding: 12px 16px;

            border-radius: 10px;

            background:

                var(--neon-glow);

            border:

                1px solid

                var(--neon);

            color: var(--neon);

            font-size: 0.85rem;

            font-weight: 700;

        }

        /* =====================================================

           MOBILE

        ===================================================== */

        @media (max-width: 600px) {

            .main-container {

                padding: 20px 12px;

            }

            .class-banner {

                padding: 20px 16px;

            }

            .class-banner h1 {

                font-size: 1.3rem;

            }

            .class-banner .sem-tag {

                position: static;

                display: inline-block;

                margin-top: 10px;

            }

            .top-action-bar {

                flex-direction: row;

                justify-content: space-between;

                align-items: center;

            }

            .g-card {

                padding: 16px;

            }

            .g-card-header {

                flex-direction: column;

                align-items: flex-start;

            }

        }

        /* =====================================================
           POLISHED, COMPACT CLASS STREAM LAYOUT
           Existing features and theme behavior are preserved.
        ===================================================== */
        .main-container {
            width: min(100%, 1080px);
            max-width: 1080px;
            padding: 30px 24px 48px;
        }
        .top-action-bar { margin-bottom: 16px; }
        .icon-btn {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
        }
        .class-banner {
            padding: 26px 30px;
            margin-bottom: 22px;
            border-radius: 20px;
            background: linear-gradient(125deg, var(--card-bg), rgba(57,255,20,.055));
            box-shadow: 0 14px 34px rgba(0,0,0,.09);
        }
        .class-banner h1 { font-size: clamp(1.4rem, 2.8vw, 1.9rem); letter-spacing: -.035em; }
        .class-banner p { font-size: .9rem; line-height: 1.6; }
        .class-banner .sem-tag { max-width: 46%; text-align: center; line-height: 1.4; }
        .feed-list { display: grid; gap: 16px; }
        .g-card {
            padding: 23px 25px;
            margin-bottom: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(0,0,0,.075);
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }
        .g-card:hover { box-shadow: 0 12px 28px rgba(0,0,0,.11); }
        .g-card-header {
            align-items: center;
            padding-bottom: 16px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        .g-icon-circle { width: 42px; height: 42px; font-size: 1rem; }
        .g-card-header h3 { line-height: 1.45; overflow-wrap: anywhere; }
        .status-badge { flex: 0 0 auto; border-radius: 999px; padding: 6px 11px; }
        .ann-content {
            font-size: .94rem;
            line-height: 1.75;
            margin-bottom: 16px;
            overflow-wrap: anywhere;
        }
        .announcement-resources {
            border-radius: 15px;
            padding: 14px;
            margin: 14px 0 18px;
            gap: 10px;
        }
        .attachments-resources {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
            align-items: start;
            gap: 10px;
        }
        .attachments-resources .resource-section-title {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 5px 12px;
            margin-bottom: 2px;
        }
        .resource-section-subtitle { font-size: .73rem; font-weight: 500; }
        .resource-item {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            gap: 10px;
            width: auto;
            min-width: 0;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--card-bg);
            box-shadow: 0 3px 12px rgba(0,0,0,.035);
        }
        .resource-file-head { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .resource-file-icon {
            display: grid;
            place-items: center;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            color: var(--neon);
            background: var(--neon-glow);
            border: 1px solid var(--border);
            font-size: 1rem;
        }
        .resource-file-meta { min-width: 0; flex: 1; }
        .resource-name { font-size: .83rem; font-weight: 800; line-height: 1.45; overflow-wrap: anywhere; opacity: 1; }
        .resource-file-subtitle { margin-top: 3px; color: var(--sub-text); font-size: .7rem; line-height: 1.45; }
        .resource-dot { padding: 0 4px; }
        .preview-disclosure { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: rgba(127,127,127,.025); }
        .preview-disclosure summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 11px;
            font-size: .78rem;
            font-weight: 800;
            color: var(--text);
            user-select: none;
            transition: background .2s ease, color .2s ease;
        }
        .preview-disclosure summary::-webkit-details-marker { display: none; }
        .preview-disclosure summary:hover { background: var(--neon-glow); color: var(--neon); }
        .preview-summary-main { display: inline-flex; align-items: center; gap: 7px; }
        .preview-eye { color: var(--neon); font-size: 1rem; }
        .preview-hint { color: var(--sub-text); font-size: .69rem; font-weight: 600; white-space: nowrap; }
        .preview-disclosure[open] .preview-hint span { display: inline-block; transform: rotate(180deg); }
        .preview-panel { padding: 0 9px 9px; }
        .attachment-preview { padding: 7px; border-radius: 10px; }
        .attachment-pdf-preview { height: min(62vh, 540px); min-height: 300px; }
        .attachment-image-preview { max-height: min(58vh, 440px); }
        .attachment-video { width: 100%; max-height: 380px; }
        .office-preview-content { max-height: 460px; overflow: auto; padding: 12px; }
        .attachment-text-preview { max-height: 360px; }
        .resource-actions { gap: 7px; width: 100%; }
        .resource-actions .resource-pill { flex: 1 1 132px; min-height: 38px; padding: 9px 12px; border-radius: 10px; font-size: .76rem; justify-content: center; text-align: center; }
        .resource-actions .download-btn { width: auto; justify-content: center; }
        .resource-actions .preview-btn { border-color: var(--border); }
        .resource-actions .preview-btn:hover { border-color: var(--neon); }
        .deadline-row { padding-top: 13px !important; margin-top: 14px !important; }
        .g-card > div[style*="border-top:1px solid var(--border)"] { margin-top: 15px !important; padding-top: 14px !important; }
        .submission-collapsible { margin-top: 14px; padding-top: 17px; }
        .sub-form label { color: var(--sub-text); }
        .sub-form input[type="file"] { max-width: 100%; }
        .sub-form textarea { min-height: 92px; line-height: 1.55; }
        .g-btn { border-radius: 10px; padding: 11px 18px; }
        @media (max-width: 700px) {
            .main-container { padding: 20px 13px 34px; }
            .class-banner { padding: 20px 18px; border-radius: 16px; }
            .class-banner .sem-tag { position: static; display: inline-block; max-width: 100%; margin-top: 12px; }
            .g-card { padding: 16px 14px; border-radius: 15px; }
            .g-card-header { align-items: flex-start; gap: 10px; }
            .g-card-header > div { min-width: 0; }
            .g-card-header h3 { font-size: .98rem !important; }
            .status-badge { font-size: .68rem; padding: 5px 9px; }
            .announcement-resources { padding: 10px; }
            .attachments-resources { grid-template-columns: minmax(0, 1fr); }
            .resource-item { padding: 10px; }
            .attachment-pdf-preview { min-height: 270px; height: 58vh; }
            .attachment-video { max-height: 260px; }
            .attachment-image-preview { max-height: 48vh; }
            .preview-disclosure summary { padding: 10px; }
            .deadline-row { align-items: flex-start !important; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
        }

            /* iOS: keep form fields at 16px so Safari does not zoom on focus. */
        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }
</style>

</head>

<body>

<div class="main-container">

    <!-- =====================================================

         TOP ACTION BAR

    ====================================================== -->

    <div class="top-action-bar">

        <a

            href="student_home.php"

            class="icon-btn close-btn"

            title="Back to Dashboard"

        >

            ✕

        </a>

        <div class="header-actions">

            <a

                href="submission_history.php?prof_id=<?= $prof_id ?>"

                class="icon-btn"

                title="History & Grades"

            >

                📊

            </a>

            <a

                href="messages.php?prof_id=<?= $prof_id ?>"

                class="icon-btn"

                title="Consultation Chat"

            >

                💬

            </a>

        </div>

    </div>

    <!-- =====================================================

         CLASS BANNER

    ====================================================== -->

    <div class="class-banner">

        <div class="sem-tag">

            <?= htmlspecialchars(

                $active_semester .

                ' | ' .

                $active_year

            ) ?>

        </div>

        <h1>

            Prof.

            <?= ns_student_stream_escape($prof_name) ?>

        </h1>

        <p>

            Class Stream & Coursework Updates

        </p>

    </div>

    <!-- =====================================================

         ALERTS

    ====================================================== -->

    <?php if (

        isset($_GET['error']) &&

        $_GET['error'] === 'deadline_passed'

    ): ?>

        <div class="alert-box">

            This task can no longer be submitted

            because the deadline has passed.

        </div>

    <?php endif; ?>

    <?php if (

        isset($_GET['submitted'])

    ): ?>

        <div class="success-box">

            Your work has been submitted successfully.

        </div>

    <?php endif; ?>

    <!-- =====================================================

         ANNOUNCEMENTS

    ====================================================== -->

    <?php if (empty($announcements)): ?>

        <div

            style="

                text-align: center;

                padding: 40px;

                background: var(--card-bg);

                border: 1px solid var(--border);

                border-radius: 16px;

            "

        >

            <p

                style="

                    font-weight: 700;

                    color: var(--text);

                "

            >

                No new active announcements.

            </p>

            <p

                style="

                    margin-top: 8px;

                    font-size: 0.85rem;

                    color: var(--sub-text);

                "

            >

                Read items and submissions can be

                viewed in your History & Grades.

            </p>

        </div>

    <?php else: ?>

        <div class="feed-list">

            <?php foreach (

                $announcements as $ann

            ): ?>

                <?php

                $has_deadline =

                    !empty($ann['deadline']);

                $is_expired =

                    $has_deadline &&

                    strtotime(

                        $ann['deadline']

                    ) < time();

                $has_submission =

                    !empty(

                        $ann['has_submission']

                    );

                ?>

                <div class="g-card" id="announcement-<?= (int)($ann['id'] ?? 0) ?>">

                    <!-- =================================================

                         CARD HEADER

                    ================================================== -->

                    <div class="g-card-header">

                        <div

                            style="

                                display: flex;

                                gap: 14px;

                                align-items: center;

                            "

                        >

                            <div class="g-icon-circle">

                                <?= strtoupper(

                                    substr(

                                        $prof_name,

                                        0,

                                        1

                                    )

                                ) ?>

                            </div>

                            <div>

                                <h3

                                    style="

                                        font-size: 1rem;

                                        color: var(--text);

                                    "

                                >

                                    <?= ns_student_stream_escape($ann['title'] ?? '') ?>

                                </h3>

                                <span

                                    style="

                                        font-size: 0.8rem;

                                        color: var(--sub-text);

                                    "

                                >

                                    <?= date(

                                        'M d, Y - h:i A',

                                        strtotime(

                                            $ann['created_at']

                                        )

                                    ) ?>

                                </span>

                            </div>

                        </div>

                        <!-- STATUS -->

                        <span class="status-badge">

                            <?php if (

                                $has_submission

                            ): ?>

                                Submitted

                            <?php else: ?>

                                New

                            <?php endif; ?>

                        </span>

                    </div>

                    <!-- =================================================

                         ANNOUNCEMENT MESSAGE

                    ================================================== -->

                    <div class="ann-content">
                        <?= ns_student_stream_render_message($ann['message'] ?? '') ?>
                    </div>

                    <!-- Preview opens a dedicated full-page viewer; cards stay compact. -->
                    <?php
                        $studentStreamAttachments = ns_student_stream_get_attachments(
                            $pdo,
                            (int)($ann['id'] ?? 0),
                            $ann['attachment'] ?? null
                        );
                    ?>
                    <?php if (!empty($studentStreamAttachments)): ?>
                        <?php
                            $imageAttachments = [];
                            $otherAttachments = [];
                            foreach ($studentStreamAttachments as $ai => $sa) {
                                $sp = trim((string)($sa['file_path'] ?? ''));
                                $ext = strtolower(pathinfo((string)(parse_url($sp, PHP_URL_PATH) ?: $sp), PATHINFO_EXTENSION));
                                if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','avif','svg'], true)) $imageAttachments[$ai] = $sa;
                                else $otherAttachments[$ai] = $sa;
                            }
                        ?>
                        <div class="announcement-resources attachments-resources">
                            <div class="resource-section-title"><span>📎 Learning materials</span><span class="resource-section-subtitle">View the file first. Download only when needed.</span></div>
                            <?php if (!empty($imageAttachments)): ?>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:4px;width:100%;margin:6px 0;">
                                    <?php foreach ($imageAttachments as $attachmentIndex => $studentStreamAttachment): ?>
                                        <?php
                                            $storedAttachmentPath = trim((string)($studentStreamAttachment['file_path'] ?? ''));
                                            $attachmentUrl = ns_student_stream_attachment_url($storedAttachmentPath);
                                            $attachmentName = trim((string)($studentStreamAttachment['original_name'] ?? basename(str_replace('\\', '/', $storedAttachmentPath))));
                                            if ($attachmentName === '') $attachmentName = 'Image';
                                            $previewPageUrl = 'professor_announcements.php?prof_id=' . (int)$prof_id . '&preview_attachment=' . (int)$ann['id'] . '&preview_file=' . (int)$attachmentIndex;
                                        ?>
                                        <a href="<?= ns_student_stream_escape($previewPageUrl) ?>" style="display:block;">
                                            <img src="<?= ns_student_stream_escape($attachmentUrl) ?>" alt="<?= ns_student_stream_escape($attachmentName) ?>" style="width:100%;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border);">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($otherAttachments as $attachmentIndex => $studentStreamAttachment): ?>
                                <?php
                                    $storedAttachmentPath = trim((string)($studentStreamAttachment['file_path'] ?? ''));
                                    $attachmentUrl = ns_student_stream_attachment_url($storedAttachmentPath);
                                    $attachmentName = trim((string)($studentStreamAttachment['original_name'] ?? basename(str_replace('\\', '/', $storedAttachmentPath))));
                                    if ($attachmentName === '') $attachmentName = 'Attachment';
                                    $attachmentPathForExt = parse_url($storedAttachmentPath, PHP_URL_PATH);
                                    $attachmentExtension = strtolower(pathinfo((string)($attachmentPathForExt ?: $storedAttachmentPath), PATHINFO_EXTENSION));
                                    $attachmentCanPreview = in_array($attachmentExtension, ['pdf','jpg','jpeg','png','gif','webp','bmp','avif','svg','mp4','m4v','webm','ogv','ogg','mov','mp3','wav','m4a','aac','flac','txt','log','md','json','xml','csv','docx','xlsx','xls','pptx'], true);
                                    $previewPageUrl = 'professor_announcements.php?prof_id=' . (int)$prof_id
                                        . '&preview_attachment=' . (int)$ann['id']
                                        . '&preview_file=' . (int)$attachmentIndex;
                                ?>
                                <div class="resource-item">
                                    <div class="resource-file-head">
                                        <div class="resource-file-icon" aria-hidden="true">📄</div>
                                        <div class="resource-file-meta">
                                            <div class="resource-name"><?= ns_student_stream_escape($attachmentName) ?></div>
                                            <div class="resource-file-subtitle">
                                                <?= $attachmentExtension !== '' ? ns_student_stream_escape(strtoupper($attachmentExtension)) . ' file' : 'Attachment' ?>
                                                <span class="resource-dot">•</span>
                                                <?= $attachmentCanPreview ? 'Preview available' : 'Preview not supported' ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="resource-actions">
                                        <a class="outline-btn resource-pill preview-btn" href="<?= ns_student_stream_escape($previewPageUrl) ?>">
                                            <span aria-hidden="true">◉</span> Preview
                                        </a>
                                        <?php if (!preg_match('~^https?://~i', $storedAttachmentPath) && $attachmentUrl !== '#'): ?>
                                            <a class="outline-btn resource-pill download-btn" href="<?= ns_student_stream_escape($attachmentUrl) ?>" download="<?= ns_student_stream_escape($attachmentName) ?>">
                                                <span aria-hidden="true">⬇</span> Download
                                            </a>
                                        <?php else: ?>
                                            <a class="outline-btn resource-pill" href="<?= ns_student_stream_escape($attachmentUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                <span aria-hidden="true">↗</span> Open Original
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                        $professorLinkFields = [
                            'meet_link' => '📹 Join Google Meet',
                            'quiz_link' => '📝 Open Google Form / Quiz',
                            'youtube_link' => '▶️ Open Professor Video Link',
                            'video_link' => '▶️ Open Video Link'
                        ];
                        $availableProfessorLinks = [];
                        foreach ($professorLinkFields as $linkField => $linkLabel) {
                            $linkValue = trim((string)($ann[$linkField] ?? ''));
                            if ($linkValue === '') continue;
                            $parsedLink = parse_url($linkValue);
                            if (in_array(strtolower((string)($parsedLink['scheme'] ?? '')), ['http', 'https'], true) && !empty($parsedLink['host'])) {
                                $availableProfessorLinks[] = ['url' => $linkValue, 'label' => $linkLabel];
                            }
                        }
                    ?>
                    <?php if (!empty($availableProfessorLinks)): ?>
                        <div class="announcement-resources">
                            <div class="resource-section-title">🔗 Professor’s links</div>
                            <?php foreach ($availableProfessorLinks as $professorLink): ?>
                                <a class="outline-btn resource-pill"
                                   href="<?= ns_student_stream_escape($professorLink['url']) ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <?= ns_student_stream_escape($professorLink['label']) ?> ↗
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Deadline display, when one exists. -->
                    <?php if ($has_deadline): ?>
                        <div class="deadline-row" style="border-top:1px solid var(--border);padding-top:14px;margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <span style="font-size:.85rem;color:<?= $is_expired ? '#ef4444' : '#ff5555' ?>;font-weight:700;">
                                ⏳ Due: <?= ns_student_stream_escape(date('M d, Y - h:i A', strtotime($ann['deadline']))) ?>
                                <?= $is_expired ? ' — Deadline passed' : '' ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div style="border-top:1px solid var(--border);padding-top:12px;margin-top:12px;display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap;">
                        <a href="professor_announcements.php?prof_id=<?= (int)$prof_id ?>&amp;mark_done=<?= (int)$ann['id'] ?>"
                           class="outline-btn" style="color:var(--neon);border-color:var(--neon);">
                            Mark as Read
                        </a>
                        <?php if (!$is_expired && !$has_submission): ?>
                            <button type="button" class="outline-btn"
                                    onclick="toggleAddWork(<?= (int)$ann['id'] ?>)"
                                    aria-controls="workBox-<?= (int)$ann['id'] ?>">
                                Add Work
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!$is_expired && !$has_submission): ?>
                        <div class="submission-collapsible" id="workBox-<?= (int)$ann['id'] ?>">
                            <form method="POST" enctype="multipart/form-data" class="sub-form">
                                <input type="hidden" name="announcement_id" value="<?= (int)$ann['id'] ?>">
                                <label style="font-size:.8rem;font-weight:700;display:block;">Upload File (optional):</label>
                                <input type="file" name="submission_file" accept=".pdf,.doc,.docx,.zip,.rar,.jpg,.jpeg,.png,.txt">
                                <label style="font-size:.8rem;font-weight:700;margin-top:10px;display:block;">Private Comment:</label>
                                <textarea name="student_note" placeholder="Write your comment or submission details..."></textarea>
                                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px;flex-wrap:wrap;">
                                    <button type="button" class="outline-btn" onclick="toggleAddWork(<?= (int)$ann['id'] ?>)">Cancel</button>
                                    <button type="submit" name="submit_task" value="1" class="g-btn">Turn In Work</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<div id="annPreviewModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
  <div style="width:90%;height:90%;background:#fff;border-radius:8px;overflow:hidden;position:relative;">
    <button onclick="closeAnnPreview()" style="position:absolute;top:8px;right:8px;z-index:2;padding:6px 10px;">Close</button>
    <iframe id="annPreviewFrame" src="" style="width:100%;height:100%;border:0;"></iframe>
  </div>
</div>
<script>
function openAnnPreview(url){var m=document.getElementById('annPreviewModal');document.getElementById('annPreviewFrame').src=url;m.style.display='flex';}
function closeAnnPreview(){var m=document.getElementById('annPreviewModal');document.getElementById('annPreviewFrame').src='';m.style.display='none';}
</script>
<!-- =========================================================

     JAVASCRIPT

========================================================= -->

<script>
    // =========================================================
    // INLINE FILE PREVIEWS
    // PDF, images, video, audio and text use the browser's native preview.
    // DOCX, spreadsheets and PPTX are processed client-side; their content is
    // fetched from the same NoteSphere server and is not uploaded to a viewer.
    // =========================================================
    function nsLoadExternalScript(src, globalName) {
        return new Promise((resolve, reject) => {
            if (window[globalName]) return resolve(window[globalName]);
            const existing = document.querySelector('script[data-ns-src="' + src + '"]');
            if (existing) {
                existing.addEventListener('load', () => resolve(window[globalName]), { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.nsSrc = src;
            script.onload = () => window[globalName] ? resolve(window[globalName]) : reject(new Error('Preview library did not load.'));
            script.onerror = () => reject(new Error('Could not load preview library.'));
            document.head.appendChild(script);
        });
    }

    function nsPreviewError(target, message) {
        if (!target) return;
        target.innerHTML = '';
        const note = document.createElement('div');
        note.className = 'preview-status';
        note.textContent = message + ' The original file is still available using the Download option below.';
        target.appendChild(note);
    }

    async function nsLoadTextPreviews() {
        for (const target of document.querySelectorAll('details.preview-disclosure[open] [data-text-preview]:not([data-preview-loaded])')) {
            target.dataset.previewLoaded = '1';
            try {
                const response = await fetch(target.dataset.textPreview, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('File could not be opened.');
                const content = await response.text();
                const pre = document.createElement('pre');
                pre.className = 'attachment-text-preview';
                pre.textContent = content.length > 250000 ? content.slice(0, 250000) + '\n\n[Preview truncated. Download the file to see the complete content.]' : content;
                target.replaceWith(pre);
            } catch (error) {
                nsPreviewError(target, 'Text preview is unavailable.');
            }
        }
    }

    async function nsLoadDocxPreviews() {
        const targets = Array.from(document.querySelectorAll('details.preview-disclosure[open] [data-office-preview="docx"]:not([data-preview-loaded])'));
        targets.forEach(target => target.dataset.previewLoaded = '1');
        if (!targets.length) return;
        try {
            const mammoth = await nsLoadExternalScript('https://cdn.jsdelivr.net/npm/mammoth@1.13.0/mammoth.browser.min.js', 'mammoth');
            const purifier = await nsLoadExternalScript('https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js', 'DOMPurify');
            for (const target of targets) {
                try {
                    const response = await fetch(target.dataset.previewUrl, { credentials: 'same-origin' });
                    if (!response.ok) throw new Error('Could not fetch Word file.');
                    const arrayBuffer = await response.arrayBuffer();
                    const result = await mammoth.convertToHtml({ arrayBuffer });
                    target.innerHTML = purifier.sanitize(result.value, { USE_PROFILES: { html: true } });
                    target.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
                } catch (error) {
                    nsPreviewError(target, 'Word preview could not be generated.');
                }
            }
        } catch (error) {
            targets.forEach(target => nsPreviewError(target, 'Word preview library could not be loaded.'));
        }
    }

    async function nsLoadSpreadsheetPreviews() {
        const targets = Array.from(document.querySelectorAll('details.preview-disclosure[open] [data-office-preview="sheet"]:not([data-preview-loaded])'));
        targets.forEach(target => target.dataset.previewLoaded = '1');
        if (!targets.length) return;
        try {
            const XLSX = await nsLoadExternalScript('https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js', 'XLSX');
            const purifier = await nsLoadExternalScript('https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js', 'DOMPurify');
            for (const target of targets) {
                try {
                    const response = await fetch(target.dataset.previewUrl, { credentials: 'same-origin' });
                    if (!response.ok) throw new Error('Could not fetch spreadsheet.');
                    const bytes = await response.arrayBuffer();
                    const workbook = XLSX.read(bytes, { type: 'array' });
                    if (!workbook.SheetNames.length) throw new Error('Spreadsheet is empty.');
                    const tabs = document.createElement('div');
                    tabs.className = 'spreadsheet-tabs';
                    const output = document.createElement('div');
                    workbook.SheetNames.forEach((sheetName, index) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'spreadsheet-tab' + (index === 0 ? ' active' : '');
                        btn.textContent = sheetName;
                        btn.addEventListener('click', () => {
                            tabs.querySelectorAll('button').forEach(b => b.classList.toggle('active', b === btn));
                            let sheetHtml = XLSX.utils.sheet_to_html(workbook.Sheets[sheetName]);
                            output.innerHTML = purifier.sanitize(sheetHtml, { USE_PROFILES: { html: true } });
                        });
                        tabs.appendChild(btn);
                    });
                    target.innerHTML = '';
                    target.appendChild(tabs);
                    target.appendChild(output);
                    tabs.querySelector('button')?.click();
                } catch (error) {
                    nsPreviewError(target, 'Spreadsheet preview could not be generated.');
                }
            }
        } catch (error) {
            targets.forEach(target => nsPreviewError(target, 'Spreadsheet preview library could not be loaded.'));
        }
    }

    async function nsLoadPptxPreviews() {
        const targets = Array.from(document.querySelectorAll('details.preview-disclosure[open] [data-office-preview="pptx"]:not([data-preview-loaded])'));
        targets.forEach(target => target.dataset.previewLoaded = '1');
        if (!targets.length) return;
        let PptxRenderer;
        try {
            ({ PptxRenderer } = await import('https://cdn.jsdelivr.net/npm/pptx-browser@4.1.5/+esm'));
        } catch (error) {
            targets.forEach(target => nsPreviewError(target, 'PowerPoint preview library could not be loaded.'));
            return;
        }
        for (const target of targets) {
            try {
                const response = await fetch(target.dataset.previewUrl, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('Could not fetch presentation.');
                const bytes = await response.arrayBuffer();
                const file = new File([bytes], target.dataset.previewName || 'presentation.pptx', {
                    type: 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                });
                const renderer = new PptxRenderer();
                const status = target.querySelector('.preview-status');
                if (status) status.textContent = 'Rendering PowerPoint slides…';
                await renderer.load(file);
                target.innerHTML = '';
                const stack = document.createElement('div');
                stack.className = 'pptx-slide-stack';
                target.appendChild(stack);
                for (let index = 0; index < renderer.slideCount; index++) {
                    const frame = document.createElement('div');
                    frame.className = 'pptx-slide-frame';
                    const caption = document.createElement('div');
                    caption.className = 'pptx-slide-caption';
                    caption.textContent = 'Slide ' + (index + 1) + ' of ' + renderer.slideCount;
                    const canvas = document.createElement('canvas');
                    canvas.setAttribute('aria-label', caption.textContent);
                    frame.appendChild(caption);
                    frame.appendChild(canvas);
                    stack.appendChild(frame);
                    await renderer.renderSlide(index, canvas, 1280);
                }
            } catch (error) {
                nsPreviewError(target, 'PowerPoint preview could not be generated.');
            }
        }
    }

    function nsActivateNativePreview(details) {
        details.querySelectorAll('[data-preview-src]').forEach(element => {
            if (!element.getAttribute('src')) {
                element.setAttribute('src', element.dataset.previewSrc);
            }
        });
        details.querySelectorAll('video').forEach(video => video.load());
        details.querySelectorAll('audio').forEach(audio => audio.load());
    }

    function nsLoadOpenPreview(details) {
        if (!details || !details.open) return;
        nsActivateNativePreview(details);
        nsLoadTextPreviews();
        nsLoadDocxPreviews();
        nsLoadSpreadsheetPreviews();
        nsLoadPptxPreviews();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('details.preview-disclosure').forEach(details => {
            details.addEventListener('toggle', () => {
                if (details.open) nsLoadOpenPreview(details);
            });
        });
    });

    // =========================================================
    // THEME

    // =========================================================

    function applyTheme(theme) {

        if (theme === 'light') {

            document.body.classList.add(

                'light-mode'

            );

        } else {

            document.body.classList.remove(

                'light-mode'

            );

        }

    }

    // =========================================================

    // LOAD SAVED THEME

    // =========================================================

    document.addEventListener(

        "DOMContentLoaded",

        () => {

            let savedTheme =

                localStorage.getItem(

                    'notesphere_theme'

                ) ||

                localStorage.getItem(

                    'theme'

                );

            if (!savedTheme) {

                if (

                    window.matchMedia &&

                    window.matchMedia(

                        '(prefers-color-scheme: light)'

                    ).matches

                ) {

                    savedTheme = 'light';

                } else {

                    savedTheme = 'dark';

                }

            }

            applyTheme(savedTheme);

        }

    );

    // =========================================================

    // LISTEN FOR THEME CHANGES

    // =========================================================

    window.addEventListener(

        'storage',

        (event) => {

            if (

                event.key ===

                    'notesphere_theme' ||

                event.key === 'theme'

            ) {

                if (event.newValue) {

                    applyTheme(

                        event.newValue

                    );

                }

            }

        }

    );

    // =========================================================

    // TOGGLE ADD WORK

    // =========================================================

    function toggleAddWork(annId) {

        const box =

            document.getElementById(

                'workBox-' + annId

            );

        if (box) {

            box.classList.toggle(

                'active'

            );

        }

    }

</script>
<script src="../assets/cloudinary_upload.js"></script>
</body>

</html>

