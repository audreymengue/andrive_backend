<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordService
{
    public const int MAX_LENGTH = 12;

    public const int MIN_LENGTH = 8;

    public function generateTemporaryPassword(): string
    {
        return Str::password(self::MAX_LENGTH, letters: true, numbers: true, symbols: true);
    }

    public function hasValidLength(string $password): bool
    {
        $length = Str::length($password);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    public function hash(string $password): string
    {
        return Hash::make($password);
    }

    public function encryptTemporaryPassword(string $temporaryPassword): string
    {
        return Crypt::encryptString($temporaryPassword);
    }

    public function temporaryPasswordForAuthorizedAdministrator(User $user): ?string
    {
        if (! $user->must_change_password || $user->temporary_password === null) {
            return null;
        }

        try {
            return Crypt::decryptString($user->temporary_password);
        } catch (DecryptException) {
            return null;
        }
    }

    public function matches(User $user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    public function change(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword): void {
            app(ParametrageSecurity::class)->lock();
            $user->refresh();
            $user->forceFill([
                'password' => $this->hash($newPassword),
                'temporary_password' => null,
                'must_change_password' => false,
                'lock_version' => (int) $user->lock_version + 1,
                'password_generation' => (int) $user->password_generation + 1,
            ])->save();

            $user->tokens()->delete();
        });
    }

    public function reset(User $user): string
    {
        $temporaryPassword = $this->generateTemporaryPassword();

        DB::transaction(function () use ($user, $temporaryPassword): void {
            app(ParametrageSecurity::class)->lock();
            $user->refresh();
            $user->forceFill([
                'password' => $this->hash($temporaryPassword),
                'temporary_password' => $this->encryptTemporaryPassword($temporaryPassword),
                'must_change_password' => true,
                'password_generation' => (int) $user->password_generation + 1,
            ])->save();

            $user->tokens()->delete();
        });

        return $temporaryPassword;
    }
}
