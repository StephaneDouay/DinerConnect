<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/DB.php';
require $root . '/src/Logger.php';

$cfg = require $root . '/config.php';
date_default_timezone_set($cfg['app']['timezone'] ?? 'Europe/Paris');
Logger::init($root . '/storage');
Logger::setDbReady(true);

$token = $_GET['token'] ?? '';
if (!hash_equals($cfg['app']['cron_token'], $token)) {
    http_response_code(403);
    exit('Forbidden');
}

$validJobs = ['offres', 'inbox', 'relances', 'digest'];
$job       = $_GET['job'] ?? '';
if (!in_array($job, $validJobs, true)) {
    http_response_code(400);
    exit('Invalid job. Valid: ' . implode(', ', $validJobs));
}

// Load all required classes
require $root . '/src/Auth.php';
require $root . '/src/FranceTravailAPI.php';
require $root . '/src/AdzunaAPI.php';
require $root . '/src/ImapClient.php';
require $root . '/src/Mailer.php';
require $root . '/src/OffreService.php';
require $root . '/src/InboxService.php';
require $root . '/src/CandidatureService.php';
require $root . '/src/RelanceService.php';
require $root . '/src/DigestService.php';

$storageDir = $root . '/storage';
$lockFile   = $storageDir . '/locks/' . $job . '.lock';
$lockFh     = fopen($lockFile, 'c');

if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    http_response_code(200);
    exit("Job {$job} already running.");
}

header('Content-Type: text/plain; charset=utf-8');

try {
    Logger::info($job, "Démarrage (web cron)");

    switch ($job) {
        case 'offres':
            $counts = (new OffreService($cfg))->fetchAndStore();
            echo "OK FT={$counts['francetravail']} Adzuna={$counts['adzuna']} skipped={$counts['skipped']} errors={$counts['errors']}";
            break;

        case 'inbox':
            $counts = (new InboxService($cfg, $storageDir))->analyzeInbox();
            echo "OK analysed={$counts['analysed']} updated={$counts['status_updated']} offers={$counts['offres_detected']}";
            break;

        case 'relances':
            $counts = (new RelanceService($cfg, $storageDir))->processRelances();
            echo "OK r1={$counts['relance1']} r2={$counts['relance2']} sans={$counts['sans_reponse']}";
            break;

        case 'digest':
            (new DigestService($cfg))->sendDigest();
            echo "OK digest sent";
            break;
    }

    Logger::info($job, "Terminé (web cron)");

} catch (Throwable $e) {
    Logger::error($job, $e->getMessage());
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}
