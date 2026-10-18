import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatMoney } from '@/lib/stocknest';

export default function OverviewChart({
    data,
    currency,
}: {
    data: { month: string; projected: number; actual: number }[];
    currency: string;
}) {
    return (
        <ResponsiveContainer width="100%" height="100%">
            <AreaChart
                data={data}
                margin={{
                    top: 16,
                    right: 12,
                    bottom: 0,
                    left: 4,
                }}
            >
                <defs>
                    <linearGradient
                        id="actualGradient"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="#0d9488"
                            stopOpacity={0.18}
                        />
                        <stop
                            offset="95%"
                            stopColor="#0d9488"
                            stopOpacity={0}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid
                    vertical={false}
                    stroke="var(--sn-border)"
                    strokeDasharray="3 4"
                />
                <XAxis
                    dataKey="month"
                    axisLine={false}
                    tickLine={false}
                    tick={{
                        fontSize: 11,
                        fill: 'var(--sn-muted)',
                    }}
                    dy={10}
                />
                <YAxis
                    axisLine={false}
                    tickLine={false}
                    tick={{
                        fontSize: 11,
                        fill: 'var(--sn-muted)',
                    }}
                    tickFormatter={(value: number) =>
                        Math.abs(value) >= 1000000
                            ? `${value / 1000000}m`
                            : Math.abs(value) >= 1000
                              ? `${value / 1000}k`
                              : String(value)
                    }
                    width={47}
                />
                <Tooltip
                    formatter={(value, name) => [
                        formatMoney(value, currency),
                        name === 'projected' ? 'Projected' : 'Actual',
                    ]}
                    contentStyle={{
                        borderRadius: 10,
                        border: '1px solid var(--sn-border)',
                        background: 'var(--sn-panel)',
                        color: 'var(--sn-text)',
                        fontSize: 12,
                    }}
                />
                <Area
                    type="monotone"
                    dataKey="projected"
                    stroke="#a8b8c8"
                    strokeWidth={2}
                    strokeDasharray="5 5"
                    fill="none"
                />
                <Area
                    type="monotone"
                    dataKey="actual"
                    stroke="#0d9488"
                    strokeWidth={2.5}
                    fill="url(#actualGradient)"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
