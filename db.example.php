<?php
// db.example.php
// Instructions: Rename this file to 'db.php' and update the credentials below.

$host = 'localhost';
$dbname = 'quran_search'; 
$username = 'root'; // Your Database Username
$password = '';     // Your Database Password

try {
    // Create connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Optional: Create database if not exists logic can be kept if needed, 
    // but usually for production/deployment, the DB is tailored.
    // Keeping simple connection logic here.
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
