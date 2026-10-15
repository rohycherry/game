
<?php
declare(strict_types=1);

$store = __DIR__ . '/store/';

// Create store folder automatically
if (!is_dir($store)) {
    mkdir($store, 0755, true);
}

$message = '';
$error = '';

function safeName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? '';
    $name = trim($name, ". ");

    return $name !== '' ? $name : 'file';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Upload file
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        $error = 'Please select a file.';
    } else {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'File is too large. Check your PHP upload limits.',
                UPLOAD_ERR_NO_FILE => 'Please select a file first.',
                default => 'Upload failed. Please try again.',
            };
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $error = 'Invalid upload.';
        } else {
            $original = safeName($file['name']);
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            // Do not allow server-executable or configuration files
            $blocked = [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8',
                'phtml', 'phar', 'cgi', 'pl', 'py', 'sh',
                'htaccess', 'htpasswd'
            ];

            if (in_array($extension, $blocked, true)) {
                $error = 'This file type is not allowed.';
            } else {
                $base = pathinfo($original, PATHINFO_FILENAME);
                $ext = $extension !== '' ? '.' . $extension : '';
                $filename = $original;
                $counter = 1;

                // Avoid overwriting an existing file
                while (file_exists($store . $filename)) {
                    $filename = $base . '_' . $counter . $ext;
                    $counter++;
                }

                if (move_uploaded_file($file['tmp_name'], $store . $filename)) {
                    $message = 'File uploaded successfully!';
                } else {
                    $error = 'Could not save the file.';
                }
            }
        }
    }
}

// Download file
if (isset($_GET['download'])) {
    $requested = (string) $_GET['download'];
    $filename = safeName($requested);
    $path = $store . $filename;

    if (
        $filename !== $requested ||
        !is_file($path) ||
        is_link($path)
    ) {
        http_response_code(404);
        exit('File not found.');
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' .
        str_replace(['"', "\r", "\n"], '_', $filename) . '"');
    header('Content-Length: ' . filesize($path));

    readfile($path);
    exit;
}

// Get stored files
$files = [];

foreach (scandir($store) ?: [] as $filename) {
    $path = $store . $filename;

    if ($filename !== '.' && $filename !== '..'
        && is_file($path) && !is_link($path)) {
        $files[] = [
            'name' => $filename,
            'size' => filesize($path),
            'time' => filemtime($path),
        ];
    }
}

usort($files, fn($a, $b) => $b['time'] <=> $a['time']);

function formatSize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';

    return round($bytes / 1073741824, 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Local File Share</title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 25px 15px;
    background: #10141c;
    color: #eef2f7;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 760px;
    margin: auto;
}

h1 { font-size: 28px; margin-bottom: 8px; }
.subtitle { color: #9ca8ba; margin-bottom: 25px; }

.panel {
    background: #1a2230;
    border: 1px solid #2c3749;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 22px;
}

h2 { font-size: 19px; margin-top: 0; }

input[type=file] {
    width: 100%;
    padding: 12px;
    margin: 10px 0 15px;
    background: #111823;
    color: #dce5f2;
    border: 1px solid #39465b;
    border-radius: 8px;
}

button, .download {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 11px 16px;
    background: #3478f6;
    color: white;
    font-size: 14px;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}

button:hover, .download:hover { background: #2463d5; }

.notice {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
    overflow-wrap: anywhere;
}

.success { background: #173d30; color: #91f0c4; }
.error { background: #4b2529; color: #ffc2c7; }

.file {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 14px 0;
    border-bottom: 1px solid #303a4a;
}

.file:last-child { border-bottom: 0; }
.filename { overflow-wrap: anywhere; font-weight: bold; }
.meta { color: #9ca8ba; font-size: 12px; margin-top: 6px; }

.empty { color: #9ca8ba; padding: 12px 0; }

@media (max-width: 480px) {
    .file { align-items: flex-start; flex-direction: column; }
    .download { width: 100%; text-align: center; }
}
</style>
</head>
<body>
<div class="container">
    <h1>Local File Share</h1>
    <p class="subtitle">Upload files on your PC and download them on Android.</p>

    <section class="panel">
        <h2>Upload a file</h2>

        <?php if ($message !== ''): ?>
            <div class="notice success"><?= h($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit">Upload File</button>
        </form>
    </section>

    <section class="panel">
        <h2>Stored Files (<?= count($files) ?>)</h2>

        <?php if (!$files): ?>
            <p class="empty">No files uploaded yet.</p>
        <?php else: ?>
            <?php foreach ($files as $file): ?>
                <div class="file">
                    <div>
                        <div class="filename"><?= h($file['name']) ?></div>
                        <div class="meta">
                            <?= h(formatSize($file['size'])) ?>
                            ·
                            <?= date('Y-m-d H:i', $file['time']) ?>
                        </div>
                    </div>

                    <a class="download"
                       href="?download=<?= rawurlencode($file['name']) ?>">
                        Download
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
