import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
    copyFileSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

function fixture(content, existing = false) {
    const directory = mkdtempSync(join(tmpdir(), 'stocknest-setup-'));
    mkdirSync(join(directory, 'scripts'));
    copyFileSync(
        new URL('../../scripts/initialize-env.mjs', import.meta.url),
        join(directory, 'scripts/initialize-env.mjs'),
    );
    writeFileSync(join(directory, existing ? '.env' : '.env.example'), content);
    return {
        directory,
        run: () =>
            execFileSync(
                process.execPath,
                [join(directory, 'scripts/initialize-env.mjs')],
                { encoding: 'utf8' },
            ),
    };
}

await test('the shipped installation template disables debugging and uses production defaults', () => {
    const template = readFileSync(
        new URL('../../.env.example', import.meta.url),
        'utf8',
    );
    const context = fixture(template);
    try {
        context.run();
        const environment = readFileSync(
            join(context.directory, '.env'),
            'utf8',
        );
        assert.match(environment, /^APP_ENV=production\r?$/m);
        assert.match(environment, /^APP_DEBUG=false\r?$/m);
        assert.match(environment, /^LOG_LEVEL=warning\r?$/m);
        assert.match(environment, /^SESSION_SECURE_COOKIE=false\r?$/m);
        assert.match(environment, /^TRUSTED_PROXIES=\r?$/m);
    } finally {
        rmSync(context.directory, { recursive: true, force: true });
    }
});

await test('new installations receive a valid application key and database password', () => {
    const context = fixture(
        'APP_NAME=StockNest\r\nAPP_KEY=\r\nDB_PASSWORD=\r\n',
    );
    try {
        const output = context.run();
        const environment = readFileSync(
            join(context.directory, '.env'),
            'utf8',
        );
        const key = environment.match(/^APP_KEY=base64:(.+)$/m)?.[1]?.trim();
        assert.ok(key);
        assert.equal(Buffer.from(key, 'base64').length, 32);
        assert.match(environment, /^DB_PASSWORD=[a-f0-9]{64}\r?$/m);
        assert.ok(!output.includes(key));
        context.run();
        assert.equal(
            readFileSync(join(context.directory, '.env'), 'utf8'),
            environment,
        );
    } finally {
        rmSync(context.directory, { recursive: true, force: true });
    }
});

await test('existing encryption keys and database passwords are preserved', () => {
    const environment =
        'APP_KEY=base64:existing-encryption-key\nDB_PASSWORD=existing-db-password\n';
    const context = fixture(environment, true);
    try {
        context.run();
        assert.equal(
            readFileSync(join(context.directory, '.env'), 'utf8'),
            environment,
        );
    } finally {
        rmSync(context.directory, { recursive: true, force: true });
    }
});

await test('missing settings are appended as real environment lines', () => {
    const context = fixture('APP_NAME=StockNest');
    try {
        context.run();
        const environment = readFileSync(
            join(context.directory, '.env'),
            'utf8',
        );
        assert.match(environment, /^APP_KEY=base64:[a-zA-Z0-9+/=]+$/m);
        assert.match(environment, /^DB_PASSWORD=[a-f0-9]{64}$/m);
        assert.equal(environment.split('\n')[0], 'APP_NAME=StockNest');
    } finally {
        rmSync(context.directory, { recursive: true, force: true });
    }
});
