import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowDownUp,
    ArrowRight,
    ArrowUpRight,
    Bell,
    Boxes,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    ClipboardCheck,
    ClipboardList,
    FileBarChart2,
    FolderKanban,
    Landmark,
    LayoutDashboard,
    ListChecks,
    LogOut,
    Menu,
    Moon,
    Package,
    PanelLeftClose,
    Plus,
    Search,
    Settings2,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Sun,
    Users,
    Wallet,
    X,
    type LucideIcon,
} from 'lucide-react';
import {
    lazy,
    Suspense,
    useEffect,
    useRef,
    useState,
    type FormEvent,
} from 'react';
import { toast } from 'sonner';
import {
    ActionButton,
    WorkflowForm,
} from '@/components/stocknest/workflow-form';
import { RecordDetails, Status } from '@/components/stocknest/record-details';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAppearance } from '@/hooks/use-appearance';
import {
    formatDate,
    formatMoney,
    formatNumber,
    formatValue,
    humanize,
    idempotencyKey,
    utilization,
    type Action,
    type Column,
    type FormDefinition,
    type WorkspaceRow,
} from '@/lib/stocknest';

const OverviewChart = lazy(
    () => import('@/components/stocknest/overview-chart'),
);

type Filters = {
    q?: string;
    status?: string;
    fund_id?: string;
    date_from?: string;
    date_to?: string;
    sort?: string;
    direction?: string;
};
type BudgetFund = {
    id: number | string;
    name: string;
    allocation: number;
    committed: number;
    spent: number;
    available: number;
    original: number;
    revised: number;
};
type Props = {
    page: {
        key: string;
        title: string;
        subtitle: string;
        columns: Column[];
        rows: WorkspaceRow[];
        pagination?: {
            current_page: number;
            last_page: number;
            total: number;
            prev_page_url: string | null;
            next_page_url: string | null;
        };
    };
    auth: { user: { name: string; email?: string }; permissions?: string[] };
    filters: Filters;
    funds: { id: number | string; label: string }[];
    selectedFund?: string;
    metrics?: {
        label: string;
        value: number | string;
        format?: string;
        helper?: string;
        tone?: string;
    }[];
    createForm?: FormDefinition;
    pageActions?: Action[];
    chartData?: { month: string; projected: number; actual: number }[];
    budgetFunds?: BudgetFund[];
    activity?: {
        id: number | string;
        title: string;
        description: string;
        date: string;
        type: string;
    }[];
    notices?: { title: string; description: string; tone?: string }[];
    flash?: { success?: string; error?: string };
    demo?: boolean | string;
    isDemo?: boolean;
    demoMode?: boolean;
    currency?: string;
    timezone?: string;
    fiscalYear?:
        | string
        | number
        | { name?: string; label?: string; year?: number };
    organization?: string | { name?: string };
    officeWorkflow?: boolean;
    allowanceRows?: AllowanceRow[];
    monthlyStats?: MonthlyItemRow[];
};

type AllowanceRow = {
    item_id?: number | string;
    item: string;
    unit: string;
    fund: string;
    year: number | string;
    approved_quantity: string | number;
    requested_quantity: string | number;
    received_quantity: string | number;
    remaining_quantity: string | number;
};
type MonthlyItemRow = {
    month: string;
    item: string;
    unit: string;
    fund: string;
    requested_quantity: string | number;
    received_quantity: string | number;
    outside_quantity: string | number;
    outside_received_quantity: string | number;
};

type NavItem = {
    key: string;
    label: string;
    icon: LucideIcon;
    permissions: string[];
};
const navigation: { title: string; items: NavItem[] }[] = [
    {
        title: 'Workspace',
        items: [
            {
                key: 'overview',
                label: 'Overview',
                icon: LayoutDashboard,
                permissions: ['dashboard.view'],
            },
            {
                key: 'funding',
                label: 'Funding & budgets',
                icon: Landmark,
                permissions: ['budgets.manage'],
            },
            {
                key: 'catalog',
                label: 'Item catalog',
                icon: Boxes,
                permissions: ['dashboard.view'],
            },
            {
                key: 'projections',
                label: 'Annual projections',
                icon: FolderKanban,
                permissions: ['dashboard.view'],
            },
        ],
    },
    {
        title: 'Operations',
        items: [
            {
                key: 'requests',
                label: 'Item requests',
                icon: ClipboardList,
                permissions: ['dashboard.view'],
            },
            {
                key: 'purchasing',
                label: 'Purchasing',
                icon: ShoppingCart,
                permissions: [
                    'procurement.manage',
                    'inventory.manage',
                    'budgets.manage',
                    'fund.approve',
                ],
            },
            {
                key: 'inventory',
                label: 'Inventory',
                icon: Package,
                permissions: ['inventory.manage'],
            },
            {
                key: 'counts',
                label: 'Physical counts',
                icon: ClipboardCheck,
                permissions: ['inventory.manage'],
            },
            {
                key: 'approvals',
                label: 'Approval center',
                icon: ListChecks,
                permissions: [
                    'requests.approve',
                    'counts.approve',
                    'budget.approve',
                    'fund.approve',
                ],
            },
        ],
    },
    {
        title: 'Organization',
        items: [
            {
                key: 'reports',
                label: 'Reports & exports',
                icon: FileBarChart2,
                permissions: ['reports.view'],
            },
            {
                key: 'audit',
                label: 'Audit trail',
                icon: ShieldCheck,
                permissions: ['audit.view'],
            },
            {
                key: 'administration',
                label: 'Administration',
                icon: Settings2,
                permissions: [
                    'users.manage',
                    'master-data.manage',
                    'fiscal.reopen',
                ],
            },
        ],
    },
];

