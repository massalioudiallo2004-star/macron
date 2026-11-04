<?php
// ======================================================
// CONFIGURATION & INITIALISATION
// ======================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!file_exists('uploads')) mkdir('uploads', 0777, true);
if (!file_exists('metadata.json')) file_put_contents('metadata.json', '{}');
if (!file_exists('categories.json')) {
    file_put_contents('categories.json', json_encode([
        'Huile'            => '🛢️',
        'Riz'              => '🍚',
        'Soda / Sucreries' => '🥤',
        'Ciment'           => '🧱',
        'Tôle'             => '🏗️',
        'Carton'           => '📦'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$metadata   = json_decode(file_get_contents('metadata.json'), true) ?: [];
$categories = json_decode(file_get_contents('categories.json'), true) ?: [];

// ======================================================
// SUPPRESSION AUTOMATIQUE DES AUDIOS DE PLUS DE 3 SEMAINES
// ======================================================
$troisSemaines = 21 * 24 * 60 * 60;
$modifie = false;

foreach ($metadata as $photoName => &$data) {
    if (isset($data['audios']) && is_array($data['audios'])) {
        $nouveaux = [];
        foreach ($data['audios'] as $item) {
            $date = DateTime::createFromFormat('d/m/Y à H:i', $item['date'] ?? '');
            if ($date && (time() - $date->getTimestamp()) > $troisSemaines) {
                @unlink('uploads/' . ($item['fichier'] ?? ''));
                $modifie = true;
            } else {
                $nouveaux[] = $item;
            }
        }
        $data['audios'] = $nouveaux;
    }
}
unset($data);

if ($modifie) {
    file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ======================================================
// AJOUTER UNE NOUVELLE CATÉGORIE
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $nom = trim($_POST['nom_categorie'] ?? '');
    $icon = trim($_POST['icon_categorie'] ?? '📁');
    if ($nom !== '' && !isset($categories[$nom])) {
        $categories[$nom] = $icon ?: '📁';
        file_put_contents('categories.json', json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php');
    exit;
}

// ======================================================
// SUPPRIMER PLUSIEURS PHOTOS
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'delete_multiple') {
    $photosToDelete = $_POST['photos'] ?? [];
    if (is_array($photosToDelete)) {
        foreach ($photosToDelete as $photo) {
            $photo = basename($photo);
            @unlink('uploads/' . $photo);
            if (isset($metadata[$photo]['audios'])) {
                foreach ($metadata[$photo]['audios'] as $a) @unlink('uploads/' . $a['fichier']);
            }
            unset($metadata[$photo]);
        }
        file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php');
    exit;
}

// ======================================================
// SUPPRIMER PLUSIEURS NOTES OU AUDIOS
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'delete_multiple_media') {
    $photo = basename($_POST['photo'] ?? '');
    $type  = $_POST['type'] ?? '';
    $indexes = $_POST['indexes'] ?? [];

    if ($photo && isset($metadata[$photo]) && is_array($indexes)) {
        rsort($indexes);
        foreach ($indexes as $i) {
            $i = (int)$i;
            if ($type === 'notes' && isset($metadata[$photo]['notes'][$i])) {
                array_splice($metadata[$photo]['notes'], $i, 1);
            }
            if ($type === 'audios' && isset($metadata[$photo]['audios'][$i])) {
                @unlink('uploads/' . $metadata[$photo]['audios'][$i]['fichier']);
                array_splice($metadata[$photo]['audios'], $i, 1);
            }
        }
        file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php?photo=' . urlencode($photo));
    exit;
}

// ======================================================
// UPLOAD D'UNE PHOTO
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && empty($_POST['action'])) {
    $f = $_FILES['photo'];
    if ($f['error'] === 0 && $f['size'] > 0) {
        $nom = date('Ymd_His') . '_' . uniqid() . '.jpg';
        if (move_uploaded_file($f['tmp_name'], 'uploads/' . $nom)) {
            header('Location: index.php?assign=' . urlencode($nom));
            exit;
        }
    }
    header('Location: index.php');
    exit;
}

// ======================================================
// ASSIGNER UNE CATÉGORIE
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'assign_category') {
    $photo = basename($_POST['photo']);
    $cat   = $_POST['category'] ?? '';
    if (isset($categories[$cat]) && file_exists('uploads/' . $photo)) {
        if (!isset($metadata[$photo])) {
            $metadata[$photo] = ['notes' => [], 'audios' => []];
        }
        $metadata[$photo]['category'] = $cat;
        file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php');
    exit;
}

// ======================================================
// AJOUTER UNE NOTE
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $photo = basename($_POST['photo']);
    $texte = mb_substr(trim($_POST['texte'] ?? ''), 0, 20);
    if ($texte !== '') {
        if (!isset($metadata[$photo])) $metadata[$photo] = ['notes' => [], 'audios' => []];
        if (!isset($metadata[$photo]['notes'])) $metadata[$photo]['notes'] = [];
        $metadata[$photo]['notes'][] = [
            'texte' => $texte,
            'date'  => date('d/m/Y à H:i')
        ];
        file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php?photo=' . urlencode($photo));
    exit;
}

// ======================================================
// AJOUTER UN AUDIO
// ======================================================
if (isset($_POST['action']) && $_POST['action'] === 'add_audio') {
    $photo = basename($_POST['photo']);
    if (!isset($metadata[$photo]['audios'])) $metadata[$photo]['audios'] = [];

    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === 0 && $_FILES['audio']['size'] > 0) {
        $nomAudio = pathinfo($photo, PATHINFO_FILENAME) . '_' . time() . '.webm';
        move_uploaded_file($_FILES['audio']['tmp_name'], 'uploads/' . $nomAudio);
        $metadata[$photo]['audios'][] = [
            'fichier' => $nomAudio,
            'date'    => date('d/m/Y à H:i')
        ];
        file_put_contents('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    header('Location: index.php?photo=' . urlencode($photo));
    exit;
}

// ======================================================
// RÉCUPÉRATION DES PHOTOS
// ======================================================
$photos = array_merge(
    glob('uploads/*.jpg') ?: [],
    glob('uploads/*.jpeg') ?: [],
    glob('uploads/*.png') ?: [],
    glob('uploads/*.webp') ?: []
);
rsort($photos);

$photosParCategorie = [];
foreach ($categories as $cat => $icon) {
    $photosParCategorie[$cat] = [];
}
$photosParCategorie['Non classé'] = [];

foreach ($photos as $p) {
    $n = basename($p);
    $cat = $metadata[$n]['category'] ?? 'Non classé';
    if (!isset($photosParCategorie[$cat])) {
        $photosParCategorie[$cat] = [];
    }
    $photosParCategorie[$cat][] = $n;
}

$photo  = isset($_GET['photo']) ? basename($_GET['photo']) : null;
$assign = isset($_GET['assign']) ? basename($_GET['assign']) : null;

$meta = ($photo && isset($metadata[$photo])) ? $metadata[$photo] : ['notes' => [], 'audios' => []];
if (!isset($meta['notes']))  $meta['notes']  = [];
if (!isset($meta['audios'])) $meta['audios'] = [];

if (!empty($meta['notes']))  $meta['notes']  = array_reverse($meta['notes']);
if (!empty($meta['audios'])) $meta['audios'] = array_reverse($meta['audios']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Mes Photos</title>
<style>
    :root {
        --primary: #00a884;
        --primary-dark: #008f72;
        --topbar: #1f2c34;
        --bg: #0b141a;
        --text: #e9edef;
        --card: #1f2c34;
        --input: #2a3942;
    }

    * { margin:0; padding:0; box-sizing:border-box; }
    body { 
        font-family: -apple-system, BlinkMacSystemFont, sans-serif; 
        background: var(--bg); 
        color: var(--text); 
        transition: background 0.3s, color 0.3s;
    }

    .top {
        position:fixed; top:0; left:0; right:0; height:56px;
        background: var(--topbar);
        display:flex; align-items:center; justify-content:space-between;
        padding:0 12px; z-index:10;
        transition: background 0.3s;
    }

    .top-left { display:flex; align-items:center; gap:10px; }
    
    /* Centre : uniquement les 2 icônes */
    .top-center {
        display:flex;
        align-items:center;
        gap:18px;
        position:absolute;
        left:50%;
        transform:translateX(-50%);
    }
    
    .top-right { display:flex; align-items:center; gap:8px; }

    .btn-hamburger {
        width:40px; height:40px; border-radius:12px;
        background:#2F2F2F; border:1px solid #2F2F2F;
        display:flex; flex-direction:column;
        align-items:center; justify-content:center; gap:5px;
    }
    .btn-hamburger span {
        display:block; width:17px; height:1px;
        background:white; border-radius:1px;
    }

    /* Icônes du centre */
    .icon-btn {
        width:40px;
        height:40px;
        border-radius:50%;
        border:none;
        background:rgba(255,255,255,0.12);
        color:white;
        font-size:20px;
        display:flex;
        align-items:center;
        justify-content:center;
    }

    .btn-add-cat-top {
        padding:7px 11px; border-radius:18px;
        background:transparent; border:1px solid var(--primary);
        color:var(--primary); font-size:13px;
    }
    
    .btn-plus {
        width:40px; height:40px; border-radius:50%;
        background:var(--primary); border:none; color:white; font-size:22px;
    }

    /* Menu latéral */
    .side-menu {
        display:none; position:fixed; top:64px; left:12px;
        width:240px; background:var(--card); border-radius:16px;
        overflow:hidden; z-index:30;
        box-shadow:0 12px 40px rgba(0,0,0,0.5);
    }
    .side-menu.show { display:block; }

    .side-menu a {
        display:flex; align-items:center; gap:14px;
        width:100%; padding:16px 20px;
        background:none; border:none;
        color:var(--text); font-size:16px; font-weight:500;
        text-align:left; text-decoration:none;
        border-bottom:1px solid rgba(255,255,255,0.06);
    }
    .side-menu a:last-child { border-bottom:none; }
    .side-menu a:active { background:rgba(255,255,255,0.08); }
    .side-menu .icon { font-size:22px; width:28px; text-align:center; }

    /* Menu photo */
    .menu {
        display:none; position:fixed; top:64px; right:12px;
        background:var(--card); border-radius:14px; overflow:hidden;
        z-index:20; min-width:170px;
        box-shadow:0 8px 24px rgba(0,0,0,.4);
    }
    .menu.show { display:block; }
    .menu button {
        display:flex; align-items:center; gap:12px; width:100%;
        padding:14px 18px; background:none; border:none;
        color:var(--text); font-size:16px; text-align:left;
    }
    .menu button:active { background:rgba(255,255,255,0.08); }

    .overlay {
        display:none; position:fixed; inset:0;
        background:rgba(0,0,0,.45); z-index:15;
    }
    .overlay.show { display:block; }

    /* ===== PERSONNALISATION DES COULEURS ===== */
    .color-modal {
        display:none;
        position:fixed; inset:0;
        background:rgba(0,0,0,0.7);
        z-index:100;
        align-items:center; justify-content:center;
        padding:20px;
    }
    .color-modal.show { display:flex; }

    .color-box {
        background:var(--card);
        border-radius:20px;
        width:100%; max-width:340px;
        padding:20px;
        max-height:85vh;
        overflow-y:auto;
    }
    .color-box h3 {
        text-align:center;
        margin-bottom:18px;
        font-size:18px;
        color:var(--text);
    }
    .color-grid {
        display:grid;
        grid-template-columns:repeat(3, 1fr);
        gap:14px;
    }
    .color-item {
        aspect-ratio:1;
        border-radius:16px;
        border:3px solid transparent;
        cursor:pointer;
        position:relative;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:6px;
        transition:0.2s;
    }
    .color-item.selected {
        border-color:white;
        transform:scale(1.05);
    }
    .color-item .check {
        position:absolute;
        top:6px; right:6px;
        width:20px; height:20px;
        background:white;
        color:#000;
        border-radius:50%;
        font-size:12px;
        display:none;
        align-items:center;
        justify-content:center;
    }
    .color-item.selected .check { display:flex; }
    .color-item span {
        font-size:12px;
        color:white;
        text-align:center;
        font-weight:500;
        text-shadow:0 1px 3px rgba(0,0,0,0.6);
    }

    .btn-close-color {
        width:100%;
        margin-top:18px;
        padding:14px;
        border:none;
        border-radius:12px;
        background:var(--primary);
        color:white;
        font-size:16px;
        font-weight:600;
    }

    /* Contenu */
    .content { padding:70px 12px 90px; }

    .categorie-titre {
        font-size:17px; font-weight:600;
        color:var(--primary);
        margin:24px 0 12px 4px;
        display:flex; align-items:center; gap:8px;
    }

    .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .item {
        aspect-ratio:1; border-radius:12px; overflow:hidden;
        position:relative; background:var(--card);
    }
    .item img { width:100%; height:100%; object-fit:cover; display:block; }

    .last-note {
        position:absolute; top:8px; right:8px;
        background:var(--primary);
        color:white; font-size:15px; font-weight:700;
        padding:6px 10px; border-radius:8px;
        max-width:75%; text-align:center;
        box-shadow:0 2px 8px rgba(0,0,0,0.4);
        pointer-events:none; z-index:4;
    }

    .item .check {
        display:none; position:absolute; top:8px; left:8px;
        width:26px; height:26px; border-radius:50%;
        background:rgba(0,0,0,0.5); border:2px solid white;
        align-items:center; justify-content:center;
        color:white; font-size:16px; z-index:5;
    }
    body.select-mode .item .check { display:flex; }
    body.select-mode .item.selected .check {
        background:var(--primary); border-color:var(--primary);
    }
    body.select-mode .last-note { display:none; }

    .bar-selection {
        display:none; position:fixed; bottom:0; left:0; right:0;
        background:var(--card); padding:14px 16px; z-index:40;
        box-shadow:0 -4px 20px rgba(0,0,0,0.4); gap:12px;
    }
    body.select-mode .bar-selection { display:flex; }
    .bar-selection button {
        flex:1; padding:14px; border:none; border-radius:10px;
        font-size:15px; font-weight:500;
    }
    .btn-cancel-sel { background:var(--input); color:var(--text); }
    .btn-delete-sel { background:#e74c3c; color:white; }

    .form-cat {
        background:var(--card); border-radius:14px;
        padding:16px; margin-bottom:20px; display:none;
    }
    .form-cat.show { display:block; }
    .form-cat input {
        width:100%; padding:12px; border:none; border-radius:8px;
        background:var(--input); color:var(--text); font-size:16px; margin-bottom:10px;
    }
    .form-cat button {
        width:100%; padding:12px; border:none; border-radius:8px;
        background:var(--primary); color:white; font-size:15px;
    }

    /* Écran catégorie */
    .assign-screen {
        position:fixed; inset:0; background:var(--bg); z-index:50;
        display:flex; flex-direction:column;
    }
    .assign-top {
        height:56px; background:var(--topbar);
        display:flex; align-items:center; padding:0 16px;
        font-size:17px; font-weight:500;
    }
    .assign-preview { padding:20px; text-align:center; }
    .assign-preview img {
        max-width:100%; max-height:180px;
        border-radius:14px; object-fit:contain; background:#000;
    }
    .assign-title {
        text-align:center; color:#8696a0;
        font-size:14px; margin-bottom:16px;
    }
    .cat-grid {
        display:grid; grid-template-columns:1fr 1fr;
        gap:12px; padding:0 16px 30px; overflow-y:auto; flex:1;
    }
    .cat-card {
        background:var(--card); border-radius:16px;
        padding:18px 10px; text-align:center;
        border:none; color:var(--text); font-size:15px;
        display:flex; flex-direction:column;
        align-items:center; gap:10px;
    }
    .cat-card:active { background:var(--input); }
    .cat-icon { font-size:36px; }

    /* Détail */
    .detail {
        display:none; position:fixed; inset:0;
        background:var(--bg); z-index:20; overflow-y:auto;
    }
    .detail.open { display:block; }

    .detail-top {
        position:sticky; top:0; height:52px;
        background:var(--topbar);
        display:flex; align-items:center; padding:0 12px; gap:12px; z-index:5;
    }
    .detail-top a { color:white; font-size:28px; text-decoration:none; }

    .photo-wrap { padding:16px; }
    .photo-wrap img {
        width:100%; max-height:40vh; object-fit:contain;
        border-radius:12px; background:#000; display:block;
    }

    .detail-body {
        padding:0 16px 110px;
        display:flex; flex-direction:column; gap:12px;
    }

    .box { background:var(--card); border-radius:14px; padding:12px; }
    .box-title { font-size:13px; color:#8696a0; margin-bottom:8px; }

    input[type=text] {
        width:100%; padding:11px; border:none; border-radius:8px;
        background:var(--input); color:var(--text); font-size:16px;
    }
    .char { text-align:right; font-size:12px; color:#8696a0; margin-top:4px; }

    .note-item, .audio-item {
        background:var(--input); border-radius:10px;
        padding:8px 10px; margin-bottom:8px; position:relative;
    }
    .note-item.selected, .audio-item.selected {
        outline:2px solid var(--primary);
    }

    .note-date, .audio-date {
        font-size:15px; font-weight:600;
        color:var(--primary); margin-bottom:2px;
    }
    .note-texte { font-size:15px; }

    .media-row { display:flex; align-items:center; gap:8px; }
    .media-row audio { flex:1; max-width:100%; }

    .media-check {
        display:none; position:absolute; top:10px; left:10px;
        width:24px; height:24px; border-radius:50%;
        background:rgba(0,0,0,0.5); border:2px solid white;
        color:white; font-size:14px;
        align-items:center; justify-content:center; z-index:5;
    }
    body.media-select-mode .media-check { display:flex; }
    body.media-select-mode .note-item.selected .media-check,
    body.media-select-mode .audio-item.selected .media-check {
        background:var(--primary); border-color:var(--primary);
    }

    .bar-media-selection {
        display:none; position:fixed; bottom:0; left:0; right:0;
        background:var(--card); padding:14px 16px; z-index:50;
        box-shadow:0 -4px 20px rgba(0,0,0,0.4); gap:12px;
    }
    body.media-select-mode .bar-media-selection { display:flex; }
    .bar-media-selection button {
        flex:1; padding:14px; border:none; border-radius:10px;
        font-size:15px; font-weight:500;
    }

    .fab-audio {
        position:fixed; bottom:24px; right:20px;
        width:58px; height:58px; border-radius:50%;
        background:var(--primary); border:none; color:white;
        font-size:36px; box-shadow:0 4px 12px rgba(0,0,0,0.3);
        z-index:30; display:flex; align-items:center; justify-content:center;
    }
    .fab-audio.recording { background:#e74c3c; }

    .timer-float {
        position:fixed; bottom:90px; left:50%; transform:translateX(-50%);
        background:var(--card); color:var(--primary); font-size:18px;
        padding:6px 14px; border-radius:20px; display:none; z-index:30;
    }
    .timer-float.show { display:block; }
</style>
</head>
<body>

<?php if ($assign && file_exists('uploads/' . $assign)): ?>
<!-- ÉCRAN CHOIX CATÉGORIE -->
<div class="assign-screen">
    <div class="assign-top">Choisir une catégorie</div>
    <div class="assign-preview">
        <img src="uploads/<?= htmlspecialchars($assign) ?>">
    </div>
    <div class="assign-title">Dans quelle catégorie mettre cette photo ?</div>
    <form method="POST" class="cat-grid">
        <input type="hidden" name="action" value="assign_category">
        <input type="hidden" name="photo" value="<?= htmlspecialchars($assign) ?>">
        <?php foreach ($categories as $nom => $icon): ?>
        <button type="submit" name="category" value="<?= htmlspecialchars($nom) ?>" class="cat-card">
            <div class="cat-icon"><?= $icon ?></div>
            <div><?= htmlspecialchars($nom) ?></div>
        </button>
        <?php endforeach; ?>
    </form>
</div>

<?php else: ?>

<!-- PAGE PRINCIPALE -->
<div class="top">
    <!-- Gauche : Hamburger -->
    <div class="top-left">
        <button class="btn-hamburger" onclick="toggleSideMenu()">
            <span></span><span></span><span></span>
        </button>
    </div>

    <!-- CENTRE : uniquement les 2 icônes -->
    <div class="top-center">
        <button class="icon-btn" onclick="openColorModal()" title="Couleurs">🎨</button>
        <button class="icon-btn" onclick="toggleBackground()" title="Fond Noir/Blanc" id="bgBtn">🌓</button>
    </div>

    <!-- Droite -->
    <div class="top-right">
        <button class="btn-add-cat-top" onclick="document.getElementById('formCat').classList.toggle('show')">＋C</button>
        <button class="btn-plus" onclick="togglePhotoMenu()">+</button>
    </div>
</div>

<div class="overlay" id="overlay" onclick="closeAllMenus()"></div>

<!-- Menu Caméra / Galerie (tes anciennes icônes) -->
<div class="menu" id="photoMenu">
    <button type="button" onclick="openCam()">
        <img src="icons/galerie2.png" style="width:22px;height:22px;vertical-align:middle;margin-right:8px"> Caméra
    </button>
    <button type="button" onclick="openGal()">
        <img src="icons/galerie1.png" style="width:22px;height:22px;vertical-align:middle;margin-right:8px"> Galerie
    </button>
</div>

<!-- Menu latéral (sans Couleurs) -->
<div class="side-menu" id="sideMenu">
    <a href="depense.php"><span class="icon">💸</span> Dépenses</a>
    <a href="client.php"><span class="icon">👥</span> Clients</a>
    <a href="credit.php"><span class="icon">🏦</span> Crédit</a>
</div>

<!-- MODAL COULEURS -->
<div class="color-modal" id="colorModal">
    <div class="color-box">
        <h3>🎨 Personnalisation des couleurs</h3>
        <div class="color-grid" id="colorGrid"></div>
        <button class="btn-close-color" onclick="closeColorModal()">Fermer</button>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" id="formPhoto" style="display:none">
    <input type="file" name="photo" id="cam" accept="image/*" capture="environment">
    <input type="file" name="photo" id="gal" accept="image/*">
</form>

<form method="POST" id="formDeleteMultiple">
    <input type="hidden" name="action" value="delete_multiple">
    <div class="content">
        <div class="form-cat" id="formCat">
            <input type="text" name="nom_categorie" form="formAddCat" placeholder="Nom de la catégorie" required>
            <input type="text" name="icon_categorie" form="formAddCat" placeholder="Emoji (ex: 🌾)" maxlength="2">
            <button type="submit" form="formAddCat">Ajouter la catégorie</button>
        </div>

        <?php
        $hasPhotos = false;
        foreach ($photosParCategorie as $liste) {
            if (!empty($liste)) { $hasPhotos = true; break; }
        }
        ?>

        <?php if (!$hasPhotos): ?>
            <p style="text-align:center;color:#8696a0;margin-top:30px">Aucune photo</p>
        <?php else: ?>
            <?php foreach ($photosParCategorie as $cat => $liste): ?>
                <?php if (empty($liste)) continue; ?>
                <div class="categorie-titre">
                    <?= $categories[$cat] ?? '📁' ?> <?= htmlspecialchars($cat) ?>
                    <span style="color:#8696a0;font-weight:400;font-size:14px">(<?= count($liste) ?>)</span>
                </div>
                <div class="grid">
                    <?php foreach ($liste as $n): 
                        $derniereNote = '';
                        if (!empty($metadata[$n]['notes'])) {
                            $derniereNote = end($metadata[$n]['notes'])['texte'] ?? '';
                        }
                    ?>
                    <div class="item" data-photo="<?= htmlspecialchars($n) ?>"
                         onmousedown="startPhotoLongPress(this)" onmouseup="cancelPhotoLongPress()" onmouseleave="cancelPhotoLongPress()"
                         ontouchstart="startPhotoLongPress(this)" ontouchend="cancelPhotoLongPress()" ontouchcancel="cancelPhotoLongPress()">
                        <div class="check">✓</div>
                        <a href="?photo=<?= urlencode($n) ?>" class="photo-link">
                            <img src="uploads/<?= htmlspecialchars($n) ?>">
                        </a>
                        <?php if ($derniereNote): ?>
                            <div class="last-note"><?= htmlspecialchars($derniereNote) ?></div>
                        <?php endif; ?>
                        <input type="checkbox" name="photos[]" value="<?= htmlspecialchars($n) ?>" style="display:none" class="photo-check">
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="bar-selection">
        <button type="button" class="btn-cancel-sel" onclick="exitPhotoSelectMode()">Annuler</button>
        <button type="submit" class="btn-delete-sel" onclick="return confirm('Supprimer les photos sélectionnées ?')">Supprimer la sélection</button>
    </div>
</form>

<form method="POST" id="formAddCat" style="display:none">
    <input type="hidden" name="action" value="add_category">
</form>

<!-- DÉTAIL -->
<div class="detail <?= $photo ? 'open' : '' ?>">
    <div class="detail-top">
        <a href="index.php">←</a>
        <span>Photo</span>
    </div>

    <?php if ($photo): ?>
    <div class="photo-wrap">
        <img src="uploads/<?= htmlspecialchars($photo) ?>">
    </div>

    <div class="detail-body">
        <form method="POST" class="box">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="photo" value="<?= htmlspecialchars($photo) ?>">
            <div class="box-title">Nouvelle note (20 caractères)</div>
            <input type="text" name="texte" maxlength="20" placeholder="Écrire ici..."
                   oninput="document.getElementById('c').textContent=this.value.length">
            <div class="char"><span id="c">0</span>/20</div>
            <button type="submit" style="margin-top:10px;width:100%;padding:12px;border:none;border-radius:8px;background:var(--primary);color:white;font-size:15px">
                Ajouter la note
            </button>
        </form>

        <div class="box">
            <div class="box-title">Notes</div>
            <?php if (empty($meta['notes'])): ?>
                <p style="color:#8696a0;font-size:14px">Aucune note</p>
            <?php else: ?>
                <?php foreach ($meta['notes'] as $i => $n): 
                    $originalIndex = count($metadata[$photo]['notes']) - 1 - $i;
                ?>
                <div class="note-item" data-type="notes" data-index="<?= $originalIndex ?>"
                     onmousedown="startLongPress(this)" onmouseup="cancelLongPress()" onmouseleave="cancelLongPress()"
                     ontouchstart="startLongPress(this)" ontouchend="cancelLongPress()" ontouchcancel="cancelLongPress()">
                    <div class="media-check">✓</div>
                    <div class="note-date"><?= htmlspecialchars($n['date']) ?></div>
                    <div class="note-texte"><?= htmlspecialchars($n['texte']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="box">
            <div class="box-title">Vocaux</div>
            <?php if (empty($meta['audios'])): ?>
                <p style="color:#8696a0;font-size:14px">Aucun vocal</p>
            <?php else: ?>
                <?php foreach ($meta['audios'] as $i => $a): 
                    $originalIndex = count($metadata[$photo]['audios']) - 1 - $i;
                ?>
                <div class="audio-item" data-type="audios" data-index="<?= $originalIndex ?>"
                     onmousedown="startLongPress(this)" onmouseup="cancelLongPress()" onmouseleave="cancelLongPress()"
                     ontouchstart="startLongPress(this)" ontouchend="cancelLongPress()" ontouchcancel="cancelLongPress()">
                    <div class="media-check">✓</div>
                    <div class="audio-date"><?= htmlspecialchars($a['date']) ?></div>
                    <div class="media-row">
                        <audio controls src="uploads/<?= htmlspecialchars($a['fichier']) ?>"></audio>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="bar-media-selection">
        <button type="button" class="btn-cancel-sel" onclick="exitMediaSelectMode()">Annuler</button>
        <button type="button" class="btn-delete-sel" onclick="deleteSelectedMedia()">Supprimer la sélection</button>
    </div>

    <div class="timer-float" id="timerFloat">00:00</div>
    <button class="fab-audio" id="fabAudio" onclick="toggleAudio()">
        <img src="icons/vol.png" style="width:50px;height:50px" id="iconAudio">
    </button>

    <form method="POST" enctype="multipart/form-data" id="formAudio" style="display:none">
        <input type="hidden" name="action" value="add_audio">
        <input type="hidden" name="photo" value="<?= htmlspecialchars($photo) ?>">
        <input type="file" name="audio" id="audioInput" accept="audio/*">
    </form>

    <form method="POST" id="formDeleteMedia" style="display:none">
        <input type="hidden" name="action" value="delete_multiple_media">
        <input type="hidden" name="photo" value="<?= htmlspecialchars($photo) ?>">
        <input type="hidden" name="type" id="deleteType">
        <div id="deleteIndexes"></div>
    </form>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
// ======================================================
// COULEURS DU THÈME
// ======================================================
const colors = [
    { name: 'Vert Émeraude', value: '#00a884', top: '#1f2c34' },
    { name: 'Rouge Passion', value: '#e74c3c', top: '#2c1a1a' },
    { name: 'Bleu Océan', value: '#3498db', top: '#1a2a3c' },
    { name: 'Violet Royal', value: '#9b59b6', top: '#2a1a3c' },
    { name: 'Orange Sunset', value: '#e67e22', top: '#3c2a1a' },
    { name: 'Rose Magnolia', value: '#e84393', top: '#3c1a2a' },
    { name: 'Cyan Océan', value: '#00cec9', top: '#1a2c2c' },
    { name: 'Indigo Profond', value: '#6c5ce7', top: '#1a1a3c' },
    { name: 'Teal Moderne', value: '#00b894', top: '#1a2c28' },
];

function openColorModal() {
    closeAllMenus();
    const grid = document.getElementById('colorGrid');
    grid.innerHTML = '';
    
    const current = localStorage.getItem('themeColor') || '#00a884';
    
    colors.forEach(c => {
        const div = document.createElement('div');
        div.className = 'color-item' + (c.value === current ? ' selected' : '');
        div.style.background = c.value;
        div.innerHTML = `<div class="check">✓</div><span>${c.name}</span>`;
        div.onclick = () => selectColor(c);
        grid.appendChild(div);
    });
    
    document.getElementById('colorModal').classList.add('show');
}

function selectColor(color) {
    document.documentElement.style.setProperty('--primary', color.value);
    document.documentElement.style.setProperty('--topbar', color.top);
    localStorage.setItem('themeColor', color.value);
    localStorage.setItem('themeTop', color.top);
    
    document.querySelectorAll('.color-item').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

function closeColorModal() {
    document.getElementById('colorModal').classList.remove('show');
}

// ======================================================
// FOND NOIR / BLANC
// ======================================================
function toggleBackground() {
    const isLight = document.body.classList.toggle('light-mode');
    
    if (isLight) {
        document.documentElement.style.setProperty('--bg', '#f0f2f5');
        document.documentElement.style.setProperty('--text', '#111b21');
        document.documentElement.style.setProperty('--card', '#ffffff');
        document.documentElement.style.setProperty('--input', '#e9edef');
        document.getElementById('bgBtn').textContent = '🌙';
        localStorage.setItem('bgMode', 'light');
    } else {
        document.documentElement.style.setProperty('--bg', '#0b141a');
        document.documentElement.style.setProperty('--text', '#e9edef');
        document.documentElement.style.setProperty('--card', '#1f2c34');
        document.documentElement.style.setProperty('--input', '#2a3942');
        document.getElementById('bgBtn').textContent = '🌓';
        localStorage.setItem('bgMode', 'dark');
    }
}

// Charger les préférences sauvegardées
(function() {
    const savedColor = localStorage.getItem('themeColor');
    const savedTop = localStorage.getItem('themeTop');
    if (savedColor) {
        document.documentElement.style.setProperty('--primary', savedColor);
        if (savedTop) document.documentElement.style.setProperty('--topbar', savedTop);
    }
    
    if (localStorage.getItem('bgMode') === 'light') {
        document.body.classList.add('light-mode');
        document.documentElement.style.setProperty('--bg', '#f0f2f5');
        document.documentElement.style.setProperty('--text', '#111b21');
        document.documentElement.style.setProperty('--card', '#ffffff');
        document.documentElement.style.setProperty('--input', '#e9edef');
        document.getElementById('bgBtn').textContent = '🌙';
    }
})();

// ======================================================
// MENUS
// ======================================================
function toggleSideMenu() {
    const menu = document.getElementById('sideMenu');
    const overlay = document.getElementById('overlay');
    document.getElementById('photoMenu').classList.remove('show');
    menu.classList.toggle('show');
    overlay.classList.toggle('show', menu.classList.contains('show'));
}

function togglePhotoMenu() {
    const menu = document.getElementById('photoMenu');
    const overlay = document.getElementById('overlay');
    document.getElementById('sideMenu').classList.remove('show');
    menu.classList.toggle('show');
    overlay.classList.toggle('show', menu.classList.contains('show'));
}

function closeAllMenus() {
    document.getElementById('sideMenu').classList.remove('show');
    document.getElementById('photoMenu').classList.remove('show');
    document.getElementById('overlay').classList.remove('show');
}

// ======================================================
// CAMÉRA & GALERIE
// ======================================================
function openCam() {
    closeAllMenus();
    setTimeout(() => document.getElementById('cam').click(), 150);
}
function openGal() {
    closeAllMenus();
    setTimeout(() => document.getElementById('gal').click(), 150);
}

async function compressImage(file, maxWidth = 1920, quality = 0.82) {
    return new Promise((resolve) => {
        if (!file.type.startsWith('image/')) return resolve(file);
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            let width = img.width, height = img.height;
            if (width > maxWidth) {
                height = Math.round(height * (maxWidth / width));
                width = maxWidth;
            }
            const canvas = document.createElement('canvas');
            canvas.width = width; canvas.height = height;
            canvas.getContext('2d').drawImage(img, 0, 0, width, height);
            canvas.toBlob(blob => {
                resolve(blob ? new File([blob], 'photo.jpg', {type:'image/jpeg'}) : file);
            }, 'image/jpeg', quality);
        };
        img.onerror = () => resolve(file);
        img.src = url;
    });
}

async function handlePhotoSelect(input) {
    if (!input.files?.[0]) return;
    const compressed = await compressImage(input.files[0]);
    const dt = new DataTransfer();
    dt.items.add(compressed);
    input.files = dt.files;
    document.getElementById('formPhoto').submit();
}

document.getElementById('cam')?.addEventListener('change', function() { handlePhotoSelect(this); });
document.getElementById('gal')?.addEventListener('change', function() { handlePhotoSelect(this); });

// ======================================================
// SÉLECTION PHOTOS
// ======================================================
let photoLongPressTimer = null, selectMode = false;

function startPhotoLongPress(el) {
    if (selectMode) return;
    photoLongPressTimer = setTimeout(() => {
        selectMode = true;
        document.body.classList.add('select-mode');
        el.classList.add('selected');
        el.querySelector('.photo-check').checked = true;
    }, 600);
}
function cancelPhotoLongPress() { clearTimeout(photoLongPressTimer); }
function exitPhotoSelectMode() {
    selectMode = false;
    document.body.classList.remove('select-mode');
    document.querySelectorAll('.item').forEach(item => {
        item.classList.remove('selected');
        item.querySelector('.photo-check').checked = false;
    });
}

document.addEventListener('click', function(e) {
    if (!selectMode) return;
    const item = e.target.closest('.item');
    if (item) {
        e.preventDefault();
        item.classList.toggle('selected');
        item.querySelector('.photo-check').checked = item.classList.contains('selected');
    }
    if (e.target.closest('.photo-link')) e.preventDefault();
});

// ======================================================
// SÉLECTION NOTES / AUDIOS
// ======================================================
let longPressTimer = null, mediaSelectMode = false, currentMediaType = null;

function startLongPress(el) {
    if (mediaSelectMode) return;
    longPressTimer = setTimeout(() => {
        mediaSelectMode = true;
        currentMediaType = el.dataset.type;
        document.body.classList.add('media-select-mode');
        el.classList.add('selected');
    }, 600);
}
function cancelLongPress() { clearTimeout(longPressTimer); }
function exitMediaSelectMode() {
    mediaSelectMode = false;
    currentMediaType = null;
    document.body.classList.remove('media-select-mode');
    document.querySelectorAll('.note-item, .audio-item').forEach(el => el.classList.remove('selected'));
}

document.addEventListener('click', function(e) {
    if (!mediaSelectMode) return;
    const item = e.target.closest('.note-item, .audio-item');
    if (item && item.dataset.type === currentMediaType) {
        e.preventDefault();
        item.classList.toggle('selected');
    }
});

function deleteSelectedMedia() {
    const selected = document.querySelectorAll(`.${currentMediaType === 'notes' ? 'note-item' : 'audio-item'}.selected`);
    if (selected.length === 0) return alert('Aucun élément sélectionné');
    if (!confirm(`Supprimer ${selected.length} élément(s) ?`)) return;
    
    document.getElementById('deleteType').value = currentMediaType;
    const div = document.getElementById('deleteIndexes');
    div.innerHTML = '';
    selected.forEach(el => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'indexes[]';
        input.value = el.dataset.index;
        div.appendChild(input);
    });
    document.getElementById('formDeleteMedia').submit();
}

// ======================================================
// AUDIO
// ======================================================
let mediaRecorder, chunks = [], startTime, timerInterval, isRecording = false;

function updateTimer() {
    const s = Math.floor((Date.now() - startTime) / 1000);
    document.getElementById('timerFloat').textContent =
        String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
}

async function startRecord() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        chunks = [];
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
        mediaRecorder.onstop = () => {
            const blob = new Blob(chunks, { type: 'audio/webm' });
            const file = new File([blob], 'audio.webm', { type: 'audio/webm' });
            const dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('audioInput').files = dt.files;
            document.getElementById('formAudio').submit();
        };
        mediaRecorder.start(1000);
        isRecording = true;
        startTime = Date.now();
        document.getElementById('fabAudio').classList.add('recording');
        document.getElementById('fabAudio').innerHTML = '⏹';
        document.getElementById('timerFloat').classList.add('show');
        timerInterval = setInterval(updateTimer, 200);
        setTimeout(() => { if (isRecording) stopRecord(); }, 60000);
    } catch (e) {
        alert('Autorisez l\'accès au micro dans les paramètres.');
    }
}

function stopRecord() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(t => t.stop());
    }
    clearInterval(timerInterval);
    isRecording = false;
    document.getElementById('fabAudio').classList.remove('recording');
    document.getElementById('fabAudio').innerHTML = '<img src="icons/vol.png" style="width:50px;height:50px" id="iconAudio">';
    document.getElementById('timerFloat').classList.remove('show');
}

function toggleAudio() {
    isRecording ? stopRecord() : startRecord();
}
</script>
</body>
</html>