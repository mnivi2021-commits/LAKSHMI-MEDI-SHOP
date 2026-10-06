import { Linking, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useApi } from '@/lib/useApi';
import { inr } from '@/lib/api';
import { Badge, Card, colors, ErrorBox, Loading, s } from '@/components/ui';

type Lead = { id: number; lead_number: string; name: string; company_name: string | null; mobile: string | null; status: string; priority: string;
  expected_value: string | null; next_followup_at: string | null; source: string | null; employee: string | null };
type Followup = { id: number; followup_at: string; followup_type: string; notes: string | null; name: string; mobile: string | null; ref: string };

const when = (dt: string) => {
  const [d, t] = dt.split(' ');
  const [y, m, day] = d.split('-');
  return `${day}-${m}-${y} ${t?.slice(0, 5) ?? ''}`;
};

export default function Leads() {
  const l = useApi<{ leads: Lead[] }>('/api/leads?status=open');
  const f = useApi<{ followups: Followup[] }>('/api/followups/due');
  const leads = l.data?.leads ?? null;
  const due = f.data?.followups ?? [];
  const error = l.error ?? f.error;
  const reload = () => {
    l.reload();
    f.reload();
  };

  if (!leads && !error) return <Loading />;
  // Local (phone) time in the server's "YYYY-MM-DD HH:MM" format, to mark overdue follow-ups.
  const d = new Date();
  const p2 = (n: number) => String(n).padStart(2, '0');
  const now = `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())} ${p2(d.getHours())}:${p2(d.getMinutes())}`;

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}
      refreshControl={<RefreshControl refreshing={(l.loading || f.loading) && leads !== null} onRefresh={reload} />}>
      {error ? <ErrorBox message={error} onRetry={reload} /> : null}

      <Card title={`Follow-ups due (${due.length})`}>
        {due.length === 0 ? <Text style={s.sub}>Nothing due. Well done!</Text> : due.map((fu) => (
          <Pressable key={fu.id} onPress={() => fu.mobile && Linking.openURL('tel:' + fu.mobile)} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}>
            <Text style={{ fontWeight: '600', color: colors.text }}>{fu.name} <Text style={s.sub}>{fu.ref}</Text></Text>
            <Text style={[s.sub, fu.followup_at < now ? { color: colors.fail } : null]}>{when(fu.followup_at)} · {fu.followup_type}{fu.mobile ? ' · tap to call ' + fu.mobile : ''}</Text>
            {fu.notes ? <Text style={s.sub}>{fu.notes}</Text> : null}
          </Pressable>
        ))}
      </Card>

      <Card title={`Open leads (${leads?.length ?? 0})`}>
        {(leads ?? []).map((lead) => (
          <Pressable key={lead.id} onPress={() => lead.mobile && Linking.openURL('tel:' + lead.mobile)} style={{ paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.border, gap: 4 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
              <Text style={{ flex: 1, fontWeight: '600', color: colors.text }}>{lead.name}{lead.company_name ? ' · ' + lead.company_name : ''}</Text>
              <Text style={{ fontWeight: '600', color: colors.text }}>{inr(lead.expected_value)}</Text>
            </View>
            <View style={{ flexDirection: 'row', gap: 6, flexWrap: 'wrap' }}>
              <Badge text={lead.status.replace('_', ' ')} tone="info" />
              <Badge text={lead.priority} tone={lead.priority === 'urgent' ? 'fail' : lead.priority === 'high' ? 'warn' : 'muted'} />
            </View>
            <Text style={s.sub}>{lead.lead_number}{lead.source ? ' · ' + lead.source : ''}{lead.employee ? ' · ' + lead.employee : ''}{lead.next_followup_at ? ' · next ' + when(lead.next_followup_at) : ''}</Text>
          </Pressable>
        ))}
      </Card>
    </ScrollView>
  );
}
