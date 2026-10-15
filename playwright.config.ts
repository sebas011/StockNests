import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
    testDir: "./tests/e2e",
    fullyParallel: false,
    workers: 1,
    timeout: 180000,
    expect: { timeout: 15000 },
    use: {
        actionTimeout: 15000,
        navigationTimeout: 30000,
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8080",
        trace: "retain-on-failure",
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
    reporter: [["list"], ["html", { open: "never" }]],
});
