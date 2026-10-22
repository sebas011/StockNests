import { describe, expect, it } from 'vitest';
import {
    displayDetailKey,
    formatDate,
    formatMoney,
    formatNumber,
    idempotencyKey,
    initialFormValues,
    statusTone,
    utilization,
    type FormDefinition,
} from '../../resources/js/lib/stocknest';

const definition: FormDefinition = {
    title: 'Create request',
    action: '/app/actions/request.create',
    fields: [
        { name: 'purpose', label: 'Purpose', type: 'text' },
        { name: 'urgent', label: 'Urgent', type: 'checkbox' },
    ],
    lineFields: [
        { name: 'item_id', label: 'Item', type: 'select' },
        { name: 'quantity', label: 'Quantity', type: 'number' },
    ],
};

describe('Workflow form initialization', () => {
    it('sends explicitly declared action context while excluding unrelated model defaults', () => {
        const values = initialFormValues({
            title: 'Approve request',
            action: '/app/actions/request.decide',
            fields: [],
            defaults: {
                id: 18,
                decision: 'approved',
                password_hash: 'private',
            },
            context: { id: 18, decision: 'approved' },
        });
        expect(values.id).toBe(18);
        expect(values.decision).toBe('approved');
        expect(values).not.toHaveProperty('password_hash');
    });
    it('starts with a valid idempotency key and a usable line while preserving checkbox types', () => {
        const values = initialFormValues(definition);
        expect(values.idempotency_key).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
        );
        expect(values.urgent).toBe(false);
        expect(values.lines).toEqual([{ item_id: '', quantity: '' }]);
    });

    it('retains decimal quantities and default lines independently of the server-provided object', () => {
        const defaults = {
            purpose: 'Monthly supplies',
            lines: [
                { item_id: '8', quantity: '0.125' },
                { item_id: '9', quantity: '12.000' },
            ],
        };
        const values = initialFormValues(
            { ...definition, defaults },
            'retry-key',
        );
        expect(values.lines).toEqual(defaults.lines);
        expect(values.lines).not.toBe(defaults.lines);
        expect((values.lines as Record<string, unknown>[])[0]).not.toBe(
            defaults.lines[0],
        );
        expect(values.idempotency_key).toBe('retry-key');
    });

    it('never copies undeclared fields, secrets, or a server-provided idempotency key into a submission', () => {
        const values = initialFormValues(
            {
                ...definition,
                defaults: {
                    password_hash: 'private',
                    idempotency_key: 'old-key',
                    purpose: 'Supplies',
                    lines: [
                        {
                            item_id: '8',
                            quantity: '2',
                            available_budget: '9000',
                        },
                    ],
                },
            },
            'fresh-key',
        );
        expect(values).not.toHaveProperty('password_hash');
        expect(values.idempotency_key).toBe('fresh-key');
        expect(
            (values.lines as Record<string, unknown>[])[0],
        ).not.toHaveProperty('available_budget');
    });

    it('creates a different UUID for each new mutation', () => {
        expect(idempotencyKey()).not.toBe(idempotencyKey());
    });
});

describe('Financial and record presentation', () => {
    it('shows precise Philippine peso values and supports configured currencies', () => {
        expect(formatMoney('1250.25')).toBe('₱1,250.25');
        expect(formatMoney(10, 'USD')).toContain('10.00');
        expect(formatMoney('invalid')).toBe('—');
    });

    it('clamps visual utilization while leaving negative availability to the ledger values', () => {
        expect(utilization(1000, 200, 300)).toBe(50);
        expect(utilization(1000, 600, 700)).toBe(100);
        expect(utilization(0, 600, 700)).toBe(0);
    });

    it('keeps calendar-only dates stable in the organization timezone', () => {
        expect(formatDate('2026-10-15')).toBe('Oct 15, 2026');
        expect(formatDate('2026-10-14T17:00:00Z')).toBe('Oct 15, 2026');
        expect(formatDate('invalid')).toBe('—');
    });

    it('clearly separates completed, pending, and rejected statuses', () => {
        expect(statusTone('approved')).toBe('success');
        expect(statusTone('partially_received')).toBe('warning');
        expect(statusTone('rejected')).toBe('danger');
        expect(statusTone('draft')).toBe('neutral');
    });

    it('excludes sensitive operational values from recursive record details', () => {
        expect(displayDetailKey('password')).toBe(false);
        expect(displayDetailKey('remember_token')).toBe(false);
        expect(displayDetailKey('idempotency_key')).toBe(false);
        expect(displayDetailKey('approved_quantity')).toBe(true);
    });
});

describe('Inventory quantity precision', () => {
    it('keeps the smallest supported inventory quantity visible', () => {
        expect(formatNumber('0.0001')).toBe('0.0001');
        expect(formatNumber('12.3456')).toBe('12.3456');
    });
});
