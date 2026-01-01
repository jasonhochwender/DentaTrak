<?php
require_once __DIR__ . '/session.php';

// Clear the session data
session_unset();
session_destroy();

// Redirect to the login page
header('Location: ../login.php');
exit;
