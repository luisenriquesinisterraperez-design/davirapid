<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class LoginThrottleService
{
    use LocatorAwareTrait;

    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MIN = 15;

    /**
     * Llamado ANTES de validar credenciales.
     *
     * @return array|null Null si el login puede proceder, o ['locked'=>true,'minutes_left'=>int] si está bloqueado.
     */
    public function checkLockout(string $username): ?array
    {
        if ($username === '') {
            return null;
        }

        $user = $this->fetchTable('Users')
            ->find()
            ->where(['Users.username' => $username])
            ->first();

        if ($user === null || $user->locked_until === null) {
            return null;
        }

        $now = DateTime::now();
        if ($user->locked_until > $now) {
            $minutesLeft = (int)ceil(($user->locked_until->getTimestamp() - $now->getTimestamp()) / 60);
            return ['locked' => true, 'minutes_left' => max(1, $minutesLeft)];
        }

        // El bloqueo cumplió: reset on-demand.
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $this->fetchTable('Users')->saveOrFail($user);
        return null;
    }

    /**
     * Llamado tras un login exitoso. Resetea contadores y registra last_login_at.
     */
    public function recordSuccess(int $userId): void
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = DateTime::now();
        $usersTable->saveOrFail($user);

        Log::info('User {username} logged in', [
            'username' => $user->username,
            'scope' => ['auth'],
        ]);
    }

    /**
     * Llamado tras un login fallido.
     *
     * @return array ['attempts_left' => int|null, 'locked_until' => DateTime|null]
     *               attempts_left es null cuando el username no existe (no se filtra al atacante).
     */
    public function recordFailure(string $username): array
    {
        if ($username === '') {
            return ['attempts_left' => null, 'locked_until' => null];
        }

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()->where(['Users.username' => $username])->first();

        if ($user === null) {
            Log::info('Login attempt for unknown username {username}', [
                'username' => $username,
                'scope' => ['auth'],
            ]);
            return ['attempts_left' => null, 'locked_until' => null];
        }

        $user->failed_login_count = (int)$user->failed_login_count + 1;

        if ($user->failed_login_count >= self::MAX_ATTEMPTS) {
            $user->locked_until = DateTime::now()->modify('+' . self::LOCKOUT_MIN . ' minutes');
            Log::warning('Account locked for {username} until {until}', [
                'username' => $user->username,
                'until' => $user->locked_until->format('Y-m-d H:i:s'),
                'scope' => ['auth'],
            ]);
        } else {
            Log::warning('Failed login for {username} ({attempts}/{max})', [
                'username' => $user->username,
                'attempts' => $user->failed_login_count,
                'max' => self::MAX_ATTEMPTS,
                'scope' => ['auth'],
            ]);
        }

        $usersTable->saveOrFail($user);

        return [
            'attempts_left' => max(0, self::MAX_ATTEMPTS - (int)$user->failed_login_count),
            'locked_until' => $user->locked_until,
        ];
    }
}
