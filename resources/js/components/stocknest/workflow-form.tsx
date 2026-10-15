import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    emptyLine,
    idempotencyKey,
    initialFormValues,
    type Action,
    type Field,
    type FieldValue,
    type FormDefinition,
    type FormValues,
} from '@/lib/stocknest';

function FormField({
    field,
    value,
    error,
    onChange,
    disabled,
    path,
}: {
    field: Field;
    value: FieldValue;
    error?: string;
    onChange: (value: FieldValue) => void;
    disabled: boolean;
    path: string;
}) {
    const id = useId();
    const [search, setSearch] = useState('');
    const searchable =
        field.type === 'select' && (field.options?.length ?? 0) > 8;
    const options = field.options?.filter(
        (option) =>
            !search ||
            option.label.toLowerCase().includes(search.toLowerCase()) ||
            String(option.value) === String(value),
    );
    const common = {
        id,
        name: path,
        required: field.required,
        disabled,
        'aria-invalid': Boolean(error),
        'aria-describedby': error
            ? `${id}-error`
            : field.description
              ? `${id}-description`
              : undefined,
    };
    return (
        <div
            className={`sn-form-field ${field.type === 'textarea' ? 'sn-field-wide' : ''}`}
        >
            <label htmlFor={id}>
                {field.label}
                {field.required && (
                    <span aria-hidden="true" className="sn-required">
                        {' '}
                        *
                    </span>
                )}
            </label>
            {searchable && (
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={`Find ${field.label.toLowerCase()}…`}
                    aria-label={`Search ${field.label.toLowerCase()} options`}
                    disabled={disabled}
                    className="mb-2"
                />
            )}
            {field.type === 'select' ? (
                <select
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    className="sn-input"
                >
                    <option value="">
                        {field.placeholder ?? 'Select an option'}
                    </option>
                    {options?.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            ) : field.type === 'textarea' ? (
                <textarea
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={field.placeholder}
                    className="sn-input"
                    rows={3}
                />
            ) : field.type === 'checkbox' ? (
                <div className="sn-checkbox-wrap">
                    <input
                        {...common}
                        type="checkbox"
                        checked={Boolean(value)}
                        onChange={(event) => onChange(event.target.checked)}
                    />
                    <span>Enabled</span>
                </div>
            ) : (
                <Input
                    {...common}
                    type={field.type}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={field.placeholder}
                    step={
                        field.step ??
                        (field.type === 'number' ? 'any' : undefined)
                    }
                    min={field.min}
                    max={field.max}
                    className="sn-input"
                />
            )}
            {field.description && (
                <p id={`${id}-description`} className="sn-field-description">
                    {field.description}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} role="alert" className="sn-field-error">
                    {error}
                </p>
            )}
        </div>
    );
}

