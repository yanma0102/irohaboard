<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * RecordsQuestion Entity
 */
class RecordsQuestion extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}
