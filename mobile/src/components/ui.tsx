import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View, type ViewStyle } from 'react-native';

export const colors = {
  bg: '#f4f6fa',
  surface: '#ffffff',
  border: '#e2e7ef',
  text: '#172033',
  muted: '#64708a',
  primary: '#2357d6',
  ok: '#15803d',
  warn: '#b45309',
  fail: '#b91c1c',
};

export function Card({ title, right, children, style }: { title?: string; right?: ReactNode; children: ReactNode; style?: ViewStyle }) {
  return (
    <View style={[s.card, style]}>
      {title ? (
        <View style={s.cardHead}>
          <Text style={s.cardTitle}>{title}</Text>
          {right}
        </View>
      ) : null}
      {children}
    </View>
  );
}

export function Row({ label, value, tone, sub }: { label: string; value: string; tone?: 'ok' | 'warn' | 'fail'; sub?: string }) {
  return (
    <View style={s.row}>
      <View style={{ flex: 1 }}>
        <Text style={s.rowLabel}>{label}</Text>
        {sub ? <Text style={s.sub}>{sub}</Text> : null}
      </View>
      <Text style={[s.rowValue, tone ? { color: colors[tone] } : null]}>{value}</Text>
    </View>
  );
}

export function Big({ value, caption }: { value: string; caption?: string }) {
  return (
    <View style={{ marginBottom: 8 }}>
      <Text style={s.big}>{value}</Text>
      {caption ? <Text style={s.sub}>{caption}</Text> : null}
    </View>
  );
}

export function Button({ title, onPress, disabled, kind = 'primary' }: { title: string; onPress: () => void; disabled?: boolean; kind?: 'primary' | 'plain' }) {
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [s.btn, kind === 'plain' ? s.btnPlain : null, (pressed || disabled) && { opacity: 0.6 }]}
    >
      <Text style={[s.btnText, kind === 'plain' ? { color: colors.primary } : null]}>{title}</Text>
    </Pressable>
  );
}

export function Loading() {
  return (
    <View style={s.center}>
      <ActivityIndicator color={colors.primary} />
    </View>
  );
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={[s.card, { borderColor: '#f3c4c4' }]}>
      <Text style={{ color: colors.fail, marginBottom: onRetry ? 10 : 0 }}>{message}</Text>
      {onRetry ? <Button title="Try again" kind="plain" onPress={onRetry} /> : null}
    </View>
  );
}

export function Badge({ text, tone = 'muted' }: { text: string; tone?: 'ok' | 'warn' | 'fail' | 'muted' | 'info' }) {
  const bg = { ok: '#dcfce7', warn: '#fef3c7', fail: '#fee2e2', muted: '#eef1f6', info: '#dbe6ff' }[tone];
  const fg = { ok: colors.ok, warn: colors.warn, fail: colors.fail, muted: colors.muted, info: colors.primary }[tone];
  return <Text style={[s.badge, { backgroundColor: bg, color: fg }]}>{text}</Text>;
}

export const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40, gap: 12 },
  card: { backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 16 },
  cardHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 },
  cardTitle: { fontSize: 15, fontWeight: '700', color: colors.text },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border, gap: 12 },
  rowLabel: { color: colors.text, fontSize: 14 },
  rowValue: { color: colors.text, fontSize: 14, fontWeight: '600', fontVariant: ['tabular-nums'] },
  sub: { color: colors.muted, fontSize: 12, marginTop: 2 },
  big: { fontSize: 28, fontWeight: '800', color: colors.text, fontVariant: ['tabular-nums'] },
  btn: { backgroundColor: colors.primary, borderRadius: 10, paddingVertical: 13, alignItems: 'center' },
  btnPlain: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.border },
  btnText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  badge: { alignSelf: 'flex-start', fontSize: 11, fontWeight: '700', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, overflow: 'hidden' },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 12, fontSize: 16, color: colors.text },
  label: { color: colors.text, fontWeight: '600', marginBottom: 6, marginTop: 12 },
});
