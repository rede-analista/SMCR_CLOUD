<?php
require_once __DIR__ . '/config/auth.php';
session_init();

if (is_logged_in()) {
    header('Location: ' . BASE . '/dashboard.php');
} else {
    header('Location: ' . BASE . '/login.php');
}
exit;
