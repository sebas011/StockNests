import { ChevronRight, FileText, ShieldCheck } from 'lucide-react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    displayDetailKey,
    formatValue,
    humanize,
    statusTone,
    type WorkspaceRow,
} from '@/lib/stocknest';
import { ActionButton } from './workflow-form';

export function Status({ value }: { value: unknown }) {
    return (
        <span className={`sn-status sn-status-${statusTone(value)}`}>
            <span aria-hidden="true" />
            {humanize(String(value ?? 'Unknown'))}
        </span>
    );
}

function detailFormat(key: string): string {
    if (/(?:^|_)(date|at)$/.test(key) || /_date$/.test(key)) return 'date';
    if (
        /price|cost|amount|allocation|committed|spent|available_budget|balance_amount|expenditure/.test(
            key,
        )
    )
        return 'money';
    if (
        /quantity|variance|stock|total_units/.test(key) &&
        !/status|location|item|code|reference/.test(key)
    )
        return 'number';
    return 'text';
}

function Scalar({
    value,
    name,
    currency,
    timezone,
}: {
    value: unknown;
    name: string;
    currency: string;
    timezone: string;
}) {
    if (/status$/.test(name)) return <Status value={value} />;
    if (
        name === 'href' &&
        typeof value === 'string' &&
        /^(\/[^/]|https?:\/\/)/.test(value)
    )
        return (
            <a
                href={value}
                className="sn-text-link"
                target={value.startsWith('http') ? '_blank' : undefined}
                rel="noopener noreferrer"
            >
                Open document
            </a>
        );
    return <>{formatValue(value, detailFormat(name), currency, timezone)}</>;
}

function DetailValue({
    value,
    name,
    currency,
    timezone,
    depth = 0,
}: {
    value: unknown;
    name: string;
    currency: string;
    timezone: string;
    depth?: number;
}) {
    if (value === null || typeof value !== 'object')
        return (
            <Scalar
                value={value}
                name={name}
                currency={currency}
                timezone={timezone}
            />
        );
    if (depth > 6)
        return <span className="sn-muted">Additional nested information</span>;
    if (Array.isArray(value)) {
        if (!value.length)
            return (
                <p className="sn-muted">
                    No {humanize(name).toLowerCase()} recorded.
                </p>
            );
        if (
            value.every(
                (item) =>
                    item && typeof item === 'object' && !Array.isArray(item),
            )
        ) {
            const records = value as Record<string, unknown>[];
            const columns = [
                ...new Set(records.flatMap((record) => Object.keys(record))),
            ].filter(displayDetailKey);
            return (
                <div className="sn-detail-table-wrap">
                    <table className="sn-detail-table">
                        <thead>
                            <tr>
                                {columns.map((key) => (
                                    <th key={key}>{humanize(key)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {records.map((record, index) => (
                                <tr key={index}>
                                    {columns.map((key) => (
                                        <td key={key}>
                                            <DetailValue
                                                value={record[key]}
                                                name={key}
                                                currency={currency}
                                                timezone={timezone}
                                                depth={depth + 1}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            );
        }
        return (
            <ul className="sn-detail-list">
                {value.map((entry, index) => (
                    <li key={index}>
                        <DetailValue
                            value={entry}
                            name={name}
                            currency={currency}
                            timezone={timezone}
                            depth={depth + 1}
                        />
                    </li>
                ))}
            </ul>
        );
    }
    return (
        <dl className="sn-details-grid">
            {Object.entries(value as Record<string, unknown>)
                .filter(([key]) => displayDetailKey(key))
                .map(([key, detail]) => (
                    <div
                        key={key}
                        className={
                            detail && typeof detail === 'object'
                                ? 'sn-detail-wide'
                                : ''
                        }
                    >
                        <dt>{humanize(key)}</dt>
                        <dd>
                            <DetailValue
                                value={detail}
                                name={key}
                                currency={currency}
                                timezone={timezone}
                                depth={depth + 1}
                            />
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

const workflows: Record<string, string[]> = {
    projections: ['Draft', 'Submitted', 'Under review', 'Approved'],
    requests: [
        'Draft',
        'Submitted',
        'Approved',
        'Partially fulfilled',
        'Fulfilled',
        'Closed',
    ],
    purchasing: [
        'Draft',
        'Submitted',
        'Approved',
        'Partially received',
        'Received',
        'Closed',
    ],
    counts: ['Counting', 'Submitted', 'Approved', 'Reconciled', 'Closed'],
};

export function RecordDetails({
    row,
    open,
    onOpenChange,
    title,
    section,
    currency,
    timezone,
}: {
    row: WorkspaceRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    section: string;
    currency: string;
    timezone: string;
}) {
    const status = row?.status;
    const steps = workflows[section];
    const details =
        row?.details ??
        Object.fromEntries(
            Object.entries(row ?? {}).filter(
                ([key]) => !['id', 'actions', 'details'].includes(key),
            ),
        );
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sn-record-sheet w-full sm:max-w-3xl">
                <SheetHeader className="sn-record-header">
                    <div className="sn-dialog-eyebrow">
                        <FileText size={15} /> Record details
                    </div>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>
                        Review the source information, related lines, and
                        authorized actions.
                    </SheetDescription>
                    {status !== undefined && (
                        <div className="mt-3">
                            <Status value={status} />
                        </div>
                    )}
                </SheetHeader>
                <div className="sn-record-body">
                    {steps && (
                        <section className="sn-workflow-guide">
                            <h3>Workflow guide</h3>
                            <ol>
                                {steps.map((step, index) => (
                                    <li
                                        key={step}
                                        aria-current={
                                            String(status ?? '')
                                                .toLowerCase()
                                                .replaceAll('_', ' ') ===
                                            step.toLowerCase()
                                                ? 'step'
                                                : undefined
                                        }
                                    >
                                        {step}
                                        {index < steps.length - 1 && (
                                            <ChevronRight
                                                size={12}
                                                aria-hidden="true"
                                            />
                                        )}
                                    </li>
                                ))}
                            </ol>
                            <p>
                                Approvals and inventory movements are recorded
                                as separate transactions.
                            </p>
                        </section>
                    )}
                    <DetailValue
                        value={details}
                        name="record"
                        currency={currency}
                        timezone={timezone}
                    />
                </div>
                <div className="sn-record-footer">
                    <p>
                        <ShieldCheck size={15} /> Actions follow your assigned
                        permissions.
                    </p>
                    <div>
                        {row?.actions?.map((action, index) => (
                            <ActionButton
                                key={`${action.label}-${index}`}
                                action={action}
                            />
                        ))}
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    );
}
