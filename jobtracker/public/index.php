<?php
declare(strict_types=1);

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

if (!is_file($root . '/config.php')) {
    // Show setup hint
    die('<pre>Copiez config.sample.php en config.php et configurez-le.</pre>');
}

$cfg = require $root . '/config.php';
date_default_timezone_set($cfg['app']['timezone'] ?? 'Europe/Paris');
Logger::init($root . '/storage');
Auth::init($cfg);

$storageDir = $root . '/storage';
$tab        = htmlspecialchars($_GET['tab'] ?? 'dashboard', ENT_QUOTES);
$flash      = '';
$flashType  = 'success';

// --- DB optional (may not be set up) ---
$dbOk = false;
try {
    DB::fetch('SELECT 1');
    Logger::setDbReady(true);
    $dbOk = true;
} catch (Throwable) {}

// ======== POST ACTIONS ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Login
    if ($action === 'login') {
        $pw = $_POST['password'] ?? '';
        if ($cfg['app']['admin_password_hash'] === '') {
            // No hash set yet — show hash generator
            $flash = 'Aucun mot de passe configuré. Utilisez le générateur ci-dessous.';
            $flashType = 'warning';
        } elseif (Auth::login($pw)) {
            header('Location: index.php');
            exit;
        } else {
            $flash = 'Mot de passe incorrect.';
            $flashType = 'error';
        }
    }

    // Logout
    if ($action === 'logout') {
        Auth::logout();
        header('Location: index.php');
        exit;
    }

    if (!Auth::check()) {
        header('Location: index.php');
        exit;
    }

    // Send candidature
    if ($action === 'send_cand' && $dbOk) {
        $candId = (int)($_POST['cand_id'] ?? 0);
        $email  = trim($_POST['recruteur_email'] ?? '');
        if ($candId && $email) {
            try {
                (new CandidatureService($cfg, $storageDir))->send($candId, $email);
                $flash = "Candidature #{$candId} envoyée à {$email}.";
            } catch (Throwable $e) {
                $flash = "Erreur : " . $e->getMessage();
                $flashType = 'error';
            }
        }
        header("Location: index.php?tab=candidatures");
        exit;
    }

    // Create candidature from offre
    if ($action === 'create_cand' && $dbOk) {
        $offreId = (int)($_POST['offre_id'] ?? 0);
        if ($offreId) {
            try {
                $candId = (new CandidatureService($cfg, $storageDir))->create($offreId);
                // Mark offer as seen
                DB::execute('UPDATE offres SET vue = 1 WHERE id = ?', [$offreId]);
                $flash = "Candidature brouillon créée (#{$candId}).";
                $tab   = 'candidatures';
            } catch (Throwable $e) {
                $flash = "Erreur : " . $e->getMessage();
                $flashType = 'error';
            }
        }
        header("Location: index.php?tab={$tab}&flash=" . urlencode($flash) . "&flashtype={$flashType}");
        exit;
    }

    // Add manual candidature
    if ($action === 'add_cand_manuel' && $dbOk) {
        $titre  = trim($_POST['titre'] ?? '');
        $ent    = trim($_POST['entreprise'] ?? '');
        $email  = trim($_POST['recruteur_email'] ?? '');
        $canal  = $_POST['canal'] ?? 'email';
        if ($titre) {
            $offreId = DB::insert(
                'INSERT INTO offres (source, titre, titre_normalized, entreprise, entreprise_normalized) VALUES (?,?,?,?,?)',
                ['manuel', $titre, mb_strtolower($titre), $ent, mb_strtolower($ent)]
            );
            DB::insert(
                'INSERT INTO candidatures (offre_id, statut, recruteur_email, canal) VALUES (?,?,?,?)',
                [$offreId, 'brouillon', $email ?: null, $canal]
            );
            $flash = "Candidature manuelle créée.";
        }
        header("Location: index.php?tab=candidatures");
        exit;
    }

    // Update candidature status
    if ($action === 'update_statut' && $dbOk) {
        $candId  = (int)($_POST['cand_id'] ?? 0);
        $statut  = $_POST['statut'] ?? '';
        $allowed = ['brouillon','envoyee','relance1','relance2','reponse_recue','entretien','refus','sans_reponse','acceptee'];
        if ($candId && in_array($statut, $allowed, true)) {
            DB::execute('UPDATE candidatures SET statut = ?, date_derniere_action = NOW() WHERE id = ?', [$statut, $candId]);
            $flash = "Statut mis à jour.";
        }
        header("Location: index.php?tab=candidatures");
        exit;
    }

    // Save template
    if ($action === 'save_template' && $dbOk) {
        $tmplId = (int)($_POST['tmpl_id'] ?? 0);
        $sujet  = trim($_POST['sujet'] ?? '');
        $corps  = trim($_POST['corps'] ?? '');
        if ($tmplId && $sujet && $corps) {
            DB::execute('UPDATE templates SET sujet = ?, corps = ? WHERE id = ?', [$sujet, $corps, $tmplId]);
            $flash = "Modèle sauvegardé.";
        }
        header("Location: index.php?tab=modeles");
        exit;
    }

    // Upload CV
    if ($action === 'upload_cv') {
        if (isset($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['cv']['tmp_name']);
            finfo_close($finfo);
            if ($mime === 'application/pdf' && $_FILES['cv']['size'] <= 5_000_000) {
                move_uploaded_file($_FILES['cv']['tmp_name'], $storageDir . '/cv.pdf');
                $flash = "CV uploadé avec succès.";
            } else {
                $flash = "Fichier invalide (PDF requis, max 5 Mo).";
                $flashType = 'error';
            }
        }
        header("Location: index.php?tab=modeles");
        exit;
    }

    // Run cron job manually
    if ($action === 'run_job' && $dbOk) {
        $job = $_POST['job'] ?? '';
        $validJobs = ['offres', 'inbox', 'relances', 'digest'];
        if (in_array($job, $validJobs, true)) {
            try {
                switch ($job) {
                    case 'offres':
                        $c = (new OffreService($cfg))->fetchAndStore();
                        $flash = "Offres : FT={$c['francetravail']} Adzuna={$c['adzuna']} doublons={$c['skipped']}";
                        break;
                    case 'inbox':
                        $c = (new InboxService($cfg, $storageDir))->analyzeInbox();
                        $flash = "Inbox : {$c['analysed']} analysés, {$c['status_updated']} mis à jour, {$c['offres_detected']} offres détectées";
                        break;
                    case 'relances':
                        $c = (new RelanceService($cfg, $storageDir))->processRelances();
                        $flash = "Relances : J+7={$c['relance1']}, J+14={$c['relance2']}, sans réponse={$c['sans_reponse']}";
                        break;
                    case 'digest':
                        (new DigestService($cfg))->sendDigest();
                        $flash = "Digest envoyé.";
                        break;
                }
            } catch (Throwable $e) {
                $flash = "Erreur : " . $e->getMessage();
                $flashType = 'error';
            }
        }
        header("Location: index.php?tab=journal");
        exit;
    }
}

