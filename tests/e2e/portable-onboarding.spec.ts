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
    await expect(
        page.getByRole('link', { name: 'Approval center', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByText('Office of the Vice President for Academic Affairs', {
            exact: false,
        }),
    ).toBeVisible();
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

async function assertExports(page: Page, expectedStock: boolean) {
    const csv = await page.request.get('/app/reports/stock.csv', {
        timeout: 60000,
    });
    expect(csv.status()).toBe(200);
    expect(csv.headers()['content-type']).toContain('text/csv');
    expect(csv.headers()['content-disposition']).toMatch(/attachment;.*\.csv/i);
    const stockCsv = (await csv.text()).replace(/^\uFEFF/, '').trim();
    if (expectedStock) {
        expect(stockCsv).toContain(itemSku);
        expect(stockCsv).toMatch(
            /PORTABLE-CHECK,"?Portable verification item"?,2\.0000,/,
        );
    } else {
        // A catalog entry alone creates no inventory.
        expect(stockCsv).toBe('"No matching records"');
    }

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

async function assertAnnualBalances(page: Page) {
    await page
        .getByRole('link', { name: 'Approved annual items', exact: true })
        .click();
    await page.waitForURL('**/app/projections');
    const allowance = page.getByRole('region', {
        name: 'Annual Supply allowance',
    });
    const item = allowance.getByRole('row').filter({ hasText: itemName });
    await expect(item).toHaveCount(1);
    const cells = item.getByRole('cell');
    await expect(cells.nth(3)).toHaveText('10');
    await expect(cells.nth(4)).toHaveText('3');
    await expect(cells.nth(5)).toHaveText('2');
    await expect(cells.nth(6)).toHaveText('7');

    const csv = await page.request.get('/app/reports/allowance.csv', {
        timeout: 60000,
    });
    expect(csv.status()).toBe(200);
    const contents = await csv.text();
    expect(contents).toContain(itemSku);
    expect(contents).toContain('10.0000,3.0000,2.0000,7.0000,1.0000');

    await page.getByRole('link', { name: 'Inventory', exact: true }).click();
    await page.waitForURL('**/app/inventory');
    const stock = page.getByRole('row').filter({ hasText: itemSku });
    await expect(stock).toHaveCount(1);
    await expect(stock.getByRole('cell').nth(4)).toHaveText('2');
}

async function exerciseSupplyWorkflow(page: Page) {
    await page
        .getByRole('link', { name: 'Approved annual items', exact: true })
        .click();
    await page.waitForURL('**/app/projections');
    await page
        .getByRole('button', {
            name: 'Encode approved annual items',
            exact: true,
        })
        .click();
    const plan = page.getByRole('dialog', {
        name: 'Encode approved annual items',
        exact: true,
    });
    await expect(plan).toBeVisible();
    await expect(plan.getByLabel(/^Department\s*\*?$/)).toHaveCount(0);
    await plan
        .getByLabel(/^Funding source\s*\*?$/)
        .selectOption({ label: 'GAA · GAA' });
    await plan.getByLabel(/^Fiscal year\s*\*?$/).selectOption({ index: 1 });
    await plan
        .getByLabel(/^Supply approval reference \/ details\s*\*?$/)
        .fill('PORTABLE-ANNUAL-APPROVAL');
    await plan
        .getByLabel(/^Approved item\s*\*?$/)
        .selectOption({ label: `${itemSku} · ${itemName}` });
    await plan.getByLabel(/^Approved annual quantity\s*\*?$/).fill('10');
    await plan
        .getByLabel(/^Approved estimated unit price\s*\*?$/)
        .fill('25.00');
    await plan
        .getByRole('button', {
            name: 'Encode approved annual items',
            exact: true,
        })
        .click();
    await expect(plan).not.toBeVisible({ timeout: 60000 });
    const allowance = page.getByRole('region', {
        name: 'Annual Supply allowance',
    });
    await expect(
        allowance
            .getByRole('row')
            .filter({ hasText: itemName })
            .getByRole('cell')
            .nth(6),
    ).toHaveText('10');

    await page
        .getByRole('link', { name: 'Item requests', exact: true })
        .click();
    await page.waitForURL('**/app/requests');
    await page
        .getByRole('button', { name: 'Create item request', exact: true })
        .click();
    const request = page.getByRole('dialog', {
        name: 'Create item request',
        exact: true,
    });
    await expect(request).toBeVisible();
    await expect(request.getByLabel(/^Department\s*\*?$/)).toHaveCount(0);
    await request
        .getByLabel(/^Funding source\s*\*?$/)
        .selectOption({ label: 'GAA · GAA' });
    await request.getByLabel(/^Fiscal year\s*\*?$/).selectOption({ index: 1 });
    await request.getByLabel(/^Request type\s*\*?$/).selectOption('supply');
    await request
        .getByLabel(/^Purpose \/ reason Supply cannot provide the items\s*\*?$/)
        .fill('Portable office workflow verification');
    await request
        .getByLabel(/^Item\s*\*?$/)
        .selectOption({ label: `${itemSku} · ${itemName}` });
    await request.getByLabel(/^Requested quantity\s*\*?$/).fill('3');
    await request.getByLabel(/^Estimated unit price\s*\*?$/).fill('25.00');
    await request
        .getByRole('button', { name: 'Create item request', exact: true })
        .click();
    await expect(request).not.toBeVisible({ timeout: 60000 });

    const requestRow = page
        .getByRole('row')
        .filter({ hasText: 'Supply items' });
    await expect(requestRow).toHaveCount(1);
    await requestRow.getByRole('button', { name: /^View / }).click();
    await page
        .getByRole('button', { name: 'Submit to Supply', exact: true })
        .click();
    const submission = page.getByRole('dialog', {
        name: 'Submit to Supply',
        exact: true,
    });
    await submission
        .getByRole('button', { name: 'Submit to Supply', exact: true })
        .click();
    await expect(submission).not.toBeVisible({ timeout: 60000 });

    await page
        .getByRole('button', { name: 'Record Supply receipt', exact: true })
        .click();
    const receipt = page.getByRole('dialog', {
        name: 'Record Supply receipt',
        exact: true,
    });
    await expect(receipt).toBeVisible();
    await receipt
        .getByLabel(/^Office storage location\s*\*?$/)
        .selectOption({ index: 1 });
    await receipt
        .getByLabel(/^Supply issuance \/ delivery reference\s*\*?$/)
        .fill('PORTABLE-SUPPLY-RECEIPT');
    await receipt
        .getByLabel(/^Requested item\s*\*?$/)
        .selectOption({ index: 1 });
    await receipt.getByLabel(/^Quantity received\s*\*?$/).fill('2');
    await receipt
        .getByRole('button', { name: 'Record Supply receipt', exact: true })
        .click();
    await expect(receipt).not.toBeVisible({ timeout: 60000 });
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Close', exact: true })
        .click();
    await assertAnnualBalances(page);
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

    test('first launch saves approved annual items and tracks a real Supply request and receipt', async ({
        page,
    }) => {
        test.skip(
            phase !== 'first',
            'Run before restarting the freshly assembled portable package.',
        );

        test.setTimeout(300000);
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
        await assertExports(page, false);
        await exerciseSupplyWorkflow(page);
        await assertExports(page, true);
        expect(browserErrors).toEqual([]);
    });

    test('a full restart preserves the office allowance and stock and keeps setup closed', async ({
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
        await assertAnnualBalances(page);
        await assertExports(page, true);
        expect(browserErrors).toEqual([]);
    });
});
