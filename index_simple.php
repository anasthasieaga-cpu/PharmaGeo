<?php
// ===== VERSION TRÈS SIMPLE POUR TESTER =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>PharmaGeo - Test</title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='assets/css/palette.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='alert alert-success'>
            <h1>✅ PharmaGeo fonctionne !</h1>
            <p>Le serveur PHP est opérationnel.</p>
        </div>";

// Test inclusion config
echo "<h2>Test d'inclusion de config.php</h2>";
if (file_exists('includes/config.php')) {
    echo "<div class='alert alert-info'>✅ includes/config.php trouvé</div>";
    try {
        require_once 'includes/config.php';
        echo "<div class='alert alert-success'>✅ config.php inclus avec succès</div>";
        echo "<p>BASE_URL : " . BASE_URL . "</p>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>❌ Erreur : " . $e->getMessage() . "</div>";
    } catch (ParseError $e) {
        echo "<div class='alert alert-danger'>❌ Erreur de syntaxe : " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='alert alert-danger'>❌ includes/config.php n'existe pas</div>";
}

echo "<hr>";

// Test de la BDD
echo "<h2>Test de la base de données</h2>";
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $test = $pdo->query("SELECT 1")->fetch();
        echo "<div class='alert alert-success'>✅ Connexion BDD réussie</div>";
    } else {
        echo "<div class='alert alert-warning'>⚠️ \$pdo n'est pas défini</div>";
    }
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>❌ " . $e->getMessage() . "</div>";
}

echo "</div>
</body>
</html>";