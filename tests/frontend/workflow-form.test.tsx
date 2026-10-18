// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import {
    fireEvent,
    render,
    screen,
    cleanup,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkflowForm } from '../../resources/js/components/stocknest/workflow-form';
import type { FormDefinition } from '../../resources/js/lib/stocknest';

const transport = vi.hoisted(() => ({
    submissions: [] as {
        data: Record<string, unknown>;
        method: string;
        url: string;
    }[],
    outcome: 'validation' as 'validation' | 'success',
    close: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: ({ children }: { children: React.ReactNode }) => children,
        useForm(initial: Record<string, unknown>) {
            const [data, update] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                setData(name: string, value: unknown) {
                    update((previous) => ({ ...previous, [name]: value }));
                },
                reset() {
                    update(initial);
                    setErrors({});
                },
                submit(
                    method: string,
                    url: string,
                    options: {
                        onError?: (errors: Record<string, string>) => void;
                        onSuccess?: () => void;
                    },
                ) {
                    transport.submissions.push({
                        data: structuredClone(data),
                        method,
                        url,
                    });
                    if (transport.outcome === 'validation') {
                        const next = {
                            'lines.0.quantity':
                                'The quantity exceeds the approved remainder.',
                        };
                        setErrors(next);
                        options.onError?.(next);
                    } else options.onSuccess?.();
                },
            };
        },
    };
});

const definition: FormDefinition = {
    title: 'Issue approved items',
    action: '/app/actions/stock.issue',
    submitLabel: 'Confirm issuance',
    fields: [
        { name: 'recipient', label: 'Recipient', type: 'text', required: true },
    ],
    lineFields: [
        {
            name: 'item_id',
            label: 'Item',
            type: 'select',
            required: true,
            options: [{ value: '5', label: 'Bond paper' }],
        },
        { name: 'quantity', label: 'Quantity', type: 'number', required: true },
    ],
    defaults: {
        recipient: 'Maria Santos',
        lines: [{ item_id: '5', quantity: '4' }],
    },
};

beforeEach(() => {
    transport.submissions.length = 0;
    transport.outcome = 'validation';
    transport.close.mockReset();
});
afterEach(cleanup);

