<?php
// Ensure the session cookie is available throughout the domain
session_set_cookie_params(0, '/');
session_start();

include __DIR__ . '/../db/connection.php';
include __DIR__ . '/../../back/env.php';
// (Optional) Remove or comment out the include for exif_functions.php if you're now handling EXIF inline
// include __DIR__ . '/exif_functions.php';

// Validation CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Token CSRF invalide.");
    }
}

// Récupération des données envoyées
$message = $_POST['post'];
$user_id = $_POST['user_id'];
$pseudo = $_POST['pseudo'];
$public = isset($_POST['public']) ? 1 : 0;
$photographe = $_POST['photographe'];  // Nouveau champ
$titre = $_POST['titre'];              // Nouveau champ
$datePrisePhoto = $_POST['datePrisePhoto'];      // Nouveau champ
$motsCles = $_POST['motsCles'];        // Nouveau champ
$auteur = $_POST['auteur'];            // Nouveau champ

$max_width = 2048;
$max_height = 2048;
$thumbnail_max_size = 512;
$unique_name = null;

// Vérifie si une image a été téléchargée
if ($_FILES['postimage']['error'] == UPLOAD_ERR_NO_FILE) {
    header("Location: /view/account.php?pseudo=" . $pseudo . "&error=image_required");
    exit();
}

// Validation de l'extension et détection du type MIME
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
$imageext = strtolower(pathinfo($_FILES['postimage']['name'], PATHINFO_EXTENSION));
$imagetmpname = $_FILES['postimage']['tmp_name'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $imagetmpname);
finfo_close($finfo);

$mime_to_function = [
    'image/jpeg' => 'imagecreatefromjpeg',
    'image/png' => 'imagecreatefrompng',
    'image/webp' => 'imagecreatefromwebp',
];

if (!array_key_exists($mime, $mime_to_function)) {
    die("Type MIME non supporté : " . htmlspecialchars($mime));
}

$image_load_function = $mime_to_function[$mime];
$source_image = @$image_load_function($imagetmpname);
if (!$source_image) {
    die("Erreur lors du chargement de l'image source.");
}

// Redimensionnement si nécessaire
list($width, $height) = getimagesize($imagetmpname);
if ($width > $max_width || $height > $max_height) {
    $ratio = min($max_width / $width, $max_height / $height);
    $new_width = (int)($width * $ratio);
    $new_height = (int)($height * $ratio);

    $resized_image = imagecreatetruecolor($new_width, $new_height);
    if (!imagecopyresampled($resized_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height)) {
        die("Erreur lors du redimensionnement de l'image.");
    }
    $final_image = $resized_image;
} else {
    $final_image = $source_image;
}

// Création du répertoire de téléchargement si nécessaire
$upload_directory = __DIR__ . "/../../upload/publication/";
if (!is_dir($upload_directory)) {
    mkdir($upload_directory, 0755, true);
}

try {
    // Insertion dans la base de données
    $sql = "INSERT INTO `publication` (`uid`, `msg`, `type`, `dop`, `public`, `nom_photographe`, `titre`, `date_capture`, `mots_clés`, `nom_auteur`) 
            VALUES (:uid, :msg, 'p', current_timestamp(), :public, :photographe, :titre, :datePrisePhoto, :motsCles, :auteur)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':uid', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':msg', $message, PDO::PARAM_STR);
    $stmt->bindParam(':public', $public, PDO::PARAM_INT);
    $stmt->bindParam(':photographe', $photographe, PDO::PARAM_STR);
    $stmt->bindParam(':titre', $titre, PDO::PARAM_STR);
    $stmt->bindParam(':datePrisePhoto', $datePrisePhoto, PDO::PARAM_STR);
    $stmt->bindParam(':motsCles', $motsCles, PDO::PARAM_STR);
    $stmt->bindParam(':auteur', $auteur, PDO::PARAM_STR);
    $stmt->execute();
    
    // Récupération de l'ID et nom unique du fichier
    $pid = $pdo->lastInsertId();
    $unique_name = $pid . "." . $imageext;
    $final_image_path = $upload_directory . $unique_name;
    
    // Déplacer le fichier téléchargé
    if (!move_uploaded_file($imagetmpname, $final_image_path)) {
        die("Erreur lors du déplacement du fichier téléchargé.");
    }
    
    // --- EXIF Processing ---
    // Initialize metadata as empty array
    $metadata = [];
    // Use exif_read_data if available and if the image MIME type is compatible (JPEG or TIFF)
    if (function_exists('exif_read_data') && in_array($mime, ['image/jpeg', 'image/tiff'])) {
        $exif_data = @exif_read_data($final_image_path, null, true);
        if ($exif_data !== false) {
            $metadata = $exif_data;
        }
    }
    // Encode metadata as JSON (or you can process it as needed)
    $metadata_json = json_encode($metadata);
    // Enregistrer les données EXIF dans la base de données (assumes 'donnees_exif' column exists)
    $sql_update_exif = "UPDATE `publication` SET `donnees_exif` = :donnees_exif WHERE `pid` = :pid";
    $stmt_exif = $pdo->prepare($sql_update_exif);
    $stmt_exif->bindParam(':donnees_exif', $metadata_json, PDO::PARAM_STR);
    $stmt_exif->bindParam(':pid', $pid, PDO::PARAM_INT);
    $stmt_exif->execute();
    // --- End EXIF Processing ---
    
    // Sauvegarde de l'image redimensionnée
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($final_image, $final_image_path);
            break;
        case 'image/png':
            imagepng($final_image, $final_image_path);
            break;
        case 'image/webp':
            imagewebp($final_image, $final_image_path);
            break;
    }
    // Détruire $final_image seulement s'il diffère de $source_image
    if ($final_image !== $source_image) {
        imagedestroy($final_image);
    }
    
    // Création de la miniature
    $thumbnail_ratio = min($thumbnail_max_size / $width, $thumbnail_max_size / $height);
    $thumbnail_width = (int)($width * $thumbnail_ratio);
    $thumbnail_height = (int)($height * $thumbnail_ratio);
    
    $thumbnail_image = imagecreatetruecolor($thumbnail_width, $thumbnail_height);
    if (!imagecopyresampled($thumbnail_image, $source_image, 0, 0, 0, 0, $thumbnail_width, $thumbnail_height, $width, $height)) {
        die("Erreur lors du redimensionnement de la miniature.");
    }
    
    $thumbnail_name = $pid . "_mini.png";
    $thumbnail_path = $upload_directory . $thumbnail_name;
    
    if (!imagepng($thumbnail_image, $thumbnail_path)) {
        die("Erreur lors de la sauvegarde de la miniature.");
    }
    
    imagedestroy($thumbnail_image);
    imagedestroy($source_image);
    
    // Mise à jour de l'image dans la base de données
    $sql_update = "UPDATE `publication` SET `image` = :image WHERE `pid` = :pid";
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->bindParam(':image', $unique_name, PDO::PARAM_STR);
    $stmt_update->bindParam(':pid', $pid, PDO::PARAM_INT);
    $stmt_update->execute();
    
    // Redirection
    if (isset($_POST['redirect'])) {
        header("Location: /view/" . $_POST['redirect']);
    } else {
        header("Location: " . $home_page . "/view/account.php?pseudo=" . $pseudo);
    }
    exit();
} catch (PDOException $e) {
    die("Erreur lors de la publication : " . $e->getMessage());
}
