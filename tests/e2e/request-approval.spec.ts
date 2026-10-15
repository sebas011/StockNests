import { expect, test, type Browser, type Page } from "@playwright/test";

test.beforeEach(async ({ page }) => {
    page.on("pageerror", (error) => console.error("Browser application error:", error.message));
});

test("login is accessible and public signup is closed", async ({ page }) => {
    await page.goto("/login");
    await expect(page.getByLabel("Email address")).toBeVisible();
    await expect(page.getByLabel("Password", { exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Log in", exact: true })).toBeEnabled();
    await expect(page.getByRole("link", { name: "Sign up" })).toHaveCount(0);
});

async function signIn(browser: Browser, role: string) {
    const context = await browser.newContext({
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8080",
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => console.error("Browser application error:", error.message));
    await page.goto("/login");
    await page.getByLabel("Email address").fill(`${role}@stocknest.test`);
    await page.getByLabel("Password", { exact: true }).fill(process.env.E2E_TEST_PASSWORD!);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL("**/app/overview");
    await expect(page.getByText("Demonstration workspace", { exact: true })).toBeVisible();
    return { context, page };
}

async function findRequest(page: Page, reference: string) {
    await page.goto("/app/requests");
    await page.getByRole("searchbox", { name: "Search records" }).fill(reference);
    await page.getByRole("button", { name: "Search", exact: true }).click();
    await page.waitForURL((url) => url.searchParams.get("q") === reference);
    await page.getByRole("button", { name: reference, exact: true }).click();
}

async function approve(page: Page) {
    await page.getByRole("button", { name: "Approve", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "Approve", exact: true });
    if (await dialog.locator("textarea").count())
        await dialog.locator("textarea").first().fill("Verified by end-to-end workflow test.");
    const quantity = dialog.getByLabel("Approved quantity");
    if (await quantity.count()) await quantity.fill("2");
    await dialog.getByRole("button", { name: "Approve", exact: true }).click();
    await expect(dialog).not.toBeVisible();
}

test("a request passes two real approval levels without changing stock", async ({ browser }) => {
    test.skip(
        process.env.E2E_ALLOW_MUTATIONS !== "true" || !process.env.E2E_TEST_PASSWORD,
        "Requires an explicitly enabled disposable demo database and its generated password.",
    );
    const custodian = await signIn(browser, "custodian");
    const stockBeforeResponse = await custodian.page.request.get("/app/reports/stock.csv");
    expect(stockBeforeResponse.ok()).toBeTruthy();
    const stockBefore = await stockBeforeResponse.text();
    const requester = await signIn(browser, "requester");
    await requester.page.goto("/app/requests");
    await requester.page.getByRole("button", { name: "Create item request", exact: true }).click();
    const dialog = requester.page.getByRole("dialog").last();
    await dialog.getByLabel("Funding source").selectOption({ label: "GAA · GAA" });
    await dialog.getByLabel("Fiscal year").selectOption({ index: 1 });
    await dialog.getByLabel("Department").selectOption({ label: "Operations" });
    await dialog
        .getByLabel("Purpose / justification")
        .fill(`E2E monthly requirement ${Date.now()}`);
    const item = dialog.getByRole("combobox", { name: "Item", exact: true });
    const itemId = await item
        .locator("option")
        .filter({ hasText: "DEMO-PAPER-A4" })
        .getAttribute("value");
    expect(itemId).toBeTruthy();
    await item.selectOption(itemId!);
    await dialog.getByLabel("Requested quantity").fill("2");
    await dialog.getByLabel("Estimated unit price").fill("250.00");
    await dialog.getByRole("button", { name: "Create item request", exact: true }).click();
    await expect(dialog).not.toBeVisible();
    const row = requester.page.locator("tbody tr").first();
    const reference = (await row.locator(".sn-record-link").textContent())!.trim();
    await requester.page.getByRole("button", { name: reference, exact: true }).click();
    await requester.page.getByRole("button", { name: "Submit request", exact: true }).click();
    const submit = requester.page.getByRole("dialog").last();
    await submit.getByRole("button", { name: "Submit request", exact: true }).click();
    await expect(row).toContainText("Submitted");
    const head = await signIn(browser, "head");
    await findRequest(head.page, reference);
    await approve(head.page);
    await expect(head.page.locator("tbody tr").first()).toContainText("Pending review");
    const approver = await signIn(browser, "approver");
    await findRequest(approver.page, reference);
    await approve(approver.page);
    await expect(approver.page.locator("tbody tr").first()).toContainText("Approved");
    const stockAfter = await custodian.page.request.get("/app/reports/stock.csv");
    expect(await stockAfter.text()).toBe(stockBefore);
    for (const actor of [custodian, requester, head, approver]) await actor.context.close();
});
