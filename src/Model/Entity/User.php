<?php
declare(strict_types=1);

namespace App\Model\Entity;

use ArrayAccess;
use Authentication\IdentityInterface;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\I18n\DateTime;
use Cake\ORM\Entity;

class User extends Entity implements IdentityInterface, ArrayAccess
{
    protected array $_accessible = [
        'username' => true,
        'name' => true,
        'password' => true,
        'role_id' => true,
        'delivery_id' => true,
        'active' => true,
        'role' => true,
    ];

    protected array $_hidden = ['password'];

    /**
     * Hashes the password automatically. Returns null when the value is empty
     * so that patchEntity() in edit() does not overwrite the existing hash
     * with an empty string.
     */
    protected function _setPassword(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DefaultPasswordHasher())->hash($value);
    }

    public function isLocked(): bool
    {
        if ($this->locked_until === null) {
            return false;
        }

        return $this->locked_until > DateTime::now();
    }

    public function isAdministrator(): bool
    {
        return !empty($this->role) && (bool)$this->role->is_admin;
    }

    /* -------- Authentication\IdentityInterface -------- */

    public function getIdentifier(): array|string|int|null
    {
        return $this->id;
    }

    public function getOriginalData(): static
    {
        return $this;
    }
}
