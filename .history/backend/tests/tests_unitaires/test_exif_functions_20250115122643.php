<?php
// tests/test_exif_functions.php

include __DIR__ . '/../../db/connection.php';
include __DIR__ . '/../../controller/exif_functions.php';

/**
 * Nettoyage des données avant et après les tests
 */
function cleanupExifTests($pdo) {
    $pdo->exec("DELETE FROM `publication` WHERE `msg` LIKE 'Test EXIF%'");
    echo "Cleanup Completed: Toutes les publications de test liées à EXIF ont été supprimées.\n";
}




/**
 * Test contre les injections de chemin
 */
function testPathInjection() {
    echo "\n--- Test contre les injections de chemin ---\n";

    $malicious_path = '../../etc/passwd';
    $post_id = 1;
    $result = collecterExif($malicious_path, $post_id);

    if (isset($result['error'])) {
        echo "Test Passed: L'injection de chemin a été bloquée.\n";
    } else {
        echo "Test Failed: L'injection de chemin a réussi !\n";
    }
}


/**
 * Test d'injection JSON dans les métadonnées
 */
function testJSONInjection($pdo) {
    echo "\n--- Test d'injection JSON dans les métadonnées ---\n";

    $malicious_metadata = '{"EXIF Data":"<script>alert(\'XSS\');</script>"}';
    $post_id = 1;

    $sql = "UPDATE `publication` 
            SET `donnees_exif` = :donnees_exif 
            WHERE `pid` = :pid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':donnees_exif' => $malicious_metadata,
        ':pid' => $post_id,
    ]);

    $sql = "SELECT `donnees_exif` FROM `publication` WHERE `pid` = :pid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid' => $post_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result !== false && isset($result['donnees_exif']) && strpos($result['donnees_exif'], '<script>') !== false) {
        echo "Test Failed: L'injection JSON a fonctionné !\n";
    } else {
        echo "Test Passed: L'injection JSON a été bloquée.\n";
    }
}



/**
 * Test d'injection de commande via shell_exec
 */
function testShellExecInjection($pdo) {
    echo "\n--- Test d'injection de commande via shell_exec ---\n";
    
    $malicious_path = "'; rm -rf /; '";
    $post_id = 1;
    $result = collecterExif($malicious_path, $post_id);
    
    if (isset($result['error'])) {
        echo "Test Passed: L'injection de commande a été bloquée.\n";
    } else {
        echo "Test Failed: L'injection de commande a réussi !\n";
    }
}



/**
 * Démarrage des tests
 */
echo "\n--- DÉMARRAGE DES TESTS EXIF ---\n";

// Nettoyage avant les tests
cleanupExifTests($pdo);


$post_id = 1;


// Test contre les injections de chemin
testPathInjection();

// Test d'injection JSON dans les métadonnées
testJSONInjection($pdo);

// Nettoyage après les tests
cleanupExifTests($pdo);

echo "\nFin des tests EXIF.\n";
