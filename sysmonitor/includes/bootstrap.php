<?php
/**
 * Common entry point: security -> configuration -> session.
 * Every page loads this file instead of config.php directly.
 */
require_once __DIR__ . '/security.php';
bv_boot('sm');
require_once dirname(__DIR__) . '/config.php';
bv_after_config('sm');
