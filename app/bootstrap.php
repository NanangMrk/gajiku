<?php
/**
 * app/bootstrap.php
 * 
 * Single bootstrap file for the Gajiku application.
 * All page controllers should require this file only.
 * It loads configuration, database, and all helper modules.
 */

// Configuration
require_once __DIR__ . '/Config/app.php';
require_once __DIR__ . '/Config/database.php';

// Helpers
require_once __DIR__ . '/Helpers/functions.php';
require_once __DIR__ . '/Helpers/csrf.php';
require_once __DIR__ . '/Helpers/auth.php';
require_once __DIR__ . '/Helpers/permissions.php';
