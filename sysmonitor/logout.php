<?php
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && bv_csrf_ok($_POST['csrf'] ?? null)) {
    bv_destroy_session();
}
header('Location: login.php');
exit;
