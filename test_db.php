<?php

require_once 'config.php';

try {

    $pdo = db();

    echo "<h2>SUCCESS!</h2>";

    echo "Database connected successfully.";

    echo "<br><br>";

    echo "Database Name: ";

    echo e($pdo->query(
        "SELECT DATABASE()"
    )->fetchColumn());

} catch (Throwable $e) {

    echo "<h2>CONNECTION FAILED!</h2>";

    echo e($e->getMessage());

}
?>
