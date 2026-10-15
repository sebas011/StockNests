// @vitest-environment jsdom
import { fireEvent, render, screen, cleanup } from '@testing-library/react';
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
