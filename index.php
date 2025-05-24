<?php
session_start();
$downloadMessage = "";
$downloadLink = "";
$debugOutput = "";
$maxDownloadsPerWindow = 20;       // Max 3 downloads per IP
$windowSeconds = 600;             // Per 10 minutes (600 detik)
$downloadLimitFile = __DIR__ . "/download_limit.json";  // Full path
$downloadsDir = __DIR__ . "/downloads";
$logsDir = __DIR__ . "/logs";
// Pastikan folder logs dan downloads ada dan writable
if (!is_dir($downloadsDir)) mkdir($downloadsDir, 0777, true);
if (!is_dir($logsDir)) mkdir($logsDir, 0777, true);
// Path yt-dlp dan ffmpeg (ubah sesuai installasi kamu)

// $ytDlpPath = "C:\\yt-dlp\\yt-dlp.exe";
// $ffmpegPath = "C:\\ffmpeg\\bin";
$ytDlpPath = "/usr/local/bin/yt-dlp";  // ini default path yt-dlp di container
$ffmpegPath = "/usr/bin";               // path ffmpeg di container

function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }
}
function loadDownloadData($file) {
    $data = [];
    if (!file_exists($file)) return $data;
    $fp = fopen($file, 'r');
    if ($fp) {
        flock($fp, LOCK_SH);
        $filesize = filesize($file);
        $json = $filesize > 0 ? fread($fp, $filesize) : '';
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($json, true) ?: [];
    }
    return $data;
}
function saveDownloadData($file, $data) {
    $fp = fopen($file, 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
function canDownload($ip, $maxDownloads, $window, $file) {
    $data = loadDownloadData($file);
    $now = time();

    if (!isset($data[$ip])) {
        $data[$ip] = [];
    }
    // Filter waktu yang sudah lewat window
    $data[$ip] = array_filter($data[$ip], fn($t) => ($now - $t) < $window);

    if (count($data[$ip]) >= $maxDownloads) {
        return [false, $data];
    }
    // Tambah waktu sekarang
    $data[$ip][] = $now;
    return [true, $data];
}
function cleanupOldFiles($folder, $maxAgeSeconds) {
    foreach (glob("$folder/*") as $file) {
        if (is_file($file) && (time() - filemtime($file)) > $maxAgeSeconds) {
            if (!@unlink($file)) {
                error_log("Gagal menghapus file lama: $file");
            } else {
                error_log("File dihapus: $file");
            }
        }
    }
}
function sanitizeFilename($filename) {
    // Ganti spasi dengan underscore, hapus karakter aneh
    $filename = str_replace(' ', '_', $filename);
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
}
function logActivity($msg) {
    global $logsDir;
    $file = $logsDir . "/download_activity.log";
    file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}
function logError($msg) {
    global $logsDir;
    $file = $logsDir . "/download_error.log";
    file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}
function isValidUrl($url) {
    // Contoh filter domain: hanya domain youtube dan youtu.be
    $allowedDomains = ['youtube.com', 'youtu.be', 'soundcloud.com', 'vimeo.com', 'facebook.com', 'spotify.com', 'tiktok.com'];
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'])) return false;

    $host = strtolower($parts['host']);
    foreach ($allowedDomains as $domain) {
        if (str_ends_with($host, $domain)) return true;
    }
    return false;
}
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $ip = getUserIP();
    list($allowed, $downloadData) = canDownload($ip, $maxDownloadsPerWindow, $windowSeconds, $downloadLimitFile);
    if (!$allowed) {
$downloadMessage = "🚫 Kamu telah mencapai batas unduhan (maks. $maxDownloadsPerWindow file per $windowSeconds detik). Tunggu sebentar sebelum mencoba lagi.";
    } else {
        saveDownloadData($downloadLimitFile, $downloadData);
        cleanupOldFiles($downloadsDir, 3600); // Hapus file > 1 jam
        $url = trim($_POST['url']);
        $format = $_POST['format'] === 'mp3' ? 'bestaudio' : 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best';
        $allowedFormats = ['mp3', 'mp4'];
        if (!in_array($_POST['format'], $allowedFormats)) {
            $downloadMessage = "Format tidak didukung.";
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !isValidUrl($url)) {
    $downloadMessage = "URL tidak valid. Pastikan kamu menyalin link dari YouTube, contoh: https://www.youtube.com/watch?v=xxxx";
        } elseif (strlen($url) > 300) {
            $downloadMessage = "URL terlalu panjang.";
        } else {
            // Ambil durasi video supaya bisa batasi panjang mp3
            $durationCommand = "\"$ytDlpPath\" --no-warnings --print duration " . escapeshellarg($url);
            exec($durationCommand, $durationOutput, $durationCode);
            $durationSeconds = isset($durationOutput[0]) ? intval($durationOutput[0]) : 0;
            if ($_POST['format'] === 'mp3' && $durationSeconds > 600) {
                $downloadMessage = "Video terlalu panjang untuk diunduh sebagai MP3 (maksimal 10 menit).";
            } else {
                $outputTemplate = $downloadsDir . "/%(title)s.%(ext)s";
                $command = "\"$ytDlpPath\" --no-warnings";
                if (!empty($format)) {
                    $command .= " -f $format";
                }
                $command .= " -o " . escapeshellarg($outputTemplate);
                $command .= " --ffmpeg-location " . escapeshellarg($ffmpegPath);
                if ($_POST['format'] === 'mp3') {
                    $command .= " --extract-audio --audio-format mp3";
                }
                $command .= " " . escapeshellarg($url);
                $command .= " --print after_move:filepath";
                $outputLines = [];
                $returnCode = 0;
                exec($command . " 2>&1", $outputLines, $returnCode);
                $debugOutput = implode("\n", $outputLines);
                if ($returnCode === 0 && !empty($outputLines)) {
                    $lastLine = end($outputLines);

                    if (file_exists($lastLine)) {
                        $basename = basename($lastLine);
                        $basenameSafe = sanitizeFilename($basename);

                        if ($basename !== $basenameSafe) {
                            rename($lastLine, $downloadsDir . "/" . $basenameSafe);
                            $basename = $basenameSafe;
                        }
                        $downloadMessage = "Download selesai. Durasi video: " . gmdate("H:i:s", $durationSeconds);
                        $downloadLink = "download.php?file=" . urlencode($basenameSafe);
                        logActivity("$ip downloaded $url as {$_POST['format']} (duration: " . gmdate("H:i:s", $durationSeconds) . ")");
                    } else {
                        $downloadMessage = "Download selesai, tetapi file tidak ditemukan.";
                        logError("File tidak ditemukan setelah download: $url oleh $ip");
                    }
                } else {
                    $downloadMessage = "Gagal download. Silakan coba lagi.";
                    logError("Download gagal: $url oleh $ip\nOutput:\n" . $debugOutput);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Downloader YouTube (Mode Debug)</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            padding: 30px;
        }
        .container {
            max-width: 650px;
            background: white;
            margin: auto;
            padding: 20px;
            border-radius: 6px;
            box-shadow: 0 0 15px #aaa;
        }
        h1 {
            text-align: center;
            color: #222;
        }
        form {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }
        input[type="text"], select {
            width: 100%;
            padding: 8px;
            font-size: 16px;
            border-radius: 3px;
            border: 1px solid #ddd;
            margin-bottom: 12px;
        }
        input[type="submit"] {
            background: #3c8dbc;
            border: none;
            padding: 12px 20px;
            font-size: 16px;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        input[type="submit"]:hover {
            background: #367fa9;
        }
        .message {
            padding: 12px;
            background: #eee;
            border-radius: 4px;
            margin-bottom: 10px;
            font-weight: bold;
            color: #555;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        .download-link {
            text-align: center;
            margin: 10px 0;
        }
        .download-link a {
            color: #1a73e8;
            font-weight: bold;
            font-size: 18px;
            text-decoration: none;
        }
        .download-link a:hover {
            text-decoration: underline;
        }
        .debug-toggle {
            margin-bottom: 10px;
            cursor: pointer;
            color: #555;
            font-size: 14px;
            user-select: none;
        }
        pre.debug-output {
            max-height: 300px;
            overflow: auto;
            background: #272822;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            font-size: 13px;
            line-height: 1.3;
            white-space: pre-wrap;
            display: none;
        }
        .footer {
            text-align: center;
            font-size: 12px;
            color: #aaa;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Downloader YouTube (Mode Debug)</h1>
        <p>Masukkan URL video YouTube yang ingin kamu unduh, lalu pilih format (MP3 untuk audio atau MP4 untuk video).</p>
        <form method="post" autocomplete="off">
            <label for="url">URL Video YouTube:</label>
            <input type="text" id="url" name="url" required placeholder="Masukkan URL YouTube..." maxlength="300"
            value="<?= isset($_POST['url']) ? htmlspecialchars($_POST['url']) : '' ?>">
            <label for="format">Format Unduhan:</label>
            <select id="format" name="format" required>
                <option value="mp3" <?= (isset($_POST['format']) && $_POST['format'] === 'mp3') ? 'selected' : '' ?>>MP3 (Audio)</option>
                <option value="mp4" <?= (isset($_POST['format']) && $_POST['format'] === 'mp4') ? 'selected' : '' ?>>MP4 (Video)</option>
            </select>
            <input type="submit" value="Download">
            <p id="loadingMsg" style="display:none; color:#888;">🚀 Memproses unduhan... Mohon tunggu sebentar.</p>
        </form>
        <?php if ($downloadMessage): ?>
            <div class="message <?= strpos($downloadMessage, 'Gagal') !== false || strpos($downloadMessage, 'tidak') !== false ? 'error' : 'success' ?>">
                <?= htmlspecialchars($downloadMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($downloadLink): ?>
            <div class="download-link">
                <a href="<?= htmlspecialchars($downloadLink) ?>" download>⬇️ Klik di sini untuk mengunduh file</a>
                <p style="font-size: 12px; color: #888;">⚠️ File hanya tersedia selama 1 jam setelah unduhan selesai.</p>
                <p style="font-size: 13px; color: #555;">Nama file: <?= htmlspecialchars($basename ?? '') ?></p>
            </div>
        <?php endif; ?>
        <?php if ($debugOutput): ?>
            <div class="debug-toggle" onclick="toggleDebug()">▶️ Tampilkan Debug Output</div>
            <pre class="debug-output" id="debugOutput"><?= htmlspecialchars($debugOutput) ?></pre>
        <?php endif; ?>
    </div>
        <!-- <button onclick="copyLink()">📋 Salin Link Unduhan</button> -->
    <script>
        function toggleDebug() {
            const pre = document.getElementById('debugOutput');
            if (!pre) return;
            if (pre.style.display === 'block') {
                pre.style.display = 'none';
                event.target.textContent = '▶️ Tampilkan Debug Output';
            } else {
                pre.style.display = 'block';
                event.target.textContent = '🔽 Sembunyikan Debug Output';
            }
        }
        document.querySelector("form").addEventListener("submit", function() {
    document.getElementById("loadingMsg").style.display = "block";
});
    </script>

<script>
function copyLink() {
    const link = document.querySelector(".download-link a");
    if (link) {
        navigator.clipboard.writeText(link.href);
        alert("Link unduhan telah disalin!");
    }
}
</script>

    <div class="footer">© 2025 YouTube Downloader</div>
</body>
</html>
