<?php

declare(strict_types=1);

/**
 * This file is part of CodeIgniter Queue.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Tests\Support\Constraints;

use CodeIgniter\Test\Constraints\SeeInDatabase;

class SeeInDatabaseExtended extends SeeInDatabase
{
    /**
     * Gets a string representation of the constraint
     *
     * @param int $options
     */
    public function toString(bool $exportObjects = false, $options = 0): string
    {
        $this->data = array_combine(
            array_map(fn ($key) => $this->extractFieldName($key), array_keys($this->data)),
            $this->data
        );

        return parent::toString($exportObjects, $options);
    }

    /**
     * Extract field name from complex key
     */
    protected function extractFieldName(string $input): string
    {
        $pattern = '/CONVERT\(\s*\w+,\s*(\w+)\s*\)/';

        if (preg_match($pattern, $input, $matches)) {
            return $matches[1];
        }

        return $input;
    }
}
