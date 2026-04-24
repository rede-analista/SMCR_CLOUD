<?php
require_once __DIR__ . '/config/auth.php';
logout();
header('Location: ' . BASE . '/login.php');
exit;
