<?php

describe('Database Safety', function (): void {
    test('rejects a development database even when the other connection is safe', function (string $connection) {
        $original = config("database.connections.{$connection}");
        config(["database.connections.{$connection}.database" => 'smartbus']);
        try {
            expect(fn () => $this->ensureTestingDatabases())->toThrow(RuntimeException::class, 'Refusing to run tests');
        } finally {
            config(["database.connections.{$connection}" => $original]);
        }
    })->with(['pgsql', 'mysql']);

    test('rejects database URLs that override a safe database name', function (string $connection, string $url) {
        $original = config("database.connections.{$connection}");
        config(["database.connections.{$connection}.url" => $url]);
        try {
            expect(fn () => $this->ensureTestingDatabases())->toThrow(RuntimeException::class, 'Refusing to run tests');
        } finally {
            config(["database.connections.{$connection}" => $original]);
        }
    })->with([
        ['pgsql', 'postgres://localhost/smartbus'],
        ['mysql', 'mysql://localhost/smartbus_users'],
        ['mysql', 'mysql://localhost/smartbus_users_testing?database=smartbus_users'],
    ]);

});
