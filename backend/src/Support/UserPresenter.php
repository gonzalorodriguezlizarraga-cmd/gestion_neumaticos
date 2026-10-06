<?php

declare(strict_types=1);

namespace App\Support;

final class UserPresenter
{
    /**
     * @param array{id:int|string,nombres:string,apellidos:string,email:string} $user
     * @param list<string> $roles
     * @param list<array{id:int,razon_social:string,nombre_comercial:?string}> $clientes
     * @return array{id:int,nombres:string,apellidos:string,email:string,roles:list<string>,scope:string,clientes:list<array{id:int,razon_social:string,nombre_comercial:?string}>}
     */
    public static function profile(array $user, array $roles, string $scope, array $clientes): array
    {
        return [
            'id' => (int) $user['id'],
            'nombres' => $user['nombres'],
            'apellidos' => $user['apellidos'],
            'email' => $user['email'],
            'roles' => array_values($roles),
            'scope' => $scope,
            'clientes' => $clientes,
        ];
    }
}
