import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import StockNest from '../../resources/js/pages/stocknest';

const navigation = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', () => {
    return {
        Head: () => null,
        Link: ({ children, ...props }: ComponentProps<'a'>) => (
            <a {...props}>{children}</a>
        ),
        usePage: () => ({
            url: '/app/catalog?q=paper&sort=name&direction=asc&page=2',
        }),
        router: {
            get: navigation.get,
            visit: navigation.visit,
            on: () => () => undefined,
        },
    };
});
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        resolvedAppearance: 'light',
        updateAppearance: vi.fn(),
    }),
}));
vi.mock('@/components/stocknest/record-details', () => ({
    RecordDetails: () => null,
    Status: ({ value }: { value: string }) => value,
}));
vi.mock('@/components/stocknest/workflow-form', () => ({
    WorkflowForm: () => null,
    ActionButton: () => null,
}));

const props = {
    page: {
        key: 'catalog',
        title: 'Catalog',
        subtitle: 'Test catalog',
        columns: [
            { key: 'name', label: 'Item name', sortKey: 'name' },
            { key: 'summary', label: 'Summary' },
        ],
        rows: [
            { id: 3, name: 'Zebra paper', summary: 'Third page record' },
            { id: 2, name: 'Alpha paper', summary: 'Second page record' },
        ],
        pagination: {
            current_page: 2,
            last_page: 3,
            total: 32,
            prev_page_url:
                '/app/catalog?q=paper&sort=name&direction=asc&page=1',
            next_page_url:
                '/app/catalog?q=paper&sort=name&direction=asc&page=3',
        },
    },
    auth: { user: { name: 'Inventory officer' }, permissions: [] },
    funds: [],
    filters: {
        q: 'paper',
        fund_id: '1',
        status: 'active',
        date_from: '2026-01-01',
        date_to: '2026-12-31',
        sort: 'name',
        direction: 'asc',
    },
};

beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

describe('Workspace capability navigation', () => {
    it.each([
        [
            'budget.approve',
            ['Approval center'],
            ['Purchasing', 'Administration'],
        ],
        ['fund.approve', ['Approval center', 'Purchasing'], ['Administration']],
        [
            'fiscal.reopen',
            ['Administration'],
            ['Approval center', 'Purchasing'],
        ],
        [
            'master-data.manage',
            ['Administration'],
            ['Approval center', 'Purchasing'],
        ],
    ])(
        'shows the available workspaces for %s alone',
        (permission, visible, hidden) => {
            render(
                <StockNest
                    {...props}
                    auth={{ ...props.auth, permissions: [permission] }}
                />,
            );
            for (const name of visible) {
                expect(screen.getByRole('link', { name })).toBeTruthy();
            }
            for (const name of hidden) {
                expect(screen.queryByRole('link', { name })).toBeNull();
            }
        },
    );
});

describe('Workspace server sorting', () => {
    it('sends an accepted server key and all filters while resetting the page and keeping the server row order', () => {
        render(<StockNest {...props} />);
        const rows = screen.getAllByRole('row');
        expect(rows[1].textContent).toContain('Zebra paper');
        expect(rows[2].textContent).toContain('Alpha paper');
        expect(
            screen
                .getByRole('columnheader', { name: 'Item name' })
                .getAttribute('aria-sort'),
        ).toBe('ascending');
        expect(screen.queryByRole('button', { name: 'Summary' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Item name' }));
        expect(navigation.get).toHaveBeenCalledWith(
            '/app/catalog',
            {
                ...props.filters,
                direction: 'desc',
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
        expect(navigation.get.mock.calls[0][1]).not.toHaveProperty('page');
    });

    it('retains the selected sort during filter changes and follows server pagination URLs unchanged', () => {
        render(<StockNest {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'ink' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Search', exact: true }),
        );
        expect(navigation.get.mock.calls[0][1]).toEqual({
            ...props.filters,
            q: 'ink',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(navigation.visit).toHaveBeenCalledWith(
            props.page.pagination.next_page_url,
            { preserveScroll: true },
        );
    });

    it('starts a newly selected sort ascending and reflects descending state from the response', () => {
        const { rerender } = render(
            <StockNest
                {...props}
                filters={{ ...props.filters, sort: 'id', direction: 'desc' }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Item name' }));
        expect(navigation.get.mock.calls[0][1]).toMatchObject({
            sort: 'name',
            direction: 'asc',
        });
        rerender(
            <StockNest
                {...props}
                filters={{ ...props.filters, direction: 'desc' }}
            />,
        );
        expect(
            screen
                .getByRole('columnheader', { name: 'Item name' })
                .getAttribute('aria-sort'),
        ).toBe('descending');
    });
});
