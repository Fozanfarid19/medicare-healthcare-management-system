<?php
/**
 * MediCare - logout.php
 * Destroys the session completely and returns to the login page.
 */
require_once __DIR__ . '/includes/auth.php';

logout_user();

// Start a clean session only to carry the goodbye message.
session_start();
flash_success('You have been signed out successfully.');
redirect('login.php');
