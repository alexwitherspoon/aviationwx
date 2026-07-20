<?php

/**
 * Shared NASR fetch utilities (temp directory cleanup).
 */

/**
 * Recursively remove a directory.
 */
function nasrCleanupDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            nasrCleanupDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}