const statuses: Record<string, string[]> = {
    projections: [
        'draft',
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'returned',
    ],
    requests: [
        'draft',
        'submitted',
        'pending_review',
        'approved',
        'rejected',
        'returned',
        'partially_fulfilled',
        'fulfilled',
        'closed',
        'canceled',
    ],
    purchasing: [
        'draft',
        'submitted',
        'approved',
        'ordered',
        'partially_received',
        'received',
        'closed',
        'canceled',
    ],
    counts: [
        'counting',
        'submitted',
        'under_review',
        'approved',
        'reconciled',
        'closed',
    ],
};

function Brand({ collapsed = false }: { collapsed?: boolean }) {
    return (
        <Link
            href="/app/overview"
            className="sn-brand"
            aria-label="StockNest overview"
        >
            <span className="sn-brand-mark">
                <Boxes size={23} strokeWidth={1.8} />
            </span>
            {!collapsed && (
                <span>
                    <strong>
                        StockNest<span className="sn-brand-dot">.</span>
                    </strong>
                    <small>INVENTORY & BUDGET</small>
                </span>
            )}
        </Link>
    );
}

function EmptyState({
    title,
    description,
    icon: Icon = Package,
}: {
    title: string;
    description: string;
    icon?: LucideIcon;
}) {
    return (
        <div className="sn-empty">
            <span>
                <Icon size={25} strokeWidth={1.5} />
            </span>
            <h3>{title}</h3>
            <p>{description}</p>
        </div>
    );
}

function MetricCard({
    metric,
    index,
    currency,
    timezone,
}: {
    metric: NonNullable<Props['metrics']>[number];
    index: number;
    currency: string;
    timezone: string;
}) {
    const icons = [
        Wallet,
        ListChecks,
        ArrowUpRight,
        Landmark,
        Package,
        ClipboardList,
    ];
    const Icon = icons[index % icons.length];
    return (
        <article
            className={`sn-metric ${metric.tone === 'success' || /available|remaining/i.test(metric.label) ? 'sn-metric-accent' : ''}`}
        >
            <div className="sn-metric-label">
                <span>{metric.label}</span>
                <Icon size={17} strokeWidth={1.7} />
            </div>
            <strong>
                {formatValue(metric.value, metric.format, currency, timezone)}
            </strong>
            <p>{metric.helper ?? 'Based on recorded transactions'}</p>
        </article>
    );
}

function FundCard({
    fund,
    currency,
    index,
}: {
    fund: BudgetFund;
    currency: string;
    index: number;
}) {
    const used = utilization(
        Number(fund.allocation),
        Number(fund.committed),
        Number(fund.spent),
    );
    return (
        <article className={`sn-fund-card sn-fund-${index % 2}`}>
            <div className="sn-fund-heading">
                <span className="sn-fund-icon">
                    <Landmark size={18} />
                </span>
                <div>
                    <h3>{fund.name}</h3>
                    <p>Authorized allocation</p>
                </div>
                <span className="sn-fund-tag">
                    FUND {String(index + 1).padStart(2, '0')}
                </span>
            </div>
            <strong className="sn-fund-allocation">
                {formatMoney(fund.allocation, currency)}
            </strong>
            <div
                className="sn-fund-progress"
                role="progressbar"
                aria-label={`${fund.name} budget utilized`}
                aria-valuenow={Math.round(used)}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <span style={{ width: `${used}%` }} />
            </div>
            <div className="sn-fund-progress-label">
                <span>Spent + outstanding commitments</span>
                <strong>{used.toFixed(1)}%</strong>
            </div>
            <dl className="sn-fund-values">
                <div>
                    <dt>
                        <span className="sn-dot sn-dot-spent" />
                        Spent
                    </dt>
                    <dd>{formatMoney(fund.spent, currency)}</dd>
                </div>
                <div>
                    <dt>
                        <span className="sn-dot sn-dot-committed" />
                        Committed
                    </dt>
                    <dd>{formatMoney(fund.committed, currency)}</dd>
                </div>
                <div>
                    <dt>
                        <span className="sn-dot sn-dot-available" />
                        Available
                    </dt>
                    <dd
                        className={
                            Number(fund.available) < 0 ? 'sn-negative' : ''
                        }
                    >
                        {formatMoney(fund.available, currency)}
                    </dd>
                </div>
            </dl>
            <div className="sn-fund-baseline">
                <span>
                    Original plan <b>{formatMoney(fund.original, currency)}</b>
                </span>
                <span>
                    Revised plan <b>{formatMoney(fund.revised, currency)}</b>
                </span>
            </div>
        </article>
    );
}