describe('Controlled workflow submissions', () => {
    it('returns focus to the button that opened a controlled workflow dialog', async () => {
        function FormLauncher() {
            const [open, setOpen] = useState(false);
            return (
                <>
                    <button type="button" onClick={() => setOpen(true)}>
                        Open issuance
                    </button>
                    <WorkflowForm
                        definition={definition}
                        open={open}
                        onOpenChange={setOpen}
                    />
                </>
            );
        }

        render(<FormLauncher />);
        const opener = screen.getByRole('button', { name: 'Open issuance' });
        opener.focus();
        fireEvent.click(opener);
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(
            screen.getByRole('textbox', { name: 'Recipient' }),
        ).toHaveFocus();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(opener).toHaveFocus());
    });

    it('preserves per-line option searches across immutable edits and a middle deletion without sending client keys', () => {
        const searchable: FormDefinition = {
            ...definition,
            lineFields: [
                {
                    name: 'item_id',
                    label: 'Item',
                    type: 'select',
                    options: Array.from({ length: 10 }, (_, index) => ({
                        value: String(index + 1),
                        label: `Catalog item ${index + 1}`,
                    })),
                },
                { name: 'quantity', label: 'Quantity', type: 'number' },
            ],
            defaults: {
                recipient: 'Maria Santos',
                lines: [
                    { item_id: '1', quantity: '1' },
                    { item_id: '2', quantity: '2' },
                    { item_id: '3', quantity: '3' },
                ],
            },
        };
        render(
            <WorkflowForm
                definition={searchable}
                open
                onOpenChange={transport.close}
            />,
        );
        const searches = screen.getAllByRole('searchbox', {
            name: 'Search item options',
        }) as HTMLInputElement[];
        fireEvent.change(searches[0], { target: { value: 'first search' } });
        fireEvent.change(searches[1], { target: { value: 'middle search' } });
        fireEvent.change(searches[2], { target: { value: 'last search' } });
        fireEvent.change(screen.getAllByLabelText('Quantity')[2], {
            target: { value: '3.125' },
        });
        expect(
            (screen.getAllByRole('searchbox')[2] as HTMLInputElement).value,
        ).toBe('last search');
        fireEvent.click(screen.getByRole('button', { name: 'Remove item 2' }));
        const remaining = screen.getAllByRole(
            'searchbox',
        ) as HTMLInputElement[];
        expect(remaining.map((search) => search.value)).toEqual([
            'first search',
            'last search',
        ]);
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(transport.submissions[0].data.lines).toEqual([
            { item_id: '1', quantity: '1' },
            { item_id: '3', quantity: '3.125' },
        ]);
    });

    it('submits permission selections as an array of known option values', () => {
        const roles: FormDefinition = {
            title: 'Edit role',
            action: '/app/roles',
            fields: [
                {
                    name: 'permissions',
                    label: 'Permissions',
                    type: 'multiselect',
                    options: [
                        {
                            value: 'requests.approve',
                            label: 'Approve requests',
                        },
                        { value: 'fund.approve', label: 'Approve funding' },
                    ],
                },
            ],
            defaults: { permissions: ['requests.approve'] },
        };
        render(
            <WorkflowForm
                definition={roles}
                open
                onOpenChange={transport.close}
            />,
        );
        expect(
            (
                screen.getByRole('checkbox', {
                    name: 'Approve requests',
                }) as HTMLInputElement
            ).checked,
        ).toBe(true);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Approve funding' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Approve requests' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.submissions[0].data.permissions).toEqual([
            'fund.approve',
        ]);
    });

    it('keeps approval lines fixed and their identifiers hidden while allowing per-line approved quantities', () => {
        const approval: FormDefinition = {
            title: 'Approve request',
            action: '/app/actions/request.decide',
            fields: [],
            fixedLines: true,
            lineFields: [
                { name: 'line_id', label: 'Line ID', type: 'hidden' },
                {
                    name: 'item',
                    label: 'Requested item',
                    type: 'text',
                    readOnly: true,
                },
                {
                    name: 'quantity',
                    label: 'Requested quantity',
                    type: 'number',
                    readOnly: true,
                },
                {
                    name: 'approved_quantity',
                    label: 'Approved quantity',
                    type: 'number',
                    min: '0',
                    step: '0.0001',
                },
            ],
            defaults: {
                lines: [
                    {
                        line_id: 12,
                        item: 'Bond paper',
                        quantity: '10',
                        approved_quantity: '10',
                    },
                    {
                        line_id: 24,
                        item: 'Ink',
                        quantity: '4',
                        approved_quantity: '4',
                    },
                ],
            },
        };
        render(
            <WorkflowForm
                definition={approval}
                open
                onOpenChange={transport.close}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Add item' })).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Remove item/ }),
        ).toBeNull();
        expect(screen.queryByLabelText('Line ID')).toBeNull();
        expect(
            (screen.getAllByLabelText('Requested item')[0] as HTMLInputElement)
                .readOnly,
        ).toBe(true);
        expect(
            (
                screen.getAllByLabelText(
                    'Requested quantity',
                )[0] as HTMLInputElement
            ).readOnly,
        ).toBe(true);
        fireEvent.change(screen.getAllByLabelText('Approved quantity')[1], {
            target: { value: '2.5001' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.submissions[0].data.lines).toEqual([
            {
                line_id: 12,
                item: 'Bond paper',
                quantity: '10',
                approved_quantity: '10',
            },
            {
                line_id: 24,
                item: 'Ink',
                quantity: '4',
                approved_quantity: '2.5001',
            },
        ]);
    });

    it('respects field-specific decimal and signed adjustment constraints', () => {
        const amounts: FormDefinition = {
            title: 'Adjust allocation',
            action: '/app/actions/allocation.create',
            fields: [
                {
                    name: 'amount',
                    label: 'Amount to add / reduce',
                    type: 'number',
                    min: '-99999999999999.99',
                    max: '99999999999999.99',
                    step: '0.01',
                },
                {
                    name: 'quantity',
                    label: 'Quantity',
                    type: 'number',
                    min: '0',
                    step: '0.0001',
                },
            ],
        };
        render(
            <WorkflowForm
                definition={amounts}
                open
                onOpenChange={transport.close}
            />,
        );
        const amount = screen.getByLabelText(
            'Amount to add / reduce',
        ) as HTMLInputElement;
        const quantity = screen.getByLabelText('Quantity') as HTMLInputElement;
        expect(amount.inputMode).toBe('decimal');
        expect(amount.min).toBe('-99999999999999.99');
        expect(amount.step).toBe('0.01');
        expect(quantity.min).toBe('0');
        expect(quantity.step).toBe('0.0001');
        fireEvent.change(amount, { target: { value: '-12.25' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.submissions[0].data.amount).toBe('-12.25');
    });

    it('adds independent lines, permits removing only the intended line, and submits all remaining lines', () => {
        render(
            <WorkflowForm
                definition={definition}
                open
                onOpenChange={transport.close}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add item' }));
        const quantities = screen.getAllByLabelText(/Quantity/);
        expect(quantities).toHaveLength(2);
        fireEvent.change(quantities[1], { target: { value: '2.5' } });
        fireEvent.change(screen.getAllByLabelText(/Item/)[1], {
            target: { value: '5' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Remove item 1' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(transport.submissions[0].data.lines).toEqual([
            { item_id: '5', quantity: '2.5' },
        ]);
    });

    it('keeps the retry key and entries after validation, shows nested line errors, and regenerates the key after success', () => {
        render(
            <WorkflowForm
                definition={definition}
                open
                onOpenChange={transport.close}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(
            screen.getAllByText('The quantity exceeds the approved remainder.')
                .length,
        ).toBeGreaterThan(0);
        const quantity = screen.getByLabelText(/Quantity/) as HTMLInputElement;
        expect(quantity.getAttribute('aria-invalid')).toBe('true');
        expect(quantity.value).toBe('4');
        fireEvent.change(quantity, { target: { value: '3' } });
        transport.outcome = 'success';
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(transport.submissions[1].data.idempotency_key).toBe(
            transport.submissions[0].data.idempotency_key,
        );
        expect(transport.close).toHaveBeenCalledWith(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(transport.submissions[2].data.idempotency_key).not.toBe(
            transport.submissions[1].data.idempotency_key,
        );
    });

    it('requires explicit review before a confirmed inventory mutation is sent', () => {
        render(
            <WorkflowForm
                definition={definition}
                open
                onOpenChange={transport.close}
                confirmation="This will release stock to the recipient."
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm issuance' }),
        );
        expect(transport.submissions).toHaveLength(0);
        expect(
            (
                screen.getByRole('button', {
                    name: 'Confirm and submit',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        fireEvent.click(
            screen.getByLabelText(
                'I have reviewed the details and authorize this change.',
            ),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm and submit' }),
        );
        expect(transport.submissions).toHaveLength(1);
        expect(transport.submissions[0].url).toBe('/app/actions/stock.issue');
    });
});