// Flash from redirect
if (empty($flash) && isset($_GET['flash'])) {
    $flash     = htmlspecialchars($_GET['flash']);
    $flashType = $_GET['flashtype'] ?? 'success';
}

// ======== DATA FETCH ========
$stats  = ['total' => 0, 'brouillon' => 0, 'envoyee' => 0, 'reponse_recue' => 0, 'entretien' => 0, 'refus' => 0, 'sans_reponse' => 0, 'acceptee' => 0];
$offres = $candidatures = $templates = $logs = [];
$cvExists = is_file($storageDir . '/cv.pdf');

if ($dbOk && Auth::check()) {
    $candSvc = new CandidatureService($cfg, $storageDir);
    $stats   = $candSvc->getStats();

    if ($tab === 'offres' || $tab === 'dashboard') {
        $offres = DB::fetchAll("SELECT * FROM offres ORDER BY date_import DESC LIMIT 100");
    }
    if ($tab === 'candidatures' || $tab === 'dashboard') {
        $candidatures = DB::fetchAll(
            "SELECT c.*, o.titre, o.entreprise, o.lieu, o.url as offre_url FROM candidatures c
             LEFT JOIN offres o ON o.id = c.offre_id
             ORDER BY c.date_creation DESC LIMIT 200"
        );
    }
    if ($tab === 'modeles') {
        $templates = DB::fetchAll("SELECT * FROM templates ORDER BY id");
    }
    if ($tab === 'journal') {
        $logs = DB::fetchAll("SELECT * FROM logs ORDER BY id DESC LIMIT 200");
    }
}