export default function StockNest(props: Props) {
    const {
        page,
        funds,
        metrics = [],
        budgetFunds = [],
        chartData = [],
        activity = [],
        notices = [],
        filters,
        currency = 'PHP',
        timezone = 'Asia/Manila',
    } = props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const currentPath = usePage().url.split('?')[0];
    const isDomainWorkspace = currentPath.startsWith('/app/');
    const supportsSearch = currentPath !== '/notifications';
    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [activeRow, setActiveRow] = useState<WorkspaceRow | null>(null);
    const [query, setQuery] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [dateOpen, setDateOpen] = useState(
        Boolean(filters.date_from || filters.date_to),
    );
    const [visiting, setVisiting] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    const permissions = props.auth?.permissions;
    const user = props.auth?.user;
    const officeWorkflow = props.officeWorkflow === true;
    const navLabel = (item: NavItem) =>
        officeWorkflow && item.key === 'projections'
            ? 'Approved annual items'
            : officeWorkflow && item.key === 'purchasing'
              ? 'Outside purchases'
              : item.label;
    const canSee = (item: NavItem) =>
        !(officeWorkflow && item.key === 'approvals') &&
        (permissions === undefined ||
            permissions.includes('*') ||
            item.permissions.some((permission) =>
                permissions.includes(permission),
            ));
    const isOverview = page.key === 'overview';
    const demo = props.demo || props.isDemo || props.demoMode;
    const orgName =
        typeof props.organization === 'string'
            ? props.organization
            : props.organization?.name;
    const fiscalYear =
        typeof props.fiscalYear === 'object'
            ? (props.fiscalYear.label ??
              props.fiscalYear.name ??
              props.fiscalYear.year)
            : props.fiscalYear;
    const selectedFund = String(filters.fund_id ?? props.selectedFund ?? '');
    const activeFilters = Boolean(
        filters.q ||
        filters.status ||
        filters.fund_id ||
        filters.date_from ||
        filters.date_to,
    );

    useEffect(() => {
        if (props.flash?.success) toast.success(props.flash.success);
        if (props.flash?.error) toast.error(props.flash.error);
    }, [props.flash?.success, props.flash?.error]);

    useEffect(() => {
        setQuery(filters.q ?? '');
        setStatus(filters.status ?? '');
        setDateFrom(filters.date_from ?? '');
        setDateTo(filters.date_to ?? '');
        setActiveRow(null);
    }, [
        page.key,
        filters.q,
        filters.status,
        filters.date_from,
        filters.date_to,
    ]);

    useEffect(() => {
        setActiveRow((current) =>
            current
                ? (page.rows.find((row) => row.id === current.id) ?? null)
                : null,
        );
    }, [page.rows]);

    useEffect(() => {
        const start = router.on('start', () => setVisiting(true));
        const finish = router.on('finish', () => setVisiting(false));
        function shortcut(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
                event.preventDefault();
                searchRef.current?.focus();
            }
        }
        window.addEventListener('keydown', shortcut);
        return () => {
            start();
            finish();
            window.removeEventListener('keydown', shortcut);
        };
    }, []);

    function filter(extra: Partial<Filters> = {}) {
        const next = {
            q: query,
            status,
            fund_id: selectedFund,
            date_from: dateFrom,
            date_to: dateTo,
            sort: filters.sort,
            direction: filters.direction,
            ...extra,
        };
        router.get(
            currentPath,
            Object.fromEntries(
                Object.entries(next).filter(
                    ([, value]) => value !== '' && value !== undefined,
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }
    function submitFilters(event: FormEvent) {
        event.preventDefault();
        filter();
    }

    const rows = page.rows;
    const statusOptions = isDomainWorkspace
        ? [
              ...new Set([
                  ...(officeWorkflow
                      ? ({
                            projections: ['approved'],
                            requests: [
                                'draft',
                                'submitted',
                                'approved',
                                'partially_received',
                                'fulfilled',
                                'canceled',
                            ],
                            purchasing: [
                                'ordered',
                                'partially_received',
                                'received',
                                'closed',
                                'canceled',
                            ],
                            counts: ['counting', 'approved', 'reconciled'],
                        }[page.key] ?? [])
                      : (statuses[page.key] ?? [])),
                  ...page.rows
                      .map((row) => row.status)
                      .filter(
                          (value): value is string => typeof value === 'string',
                      ),
                  ...(status ? [status] : []),
              ]),
          ]
        : [];
    const recordTitle = activeRow
        ? formatValue(
              activeRow[page.columns[0]?.key] ??
                  `${page.title} #${activeRow.id}`,
          )
        : 'Record details';

    function navContent(isMobile = false) {
        return (
            <>
                <Brand collapsed={!isMobile && collapsed} />
                <div className="sn-sidebar-inner">
                    {navigation.map((group) => (
                        <div className="sn-nav-group" key={group.title}>
                            {(!collapsed || isMobile) && <p>{group.title}</p>}
                            <nav aria-label={group.title}>
                                {group.items.filter(canSee).map((item) => (
                                    <Link
                                        key={item.key}
                                        href={`/app/${item.key}`}
                                        className={`sn-nav-link ${currentPath === `/app/${item.key}` ? 'sn-nav-active' : ''}`}
                                        aria-current={
                                            currentPath === `/app/${item.key}`
                                                ? 'page'
                                                : undefined
                                        }
                                        title={
                                            collapsed && !isMobile
                                                ? navLabel(item)
                                                : undefined
                                        }
                                        onClick={() => setMobileOpen(false)}
                                    >
                                        <item.icon
                                            size={19}
                                            strokeWidth={1.7}
                                        />
                                        {(!collapsed || isMobile) && (
                                            <span>{navLabel(item)}</span>
                                        )}
                                        {currentPath === `/app/${item.key}` &&
                                            (!collapsed || isMobile) && (
                                                <span className="sn-active-dot" />
                                            )}
                                    </Link>
                                ))}
                                {group.title === 'Organization' &&
                                    (permissions?.includes('users.manage') ||
                                        permissions?.includes('*')) && (
                                        <Link
                                            href="/administration/users"
                                            className={`sn-nav-link ${currentPath === '/administration/users' ? 'sn-nav-active' : ''}`}
                                            title={
                                                collapsed && !isMobile
                                                    ? 'People & access'
                                                    : undefined
                                            }
                                            onClick={() => setMobileOpen(false)}
                                        >
                                            <Users
                                                size={19}
                                                strokeWidth={1.7}
                                            />
                                            {(!collapsed || isMobile) && (
                                                <span>People & access</span>
                                            )}
                                        </Link>
                                    )}
                            </nav>
                        </div>
                    ))}
                </div>
                {(!collapsed || isMobile) && (
                    <div className="sn-sidebar-note">
                        <span>
                            <ShieldCheck size={18} />
                        </span>
                        <strong>Every movement matters.</strong>
                        <p>Connected budgets. Accountable inventory.</p>
                    </div>
                )}
                <div className="sn-sidebar-footer">
                    <span className="sn-system-dot" />
                    {(!collapsed || isMobile) && (
                        <>
                            <span>Secure workspace</span>
                            <span className="sn-version">v1.0</span>
                        </>
                    )}
                </div>
            </>
        );
    }

    return (
        <div className={`sn-app ${collapsed ? 'sn-is-collapsed' : ''}`}>
            <Head title={`${page.title} · StockNest`} />
            <a className="sn-skip-link" href="#main-content">
                Skip to content
            </a>
            <aside className="sn-sidebar">{navContent()}</aside>
            <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                <SheetContent side="left" className="sn-mobile-sidebar">
                    <SheetHeader className="sr-only">
                        <SheetTitle>Workspace navigation</SheetTitle>
                        <SheetDescription>
                            Navigate your inventory and budget modules.
                        </SheetDescription>
                    </SheetHeader>
                    {navContent(true)}
                </SheetContent>
            </Sheet>
            <div className="sn-main-shell">
                <header className="sn-topbar">
                    <div className="sn-topbar-start">
                        <Button
                            size="icon"
                            variant="ghost"
                            className="sn-desktop-toggle"
                            aria-label={
                                collapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            onClick={() => setCollapsed(!collapsed)}
                        >
                            <PanelLeftClose
                                size={18}
                                className={collapsed ? 'rotate-180' : ''}
                            />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="sn-mobile-toggle"
                            aria-label="Open navigation"
                            onClick={() => setMobileOpen(true)}
                        >
                            <Menu size={20} />
                        </Button>
                        <nav aria-label="Breadcrumb" className="sn-breadcrumb">
                            <Link href="/app/overview">Workspace</Link>
                            <ChevronRight size={13} />
                            <span aria-current="page">{page.title}</span>
                        </nav>
                    </div>
                    <div className="sn-topbar-end">
                        <span className="sn-current-year">
                            <CalendarDays size={15} />
                            {fiscalYear ? `FY ${fiscalYear}` : 'Fiscal records'}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Switch to ${resolvedAppearance === 'dark' ? 'light' : 'dark'} mode`}
                            onClick={() =>
                                updateAppearance(
                                    resolvedAppearance === 'dark'
                                        ? 'light'
                                        : 'dark',
                                )
                            }
                        >
                            {resolvedAppearance === 'dark' ? (
                                <Sun size={18} />
                            ) : (
                                <Moon size={18} />
                            )}
                        </Button>
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href="/notifications"
                                aria-label="Open notifications"
                            >
                                <Bell size={18} />
                            </Link>
                        </Button>
                        <span className="sn-topbar-divider" />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    className="sn-user-button"
                                    aria-label="Open account menu"
                                >
                                    <span className="sn-avatar">
                                        {(user?.name ?? 'User')
                                            .split(' ')
                                            .map((name) => name[0])
                                            .slice(0, 2)
                                            .join('')}
                                    </span>
                                    <span className="sn-user-name">
                                        {user?.name ?? 'Account'}
                                        <small>My workspace</small>
                                    </span>
                                    <ChevronDown size={13} />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel>
                                    {user?.email ?? user?.name}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href="/settings/profile">
                                        Profile settings
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() =>
                                        router.post('/logout', {
                                            idempotency_key: idempotencyKey(),
                                        })
                                    }
                                >
                                    <LogOut size={15} />
                                    Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>
                {visiting && (
                    <div
                        className="sn-route-progress"
                        aria-label="Loading workspace"
                        role="progressbar"
                    />
                )}
                <main
                    id="main-content"
                    className="sn-content"
                    aria-busy={visiting}
                >
                    {demo && (
                        <div className="sn-demo-banner">
                            <CircleHelp size={17} />
                            <span>
                                <strong>Demonstration workspace</strong>{' '}
                                {typeof demo === 'string'
                                    ? demo
                                    : 'These are sample records for exploring the workflow.'}
                            </span>
                        </div>
                    )}
                    <div className="sn-page-heading">
                        <div>
                            <p className="sn-eyebrow">
                                {orgName ?? 'Inventory & budget workspace'}
                                {isOverview && (
                                    <span className="sn-live-label">
                                        <span />
                                        Live overview
                                    </span>
                                )}
                            </p>
                            <h1>{page.title}</h1>
                            <p className="sn-page-subtitle">{page.subtitle}</p>
                        </div>
                        <div className="sn-heading-actions">
                            {props.pageActions?.map((action, index) => (
                                <ActionButton key={index} action={action} />
                            ))}
                            {props.createForm && (
                                <Button
                                    className="sn-primary"
                                    onClick={() => setFormOpen(true)}
                                >
                                    <Plus size={17} />
                                    {props.createForm.title}
                                </Button>
                            )}
                        </div>
                    </div>
                    <div className="sn-workspace-bar">
                        <div className="sn-workspace-tabs">
                            <span className="sn-workspace-tab-active">
                                {isOverview
                                    ? 'Financial overview'
                                    : 'All records'}
                            </span>
                            <span className="sn-workspace-context">
                                {isOverview
                                    ? 'Plan. Request. Procure. Account.'
                                    : 'Connected to your live records'}
                            </span>
                        </div>
                        {isDomainWorkspace && (
                            <label className="sn-fund-select">
                                <Landmark size={16} />
                                <span className="sr-only">Funding source</span>
                                <select
                                    value={selectedFund}
                                    onChange={(event) =>
                                        filter({ fund_id: event.target.value })
                                    }
                                >
                                    <option value="">
                                        All funding sources
                                    </option>
                                    {funds.map((fund) => (
                                        <option key={fund.id} value={fund.id}>
                                            {fund.label}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown size={13} aria-hidden="true" />
                            </label>
                        )}
                    </div>
                    {metrics.length > 0 && (
                        <section
                            aria-label="Key metrics"
                            className="sn-metrics"
                        >
                            {metrics.map((metric, index) => (
                                <MetricCard
                                    key={`${metric.label}-${index}`}
                                    metric={metric}
                                    index={index}
                                    currency={currency}
                                    timezone={timezone}
                                />
                            ))}
                        </section>
                    )}
                    {notices.length > 0 && (
                        <section
                            aria-label="Workspace notices"
                            className="sn-notices"
                        >
                            {notices.map((notice, index) => (
                                <article
                                    key={index}
                                    className={`sn-notice sn-notice-${notice.tone ?? 'info'}`}
                                >
                                    <span>
                                        <Activity size={18} />
                                    </span>
                                    <div>
                                        <strong>{notice.title}</strong>
                                        <p>{notice.description}</p>
                                    </div>
                                </article>
                            ))}
                        </section>
                    )}
                    {officeWorkflow &&
                        (isOverview || page.key === 'projections') && (
                            <section
                                className="sn-panel mb-6"
                                aria-label="Annual Supply allowance"
                            >
                                <div className="sn-panel-heading">
                                    <div>
                                        <p className="sn-section-eyebrow">
                                            APPROVED ANNUAL ITEMS
                                        </p>
                                        <h2>
                                            Remaining to request from Supply
                                        </h2>
                                    </div>
                                </div>
                                {(props.allowanceRows?.length ?? 0) > 0 ? (
                                    <div className="sn-table-wrap">
                                        <table className="sn-data-table">
                                            <thead>
                                                <tr>
                                                    {[
                                                        'Item',
                                                        'Unit',
                                                        'Fund',
                                                        'Year',
                                                        'Approved',
                                                        'Requested',
                                                        'Received',
                                                        'Left to request',
                                                    ].map((label) => (
                                                        <th
                                                            scope="col"
                                                            key={label}
                                                        >
                                                            {label}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {props.allowanceRows?.map(
                                                    (row, index) => (
                                                        <tr
                                                            key={`${row.item_id ?? row.item}-${row.fund}-${row.year}-${index}`}
                                                        >
                                                            <th scope="row">
                                                                {row.item}
                                                            </th>
                                                            <td>{row.unit}</td>
                                                            <td>{row.fund}</td>
                                                            <td>{row.year}</td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.approved_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.requested_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.received_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                <strong>
                                                                    {formatNumber(
                                                                        row.remaining_quantity,
                                                                    )}
                                                                </strong>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <EmptyState
                                        title="Add your approved annual items"
                                        description="Encode the quantities already approved by Supply to start tracking what remains."
                                        icon={ClipboardList}
                                    />
                                )}
                                <p className="sn-panel-footnote">
                                    Submitting to Supply reduces the allowance.
                                    Recording a receipt updates office stock.
                                    Outside cash purchases are tracked
                                    separately.
                                </p>
                            </section>
                        )}
                    {officeWorkflow && isOverview && (
                        <section
                            className="sn-panel mb-6"
                            aria-label="Monthly item statistics"
                        >
                            <div className="sn-panel-heading">
                                <div>
                                    <p className="sn-section-eyebrow">
                                        ITEM ACTIVITY
                                    </p>
                                    <h2>Items by month</h2>
                                </div>
                            </div>
                            {(props.monthlyStats?.length ?? 0) > 0 ? (
                                <details className="sn-chart-data" open>
                                    <summary>
                                        View monthly requests and receipts
                                    </summary>
                                    <div className="sn-table-wrap">
                                        <table className="sn-data-table">
                                            <thead>
                                                <tr>
                                                    {[
                                                        'Month',
                                                        'Item',
                                                        'Unit',
                                                        'Fund',
                                                        'Requested from Supply',
                                                        'Received from Supply',
                                                        'Cash requested',
                                                        'Outside items received',
                                                    ].map((label) => (
                                                        <th
                                                            scope="col"
                                                            key={label}
                                                        >
                                                            {label}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {props.monthlyStats?.map(
                                                    (row, index) => (
                                                        <tr
                                                            key={`${row.month}-${row.item}-${row.fund}-${index}`}
                                                        >
                                                            <th scope="row">
                                                                {row.month}
                                                            </th>
                                                            <td>{row.item}</td>
                                                            <td>{row.unit}</td>
                                                            <td>{row.fund}</td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.requested_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.received_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.outside_quantity,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatNumber(
                                                                    row.outside_received_quantity,
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            ) : (
                                <EmptyState
                                    title="Monthly activity will appear here"
                                    description="Submit a Supply request or record a receipt to start the monthly item history."
                                    icon={CalendarDays}
                                />
                            )}
                        </section>
                    )}
                    {isOverview && (
                        <div className="sn-analytics-grid">
                            <section className="sn-panel sn-spending-panel">
                                <div className="sn-panel-heading">
                                    <div>
                                        <p className="sn-section-eyebrow">
                                            BUDGET PERFORMANCE
                                        </p>
                                        <h2>Projected vs. actual spending</h2>
                                    </div>
                                    <div className="sn-chart-legend">
                                        <span>
                                            <i className="sn-legend-projected" />
                                            Projected
                                        </span>
                                        <span>
                                            <i className="sn-legend-actual" />
                                            Actual
                                        </span>
                                    </div>
                                </div>
                                {chartData.length > 0 ? (
                                    <>
                                        <div
                                            className="sn-chart"
                                            role="img"
                                            aria-label="Monthly projected and actual expense comparison. The underlying monthly figures are available below."
                                        >
                                            <Suspense
                                                fallback={
                                                    <p role="status">
                                                        Loading spending chart…
                                                    </p>
                                                }
                                            >
                                                <OverviewChart
                                                    data={chartData}
                                                    currency={currency}
                                                />
                                            </Suspense>
                                        </div>
                                        <details className="sn-chart-data">
                                            <summary>
                                                View monthly figures
                                            </summary>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th scope="col">
                                                            Month
                                                        </th>
                                                        <th scope="col">
                                                            Projected
                                                        </th>
                                                        <th scope="col">
                                                            Actual
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {chartData.map((point) => (
                                                        <tr key={point.month}>
                                                            <th scope="row">
                                                                {point.month}
                                                            </th>
                                                            <td>
                                                                {formatMoney(
                                                                    point.projected,
                                                                    currency,
                                                                )}
                                                            </td>
                                                            <td>
                                                                {formatMoney(
                                                                    point.actual,
                                                                    currency,
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </details>
                                    </>
                                ) : (
                                    <EmptyState
                                        title="Your spending story starts here"
                                        description="Approved projections and recorded expenditures will appear in this view."
                                        icon={FileBarChart2}
                                    />
                                )}
                                <div className="sn-panel-footnote">
                                    <ShieldCheck size={14} />
                                    Monthly projections include only lines with
                                    a planned month. Annual totals remain
                                    visible in fund cards.
                                </div>
                            </section>
                            <section className="sn-funds-section">
                                <div className="sn-funds-title">
                                    <h2>Funding sources</h2>
                                    <span>
                                        {budgetFunds.length}{' '}
                                        {budgetFunds.length === 1
                                            ? 'fund'
                                            : 'funds'}
                                    </span>
                                </div>
                                {budgetFunds.length ? (
                                    budgetFunds.map((fund, index) => (
                                        <FundCard
                                            key={fund.id}
                                            fund={fund}
                                            currency={currency}
                                            index={index}
                                        />
                                    ))
                                ) : (
                                    <div className="sn-panel">
                                        <EmptyState
                                            title="No fund balances yet"
                                            description="Authorized funding sources and their balances will appear here."
                                            icon={Landmark}
                                        />
                                    </div>
                                )}
                            </section>
                        </div>
                    )}
                    <div
                        className={
                            isOverview && activity.length > 0
                                ? 'sn-bottom-grid'
                                : ''
                        }
                    >
                        <section className="sn-panel sn-records-panel">
                            <div className="sn-panel-heading">
                                <div>
                                    <p className="sn-section-eyebrow">
                                        {isOverview
                                            ? 'WORKSPACE ACTIVITY'
                                            : 'LIVE RECORDS'}
                                    </p>
                                    <h2>
                                        {isOverview
                                            ? 'Recent transactions'
                                            : page.title}
                                        <span className="sn-record-count">
                                            {formatNumber(
                                                page.pagination?.total ??
                                                    rows.length,
                                            )}
                                        </span>
                                    </h2>
                                </div>
                                {visiting && (
                                    <span className="sn-loading-text">
                                        Updating…
                                    </span>
                                )}
                            </div>
                            {supportsSearch && (
                                <form
                                    onSubmit={submitFilters}
                                    className="sn-filter-bar"
                                >
                                    <div className="sn-search">
                                        <Search size={16} />
                                        <Input
                                            ref={searchRef}
                                            type="search"
                                            name="q"
                                            value={query}
                                            onChange={(event) =>
                                                setQuery(event.target.value)
                                            }
                                            placeholder="Search records…"
                                            aria-label="Search records"
                                        />
                                        <kbd>⌘ K</kbd>
                                    </div>
                                    {statusOptions.length > 0 && (
                                        <label className="sn-filter-select">
                                            <span className="sr-only">
                                                Status
                                            </span>
                                            <select
                                                name="status"
                                                value={status}
                                                onChange={(event) => {
                                                    setStatus(
                                                        event.target.value,
                                                    );
                                                    filter({
                                                        status: event.target
                                                            .value,
                                                    });
                                                }}
                                            >
                                                <option value="">
                                                    All statuses
                                                </option>
                                                {statusOptions.map((value) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {officeWorkflow &&
                                                        page.key ===
                                                            'requests' &&
                                                        value === 'approved'
                                                            ? 'Cash requested'
                                                            : officeWorkflow &&
                                                                page.key ===
                                                                    'requests' &&
                                                                value ===
                                                                    'partially_fulfilled'
                                                              ? 'Partially received'
                                                              : humanize(value)}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    )}
                                    {isDomainWorkspace && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className={
                                                dateOpen
                                                    ? 'sn-filter-active'
                                                    : ''
                                            }
                                            onClick={() =>
                                                setDateOpen(!dateOpen)
                                            }
                                            aria-expanded={dateOpen}
                                            aria-controls="sn-date-filters"
                                        >
                                            <SlidersHorizontal size={14} />
                                            <span>Filters</span>
                                        </Button>
                                    )}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={visiting}
                                    >
                                        Search
                                    </Button>
                                    {activeFilters && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setQuery('');
                                                setStatus('');
                                                setDateFrom('');
                                                setDateTo('');
                                                filter({
                                                    q: '',
                                                    status: '',
                                                    fund_id: '',
                                                    date_from: '',
                                                    date_to: '',
                                                });
                                            }}
                                        >
                                            <X size={13} />
                                            Clear
                                        </Button>
                                    )}
                                </form>
                            )}
                            {dateOpen && isDomainWorkspace && (
                                <form
                                    id="sn-date-filters"
                                    onSubmit={submitFilters}
                                    className="sn-date-filters"
                                >
                                    <label>
                                        From date
                                        <Input
                                            type="date"
                                            value={dateFrom}
                                            max={dateTo || undefined}
                                            onChange={(event) =>
                                                setDateFrom(event.target.value)
                                            }
                                        />
                                    </label>
                                    <label>
                                        To date
                                        <Input
                                            type="date"
                                            value={dateTo}
                                            min={dateFrom || undefined}
                                            onChange={(event) =>
                                                setDateTo(event.target.value)
                                            }
                                        />
                                    </label>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={visiting}
                                    >
                                        Apply dates
                                    </Button>
                                    <p>Filters records by creation date.</p>
                                </form>
                            )}
                            {rows.length > 0 && page.columns.length > 0 ? (
                                <div className="sn-table-wrap">
                                    <table className="sn-data-table">
                                        <thead>
                                            <tr>
                                                {page.columns.map((column) => (
                                                    <th
                                                        key={column.key}
                                                        scope="col"
                                                        aria-sort={
                                                            column.sortKey
                                                                ? filters.sort ===
                                                                  column.sortKey
                                                                    ? filters.direction ===
                                                                      'asc'
                                                                        ? 'ascending'
                                                                        : 'descending'
                                                                    : 'none'
                                                                : undefined
                                                        }
                                                        className={
                                                            column.format ===
                                                                'money' ||
                                                            column.format ===
                                                                'number'
                                                                ? 'sn-cell-numeric'
                                                                : ''
                                                        }
                                                    >
                                                        {column.sortKey ? (
                                                            <button
                                                                type="button"
                                                                title={`Sort all records by ${column.label.toLowerCase()}`}
                                                                disabled={
                                                                    visiting
                                                                }
                                                                onClick={() =>
                                                                    filter({
                                                                        sort: column.sortKey,
                                                                        direction:
                                                                            filters.sort ===
                                                                                column.sortKey &&
                                                                            filters.direction ===
                                                                                'asc'
                                                                                ? 'desc'
                                                                                : 'asc',
                                                                    })
                                                                }
                                                            >
                                                                {column.label}
                                                                <ArrowDownUp
                                                                    size={11}
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                        ) : (
                                                            column.label
                                                        )}
                                                    </th>
                                                ))}
                                                <th scope="col">
                                                    <span className="sr-only">
                                                        Record actions
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map((row) => (
                                                <tr key={row.id}>
                                                    {page.columns.map(
                                                        (column, index) => (
                                                            <td
                                                                key={column.key}
                                                                className={`${column.format === 'money' || column.format === 'number' ? 'sn-cell-numeric' : ''} ${index === 0 ? 'sn-cell-reference' : ''}`}
                                                            >
                                                                {column.format ===
                                                                'status' ? (
                                                                    <Status
                                                                        value={
                                                                            row[
                                                                                column
                                                                                    .key
                                                                            ]
                                                                        }
                                                                    />
                                                                ) : index ===
                                                                  0 ? (
                                                                    <button
                                                                        type="button"
                                                                        className="sn-record-link"
                                                                        onClick={() =>
                                                                            setActiveRow(
                                                                                row,
                                                                            )
                                                                        }
                                                                    >
                                                                        {formatValue(
                                                                            row[
                                                                                column
                                                                                    .key
                                                                            ],
                                                                            column.format,
                                                                            currency,
                                                                            timezone,
                                                                        )}
                                                                    </button>
                                                                ) : (
                                                                    formatValue(
                                                                        row[
                                                                            column
                                                                                .key
                                                                        ],
                                                                        column.format,
                                                                        currency,
                                                                        timezone,
                                                                    )
                                                                )}
                                                            </td>
                                                        ),
                                                    )}
                                                    <td className="sn-row-actions">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setActiveRow(
                                                                    row,
                                                                )
                                                            }
                                                            aria-label={`View ${formatValue(row[page.columns[0]?.key] ?? row.id)}`}
                                                        >
                                                            View
                                                            <ChevronRight
                                                                size={13}
                                                            />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState
                                    title={
                                        activeFilters
                                            ? 'No matching records'
                                            : 'Ready for your first record'
                                    }
                                    description={
                                        activeFilters
                                            ? 'Try a different search, funding source, date range, or status.'
                                            : props.createForm
                                              ? 'Use the create button above to start. Your records will appear here as you work.'
                                              : 'Records will appear here when authorized workflows are completed.'
                                    }
                                    icon={isOverview ? Activity : ClipboardList}
                                />
                            )}
                            <div className="sn-table-footer">
                                <span>
                                    {page.pagination
                                        ? `Page ${page.pagination.current_page} of ${page.pagination.last_page} · ${formatNumber(page.pagination.total)} records`
                                        : `${formatNumber(rows.length)} ${rows.length === 1 ? 'record' : 'records'}`}
                                </span>
                                {page.pagination && (
                                    <div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                !page.pagination
                                                    .prev_page_url || visiting
                                            }
                                            onClick={() =>
                                                page.pagination
                                                    ?.prev_page_url &&
                                                router.visit(
                                                    page.pagination
                                                        .prev_page_url,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                            aria-label="Previous page"
                                        >
                                            <ChevronLeft size={15} />
                                            Previous
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                !page.pagination
                                                    .next_page_url || visiting
                                            }
                                            onClick={() =>
                                                page.pagination
                                                    ?.next_page_url &&
                                                router.visit(
                                                    page.pagination
                                                        .next_page_url,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                            aria-label="Next page"
                                        >
                                            Next
                                            <ChevronRight size={15} />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </section>
                        {isOverview && activity.length > 0 && (
                            <section className="sn-panel sn-activity-panel">
                                <div className="sn-panel-heading">
                                    <div>
                                        <p className="sn-section-eyebrow">
                                            THE LATEST
                                        </p>
                                        <h2>Activity feed</h2>
                                    </div>
                                    <Activity size={18} className="sn-muted" />
                                </div>
                                <ol className="sn-activity-list">
                                    {activity.map((entry) => (
                                        <li key={entry.id}>
                                            <span
                                                className={`sn-activity-icon sn-activity-${entry.type}`}
                                            >
                                                <Check size={14} />
                                            </span>
                                            <div>
                                                <strong>{entry.title}</strong>
                                                <p>{entry.description}</p>
                                                <time dateTime={entry.date}>
                                                    {formatDate(
                                                        entry.date,
                                                        timezone,
                                                    )}
                                                </time>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                                {permissions?.includes('audit.view') && (
                                    <Link
                                        href="/app/audit"
                                        className="sn-activity-link"
                                    >
                                        View audit trail
                                        <ArrowRight size={14} />
                                    </Link>
                                )}
                            </section>
                        )}
                    </div>
                    <footer className="sn-page-footer">
                        <span>
                            StockNest<span className="sn-footer-dot">·</span>
                            Built for accountable operations
                        </span>
                        <span>
                            <ShieldCheck size={13} />
                            {currency} · {timezone}
                        </span>
                    </footer>
                </main>
            </div>
            {props.createForm && (
                <WorkflowForm
                    key={`${page.key}-${props.createForm.action}`}
                    definition={props.createForm}
                    open={formOpen}
                    onOpenChange={setFormOpen}
                />
            )}
            <RecordDetails
                row={activeRow}
                open={Boolean(activeRow)}
                onOpenChange={(open) => {
                    if (!open) setActiveRow(null);
                }}
                title={recordTitle}
                section={page.key}
                officeWorkflow={officeWorkflow}
                currency={currency}
                timezone={timezone}
            />
        </div>
    );
}
