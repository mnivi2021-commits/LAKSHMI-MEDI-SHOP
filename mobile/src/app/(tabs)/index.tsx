import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/useApi';
import { dmy, inr } from '@/lib/api';
import { Badge, Big, Card, colors, ErrorBox, Loading, Row, s } from '@/components/ui';

type Summary = {
  as_on: string;
  fy: string;
  is_live: boolean;
  notices: string[];
  sales?: { total: string; today: string; target: string | null; achieved_pct: number | null; target_pending: string | null; month_to_date: string };
  collection?: { month: string | null; today: string | null; target: string | null; pct: number | null; fy_to_date: string | null; overdue: string | null };
  pending?: { value: string; orders: number; customers: number; oldest_days: number | null };
  outstanding?: { upto90: string; d90: string; d150: string; d90_customers: number; d150_customers: number; total: string };
  samples_dc?: { samples: string; sample_docs: number; dc: string; dc_docs: number };
  email?: { code: string; name: string; month: number; day: number; open: number }[];
};

export default function Dashboard() {
  const { user } = useAuth();
  const { data, error, loading, reload } = useApi<Summary>('/api/dashboard/summary');

  if (!data && !error) return <Loading />;

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content} refreshControl={<RefreshControl refreshing={loading && data !== null} onRefresh={reload} />}>
      <View>
        <Text style={{ fontSize: 13, color: colors.muted }}>Hello {user?.name}</Text>
        {data ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 2 }}>
            <Text style={{ fontSize: 16, fontWeight: '700', color: colors.text }}>As on {dmy(data.as_on)} · {data.fy}</Text>
            {data.is_live ? <Badge text="Live" tone="ok" /> : null}
          </View>
        ) : null}
      </View>
      {error ? <ErrorBox message={error} onRetry={reload} /> : null}
      {data?.notices.map((n) => <ErrorBox key={n} message={n} />)}

      {data?.sales ? (
        <Card title="Sales performance">
          <Big value={inr(data.sales.total)} caption={`Total sales ${data.fy}`} />
          {data.sales.achieved_pct !== null ? <Row label="Target achieved" value={`${data.sales.achieved_pct}%`} /> : null}
          <Row label="Annual target" value={inr(data.sales.target)} />
          <Row label="This month" value={inr(data.sales.month_to_date)} />
          <Row label="Today" value={inr(data.sales.today)} />
          <Row label="Target pending" value={inr(data.sales.target_pending)} />
        </Card>
      ) : null}

      {data?.collection ? (
        <Card title="Payment collection">
          <Big value={inr(data.collection.month)} caption="Collected this month" />
          {data.collection.pct !== null ? <Row label="Of monthly target" value={`${data.collection.pct}%`} /> : null}
          <Row label="Monthly target" value={inr(data.collection.target)} />
          <Row label="Today" value={inr(data.collection.today)} />
          <Row label="Overdue to collect" value={inr(data.collection.overdue)} tone="fail" />
        </Card>
      ) : null}

      {data?.outstanding ? (
        <Card title="Outstanding by age">
          <Row label="Up to 90 days" value={inr(data.outstanding.upto90)} />
          <Row label="90 DAYS (91-150)" value={inr(data.outstanding.d90)} tone="warn" sub={`${data.outstanding.d90_customers} customer(s)`} />
          <Row label="150 DAYS (over 150)" value={inr(data.outstanding.d150)} tone="fail" sub={`${data.outstanding.d150_customers} customer(s)`} />
          <Row label="Total outstanding" value={inr(data.outstanding.total)} />
        </Card>
      ) : null}

      {data?.pending ? (
        <Card title="Pending orders">
          <Big value={inr(data.pending.value)} caption={`${data.pending.orders} order(s) · ${data.pending.customers} customer(s)`} />
          {data.pending.oldest_days !== null ? <Row label="Oldest order" value={`${data.pending.oldest_days} days`} tone={data.pending.oldest_days > 90 ? 'fail' : undefined} /> : null}
        </Card>
      ) : null}

      {data?.samples_dc ? (
        <Card title="Samples and DC">
          <Row label="Pending samples" value={inr(data.samples_dc.samples)} sub={`${data.samples_dc.sample_docs} document(s)`} />
          <Row label="Pending DC" value={inr(data.samples_dc.dc)} sub={`${data.samples_dc.dc_docs} document(s)`} />
        </Card>
      ) : null}

      {data?.email ? (
        <Card title="Email this month">
          {data.email.map((e) => (
            <Row key={e.code} label={e.name} value={String(e.month)} sub={`Today ${e.day} · open ${e.open}`} />
          ))}
        </Card>
      ) : null}
      <Text style={[s.sub, { textAlign: 'center' }]}>Pull down to refresh. Figures match the web dashboard.</Text>
    </ScrollView>
  );
}
