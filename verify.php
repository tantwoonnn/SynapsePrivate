<?php
require_once __DIR__ . '/includes/functions.php';

// Old application-generated verification tokens no longer grant verified status.
// Supabase Auth confirmation links now redirect directly to login.php.
flash('info', 'Use the confirmation link sent by Supabase Auth. If it has expired, request another link.');
redirect('verify-email.php');
