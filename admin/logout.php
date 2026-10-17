<?php
/** Odhlášení z administrace. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

adminLogout();
adminSession();
flash('ok', 'Jsi odhlášená.');
redirect('login.php');
