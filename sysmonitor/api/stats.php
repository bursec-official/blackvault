<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
sm_require_login();
// Release the session before measuring, so several tabs never block each other
session_write_close();

header('Cache-Control: no-store');
sm_json(sm_collect_stats());
