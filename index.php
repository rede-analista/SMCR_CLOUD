<?php
require_once __DIR__ . '/config/auth.php';
session_init();

if (is_logged_in()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
