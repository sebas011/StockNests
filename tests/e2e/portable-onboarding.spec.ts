import { expect, test, type Page } from '@playwright/test';

const ownerEmail = 'portable-owner@example.test';
const itemSku = 'PORTABLE-CHECK';
const itemName = 'Portable verification item';
const phase = process.env.E2E_PORTABLE_PHASE;

async function assertFundingAccess(page: Page) {
    await expect(page).toHaveURL(/\/app\/overview$/);
    const fundingSource = page.getByRole('combobox', {
        name: 'Funding source',
    });
    await expect(fundingSource).toBeVisible();
    await expect(
        fundingSource.getByRole('option', { name: 'GAA', exact: true }),
    ).toHaveCount(1);
    await expect(
        fundingSource.getByRole('option', { name: 'APPCSE', exact: true }),
    ).toHaveCount(1);
    await expect(
        page.getByText('Demonstration workspace', { exact: true }),
    ).toHaveCount(0);
}

async function openCatalog(page: Page) {
    await page.getByRole('link', { name: 'Item catalog', exact: true }).click();
    await page.waitForURL('**/app/catalog');
    await expect(
        page.getByRole('heading', { name: 'Item catalog', exact: true }),
    ).toBeVisible();
}

async function assertSavedItem(page: Page) {
    const row = page.getByRole('row').filter({ hasText: itemSku });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(itemName);
    await expect(row).toContainText('Office supplies');
    await expect(row).toContainText('Piece');
}

async function assertExports(page: Page) {
    const csv = await page.request.get('/app/reports/stock.csv', {
        timeout: 60000,
    });
    expect(csv.status()).toBe(200);
    expect(csv.headers()['content-type']).toContain('text/csv');
    expect(csv.headers()['content-disposition']).toMatch(/attachment;.*\.csv/i);
    // A catalog entry creates no inventory; an untouched portable stock ledger is empty.
    expect((await csv.text()).replace(/^\uFEFF/, '').trim()).toBe(
        '"No matching records"',
    );

    const spreadsheet = await page.request.get('/app/reports/budgets.xlsx', {
        timeout: 60000,
    });
    expect(spreadsheet.status()).toBe(200);
    expect(spreadsheet.headers()['content-type']).toContain(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    expect(spreadsheet.headers()['content-disposition']).toMatch(
        /attachment;.*\.xlsx/i,
    );
    expect((await spreadsheet.body()).subarray(0, 4)).toEqual(
        Buffer.from([0x50, 0x4b, 0x03, 0x04]),
    );

    const pdf = await page.request.get('/app/reports/stock.pdf', {
        timeout: 60000,
    });
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toMatch(/attachment;.*\.pdf/i);
    expect((await pdf.body()).subarray(0, 5).toString('ascii')).toBe('%PDF-');
}

test.describe('assembled portable Windows package', () => {
    test.skip(
        process.env.E2E_PORTABLE !== 'true' || !process.env.E2E_TEST_PASSWORD,
        'Requires an explicitly selected disposable portable package and its test password.',
    );

    test.beforeEach(() => {
        expect(
            process.env.PLAYWRIGHT_BASE_URL,
            'Set the assembled portable package URL explicitly.',
        ).toBeTruthy();
        expect(
            ['first', 'restart'],
            'Choose E2E_PORTABLE_PHASE=first or restart.',
        ).toContain(phase);
    });

    test('first launch provisions an owner and saves a real catalog item', async ({
        page,
    }) => {
        test.skip(
            phase !== 'first',
            'Run before restarting the freshly assembled portable package.',
        );

        const browserErrors: string[] = [];
        page.on('pageerror', (error) => browserErrors.push(error.message));
        await page.goto('/');
        await page.waitForURL('**/setup');
        await expect(
            page.getByRole('heading', { name: 'Welcome to StockNest' }),
        ).toBeVisible();
        await page
            .getByLabel('Your name', { exact: true })
            .fill('Portable Owner');
        await page
            .getByLabel('Email address', { exact: true })
            .fill(ownerEmail);
        await page
            .getByLabel('Password', { exact: true })
            .fill(process.env.E2E_TEST_PASSWORD!);
        await page
            .getByLabel('Confirm password', { exact: true })
            .fill(process.env.E2E_TEST_PASSWORD!);
        await page
            .getByRole('button', { name: 'Create administrator', exact: true })
            .click();
        await page.waitForURL('**/app/overview');
        await assertFundingAccess(page);

        await openCatalog(page);
        await page
            .getByRole('button', { name: 'Add catalog item', exact: true })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Add catalog item',
            exact: true,
        });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel(/^Item code \/ SKU\s*\*?$/).fill(itemSku);
        await dialog.getByLabel(/^Item name\s*\*?$/).fill(itemName);
        await dialog
            .getByLabel(/^Category\s*\*?$/)
            .selectOption({ label: 'Office supplies' });
        await dialog
            .getByLabel(/^Unit of measurement\s*\*?$/)
            .selectOption({ label: 'Piece' });
        await dialog.getByLabel(/^Item type\s*\*?$/).selectOption('consumable');
        await dialog.getByLabel(/^Minimum stock\s*\*?$/).fill('0');
        await dialog.getByLabel(/^Reorder point\s*\*?$/).fill('0');
        await dialog.getByLabel(/^Standard unit cost\s*\*?$/).fill('25.00');
        await dialog
            .getByRole('button', { name: 'Add catalog item', exact: true })
            .click();
        await expect(dialog).not.toBeVisible();
        await assertSavedItem(page);
        await assertExports(page);
        expect(browserErrors).toEqual([]);
    });

    test('a full restart preserves the owner and catalog and keeps setup closed', async ({
        page,
    }) => {
        test.skip(
            phase !== 'restart',
            'Run after stopping and restarting the same portable data directory.',
        );

        const browserErrors: string[] = [];
        page.on('pageerror', (error) => browserErrors.push(error.message));
        await page.goto('/setup');
        await page.waitForURL('**/login');
        await expect(
            page.getByLabel('Email address', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: 'Create administrator',
                exact: true,
            }),
        ).toHaveCount(0);
        await page
            .getByLabel('Email address', { exact: true })
            .fill(ownerEmail);
        await page
            .getByLabel('Password', { exact: true })
            .fill(process.env.E2E_TEST_PASSWORD!);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await page.waitForURL('**/app/overview');
        await assertFundingAccess(page);
        await openCatalog(page);
        await assertSavedItem(page);

        const closedSetup = await page.request.get('/setup', {
            maxRedirects: 0,
        });
        expect(closedSetup.status()).toBe(302);
        expect(closedSetup.headers().location).toMatch(/\/login$/);
        await assertExports(page);
        expect(browserErrors).toEqual([]);
    });
});
