"use client";

import { Area, AreaChart, CartesianGrid, Tooltip, XAxis, YAxis, type TooltipContentProps } from "recharts";

import { formatDate, formatDayMonth } from "@/lib/format";

type Point = { date: string; mid: number };

/**
 * A currency's middle rate over 30 days, drawn with Recharts. This module is loaded only by the currency page, and only
 * when the chart comes near the screen (LazyRateChart), so no other page and not the frame carries it. Time runs from
 * the start side (the oldest on the right) like the reading, and nothing animates: the line is simply there.
 */
export default function RateChart({ points, decimals }: { points: { date: string; mid: string }[]; decimals: number }) {
  const data: Point[] = points.map((p) => ({ date: p.date, mid: Number(p.mid) }));
  const tick = { fill: "var(--color-muted)", fontSize: 13 };

  return (
    <div dir="ltr" className="figures size-full">
      <AreaChart data={data} responsive style={{ width: "100%", height: "100%" }} margin={{ top: 8, right: 4, bottom: 0, left: 4 }} accessibilityLayer={false}>
        <CartesianGrid vertical={false} stroke="var(--color-line)" />
        <XAxis
          dataKey="date"
          reversed
          tickFormatter={formatDayMonth}
          interval="preserveStartEnd"
          minTickGap={28}
          tickMargin={10}
          tick={tick}
          tickLine={false}
          axisLine={{ stroke: "var(--color-line-strong)" }}
        />
        <YAxis
          orientation="right"
          domain={["auto", "auto"]}
          tickFormatter={(value: number) => value.toFixed(decimals)}
          width={decimals > 3 ? 64 : 52}
          tick={tick}
          tickLine={false}
          axisLine={false}
          tickCount={4}
        />
        <Tooltip content={(props) => <Tip {...props} decimals={decimals} />} cursor={{ stroke: "var(--color-line-strong)" }} isAnimationActive={false} />
        <Area
          type="linear"
          dataKey="mid"
          stroke="var(--color-brand)"
          strokeWidth={2}
          fill="var(--color-brand)"
          fillOpacity={0.08}
          dot={false}
          activeDot={{ r: 4, fill: "var(--color-brand)", stroke: "var(--color-ground)", strokeWidth: 2 }}
          isAnimationActive={false}
        />
      </AreaChart>
    </div>
  );
}

function Tip({ active, payload, decimals }: TooltipContentProps & { decimals: number }) {
  const point = active ? (payload?.[0]?.payload as Point | undefined) : undefined;
  if (!point) return null;

  return (
    <div dir="rtl" className="rounded-sm border border-line-strong bg-ground px-3 py-2 text-small shadow-raised">
      <p className="text-muted">{formatDate(`${point.date}T12:00:00Z`)}</p>
      <p dir="ltr" className="text-end font-bold">
        {point.mid.toFixed(decimals)}
      </p>
    </div>
  );
}
