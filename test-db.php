<?php
$dsn = "pgsql:host=aws-0-eu-west-1.pooler.supabase.com;port=6543;dbname=postgres;sslmode=require";
$user = "postgres.dgqaevwqulxoyzkeqntj";
$password = "VPsJ6YWkg8lXq4bl";

try {
    $pdo = new PDO($dsn, $user, $password);
    echo "SUCCESS\n";
    $stmt = $pdo->query("SELECT count(*) FROM users");
    echo "Users count: " . $stmt->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
