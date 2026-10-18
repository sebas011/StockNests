import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const target = resolve(root, '.env');
let content = readFileSync(
    existsSync(target) ? target : resolve(root, '.env.example'),
    'utf8',
);
for (const [key, value] of Object.entries({
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    DB_PASSWORD: randomBytes(32).toString('hex'),
})) {
    const pattern = new RegExp(`^${key}=\\s*$`, 'm');
    if (pattern.test(content))
        content = content.replace(pattern, `${key}=${value}`);
    else if (!new RegExp(`^${key}=`, 'm').test(content))
        content += `\n${key}=${value}\n`;
}
writeFileSync(target, content, { mode: 0o600 });
console.log(
    'Local .env is ready. Existing keys were preserved; secrets were not printed.',
);
