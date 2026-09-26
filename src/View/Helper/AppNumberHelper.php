<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper\NumberHelper;

/**
 * CakePHP 2 compatible toReadableSize on top of CakePHP 5 NumberHelper.
 */
class AppNumberHelper extends NumberHelper
{
    /**
     * Convert byte size to human-readable string.
     * Matches legacy CakePHP 2 NumberHelper::toReadableSize().
     * Uses 1024-based units, 1 decimal place.
     *
     * @param string|float|int $size Size in bytes.
     * @return string Human-readable size string.
     */
    public function toReadableSize(int|float|string|null $size): string
    {
        if ($size === null || $size === '') {
            return '0 B';
        }
        $size = (float)$size;

        if ($size === 0.0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $i = (int)floor(log($size, 1024));
        if ($i < 0) {
            $i = 0;
        }
        if ($i >= count($units)) {
            $i = count($units) - 1;
        }

        return round($size / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
