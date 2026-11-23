<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact hello@terbora.com.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/vendor/argora/tabler-assets/public';
$target = $root . '/panel/public/assets';
if (!is_dir($source)) {
    throw new RuntimeException('Foundry Tabler assets are missing; run composer install.');
}
if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
    throw new RuntimeException('Cannot create panel asset directory.');
}
foreach (['css/tabler.min.css', 'js/tabler.min.js'] as $relative) {
    $directory = dirname($target . '/' . $relative);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create panel asset directory.');
    }
    if (!copy($source . '/' . $relative, $target . '/' . $relative)) {
        throw new RuntimeException('Cannot copy panel asset.');
    }
}
echo "Foundry Tabler assets installed.\n";