$statusLabels = [
    'brouillon'     => ['label' => 'Brouillon',      'color' => '#8b91a5'],
    'envoyee'       => ['label' => 'Envoyée',         'color' => '#93c5fd'],
    'relance1'      => ['label' => 'Relance J+7',     'color' => '#fbbf24'],
    'relance2'      => ['label' => 'Relance J+14',    'color' => '#f59e0b'],
    'reponse_recue' => ['label' => 'Réponse reçue',   'color' => '#facc15'],
    'entretien'     => ['label' => 'Entretien 🎉',    'color' => '#4ade80'],
    'refus'         => ['label' => 'Refus',            'color' => '#f87171'],
    'sans_reponse'  => ['label' => 'Sans réponse',    'color' => '#6b7280'],
    'acceptee'      => ['label' => 'Acceptée ✅',     'color' => '#22c55e'],
];

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JobTracker – Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --gold:#c9a96e; --gold-l:#e0c28c; --bg:#06080f; --bg2:#0b0e18; --bg3:#10131f;
  --card:rgba(12,15,24,.88); --border:rgba(201,169,110,.14); --border-h:rgba(201,169,110,.45);
  --text:#f0ece4; --muted:#8b91a5; --dim:#454c60;
  --sans:'DM Sans',system-ui,sans-serif; --radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:var(--sans);font-size:15px;line-height:1.6}
