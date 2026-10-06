import { ScrollView, Text } from 'react-native';
import { useAuth } from '@/lib/auth';
import { Button, Card, Row, s } from '@/components/ui';

export default function Account() {
  const { user, server, signOut } = useAuth();
  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      <Card title="Signed in">
        <Row label="Name" value={user?.name ?? '—'} />
        <Row label="Username" value={user?.username ?? '—'} />
        <Row label="Role" value={user?.role.name ?? '—'} />
        <Row label="Sees" value={{ all: 'All branches', branch: 'Own branches', team: 'Own team', own: 'Own records' }[user?.role.data_scope ?? ''] ?? '—'} />
        <Row label="Server" value={server.replace(/^https?:\/\//, '')} />
      </Card>
      <Button title="Sign out" kind="plain" onPress={signOut} />
      <Text style={[s.sub, { textAlign: 'center' }]}>Adding entries, imports and settings are on the web version.</Text>
    </ScrollView>
  );
}
