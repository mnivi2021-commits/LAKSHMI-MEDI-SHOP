import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, Text, TextInput, View } from 'react-native';
import { router } from 'expo-router';
import { useAuth } from '@/lib/auth';
import { inr } from '@/lib/api';
import { colors, ErrorBox, Loading, s } from '@/components/ui';

type Customer = { id: number; customer_code: string; name: string; mobile: string | null; city: string | null; branch_code: string; employee: string | null; outstanding: string };

export default function Customers() {
  const { get } = useAuth();
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<Customer[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (term: string) => {
    setError(null);
    try {
      const d = await get<{ customers: Customer[] }>('/api/customers?q=' + encodeURIComponent(term));
      setRows(d.customers);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not load customers.');
    }
  }, [get]);

  // Search as the user types, but wait for a short pause.
  useEffect(() => {
    const t = setTimeout(() => load(q), 300);
    return () => clearTimeout(t);
  }, [q, load]);

  return (
    <View style={s.screen}>
      <View style={{ padding: 16, paddingBottom: 8 }}>
        <TextInput style={s.input} value={q} onChangeText={setQ} placeholder="Search name, code, mobile, city" placeholderTextColor={colors.muted} autoCorrect={false} accessibilityLabel="Search customers" />
      </View>
      {error ? <View style={{ paddingHorizontal: 16 }}><ErrorBox message={error} onRetry={() => load(q)} /></View> : null}
      {rows === null && !error ? <Loading /> : (
        <FlatList
          data={rows ?? []}
          keyExtractor={(c) => String(c.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4, gap: 8 }}
          ListEmptyComponent={<Text style={[s.sub, { textAlign: 'center', marginTop: 24 }]}>No customers found.</Text>}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/customer/${item.id}`)} style={({ pressed }) => [s.card, pressed && { opacity: 0.7 }]}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontWeight: '700', color: colors.text }}>{item.name}</Text>
                  <Text style={s.sub}>{item.customer_code} · {item.city ?? '—'} · {item.branch_code}{item.employee ? ' · ' + item.employee : ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={{ fontWeight: '700', color: Number(item.outstanding) > 0 ? colors.fail : colors.muted }}>{inr(item.outstanding)}</Text>
                  <Text style={s.sub}>outstanding</Text>
                </View>
              </View>
            </Pressable>
          )}
        />
      )}
    </View>
  );
}
