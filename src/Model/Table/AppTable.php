<?php
declare(strict_types=1);

/**
 * iroha Board Project
 * アプリケーション共通の Table 基底クラス
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\ORM\Table;

class AppTable extends Table
{
    /**
     * 英数字チェック（マルチバイト対応）
     *
     * @param array $check チェック対象
     * @return bool OK:true, NG:false
     */
    public function alphaNumericMB(array $check): bool
    {
        $value = array_values($check);
        $value = $value[0];

        return (bool)preg_match('/^[a-zA-Z0-9]+$/', $value);
    }
}