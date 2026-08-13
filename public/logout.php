<?php
// public/logout.php
require_once __DIR__ . '/../app/bootstrap.php';
logoutUser();
header('Location: ' . BASE_URL . '/login.php');
exit;