a{color:var(--gold);text-decoration:none} a:hover{text-decoration:underline}
input,textarea,select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.5rem .8rem;color:var(--text);font-family:var(--sans);font-size:.88rem;outline:none;transition:border-color .2s;width:100%}
input:focus,textarea:focus,select:focus{border-color:var(--gold)}
select option{background:var(--bg3)}
label{font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);display:block;margin-bottom:.3rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;border:0;border-radius:999px;padding:.5rem 1.1rem;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;transition:all .2s}
.btn-gold{background:var(--gold);color:#070707}.btn-gold:hover{background:var(--gold-l)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--muted)}.btn-ghost:hover{border-color:var(--gold);color:var(--text)}
.btn-sm{padding:.35rem .8rem;font-size:.68rem}
.btn-danger{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}.btn-danger:hover{background:rgba(239,68,68,.25)}
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;flex-shrink:0;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:1.5rem 1rem}
.sidebar-brand{font-size:1.3rem;font-weight:700;color:var(--gold);margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid var(--border)}
.nav-link{display:flex;align-items:center;gap:.6rem;padding:.7rem .9rem;border-radius:10px;color:var(--muted);font-size:.82rem;font-weight:600;transition:all .2s;margin-bottom:.2rem;cursor:pointer;text-decoration:none}
.nav-link:hover,.nav-link.active{background:rgba(201,169,110,.1);color:var(--text);text-decoration:none}
.nav-link.active{color:var(--gold)}
.sidebar-bottom{margin-top:auto}
.main{flex:1;overflow-y:auto;padding:2rem}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
.page-title{font-size:1.5rem;font-weight:700}
.flash{padding:.75rem 1rem;border-radius:10px;margin-bottom:1.2rem;font-size:.85rem;font-weight:600}
.flash.success{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:#86efac}
.flash.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.flash.warning{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);color:#fde68a}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem 1rem;text-align:center}
.stat-num{font-size:2rem;font-weight:700;line-height:1;color:var(--gold)}
.stat-label{font-size:.62rem;text-transform:uppercase;letter-spacing:.15em;color:var(--muted);margin-top:.3rem}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{font-size:.65rem;text-transform:uppercase;letter-spacing:.15em;color:var(--gold);padding:.6rem .8rem;text-align:left;border-bottom:1px solid var(--border)}
td{padding:.65rem .8rem;border-bottom:1px solid rgba(201,169,110,.07);font-size:.85rem;vertical-align:middle}
tr:hover td{background:rgba(255,255,255,.025)}
.badge{display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;padding:.2rem .7rem;font-size:.65rem;font-weight:700;letter-spacing:.08em}
.section-title{font-size:1rem;font-weight:700;color:var(--gold-l);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.form-group{margin-bottom:1rem}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);padding:2rem;width:min(560px,95vw);max-height:90vh;overflow-y:auto}
.modal-title{font-size:1.1rem;font-weight:700;color:var(--gold-l);margin-bottom:1.2rem}
/* Login page */
.login-wrap{min-height:100vh;display:grid;place-items:center;background:var(--bg)}
.login-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:2.5rem;width:min(420px,95vw)}
.login-title{font-size:1.6rem;font-weight:700;color:var(--gold);margin-bottom:.3rem}
.login-sub{color:var(--muted);font-size:.85rem;margin-bottom:1.5rem}
</style>
</head>
<body>
<?php if (!Auth::check()): ?>
<!-- ===== LOGIN PAGE ===== -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-title">🗂 JobTracker</div>
    <div class="login-sub">Connexion au tableau de bord</div>

    <?php if ($flash): ?>
      <div class="flash <?= e($flashType) ?>"><?= $flash ?></div>
    <?php endif ?>

    <?php if ($cfg['app']['admin_password_hash'] === ''): ?>
      <div class="flash warning">Mot de passe non configuré. Générez un hash ci-dessous, copiez-le dans <code>config.php → app.admin_password_hash</code>, puis rechargez.</div>
      <form method="post" style="margin-top:1rem">
        <input type="hidden" name="action" value="gen_hash">
        <div class="form-group">
          <label>Choisir un mot de passe</label>
          <input type="password" name="newpw" required placeholder="Votre mot de passe">
        </div>
        <?php
          $genHash = '';
          if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_hash') {
              $genHash = Auth::hashPassword($_POST['newpw'] ?? '');
          }
        ?>
        <button class="btn btn-gold" type="submit">Générer le hash</button>
        <?php if ($genHash): ?>
          <div style="margin-top:1rem;padding:1rem;background:var(--bg3);border-radius:8px;word-break:break-all;font-family:monospace;font-size:.78rem;color:var(--gold-l)"><?= e($genHash) ?></div>
        <?php endif ?>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label>Mot de passe</label>
          <input type="password" name="password" autofocus required>
        </div>
        <button class="btn btn-gold" type="submit" style="width:100%">Connexion</button>
      </form>
    <?php endif ?>
  </div>
</div>

