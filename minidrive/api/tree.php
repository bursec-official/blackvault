<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();

/**
 * Walks the drive and returns only the folders, as a flat list with
 * indentation (simpler and sturdier than nested JSON).
 */
function md_walk_folders(string $absPath, string $relPath, int $depth, array &$out): void {
    $entries = @scandir($absPath);
    if ($entries === false) return;
    natcasesort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $absPath . '/' . $entry;
        if (is_link($full) || !is_dir($full)) continue;
        $rel = trim($relPath . '/' . $entry, '/');
        $out[] = ['name' => $entry, 'path' => $rel, 'depth' => $depth];
        md_walk_folders($full, $rel, $depth + 1, $out);
    }
}

$root = md_root();

$folders = [];
md_walk_folders($root, '', 0, $folders);

md_json(['folders' => $folders]);
