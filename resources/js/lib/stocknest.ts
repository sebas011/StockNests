export type FieldValue = string | number | boolean | null;
export type FieldInputValue = FieldValue | string[];
export type LineValues = Record<string, FieldInputValue>;
export type FormValues = {
    [key: string]: FieldInputValue | FieldValue[] | LineValues[];
};

export type Field = {
    name: string;
    label: string;
    type:
        | 'text'
        | 'textarea'
        | 'number'
        | 'select'
        | 'multiselect'
        | 'hidden'
        | 'date'
        | 'email'
        | 'password'
        | 'checkbox';
    required?: boolean;
    readOnly?: boolean;
    options?: { value: string; label: string }[];
    placeholder?: string;
    step?: string;
    description?: string;
    min?: string | number;
    max?: string | number;
};

export type FormDefinition = {
    title: string;
    action: string;
    method?: 'post' | 'put' | 'patch';
    fields: Field[];
    lineFields?: Field[];
    fixedLines?: boolean;
    defaults?: Record<string, unknown>;
    context?: Partial<
        Record<
            | 'id'
            | 'kind'
            | 'decision'
            | 'item_request_id'
            | 'original_projection_id',
            FieldValue
        >
    >;
    submitLabel?: string;
    description?: string;
};

export type Action = {
    label: string;
    href?: string;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    tone?: 'default' | 'danger' | 'primary';
    form?: FormDefinition;
    confirm?: string;
};

export type Column = {
    key: string;
    label: string;
    format?: 'money' | 'number' | 'date' | 'status' | 'text';
    sortKey?: string;
};
export type WorkspaceRow = {
    id: number | string;
    details?: Record<string, unknown>;
    actions?: Action[];
    [key: string]: unknown;
};

export function idempotencyKey(): string {
    return globalThis.crypto.randomUUID();
}

function scalar(value: unknown, fallback: FieldValue): FieldValue {
    return typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        value === null
        ? value
        : fallback;
}

export function emptyLine(
    fields: Field[],
    defaults: Record<string, unknown> = {},
): LineValues {
    return Object.fromEntries(
        fields.map((field) => [
            field.name,
            field.type === 'multiselect'
                ? Array.isArray(defaults[field.name])
                    ? (defaults[field.name] as unknown[]).filter(
                          (value): value is string => typeof value === 'string',
                      )
                    : []
                : scalar(
                      defaults[field.name],
                      field.type === 'checkbox' ? false : '',
                  ),
        ]),
    );
}

export function initialFormValues(
    definition: FormDefinition,
    key = idempotencyKey(),
): FormValues {
    const defaults = definition.defaults ?? {};
    const context = Object.fromEntries(
        ['id', 'kind', 'decision', 'item_request_id', 'original_projection_id']
            .filter((name) => Object.hasOwn(definition.context ?? {}, name))
            .map((name) => [
                name,
                scalar(
                    (definition.context as Record<string, unknown>)[name],
                    null,
                ),
            ]),
    );
    const values: FormValues = {
        ...context,
        ...emptyLine(definition.fields, defaults),
        idempotency_key: key,
    };

    if (definition.lineFields?.length) {
        const defaultLines = Array.isArray(defaults.lines)
            ? defaults.lines
            : [{}];
        values.lines = defaultLines.map((line) =>
            emptyLine(
                definition.lineFields!,
                typeof line === 'object' && line !== null
                    ? (line as Record<string, unknown>)
                    : {},
            ),
        );
    }

    return values;
}

export function humanize(value: string): string {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[_.-]+/g, ' ')
        .replace(/^\w/, (letter) => letter.toUpperCase());
}

export function formatMoney(value: unknown, currency = 'PHP'): string {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function formatNumber(value: unknown): string {
    const amount = Number(value);
    return Number.isFinite(amount)
        ? new Intl.NumberFormat('en-PH', { maximumFractionDigits: 4 }).format(
              amount,
          )
        : '—';
}

export function formatDate(value: unknown, timezone = 'Asia/Manila'): string {
    if (
        !value ||
        (typeof value !== 'string' &&
            typeof value !== 'number' &&
            !(value instanceof Date))
    )
        return '—';
    // Date-only values represent calendar dates, so avoid shifting them by timezone.
    const source = String(value);
    const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(source);
    const date = new Date(dateOnly ? `${source}T12:00:00Z` : source);
    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat('en-PH', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              timeZone: dateOnly ? 'UTC' : timezone,
          }).format(date);
}

export function formatValue(
    value: unknown,
    format = 'text',
    currency = 'PHP',
    timezone = 'Asia/Manila',
): string {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'money') return formatMoney(value, currency);
    if (format === 'number') return formatNumber(value);
    if (format === 'date') return formatDate(value, timezone);
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return 'View details';
    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'bigint' ||
        typeof value === 'symbol'
    )
        return String(value);
    return '—';
}

export function statusTone(value: unknown): string {
    const status = (typeof value === 'string' ? value : '')
        .toLowerCase()
        .replaceAll(' ', '_');
    if (
        /^(approved|fulfilled|received|reconciled|closed|active|paid|completed)$/.test(
            status,
        )
    )
        return 'success';
    if (/reject|cancel|inactive|negative|overdue/.test(status)) return 'danger';
    if (
        /pending|submit|review|partial|counting|ordered|revision|reserved/.test(
            status,
        )
    )
        return 'warning';
    return 'neutral';
}

export function utilization(
    allocation: number,
    committed: number,
    spent: number,
): number {
    return allocation > 0
        ? Math.max(0, Math.min(100, ((committed + spent) / allocation) * 100))
        : 0;
}

export function displayDetailKey(key: string): boolean {
    return !/password|secret|api_token|remember_token|idempotency_key/i.test(
        key,
    );
}