<?php else: ?>
<!-- ===== DASHBOARD ===== -->
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">🗂 JobTracker</div>
    <a href="?tab=dashboard" class="nav-link <?= $tab==='dashboard'?'active':'' ?>">📊 Tableau de bord</a>
    <a href="?tab=offres"    class="nav-link <?= $tab==='offres'?'active':'' ?>">🔍 Offres</a>
    <a href="?tab=candidatures" class="nav-link <?= $tab==='candidatures'?'active':'' ?>">📨 Candidatures</a>
    <a href="?tab=modeles"   class="nav-link <?= $tab==='modeles'?'active':'' ?>">✉️ Modèles & CV</a>
    <a href="?tab=journal"   class="nav-link <?= $tab==='journal'?'active':'' ?>">📋 Journal</a>
    <div class="sidebar-bottom">
      <form method="post" style="margin-top:1rem">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm" type="submit" style="width:100%">Déconnexion</button>
      </form>
    </div>
  </aside>

  <main class="main">
    <?php if ($flash): ?>
      <div class="flash <?= e($flashType) ?>"><?= $flash ?></div>
    <?php endif ?>

    <?php if (!$dbOk): ?>
      <div class="flash error">Base de données inaccessible. Vérifiez config.php et importez sql/schema.sql.</div>
    <?php endif ?>

    <?php if ($tab === 'dashboard'): ?>
    <!-- ===== DASHBOARD TAB ===== -->
    <div class="page-header"><h1 class="page-title">Tableau de bord</h1></div>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-num"><?= $stats['total'] ?></div><div class="stat-label">Total</div></div>
      <div class="stat-card"><div class="stat-num" style="color:#93c5fd"><?= $stats['envoyee'] ?></div><div class="stat-label">Envoyées</div></div>
      <div class="stat-card"><div class="stat-num" style="color:#facc15"><?= $stats['reponse_recue'] ?></div><div class="stat-label">À traiter</div></div>
      <div class="stat-card"><div class="stat-num" style="color:#4ade80"><?= $stats['entretien'] ?></div><div class="stat-label">Entretiens</div></div>
      <div class="stat-card"><div class="stat-num" style="color:#f87171"><?= $stats['refus'] ?></div><div class="stat-label">Refus</div></div>
      <div class="stat-card"><div class="stat-num" style="color:#22c55e"><?= $stats['acceptee'] ?></div><div class="stat-label">Acceptées</div></div>
    </div>

    <?php if ($dbOk): ?>
    <?php $newOffres = DB::fetchAll("SELECT COUNT(*) as n FROM offres WHERE date_import >= DATE_SUB(NOW(),INTERVAL 24 HOUR)")[0]['n'] ?? 0 ?>
    <?php $reponses  = DB::fetchAll("SELECT COUNT(*) as n FROM candidatures WHERE statut = 'reponse_recue'")[0]['n'] ?? 0 ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="card">
        <div class="section-title">🆕 Dernières offres</div>
        <?php if ($offres): ?>
        <ul style="list-style:none;padding:0">
          <?php foreach (array_slice($offres, 0, 8) as $o): ?>
            <li style="padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.84rem">
              <strong><?= e($o['titre']) ?></strong><br>
              <span style="color:var(--muted)"><?= e($o['entreprise'] ?? '') ?> — <?= e($o['lieu'] ?? '') ?></span>
            </li>
          <?php endforeach ?>
        </ul>
        <?php else: ?><p style="color:var(--muted)">Aucune offre. Lancez la tâche <strong>offres</strong> dans le Journal.</p>
        <?php endif ?>
      </div>
      <div class="card">
        <div class="section-title">📨 Candidatures récentes</div>
        <?php if ($candidatures): ?>
        <ul style="list-style:none;padding:0">
          <?php foreach (array_slice($candidatures, 0, 8) as $c): ?>
            <li style="padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.84rem">
              <strong><?= e($c['titre'] ?? 'Sans titre') ?></strong>
              <?php $sl = $statusLabels[$c['statut']] ?? ['label'=>$c['statut'],'color'=>'#888']; ?>
              <span class="badge" style="background:<?= $sl['color'] ?>22;color:<?= $sl['color'] ?>;border:1px solid <?= $sl['color'] ?>44;float:right"><?= e($sl['label']) ?></span>
            </li>
          <?php endforeach ?>
        </ul>
        <?php else: ?><p style="color:var(--muted)">Aucune candidature.</p>
        <?php endif ?>
      </div>
    </div>
    <?php endif ?>

    <?php elseif ($tab === 'offres'): ?>
    <!-- ===== OFFRES TAB ===== -->
    <div class="page-header">
      <h1 class="page-title">Offres d'emploi</h1>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="run_job">
        <input type="hidden" name="job" value="offres">
        <button class="btn btn-gold btn-sm" type="submit">▶ Rechercher maintenant</button>
      </form>
    </div>
    <div class="card table-wrap">
      <table>
        <thead><tr>
          <th>Titre</th><th>Entreprise</th><th>Lieu</th><th>Source</th><th>Date</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($offres as $o): ?>
          <tr>
            <td><?php if($o['url']): ?><a href="<?= e($o['url']) ?>" target="_blank"><?= e($o['titre']) ?></a><?php else: ?><?= e($o['titre']) ?><?php endif ?></td>
            <td><?= e($o['entreprise'] ?? '') ?></td>
            <td><?= e($o['lieu'] ?? '') ?></td>
            <td><span class="badge" style="background:rgba(201,169,110,.1);color:var(--gold-l);border:1px solid var(--border)"><?= e($o['source']) ?></span></td>
            <td style="white-space:nowrap;color:var(--muted)"><?= e(substr($o['date_import'],0,10)) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="create_cand">
                <input type="hidden" name="offre_id" value="<?= $o['id'] ?>">
                <button class="btn btn-gold btn-sm" type="submit">Postuler</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if (!$offres): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem">Aucune offre. Lancez la recherche.</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'candidatures'): ?>
    <!-- ===== CANDIDATURES TAB ===== -->
    <div class="page-header">
      <h1 class="page-title">Candidatures</h1>
      <button class="btn btn-gold btn-sm" onclick="document.getElementById('modal-add').classList.add('open')">+ Ajouter</button>
    </div>

    <div class="card table-wrap">
      <table>
        <thead><tr>
          <th>Poste</th><th>Entreprise</th><th>Statut</th><th>Recruteur</th><th>Canal</th><th>Date envoi</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($candidatures as $c): ?>
          <?php $sl = $statusLabels[$c['statut']] ?? ['label'=>$c['statut'],'color'=>'#888']; ?>
          <tr>
            <td><?= e($c['titre'] ?? 'N/A') ?></td>
            <td><?= e($c['entreprise'] ?? '') ?></td>
            <td><span class="badge" style="background:<?= $sl['color'] ?>22;color:<?= $sl['color'] ?>;border:1px solid <?= $sl['color'] ?>44"><?= e($sl['label']) ?></span></td>
            <td style="font-size:.8rem"><?= e($c['recruteur_email'] ?? '') ?></td>
            <td style="color:var(--muted)"><?= e($c['canal']) ?></td>
            <td style="white-space:nowrap;color:var(--muted)"><?= $c['date_envoi'] ? e(substr($c['date_envoi'],0,10)) : '–' ?></td>
            <td style="white-space:nowrap">
              <?php if ($c['statut'] === 'brouillon'): ?>
                <button class="btn btn-gold btn-sm"
                  onclick="document.getElementById('send-cand-id').value='<?= $c['id'] ?>';
                           document.getElementById('modal-send').classList.add('open')">Envoyer</button>
              <?php endif ?>
              <button class="btn btn-ghost btn-sm"
                onclick="document.getElementById('status-cand-id').value='<?= $c['id'] ?>';
                         document.getElementById('modal-status').classList.add('open')">Statut</button>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if (!$candidatures): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:2rem">Aucune candidature.</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>

    <!-- Modal: Send candidature -->
    <div class="modal-overlay" id="modal-send" onclick="if(event.target===this)this.classList.remove('open')">
      <div class="modal">
        <div class="modal-title">Envoyer la candidature</div>
        <form method="post">
          <input type="hidden" name="action" value="send_cand">
          <input type="hidden" name="cand_id" id="send-cand-id" value="">
          <div class="form-group">
            <label>Email du recruteur *</label>
            <input type="email" name="recruteur_email" required placeholder="recruteur@entreprise.fr">
          </div>
          <div style="display:flex;gap:.7rem;margin-top:1rem">
            <button class="btn btn-gold" type="submit">Envoyer</button>
            <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-send').classList.remove('open')">Annuler</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Update status -->
    <div class="modal-overlay" id="modal-status" onclick="if(event.target===this)this.classList.remove('open')">
      <div class="modal">
        <div class="modal-title">Changer le statut</div>
        <form method="post">
          <input type="hidden" name="action" value="update_statut">
          <input type="hidden" name="cand_id" id="status-cand-id" value="">
          <div class="form-group">
            <label>Nouveau statut</label>
            <select name="statut">
              <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v['label']) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div style="display:flex;gap:.7rem;margin-top:1rem">
            <button class="btn btn-gold" type="submit">Enregistrer</button>
            <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-status').classList.remove('open')">Annuler</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Add manual candidature -->
    <div class="modal-overlay" id="modal-add" onclick="if(event.target===this)this.classList.remove('open')">
      <div class="modal">
        <div class="modal-title">Ajouter une candidature manuelle</div>
        <form method="post">
          <input type="hidden" name="action" value="add_cand_manuel">
          <div class="form-row">
            <div class="form-group">
              <label>Poste *</label>
              <input type="text" name="titre" required placeholder="Titre du poste">
            </div>
            <div class="form-group">
              <label>Entreprise</label>
              <input type="text" name="entreprise" placeholder="Nom de l'entreprise">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Email recruteur</label>
              <input type="email" name="recruteur_email" placeholder="recruteur@entreprise.fr">
            </div>
            <div class="form-group">
              <label>Canal</label>
              <select name="canal">
                <option value="email">Email</option>
                <option value="linkedin">LinkedIn</option>
                <option value="indeed">Indeed</option>
                <option value="manuel">Autre</option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:.7rem;margin-top:1rem">
            <button class="btn btn-gold" type="submit">Ajouter</button>
            <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-add').classList.remove('open')">Annuler</button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($tab === 'modeles'): ?>
    <!-- ===== MODÈLES & CV TAB ===== -->
    <div class="page-header"><h1 class="page-title">Modèles & CV</h1></div>

    <div class="card" style="margin-bottom:1.5rem">
      <div class="section-title">📄 CV (PDF)</div>
      <?php if ($cvExists): ?>
        <p style="color:#86efac;margin-bottom:.8rem">✅ CV téléversé</p>
      <?php else: ?>
        <p style="color:#fca5a5;margin-bottom:.8rem">⚠️ Aucun CV téléversé</p>
      <?php endif ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_cv">
        <div style="display:flex;gap:.7rem;align-items:center">
          <input type="file" name="cv" accept="application/pdf" style="flex:1">
          <button class="btn btn-gold" type="submit">Téléverser</button>
        </div>
      </form>
    </div>

    <?php foreach ($templates as $t): ?>
    <div class="card" style="margin-bottom:1.2rem">
      <div class="section-title"><?= e(ucfirst($t['nom'])) ?></div>
      <form method="post">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="tmpl_id" value="<?= $t['id'] ?>">
        <div class="form-group">
          <label>Sujet</label>
          <input type="text" name="sujet" value="<?= e($t['sujet']) ?>" required>
        </div>
        <div class="form-group">
          <label>Corps (HTML accepté) · Variables : {titre} {entreprise} {nom} {telephone} {linkedin} {date_envoi}</label>
          <textarea name="corps" rows="8" required><?= e($t['corps']) ?></textarea>
        </div>
        <button class="btn btn-gold btn-sm" type="submit">Sauvegarder</button>
      </form>
    </div>
    <?php endforeach ?>

    <?php elseif ($tab === 'journal'): ?>
    <!-- ===== JOURNAL TAB ===== -->
    <div class="page-header"><h1 class="page-title">Journal</h1></div>

    <div style="display:flex;gap:.7rem;margin-bottom:1.5rem;flex-wrap:wrap">
      <?php foreach (['offres','inbox','relances','digest'] as $j): ?>
        <form method="post">
          <input type="hidden" name="action" value="run_job">
          <input type="hidden" name="job" value="<?= $j ?>">
          <button class="btn btn-ghost btn-sm" type="submit">▶ <?= ucfirst($j) ?></button>
        </form>
      <?php endforeach ?>
    </div>

    <div class="card table-wrap">
      <table>
        <thead><tr>
          <th>Date</th><th>Niveau</th><th>Tâche</th><th>Message</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <?php
          $lc = match($l['level']) {
            'error'   => '#fca5a5',
            'warning' => '#fde68a',
            'info'    => '#93c5fd',
            default   => '#8b91a5',
          };
          ?>
          <tr>
            <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= e($l['created_at']) ?></td>
            <td><span class="badge" style="background:<?= $lc ?>22;color:<?= $lc ?>;border:1px solid <?= $lc ?>44"><?= e($l['level']) ?></span></td>
            <td style="color:var(--gold);font-size:.82rem"><?= e($l['job']) ?></td>
            <td style="font-size:.82rem"><?= e($l['message']) ?></td>
          </tr>
        <?php endforeach ?>
        <?php if (!$logs): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:2rem">Aucun log. Lancez une tâche ci-dessus.</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>

    <?php endif ?>

  </main>
</div>
<?php endif ?>

</body>
</html>
