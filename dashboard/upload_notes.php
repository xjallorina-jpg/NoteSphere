<?php
session_start();
require_once __DIR__ . '/../includes/db_config.php';

/* NoteSphere - Student Upload / Create Note
   Upgrade: camera capture + multi-image attachments in one note.
   Existing theme, storage quota, institute routing, CSRF, and pending review are retained.
*/
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php'); exit;
}
$userId = (int)$_SESSION['user_id'];
if (empty($_SESSION['notesphere_upload_csrf'])) $_SESSION['notesphere_upload_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['notesphere_upload_csrf'];
$projectRoot = dirname(__DIR__);
$uploadDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notes';
$uploadRelativeDirectory = 'uploads/notes';
$allowedExtensions = ['pdf','doc','docx','txt','rtf','ppt','pptx','xls','xlsx','csv','jpg','jpeg','png','gif','webp','zip','rar','7z'];
$imageExtensions = ['jpg','jpeg','png','gif','webp'];
$error = ''; $success = ''; $activeType = 'upload';
$title = ''; $description = ''; $institute = strtoupper(trim($_GET['institute'] ?? '')); $content = '';
$storageLimitBytes = 0; $usedStorageBytes = 0; $remainingStorageBytes = 0; $storageLoaded = false;

function getInstituteBackUrl(): string {
    $fallback = 'student_home.php'; $allowed = ['ICS','IBE','ITE']; $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') return $fallback;
    $parts = parse_url($referer); if (!is_array($parts)) return $fallback;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $refHost = strtolower((string)($parts['host'] ?? $host));
    if ($host !== '' && $refHost !== $host) return $fallback;
    if (basename(str_replace('\\','/',(string)($parts['path'] ?? ''))) !== 'view_notes.php') return $fallback;
    $query = []; parse_str((string)($parts['query'] ?? ''), $query);
    $origin = strtoupper(trim((string)($query['institute'] ?? '')));
    return in_array($origin, $allowed, true) ? 'view_notes.php?institute=' . rawurlencode($origin) : $fallback;
}
$backUrl = getInstituteBackUrl();
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatBytes($bytes): string {
    $bytes = max(0,(float)$bytes);
    if ($bytes >= 1099511627776) return number_format($bytes/1099511627776,2).' TB';
    if ($bytes >= 1073741824) return number_format($bytes/1073741824,2).' GB';
    if ($bytes >= 1048576) return number_format($bytes/1048576,2).' MB';
    if ($bytes >= 1024) return number_format($bytes/1024,2).' KB';
    return number_format($bytes,0).' bytes';
}
function resolveNoteFile(string $path, string $root): ?string {
    $path = trim(str_replace('\\','/',$path));
    if ($path === '' || strpos($path,'://') !== false) return null;
    $path = ltrim($path,'/'); if (strpos($path,'../') !== false) return null;
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$path);
    $realRoot = realpath($root); $realFile = realpath($full);
    if ($realRoot === false || $realFile === false || !is_file($realFile)) return null;
    $prefix = rtrim($realRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    return strpos($realFile,$prefix) === 0 ? $realFile : null;
}
function getStudentUsedStorage(PDO $conn, int $userId, string $root): int {
    $unique = []; $total = 0;
    $stmt = $conn->prepare('SELECT file_path, thumbnail FROM notes WHERE user_id = ?');
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) foreach (['file_path','thumbnail'] as $col) {
        $path = (string)($row[$col] ?? '');
        // Cloudinary assets are not on this filesystem; their size is tracked
        // through note_attachments below when available.
        if (ns_is_remote_file($path)) continue;
        $full = resolveNoteFile($path,$root);
        if ($full === null || isset($unique[$full])) continue;
        $unique[$full] = true; clearstatcache(true,$full); $size = filesize($full);
        if ($size !== false) $total += (int)$size;
    }
    // The migration creates this table. If it is missing, surface a clear setup error rather than silently excluding attachment storage.
    $stmt = $conn->prepare('SELECT file_path, file_size FROM note_attachments WHERE user_id = ?');
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $path = (string)($row['file_path'] ?? '');
        if (ns_is_remote_file($path)) { $total += max(0,(int)($row['file_size'] ?? 0)); continue; }
        $full = resolveNoteFile($path,$root);
        if ($full === null || isset($unique[$full])) continue;
        $unique[$full] = true; clearstatcache(true,$full); $size = filesize($full);
        if ($size !== false) $total += (int)$size;
    }
    return $total;
}
function refreshStudentStorage(PDO $conn, int $userId, string $root): array {
    $row = $conn->query('SELECT student_storage_gb FROM system_storage WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row || !isset($row['student_storage_gb'])) throw new RuntimeException('Student storage settings were not found. Please contact the administrator.');
    $gb = (float)$row['student_storage_gb'];
    if (!is_finite($gb) || $gb < 0) throw new RuntimeException('The configured student storage limit is invalid.');
    $limit = (int)round($gb * 1024 * 1024 * 1024); $used = getStudentUsedStorage($conn,$userId,$root);
    return ['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used)];
}
function ensureStorageAvailable(int $size, int $remaining): void {
    if ($size < 0) throw new RuntimeException('Unable to determine the file size.');
    if ($size > $remaining) throw new RuntimeException('Not enough storage space. Your remaining storage is '.formatBytes($remaining).'. Please remove some files or contact the administrator.');
}
function uploadErrorMessage(int $code): string {
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) return 'A selected file exceeds the configured PHP upload limit. This is a server setting, not your NoteSphere storage quota.';
    if ($code === UPLOAD_ERR_PARTIAL) return 'A file was only partially uploaded. Please try again.';
    if ($code === UPLOAD_ERR_NO_TMP_DIR) return 'The server temporary upload directory is missing.';
    if ($code === UPLOAD_ERR_CANT_WRITE) return 'The server could not write an uploaded file.';
    return 'The upload failed. Please check your files and try again.';
}
function normalizeMultiFiles(array $files): array {
    $out = []; if (!isset($files['name']) || !is_array($files['name'])) return $out;
    $count = count($files['name']);
    for ($i=0; $i<$count; $i++) {
        $out[] = ['name'=>$files['name'][$i] ?? '', 'type'=>$files['type'][$i] ?? '', 'tmp_name'=>$files['tmp_name'][$i] ?? '', 'error'=>(int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size'=>(int)($files['size'][$i] ?? 0)];
    }
    return $out;
}
try {
    $s = refreshStudentStorage($conn,$userId,$projectRoot);
    $storageLimitBytes=$s['limit']; $usedStorageBytes=$s['used']; $remainingStorageBytes=$s['remaining']; $storageLoaded=true;
} catch (Throwable $e) {
    error_log('NoteSphere upload storage error: '.$e->getMessage());
    $error = (strpos(strtolower($e->getMessage()),'note_attachments') !== false) ? 'Please import the NoteSphere multi-image database migration before uploading notes.' : 'Unable to load your storage information. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activeType=(string)($_POST['note_type'] ?? 'upload'); $title=trim((string)($_POST['title'] ?? ''));
    $description=trim((string)($_POST['description'] ?? '')); $institute=trim((string)($_POST['institute'] ?? ''));
    $content=trim((string)($_POST['content'] ?? '')); $postedToken=(string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['notesphere_upload_csrf'] ?? ''),$postedToken)) $error='Your session verification failed. Please refresh the page and try again.';
    elseif (!$storageLoaded) $error='Storage information is unavailable. Please import the multi-image migration if it has not been installed, then try again.';
    elseif (!in_array($activeType,['upload','created'],true)) $error='Please select a valid note creation method.';
    elseif ($title === '' || mb_strlen($title)>255) $error='Please enter a title with no more than 255 characters.';
    elseif (!in_array($institute,['ICS','IBE','ITE'],true)) $error='Please select a valid institute.';
    elseif (mb_strlen($description)>10000) $error='Your description is too long.';
    else {
        $savedPaths=[]; $savedAbsolutePaths=[]; $attachmentPaths=[]; $transactionStarted=false;
        try {
            $s=refreshStudentStorage($conn,$userId,$projectRoot); $storageLimitBytes=$s['limit']; $usedStorageBytes=$s['used']; $remainingStorageBytes=$s['remaining'];
            if (!ns_cloudinary_enabled()) {
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory,0755,true) && !is_dir($uploadDirectory)) throw new RuntimeException('Unable to create the notes upload directory.');
                if (!is_writable($uploadDirectory)) throw new RuntimeException('The notes upload directory is not writable.');
            }
            $noteContent=null; $mainPath=null; $totalIncomingBytes=0; $uploadedItems=[];
            if ($activeType === 'created') {
                if ($content==='') throw new RuntimeException('Please write something in your note before saving.');
                $noteContent=$content;
                $plainText=$title.PHP_EOL.str_repeat('=',min(60,max(1,mb_strlen($title)))).PHP_EOL.PHP_EOL.$content;
                $totalIncomingBytes=strlen($plainText); ensureStorageAvailable($totalIncomingBytes,$remainingStorageBytes);
                $name='created_'.$userId.'_'.bin2hex(random_bytes(12)).'.txt';
                $mainPath=ns_store_text_file($plainText,$name,'notes');
                $savedPaths[]=$mainPath;
                if (!ns_is_remote_file($mainPath)) $savedAbsolutePaths[]=$uploadDirectory.DIRECTORY_SEPARATOR.basename($mainPath);
            } else {
                // Existing single-file field remains supported. A photo-only submission may use the multiple-photo picker instead.
                $cloudSingle=ns_cloud_upload_from_request('note_file');
                $cloudImages=ns_cloud_uploads_from_request('note_images');
                $single=$_FILES['note_file'] ?? null;
                $hasSingle=$cloudSingle!==null || (is_array($single) && (int)($single['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE);
                $images=normalizeMultiFiles($_FILES['note_images'] ?? []);
                $images=array_values(array_filter($images,fn($f)=>$f['error']!==UPLOAD_ERR_NO_FILE));
                if (count($images)+count($cloudImages) > 30) throw new RuntimeException('You can upload a maximum of 30 images per note.');
                if (!$hasSingle && !$images && !$cloudImages) throw new RuntimeException('Please choose a file or add at least one photo.');
                if ($cloudSingle !== null) {
                    $original=(string)$cloudSingle['name']; $size=(int)$cloudSingle['size']; $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
                    if ($size<=0) throw new RuntimeException('The selected file is empty.');
                    if ($size > 1024 * 1024 * 1024) throw new RuntimeException('File too large. Max 1 GB per file.');
                    if (!in_array($ext,$allowedExtensions,true)) throw new RuntimeException('This file type is not supported.');
                    $uploadedItems[]=['cloud'=>true,'path'=>$cloudSingle['path'],'original'=>$original,'size'=>$size,'ext'=>$ext,'mime'=>(string)$cloudSingle['mime'],'main'=>true]; $totalIncomingBytes += $size;
                } elseif (is_array($single) && $hasSingle) {
                    $code=(int)($single['error'] ?? UPLOAD_ERR_NO_FILE); if ($code!==UPLOAD_ERR_OK) throw new RuntimeException(uploadErrorMessage($code));
                    $tmp=(string)($single['tmp_name'] ?? ''); $original=(string)($single['name'] ?? ''); $size=(int)($single['size'] ?? 0); $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
                    if ($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('The uploaded file could not be verified.');
                    if ($size<=0) throw new RuntimeException('The selected file is empty.');
                    if ($size > 1024 * 1024 * 1024) throw new RuntimeException('File too large. Max 1 GB per file.');
                    if (!in_array($ext,$allowedExtensions,true)) throw new RuntimeException('This file type is not supported.');
                    $uploadedItems[]=['cloud'=>false,'tmp'=>$tmp,'original'=>$original,'size'=>$size,'ext'=>$ext,'main'=>true]; $totalIncomingBytes += $size;
                }
                foreach ($cloudImages as $img) {
                    $original=(string)$img['name']; $size=(int)$img['size']; $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
                    if ($size<=0) throw new RuntimeException('One of the selected photos is empty.');
                    if ($size > 1024 * 1024 * 1024) throw new RuntimeException('Photo too large. Max 1 GB per file.');
                    if (!in_array($ext,$imageExtensions,true)) throw new RuntimeException('Only JPG, JPEG, PNG, GIF, and WEBP files are accepted in the photo picker.');
                    $uploadedItems[]=['cloud'=>true,'path'=>$img['path'],'original'=>$original,'size'=>$size,'ext'=>$ext,'mime'=>(string)$img['mime'],'main'=>false]; $totalIncomingBytes += $size;
                }
                foreach ($images as $idx=>$img) {
                    if ($img['error']!==UPLOAD_ERR_OK) throw new RuntimeException(uploadErrorMessage($img['error']));
                    $tmp=(string)$img['tmp_name']; $original=(string)$img['name']; $size=(int)$img['size']; $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
                    if ($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('One of the selected photos could not be verified.');
                    if ($size<=0) throw new RuntimeException('One of the selected photos is empty.');
                    if ($size > 1024 * 1024 * 1024) throw new RuntimeException('Photo too large. Max 1 GB per file.');
                    if (!in_array($ext,$imageExtensions,true)) throw new RuntimeException('Only JPG, JPEG, PNG, GIF, and WEBP files are accepted in the photo picker.');
                    $uploadedItems[]=['cloud'=>false,'tmp'=>$tmp,'original'=>$original,'size'=>$size,'ext'=>$ext,'main'=>false]; $totalIncomingBytes += $size;
                }
                ensureStorageAvailable($totalIncomingBytes,$remainingStorageBytes);
                foreach ($uploadedItems as $item) {
                    if (!empty($item['cloud'])) {
                        $relative=(string)$item['path'];
                        $savedPaths[]=$relative;
                        if (!empty($item['main'])) $mainPath=$relative;
                        else $attachmentPaths[]=['path'=>$relative,'original'=>$item['original'],'mime'=>$item['mime']!==''?$item['mime']:'application/octet-stream','size'=>(int)$item['size']];
                        continue;
                    }
                    $fileName='upload_'.$userId.'_'.bin2hex(random_bytes(12)).'.'.$item['ext'];
                    $relative=$uploadRelativeDirectory.'/'.$fileName; $absolute=$uploadDirectory.DIRECTORY_SEPARATOR.$fileName;
                    if (!move_uploaded_file($item['tmp'],$absolute)) throw new RuntimeException('Unable to save one of the selected files.');
                    $savedPaths[]=$relative; $savedAbsolutePaths[]=$absolute;
                    if (!empty($item['main'])) $mainPath=$relative;
                    else $attachmentPaths[]=['path'=>$relative,'original'=>$item['original'],'mime'=>function_exists('mime_content_type') ? (mime_content_type($absolute) ?: 'application/octet-stream') : 'application/octet-stream','size'=>(int)filesize($absolute)];
                }
                if ($mainPath===null && $savedPaths) $mainPath=$savedPaths[0];
            }
            if ($mainPath===null) throw new RuntimeException('Unable to identify the main note file.');
            $s=refreshStudentStorage($conn,$userId,$projectRoot); $storageLimitBytes=$s['limit']; $usedStorageBytes=$s['used']; $remainingStorageBytes=$s['remaining'];
            ensureStorageAvailable($totalIncomingBytes,$remainingStorageBytes);
            $conn->beginTransaction(); $transactionStarted=true;
            $insert=$conn->prepare("INSERT INTO notes (user_id,title,description,content,institute,file_path,thumbnail,note_type,status,created_at) VALUES (:user_id,:title,:description,:content,:institute,:file_path,NULL,:note_type,'pending',NOW())");
            $insert->execute([':user_id'=>$userId,':title'=>$title,':description'=>$description!==''?$description:null,':content'=>$noteContent,':institute'=>$institute,':file_path'=>$mainPath,':note_type'=>$activeType]);
            $noteId=(int)$conn->lastInsertId();
            if ($noteId<=0 && $attachmentPaths) throw new RuntimeException('The note was saved without a usable note ID. Check that the notes table has an auto-increment primary key.');
            if ($attachmentPaths) {
                $attach=$conn->prepare('INSERT INTO note_attachments (note_id,user_id,file_path,original_name,mime_type,file_size,created_at) VALUES (:note_id,:user_id,:file_path,:original_name,:mime_type,:file_size,NOW())');
                foreach ($attachmentPaths as $a) $attach->execute([':note_id'=>$noteId,':user_id'=>$userId,':file_path'=>$a['path'],':original_name'=>$a['original'],':mime_type'=>$a['mime'],':file_size'=>$a['size']]);
            }
            $conn->commit(); $transactionStarted=false;
            $_SESSION['notesphere_upload_csrf']=bin2hex(random_bytes(32)); header('Location: upload_notes.php?success=1'); exit;
        } catch (Throwable $e) {
            if ($transactionStarted && $conn->inTransaction()) $conn->rollBack();
            foreach ($savedAbsolutePaths as $path) if (is_file($path)) @unlink($path);
            if ($e instanceof RuntimeException) $error=$e->getMessage();
            else { error_log('NoteSphere note submission error: '.$e->getMessage()); $error=(stripos($e->getMessage(),'note_attachments')!==false) ? 'The multi-image database table is missing. Import notesphere_multi_image_migration.sql, then try again.' : 'Unable to save your note right now. Please check your database setup and try again.'; }
            try { $s=refreshStudentStorage($conn,$userId,$projectRoot); $storageLimitBytes=$s['limit']; $usedStorageBytes=$s['used']; $remainingStorageBytes=$s['remaining']; $storageLoaded=true; } catch(Throwable $ignore) {}
        }
    }
}
if (isset($_GET['success']) && $_GET['success']==='1') $success='Your note has been submitted successfully and is waiting for admin approval.';
function iniSizeToBytes(string $v): int { $v=trim($v); if ($v==='') return 0; $u=strtolower(substr($v,-1)); $n=(float)$v; switch($u){case 'g':$n*=1024; case 'm':$n*=1024; case 'k':$n*=1024;} return (int)$n; }
$phpUploadLimit=iniSizeToBytes((string)ini_get('upload_max_filesize')); $phpPostLimit=iniSizeToBytes((string)ini_get('post_max_size')); $serverUploadLimit=$phpUploadLimit;
if ($phpPostLimit>0) $serverUploadLimit=$serverUploadLimit>0?min($serverUploadLimit,$phpPostLimit):$phpPostLimit;
$serverUploadLimitText=$serverUploadLimit>0?formatBytes($serverUploadLimit):'determined by server settings';
$storagePercent=$storageLimitBytes>0?min(100,($usedStorageBytes/$storageLimitBytes)*100):($usedStorageBytes>0?100:0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#0b0b0b" id="themeColorMeta">
<title>Upload or Create Note | NoteSphere</title>
<script>(function(){try{if(localStorage.getItem('notesphere_theme')==='light')document.documentElement.classList.add('light-mode');}catch(e){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{color-scheme:dark;--neon:#39FF14;--neon-glow:rgba(57,255,20,.12);--card-bg:rgba(12,12,12,.94);--page-bg:#080808;--text:#f8fafc;--sub-text:#94a3b8;--border:rgba(148,163,184,.18);--input-bg:#111713;--soft:rgba(57,255,20,.07);--danger:#ff6464;--shadow:0 12px 35px rgba(0,0,0,.18)}
:root.light-mode,body.light-mode{color-scheme:light;--neon:#087f3e;--neon-glow:rgba(8,127,62,.12);--card-bg:rgba(255,255,255,.95);--page-bg:#ffffff;--text:#000000;--sub-text:#334155;--border:rgba(15,23,42,.12);--input-bg:#fff;--soft:rgba(8,127,62,.07);--danger:#dc2626;--shadow:0 12px 35px rgba(21,54,39,.07)}
*,*::before,*::after{box-sizing:border-box}html{width:100%;min-height:100%;overflow-x:clip;-webkit-text-size-adjust:100%}body{position:relative;width:100%;min-width:0;min-height:100vh;margin:0;overflow-x:clip;color:var(--text);background:var(--page-bg);font-family:'Plus Jakarta Sans',sans-serif;transition:background .25s,color .25s}body::before{content:none}button,input,select,textarea{max-width:100%;font:inherit}button,a{-webkit-tap-highlight-color:transparent}
.close-button{position:fixed;top:18px;right:22px;z-index:1000;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;color:var(--sub-text);background:var(--card-bg);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);font-size:28px;line-height:1;font-weight:500;transition:color .2s,border-color .2s,background .2s}.close-button:hover,.close-button:focus-visible{color:var(--neon);border-color:var(--neon);background:var(--neon-glow);outline:none}
.page{width:min(1000px,calc(100% - 36px));min-width:0;margin:35px auto 55px}.page-heading{margin-bottom:24px;padding-right:48px}.eyebrow{margin-bottom:9px;color:var(--neon);font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase}h1{margin:0 0 10px;font-size:clamp(26px,4vw,35px);line-height:1.25;letter-spacing:-1.1px;overflow-wrap:anywhere}.subtitle{max-width:720px;margin:0;color:var(--sub-text);font-size:14px;line-height:1.8}
.storage-card{margin-bottom:24px;padding:21px 23px;background:var(--card-bg);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}.storage-top{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:14px}.storage-title{margin-bottom:5px;font-size:14px;font-weight:800}.storage-caption{color:var(--sub-text);font-size:12px;line-height:1.7}.storage-number{text-align:right;font-size:13px;font-weight:800;overflow-wrap:anywhere}.storage-number small{display:block;margin-top:5px;color:var(--sub-text);font-size:11px;font-weight:500}.progress-track{width:100%;height:9px;overflow:hidden;background:var(--border);border-radius:20px}.progress-fill{height:100%;background:var(--neon);border-radius:inherit;transition:width .25s}.storage-foot{display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:10px;color:var(--sub-text);font-size:11px}
.form-card{min-width:0;padding:28px;background:var(--card-bg);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow)}.section-heading{margin-bottom:18px}.section-heading h2{margin:0 0 6px;font-size:17px;line-height:1.5}.section-heading p{margin:0;color:var(--sub-text);font-size:12px;line-height:1.7}.method-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:27px}.method-option{position:relative;display:block;min-width:0;cursor:pointer}.method-option input{position:absolute;width:1px;height:1px;opacity:0}.method-card{height:100%;min-height:150px;padding:20px;background:var(--card-bg);border:1.5px solid var(--border);border-radius:16px;transition:background .2s,border-color .2s,box-shadow .2s}.method-option input:checked+.method-card{background:var(--soft);border-color:var(--neon);box-shadow:0 0 0 3px var(--neon-glow)}.method-option input:focus-visible+.method-card{outline:3px solid var(--neon-glow);outline-offset:3px}.method-icon{display:grid;place-items:center;width:43px;height:43px;margin-bottom:13px;color:var(--neon);background:var(--neon-glow);border-radius:13px;font-size:22px}.method-card h3{margin:0 0 7px;font-size:14px;line-height:1.5}.method-card p{margin:0;color:var(--sub-text);font-size:11px;line-height:1.7}
.form-section{min-width:0;padding-top:25px;margin-top:25px;border-top:1px solid var(--border)}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.field{min-width:0;margin-bottom:19px}.field.full{grid-column:1/-1}.field-label{display:block;margin-bottom:9px;font-size:12px;font-weight:800}.required{color:var(--danger)}.input,.select,.textarea{display:block;width:100%;min-width:0;padding:13px 14px;color:var(--text);background:var(--input-bg);border:1px solid var(--border);border-radius:11px;font-size:13px;outline:none;transition:border-color .2s,box-shadow .2s}.input:focus,.select:focus,.textarea:focus{border-color:var(--neon);box-shadow:0 0 0 3px var(--neon-glow)}.select option{color:var(--text);background:var(--card-bg)}.textarea{min-height:100px;resize:vertical;line-height:1.8}.note-editor{min-height:320px;font-size:14px;line-height:1.9}.help-text{display:block;margin-top:8px;color:var(--sub-text);font-size:11px;line-height:1.7;overflow-wrap:anywhere}
.file-drop{display:block;min-width:0;padding:28px 18px;background:var(--soft);border:1.5px dashed var(--neon);border-radius:15px;text-align:center;cursor:pointer;transition:background .2s,border-color .2s}.file-drop:hover{background:var(--neon-glow)}.file-icon{margin-bottom:10px;font-size:31px}.file-drop strong{display:block;margin-bottom:7px;font-size:13px;line-height:1.6}.file-drop span{display:block;color:var(--sub-text);font-size:11px;line-height:1.7;overflow-wrap:anywhere}.file-drop input{display:block;width:100%;min-width:0;margin-top:15px;color:var(--text);font-size:12px}.photo-tools{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.photo-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 15px;color:var(--text);background:var(--card-bg);border:1px solid var(--border);border-radius:11px;font-size:12px;font-weight:800;cursor:pointer;transition:border-color .2s,color .2s,background .2s}.photo-btn:hover{border-color:var(--neon);color:var(--neon);background:var(--soft)}.photo-btn .plus{display:inline-grid;place-items:center;width:22px;height:22px;color:#07110a;background:var(--neon);border-radius:50%;font-size:17px;line-height:1}.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:10px;margin-top:14px}.photo-item{position:relative;min-width:0;overflow:hidden;background:var(--input-bg);border:1px solid var(--border);border-radius:12px}.photo-item img{display:block;width:100%;height:100px;object-fit:cover}.photo-name{padding:7px;font-size:10px;color:var(--sub-text);overflow-wrap:anywhere}.remove-photo{position:absolute;top:5px;right:5px;display:grid;place-items:center;width:26px;height:26px;color:#fff;background:rgba(0,0,0,.78);border:1px solid rgba(255,255,255,.35);border-radius:50%;font-size:17px;cursor:pointer}.photo-summary{margin-top:10px;color:var(--sub-text);font-size:11px;line-height:1.7}
.alert{margin-bottom:20px;padding:14px 16px;border-radius:12px;font-size:12px;line-height:1.7;overflow-wrap:anywhere}.alert.error{color:#ffb4b4;background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.35)}.light-mode .alert.error{color:#991b1b;background:#fef2f2;border-color:#fecaca}.alert.success{color:#86efac;background:rgba(22,163,74,.12);border:1px solid rgba(22,163,74,.35)}.light-mode .alert.success{color:#166534;background:#f0fdf4;border-color:#bbf7d0}.notice{display:flex;align-items:flex-start;gap:10px;margin-top:8px;padding:14px;color:var(--sub-text);background:var(--soft);border:1px solid var(--border);border-radius:12px;font-size:11px;line-height:1.8}.notice>span{flex-shrink:0;color:var(--neon)}.notice strong{color:var(--text)}.actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:12px;margin-top:26px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:45px;padding:12px 20px;border:1px solid transparent;border-radius:11px;font-size:12px;font-weight:800;cursor:pointer;transition:background .2s,border-color .2s,transform .2s}.btn-secondary{color:var(--text);background:var(--card-bg);border-color:var(--border)}.btn-secondary:hover{border-color:var(--neon);color:var(--neon)}.btn-primary{color:#07110a;background:var(--neon)}.btn-primary:hover{filter:brightness(.94);transform:translateY(-1px)}.btn-primary:disabled{opacity:.55;cursor:not-allowed;transform:none}.hidden{display:none!important}
@media(max-width:800px){.page{margin-top:28px}.form-card{padding:23px}}@media(max-width:650px){.close-button{top:12px;right:12px;width:39px;height:39px;border-radius:10px;font-size:25px}.page{width:calc(100% - 24px);margin:23px auto 35px}.page-heading{margin-bottom:20px;padding-right:38px}h1{font-size:clamp(25px,7vw,32px)}.subtitle{font-size:13px}.form-card{padding:17px;border-radius:16px}.method-grid,.field-grid{grid-template-columns:minmax(0,1fr);gap:12px}.field.full{grid-column:auto}.method-card{min-height:0;padding:17px}.storage-card{padding:16px;border-radius:15px}.storage-top{flex-direction:column;gap:12px}.storage-number{text-align:left}.storage-foot{font-size:10px}.form-section{padding-top:20px;margin-top:20px}.note-editor{min-height:250px}.file-drop{padding:22px 12px}.actions{flex-direction:column-reverse;align-items:stretch}.actions .btn{width:100%;text-align:center}.photo-tools .photo-btn{flex:1 1 140px}}@media(max-width:360px){.close-button{top:10px;right:10px;width:36px;height:36px}.page{width:calc(100% - 18px)}.form-card,.storage-card{padding:13px}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition-duration:.01ms!important;animation-duration:.01ms!important}}
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
<a class="close-button" href="view_notes_institutes.php?institute=<?=h($institute)?>" aria-label="Return to institute" title="Back to Institute">&times;</a>
<main class="page">
 <div class="page-heading"><div class="eyebrow">Student Workspace</div><h1>Share Your Knowledge</h1><p class="subtitle">Upload your existing academic files or create your own notes directly in NoteSphere.</p></div>
 <?php if($error!==''):?><div class="alert error" role="alert"><strong>Unable to submit:</strong> <?=h($error)?></div><?php endif;?>
 <?php if($success!=='' && !isset($_GET['success'])):?><div class="alert success" role="status"><strong>Submission successful!</strong> <?=h($success)?></div><?php endif;?>

 <form class="form-card" id="noteForm" action="upload_notes.php" method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>">
  <div class="section-heading"><h2>How would you like to add your note?</h2><p>Choose one method to continue.</p></div>
  <div class="method-grid"><label class="method-option"><input type="radio" name="note_type" value="upload" <?=$activeType==='upload'?'checked':''?>><div class="method-card"><div class="method-icon">&#8682;</div><h3>Upload Existing Note</h3><p>Choose an academic file or attach several photos to one note.</p></div></label><label class="method-option"><input type="radio" name="note_type" value="created" <?=$activeType==='created'?'checked':''?>><div class="method-card"><div class="method-icon">&#9998;</div><h3>Create a Note</h3><p>Write your own notes directly in NoteSphere without uploading an existing document.</p></div></label></div>
  <div class="form-section <?=$activeType==='upload'?'':'hidden'?>" id="uploadSection"><div class="section-heading"><h2>Choose Your File or Photos</h2><p>Use the original file picker for a document, or use the photo buttons to attach multiple pictures to this same note.</p></div>
   <div class="field"><label class="file-drop" for="note_file"><div class="file-icon">&#128196;</div><strong>Choose a main file (optional if adding photos)</strong><span>Documents, presentations, spreadsheets, images, videos, and archives are supported.</span><input type="file" id="note_file" name="note_file" accept=".pdf,.doc,.docx,.txt,.rtf,.ppt,.pptx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z"></label>
    <span class="help-text" id="selectedFileName">No main file selected.</span>
    <div class="photo-tools"><button class="photo-btn" type="button" id="addPhotosButton"><span class="plus">+</span> Add Photos</button><button class="photo-btn" type="button" id="cameraButton"><span class="plus">+</span> Take Photo</button></div>
    <input type="file" id="note_images_picker" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
    <input type="file" id="note_camera_picker" accept="image/*" capture="environment" hidden>
    <input type="file" id="note_images" name="note_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
    <div class="photo-summary" id="photoSummary">No extra photos selected. Use + Add Photos to select several images, or Take Photo to open your device camera when supported.</div><div class="photo-grid" id="photoGrid" aria-live="polite"></div>
    <span class="help-text">Supported photo formats: JPG, JPEG, PNG, GIF, and WEBP. You may select several photos and remove any photo before submitting. Current PHP upload limit: <?=h($serverUploadLimitText)?>; the web server may also enforce a total request-size limit.</span>
   </div></div>
  <div class="form-section <?=$activeType==='created'?'':'hidden'?>" id="createSection"><div class="section-heading"><h2>Write Your Note</h2><p>Enter your lesson, reviewer, summary, or other academic material below.</p></div><div class="field"><label class="field-label" for="content">Note Content <span class="required">*</span></label><textarea class="textarea note-editor" id="content" name="content" placeholder="Start writing your notes here..."><?=h($content)?></textarea><div class="storage-foot"><span>Plain-text notes</span><span id="characterCount">0 characters</span></div><span class="help-text">Your written note will be saved in NoteSphere and a text-file copy will be stored with your note.</span></div></div>
  <div class="form-section"><div class="field-grid"><div class="field full"><label class="field-label" for="title">Note Title <span class="required">*</span></label><input class="input" type="text" id="title" name="title" maxlength="255" placeholder="e.g. Introduction to Programming" value="<?=h($title)?>" required></div><div class="field"><label class="field-label" for="institute">Institute <span class="required">*</span></label><select class="select" id="institute" name="institute" required><option value="">Select Institute</option><option value="ICS" <?=$institute==='ICS'?'selected':''?>>ICS</option><option value="IBE" <?=$institute==='IBE'?'selected':''?>>IBE</option><option value="ITE" <?=$institute==='ITE'?'selected':''?>>ITE</option></select></div><div class="field"><label class="field-label" for="description">Short Description</label><input class="input" type="text" id="description" name="description" maxlength="10000" placeholder="Briefly describe your note" value="<?=h($description)?>"></div></div></div>
  <div class="notice"><span>&#9432;</span><div><strong>Admin review required</strong><br>Student submissions are marked as pending until approved by the administrator. Your saved note will not automatically become publicly available before approval.</div></div>
  <div class="actions"><a class="btn btn-secondary" href="<?=h($backUrl)?>">Cancel</a><button class="btn btn-primary" type="submit" id="submitButton" <?=$storageLoaded?'':'disabled'?>>Submit Note for Approval</button></div>
 </form>
</main>
<script src="../assets/cloudinary_upload.js"></script>
<script>
(function(){'use strict';
 const urlParams = new URLSearchParams(window.location.search);
 if (urlParams.get('success') === '1') {
   const lightMode = document.documentElement.classList.contains('light-mode');
   const showSuccess = () => {
     if (window.Swal) {
       Swal.fire({
         icon: 'success',
         title: 'Note Submitted Successfully!',
         text: 'Your note has been submitted. Please wait while the administrator reviews and approves it.',
         confirmButtonText: 'Okay',
         confirmButtonColor: lightMode ? '#22a447' : '#39FF14',
         color: lightMode ? '#111827' : '#f8fafc',
         background: lightMode ? '#ffffff' : '#111713',
         allowOutsideClick: false,
         allowEscapeKey: true
       }).then(() => {
         const cleanUrl = new URL(window.location.href);
         cleanUrl.searchParams.delete('success');
         window.history.replaceState({}, document.title, cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
       });
     } else {
       window.alert('Note submitted successfully! Please wait while the administrator reviews and approves it.');
       const cleanUrl = new URL(window.location.href);
       cleanUrl.searchParams.delete('success');
       window.history.replaceState({}, document.title, cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
     }
   };
   if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showSuccess, {once:true});
   else showSuccess();
 }
 const root=document.documentElement,body=document.body,meta=document.getElementById('themeColorMeta');
 function applyTheme(t){const light=t==='light';root.classList.toggle('light-mode',light);body.classList.toggle('light-mode',light);if(meta)meta.setAttribute('content',light?'#ffffff':'#080808');}
 function readTheme(){try{return localStorage.getItem('notesphere_theme')==='light'?'light':'dark';}catch(e){return'dark';}}
 applyTheme(readTheme());window.addEventListener('storage',e=>{if(e.key==='notesphere_theme')applyTheme(e.newValue==='light'?'light':'dark');});window.addEventListener('focus',()=>applyTheme(readTheme()));document.addEventListener('visibilitychange',()=>{if(!document.hidden)applyTheme(readTheme());});
 const methods=document.querySelectorAll('input[name="note_type"]'),uploadSection=document.getElementById('uploadSection'),createSection=document.getElementById('createSection'),fileInput=document.getElementById('note_file'),content=document.getElementById('content'),fileName=document.getElementById('selectedFileName'),count=document.getElementById('characterCount'),form=document.getElementById('noteForm'),submit=document.getElementById('submitButton');
 const galleryPicker=document.getElementById('note_images_picker'),cameraPicker=document.getElementById('note_camera_picker'),hiddenImages=document.getElementById('note_images'),addBtn=document.getElementById('addPhotosButton'),cameraBtn=document.getElementById('cameraButton'),grid=document.getElementById('photoGrid'),summary=document.getElementById('photoSummary');
 let photos=[]; let objectUrls=[];
 function method(){const c=document.querySelector('input[name="note_type"]:checked');return c?c.value:'upload';}
 function updateMethod(){const up=method()==='upload';uploadSection.classList.toggle('hidden',!up);createSection.classList.toggle('hidden',up);content.required=!up;fileInput.required=false;galleryPicker.disabled=!up;cameraPicker.disabled=!up;}
 function updateCount(){count.textContent=content.value.length.toLocaleString()+' characters';}
 function syncFiles(){const dt=new DataTransfer();photos.forEach(p=>dt.items.add(p));hiddenImages.files=dt.files;renderPhotos();}
 function renderPhotos(){objectUrls.forEach(URL.revokeObjectURL);objectUrls=[];grid.innerHTML='';photos.forEach((file,index)=>{const card=document.createElement('div');card.className='photo-item';const img=document.createElement('img');const url=URL.createObjectURL(file);objectUrls.push(url);img.src=url;img.alt=file.name||('Selected photo '+(index+1));const name=document.createElement('div');name.className='photo-name';name.textContent=file.name||('Photo '+(index+1));const remove=document.createElement('button');remove.type='button';remove.className='remove-photo';remove.setAttribute('aria-label','Remove '+(file.name||'photo'));remove.textContent='×';remove.addEventListener('click',()=>{photos.splice(index,1);syncFiles();});card.append(img,name,remove);grid.appendChild(card);});const bytes=photos.reduce((sum,f)=>sum+f.size,0);summary.textContent=photos.length?photos.length+' photo(s) selected · '+(bytes/1048576).toFixed(2)+' MB total. These photos will be attached to this one note.':'No extra photos selected. Use + Add Photos to select several images, or Take Photo to open your device camera when supported.';}
 function addPhotoFiles(fileList){const incoming=Array.from(fileList||[]);for(const f of incoming){if(!f.type.startsWith('image/')){alert('Please select image files only in the photo picker.');continue;}if(photos.length>=30){alert('You can upload a maximum of 30 images per note.');break;}const duplicate=photos.some(p=>p.name===f.name&&p.size===f.size&&p.lastModified===f.lastModified);if(!duplicate)photos.push(f);}syncFiles();}
 methods.forEach(i=>i.addEventListener('change',updateMethod));fileInput.addEventListener('change',()=>{fileName.textContent=fileInput.files&&fileInput.files.length?'Selected main file: '+fileInput.files[0].name:'No main file selected.';});content.addEventListener('input',updateCount);
 addBtn.addEventListener('click',()=>galleryPicker.click());cameraBtn.addEventListener('click',()=>cameraPicker.click());galleryPicker.addEventListener('change',()=>{addPhotoFiles(galleryPicker.files);galleryPicker.value='';});cameraPicker.addEventListener('change',()=>{addPhotoFiles(cameraPicker.files);cameraPicker.value='';});
 form.addEventListener('submit',e=>{const cloudReady=form.dataset.nsCloudReady==='1'||!!form.querySelector('input[name="note_file__url"]');if(method()==='upload'&&!cloudReady&&fileInput.files.length===0&&photos.length===0){e.preventDefault();alert('Please choose a main file or add at least one photo.');fileInput.focus();return;}if(method()==='created'&&content.value.trim()===''){e.preventDefault();alert('Please write your note before submitting.');content.focus();return;}submit.disabled=true;submit.textContent='Submitting...';});
 updateMethod();updateCount();renderPhotos();
})();
</script>
</body></html>

