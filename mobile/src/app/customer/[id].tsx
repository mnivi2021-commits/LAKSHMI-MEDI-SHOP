import { Linking, ScrollView, Text, View } from 'react-native';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useApi } from '@/lib/useApi';
import { dmy, inr } from '@/lib/api';
import { Big, Button, Card, colors, ErrorBox, Loading, Row, s } from '@/components/ui';

type Detail = {
  customer: { id: number; customer_code: string; name: string; company_name: string | null; mobile: string | null; email: string | null; city: string | null;
    gstin: string | null; credit_days: number; status: string; branch: string; employee: string | null };
  outstanding_total: string;
  open_bills: { invoice_no: string; invoice_date: string; due_date: string | null; bill_amount: string; balance: string; age: number }[];
  invoices: { invoice_no: string; invoice_date: string; document_type: string; taxable_value: string; total_value: string }[];
  receipts: { receipt_no: string; receipt_date: string; amount: string; payment_mode: string }[];
};

export default function CustomerDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data: d, error, reload } = useApi<Detail>('/api/customers/' + encodeURIComponent(String(id)));

  if (error) return <View style={s.screen}><View style={s.content}><ErrorBox message={error} onRetry={reload} /></View></View>;
  if (!d) return <Loading />;
  const c = d.customer;

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      <Stack.Screen options={{ title: c.name }} />
      <Card>
        <Text style={{ fontSize: 18, fontWeight: '800', color: colors.text }}>{c.name}</Text>
        <Text style={s.sub}>{c.customer_code} · {c.branch}{c.employee ? ' · ' + c.employee : ''}</Text>
        {c.mobile ? <View style={{ marginTop: 12 }}><Button title={`Call ${c.mobile}`} kind="plain" onPress={() => Linking.openURL('tel:' + c.mobile)} /></View> : null}
        <Row label="City" value={c.city ?? '—'} />
        <Row label="Credit days" value={String(c.credit_days)} />
        {c.gstin ? <Row label="GSTIN" value={c.gstin} /> : null}
      </Card>

      <Card title="Outstanding">
        <Big value={inr(d.outstanding_total)} caption={`${d.open_bills.length} open bill(s), oldest first`} />
        {d.open_bills.map((b) => (
          <Row key={b.invoice_no + b.invoice_date} label={b.invoice_no} sub={`${dmy(b.invoice_date)} · ${b.age} days`}
            value={inr(b.balance)} tone={b.age > 150 ? 'fail' : b.age > 90 ? 'warn' : undefined} />
        ))}
      </Card>

      {d.invoices.length ? (
        <Card title="Recent invoices">
          {d.invoices.map((i) => (
            <Row key={i.invoice_no + i.document_type} label={i.invoice_no} sub={`${dmy(i.invoice_date)}${i.document_type === 'credit_note' ? ' · credit note' : ''}`} value={inr(i.total_value)} />
          ))}
        </Card>
      ) : null}

      {d.receipts.length ? (
        <Card title="Recent receipts">
          {d.receipts.map((r) => (
            <Row key={r.receipt_no} label={r.receipt_no} sub={`${dmy(r.receipt_date)} · ${r.payment_mode.toUpperCase()}`} value={inr(r.amount)} tone="ok" />
          ))}
        </Card>
      ) : null}
    </ScrollView>
  );
}
