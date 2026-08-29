<?php
require_once __DIR__ . '/includes/functions.php';
$_SESSION = [];
session_destroy();
session_start();
flash('You have been signed out.');
redirect('login.php');
