<?php
// db_conn.php

function getPDO(): PDO {
    $host     = 'localhost';   // XAMPP default
    $port     = '3306';        // default MySQL port
    $db       = 'rbac_app';    // your database name
    $user     = 'root';        // XAMPP default user
    $pass     = '';            // XAMPP default password is empty
    $charset  = 'utf8mb4';

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // In production: log error instead of echo
        die("Database connection failed: " . $e->getMessage());
    }
}
