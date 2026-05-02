<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Permission extends Entity
{
    protected array $_accessible = [
        'role_id' => true,
        'module' => true,
        'can_view' => true,
        'can_create' => true,
        'can_edit' => true,
        'can_delete' => true,
    ];
}
