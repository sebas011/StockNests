import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
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

describe('Office Supply tracking', () => {
    it('shows the office workflow without the internal approval workspace', () => {
        render(
            <StockNest
                {...props}
                officeWorkflow
                auth={{ ...props.auth, permissions: ['*'] }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'Approval center' }),
        ).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Approved annual items' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Outside purchases' }),
        ).toBeTruthy();
    });

    it('shows approved, submitted, received and remaining quantities independently', () => {
        render(
            <StockNest
                {...props}
                officeWorkflow
                page={{ ...props.page, key: 'projections' }}
                allowanceRows={[
                    {
                        item_id: 1,
                        item: 'Copy paper',
                        unit: 'Ream',
                        fund: 'GAA',
                        year: 2026,
                        approved_quantity: '100.0000',
                        requested_quantity: '25.0000',
                        received_quantity: '10.0000',
                        remaining_quantity: '75.0000',
                    },
                ]}
            />,
        );
        const allowance = screen.getByRole('region', {
            name: 'Annual Supply allowance',
        });
        expect(
            within(allowance).getByRole('columnheader', {
                name: 'Left to request',
            }),
        ).toBeTruthy();
        const row = within(allowance).getByRole('row', { name: /Copy paper/ });
        expect(
            within(row)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['Ream', 'GAA', '2026', '100', '25', '10', '75']);
        expect(
            within(allowance).getByText(
                /Outside cash purchases are tracked separately/,
            ),
        ).toBeTruthy();
    });

    it('shows monthly Supply activity separately from outside receipts', () => {
        render(
            <StockNest
                {...props}
                officeWorkflow
                page={{ ...props.page, key: 'overview' }}
                monthlyStats={[
                    {
                        month: '2026-10',
                        item: 'Copy paper',
                        unit: 'Ream',
                        fund: 'GAA',
                        requested_quantity: '5',
                        received_quantity: '3',
                        outside_quantity: '2',
                        outside_received_quantity: '1',
                    },
                ]}
            />,
        );
        const activity = screen.getByRole('region', {
            name: 'Monthly item statistics',
        });
        expect(
            within(activity).getByRole('columnheader', {
                name: 'Requested from Supply',
            }),
        ).toBeTruthy();
        expect(
            within(activity).getByRole('columnheader', {
                name: 'Outside items received',
            }),
        ).toBeTruthy();
        expect(
            within(activity).getByRole('row', { name: /2026-10 Copy paper/ }),
        ).toBeTruthy();
    });
});