export function WorkflowForm({
    definition,
    open,
    onOpenChange,
    confirmation,
}: {
    definition: FormDefinition;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    confirmation?: string;
}) {
    const [defaults] = useState(() => initialFormValues(definition));
    const form = useForm<FormValues>(defaults);
    const [reviewed, setReviewed] = useState(false);
    const [awaitingConfirmation, setAwaitingConfirmation] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;
    const lines = (form.data.lines ?? []) as Record<string, FieldValue>[];

    function submit(event: FormEvent) {
        event.preventDefault();
        if (confirmation && !reviewed) {
            setAwaitingConfirmation(true);
            return;
        }
        form.submit(definition.method ?? 'post', definition.action, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData('idempotency_key', idempotencyKey());
                setReviewed(false);
                setAwaitingConfirmation(false);
                onOpenChange(false);
            },
            onError: () => {
                setReviewed(false);
                setAwaitingConfirmation(false);
                toast.error(
                    'Please review the highlighted fields. Your entries have been kept.',
                );
            },
        });
    }

    function setLine(index: number, name: string, value: FieldValue) {
        form.setData(
            'lines',
            lines.map((line, row) =>
                row === index ? { ...line, [name]: value } : line,
            ),
        );
        setReviewed(false);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) onOpenChange(next);
            }}
        >
            <DialogContent
                className="sn-form-dialog sm:max-w-3xl"
                onEscapeKeyDown={(event) => {
                    if (form.processing) event.preventDefault();
                }}
                onInteractOutside={(event) => {
                    if (form.processing) event.preventDefault();
                }}
            >
                <DialogHeader>
                    <div className="sn-dialog-eyebrow">
                        <ShieldCheck size={15} /> Authorized workflow
                    </div>
                    <DialogTitle>{definition.title}</DialogTitle>
                    <DialogDescription>
                        {definition.description ??
                            'Enter the details below. Every submitted change is validated and recorded in the audit trail.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="sn-workflow-form"
                    aria-busy={form.processing}
                >
                    {Object.keys(errors).length > 0 && (
                        <div className="sn-form-error-summary" role="alert">
                            <strong>The change needs your attention.</strong>
                            <ul>
                                {Object.entries(errors).map(([key, error]) => (
                                    <li key={key}>{error}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="sn-form-grid">
                        {definition.fields.map((field) => (
                            <FormField
                                key={field.name}
                                field={field}
                                path={field.name}
                                value={form.data[field.name] as FieldValue}
                                error={errors[field.name]}
                                disabled={form.processing}
                                onChange={(value) => {
                                    form.setData(field.name, value);
                                    setReviewed(false);
                                }}
                            />
                        ))}
                    </div>
                    {definition.lineFields?.length ? (
                        <fieldset className="sn-lines">
                            <legend>
                                Line items <span>{lines.length}</span>
                            </legend>
                            <p className="sn-field-description">
                                Add each item separately to preserve its
                                quantity and cost.
                            </p>
                            {lines.map((line, index) => (
                                <div className="sn-line" key={index}>
                                    <div className="sn-line-heading">
                                        <strong>Item {index + 1}</strong>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={
                                                form.processing ||
                                                lines.length === 1
                                            }
                                            aria-label={`Remove item ${index + 1}`}
                                            onClick={() =>
                                                form.setData(
                                                    'lines',
                                                    lines.filter(
                                                        (_, row) =>
                                                            row !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 size={14} /> Remove
                                        </Button>
                                    </div>
                                    <div className="sn-form-grid">
                                        {definition.lineFields!.map((field) => (
                                            <FormField
                                                key={field.name}
                                                path={`lines.${index}.${field.name}`}
                                                field={field}
                                                value={line[field.name]}
                                                error={
                                                    errors[
                                                        `lines.${index}.${field.name}`
                                                    ]
                                                }
                                                disabled={form.processing}
                                                onChange={(value) =>
                                                    setLine(
                                                        index,
                                                        field.name,
                                                        value,
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                            {errors.lines && (
                                <p role="alert" className="sn-field-error">
                                    {errors.lines}
                                </p>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => {
                                    form.setData('lines', [
                                        ...lines,
                                        emptyLine(definition.lineFields!),
                                    ]);
                                    setReviewed(false);
                                }}
                            >
                                <Plus size={15} /> Add item
                            </Button>
                        </fieldset>
                    ) : null}
                    {confirmation && awaitingConfirmation && (
                        <div className="sn-confirmation">
                            <ShieldCheck size={20} />
                            <div>
                                <strong>Confirm this change</strong>
                                <p>{confirmation}</p>
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={reviewed}
                                        onChange={(event) =>
                                            setReviewed(event.target.checked)
                                        }
                                    />{' '}
                                    I have reviewed the details and authorize
                                    this change.
                                </label>
                            </div>
                        </div>
                    )}
                    <DialogFooter className="sn-form-footer">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            className="sn-primary"
                            disabled={
                                form.processing ||
                                (awaitingConfirmation && !reviewed)
                            }
                        >
                            {form.processing ? (
                                <LoaderCircle
                                    className="animate-spin"
                                    size={16}
                                />
                            ) : (
                                <ShieldCheck size={16} />
                            )}
                            {form.processing
                                ? 'Saving…'
                                : awaitingConfirmation
                                  ? 'Confirm and submit'
                                  : (definition.submitLabel ?? 'Save changes')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function ActionButton({
    action,
    compact = false,
}: {
    action: Action;
    compact?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [initialKey] = useState(idempotencyKey);
    const form = useForm({ idempotency_key: initialKey });
    const className =
        action.tone === 'primary'
            ? 'sn-primary'
            : action.tone === 'danger'
              ? 'sn-danger-action'
              : '';
    const size = compact ? 'sm' : 'default';
    if (!action.form && !action.href) return null;

    function mutate() {
        if (
            !action.href ||
            !action.method ||
            action.method === 'get' ||
            form.processing
        )
            return;
        form.submit(action.method, action.href, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('idempotency_key', idempotencyKey());
                setConfirmOpen(false);
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        'The action could not be completed. Please try again.',
                ),
        });
    }

    return (
        <>
            {action.form ? (
                <Button
                    type="button"
                    size={size}
                    variant={action.tone === 'primary' ? 'default' : 'outline'}
                    className={className}
                    onClick={() => setOpen(true)}
                >
                    {action.label}
                </Button>
            ) : !action.method || action.method === 'get' ? (
                <Button
                    size={size}
                    variant={action.tone === 'primary' ? 'default' : 'outline'}
                    className={className}
                    asChild
                >
                    {/export|download|print|csv|excel|pdf/i.test(
                        `${action.label} ${action.href}`,
                    ) ? (
                        <a href={action.href!}>{action.label}</a>
                    ) : (
                        <Link href={action.href!}>{action.label}</Link>
                    )}
                </Button>
            ) : (
                <Button
                    type="button"
                    size={size}
                    variant="outline"
                    disabled={form.processing}
                    className={className}
                    onClick={() =>
                        action.confirm ? setConfirmOpen(true) : mutate()
                    }
                >
                    {form.processing && (
                        <LoaderCircle size={14} className="animate-spin" />
                    )}
                    {action.label}
                </Button>
            )}
            {action.form && (
                <WorkflowForm
                    key={`${action.form.action}-${JSON.stringify(action.form.defaults ?? {})}`}
                    definition={action.form}
                    open={open}
                    onOpenChange={setOpen}
                    confirmation={action.confirm}
                />
            )}
            <Dialog
                open={confirmOpen}
                onOpenChange={(next) => {
                    if (!form.processing) setConfirmOpen(next);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{action.label}</DialogTitle>
                        <DialogDescription>{action.confirm}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmOpen(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            className={
                                action.tone === 'danger'
                                    ? 'bg-red-700 text-white hover:bg-red-800'
                                    : 'sn-primary'
                            }
                            onClick={mutate}
                            disabled={form.processing}
                        >
                            {form.processing && (
                                <LoaderCircle
                                    className="animate-spin"
                                    size={15}
                                />
                            )}
                            Confirm {action.label.toLowerCase()}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
