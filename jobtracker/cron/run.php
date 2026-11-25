<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
require $root . '/src/DB.php';
require $root . '/src/Logger.php';
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

$cfg        = require $root . '/config.php';
$storageDir = $root . '/storage';

date_default_timezone_set($cfg['app']['timezone'] ?? 'Europe/Paris');
Logger::init($storageDir);
Logger::setDbReady(true);

$validJobs = ['offres', 'inbox', 'relances', 'digest'];
$job       = $argv[1] ?? '';

if (!in_array($job, $validJobs, true)) {
    fwrite(STDERR, "Usage: php cron/run.php <job>\nJobs: " . implode(', ', $validJobs) . "\n");
    exit(1);
}

// Lock
$lockFile = $storageDir . '/locks/' . $job . '.lock';
$lockFh   = fopen($lockFile, 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    Logger::warning($job, "Déjà en cours (lock), abandon.");
    exit(0);
}

Logger::info($job, "Démarrage");
$exitCode = 0;

try {
    switch ($job) {
        case 'offres':
            $svc    = new OffreService($cfg);
            $counts = $svc->fetchAndStore();
            echo "Offres : FT={$counts['francetravail']} Adzuna={$counts['adzuna']} Doublons={$counts['skipped']} Erreurs={$counts['errors']}\n";
            Logger::info($job, "Terminé", $counts);
            break;

        case 'inbox':
            $svc    = new InboxService($cfg, $storageDir);
            $counts = $svc->analyzeInbox();
            echo "Inbox : analysés={$counts['analysed']} statuts_mis_à_jour={$counts['status_updated']} offres_détectées={$counts['offres_detected']}\n";
            Logger::info($job, "Terminé", $counts);
            break;

        case 'relances':
            $svc    = new RelanceService($cfg, $storageDir);
            $counts = $svc->processRelances();
            echo "Relances : J+7={$counts['relance1']} J+14={$counts['relance2']} sans_réponse={$counts['sans_reponse']}\n";
            Logger::info($job, "Terminé", $counts);
            break;

        case 'digest':
            $svc = new DigestService($cfg);
            $svc->sendDigest();
            echo "Digest envoyé.\n";
            Logger::info($job, "Terminé");
            break;
    }
} catch (Throwable $e) {
    Logger::error($job, $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
    fwrite(STDERR, "ERREUR [{$job}]: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

exit($exitCode);
