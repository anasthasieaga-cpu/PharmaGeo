<?php
// ===== TEST GLOBAL DE DIAGNOSTIC =====
echo "<h1>🔍 Diagnostic PharmaGeo</h1>";
echo "<hr>";

// 1. Test PHP
echo "<h2>1. Test PHP</h2>";
echo "✅ PHP version : " . phpversion() . "<br>";
echo "✅ PHP fonctionne correctement<br><br>";

// 2. Test des extensions
echo "<h2>2. Extensions PHP</h2>";
$extensions = ['pdo', 'pdo_mysql', 'mysqli', 'session', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ $ext chargée<br>";
    } else {
        echo "❌ $ext NON chargée<br>";
    }
}
echo "<br>";

// 3. Test de la base de données
echo "<h2>3. Base de données</h2>";
try {
    $pdo_test = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo_test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Connexion à la BDD réussie<br>";
    
    // Vérifier les tables
    $tables = $pdo_test->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "📋 Tables trouvées : " . implode(', ', $tables) . "<br>";
    
    if (in_array('utilisateur', $tables)) {
        $count = $pdo_test->query("SELECT COUNT(*) FROM utilisateur")->fetchColumn();
        echo "✅ Table 'utilisateur' trouvée avec $count enregistrements<br>";
    } else {
        echo "❌ Table 'utilisateur' manquante<br>";
    }
} catch(PDOException $e) {
    echo "❌ Erreur BDD : " . $e->getMessage() . "<br>";
}
echo "<br>";

// 4. Test d'inclusion de fichiers
echo "<h2>4. Inclusion de fichiers</h2>";
$files_to_test = [
    'includes/config.php',
    'includes/session.php',
    'includes/header.php',
    'includes/footer.php'
];

foreach ($files_to_test as $file) {
    $full_path = __DIR__ . '/' . $file;
    if (file_exists($full_path)) {
        echo "✅ $file existe<br>";
        // Tester l'inclusion
        try {
            include_once $full_path;
            echo "   ✅ Inclusion réussie<br>";
        } catch (Exception $e) {
            echo "   ❌ Erreur d'inclusion : " . $e->getMessage() . "<br>";
        } catch (ParseError $e) {
            echo "   ❌ Erreur de syntaxe : " . $e->getMessage() . "<br>";
        }
    } else {
        echo "❌ $file n'existe PAS<br>";
    }
}
echo "<br>";

// 5. Test des chemins
echo "<h2>5. Chemins</h2>";
echo "📁 __DIR__ : " . __DIR__ . "<br>";
echo "📁 BASE_PATH calculé : " . realpath(__DIR__) . "<br>";
echo "📁 Document Root : " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "📁 Script Name : " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "<br>";

// 6. Test d'erreur de syntaxe dans config.php
echo "<h2>6. Analyse config.php</h2>";
if (file_exists('includes/config.php')) {
    $content = file_get_contents('includes/config.php');
    // Vérifier les erreurs communes
    if (strpos($content, '<?php') === false) {
        echo "❌ config.php ne commence pas par <?php<br>";
    }
    if (strpos($content, 'define(') === false) {
        echo "❌ config.php ne contient pas de define()<br>";
    }
    echo "📄 config.php : " . strlen($content) . " caractères<br>";
    // Vérifier les erreurs de syntaxe
    $output = shell_exec('php -l includes/config.php 2>&1');
    if (strpos($output, 'No syntax errors') !== false) {
        echo "✅ config.php syntaxe correcte<br>";
    } else {
        echo "❌ config.php erreur de syntaxe :<br><pre>$output</pre>";
    }
}
echo "<br>";

// 7. Test de session
echo "<h2>7. Session</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['test_diagnostic'] = 'OK';
if (isset($_SESSION['test_diagnostic']) && $_SESSION['test_diagnostic'] === 'OK') {
    echo "✅ Session fonctionne correctement<br>";
} else {
    echo "❌ Session ne fonctionne pas<br>";
}
echo "<br>";

// 8. Résumé final
echo "<h2>📊 Résumé</h2>";
echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 8px;'>";
echo "<p>Si vous voyez ce message, PHP fonctionne correctement.</p>";
echo "<p><strong>Problèmes possibles :</strong></p>";
echo "<ul>";
echo "<li>❌ Fichier config.php mal configuré</li>";
echo "<li>❌ Base de données 'gestion_soutenance' inexistante</li>";
echo "<li>❌ Erreur de syntaxe dans un fichier PHP</li>";
echo "<li>❌ Problème de chemin dans les includes</li>";
echo "</ul>";
echo "</div>";
?>