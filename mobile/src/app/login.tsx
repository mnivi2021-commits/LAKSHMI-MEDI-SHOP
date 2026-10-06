import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/lib/auth';
import { Button, colors, ErrorBox, s } from '@/components/ui';

/** On the web build (served from <server>/marketing_crm/app) the CRM is the same site, so prefill it. */
function defaultServer(): string {
  if (Platform.OS !== 'web' || typeof window === 'undefined') return '';
  const i = window.location.pathname.indexOf('/app');
  return i >= 0 ? window.location.origin + window.location.pathname.slice(0, i) : '';
}

export default function Login() {
  const { signIn, server: savedServer } = useAuth();
  // The login screen only mounts after the saved session was read, so savedServer is final here.
  const [server, setServer] = useState(savedServer || defaultServer());
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (!server.trim() || !username.trim() || !password) {
      setError('Enter the server address, your username and password.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await signIn(server, username.trim(), password);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Sign-in failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={s.screen}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={[s.content, { paddingTop: 48 }]} keyboardShouldPersistTaps="handled">
          <View style={{ marginBottom: 12 }}>
            <Text style={{ fontSize: 26, fontWeight: '800', color: colors.text }}>Marketing CRM</Text>
            <Text style={s.sub}>Sign in with your CRM username. Use the same password as the web.</Text>
          </View>
          {error ? <ErrorBox message={error} /> : null}
          <View style={s.card}>
            <Text style={[s.label, { marginTop: 0 }]}>Server address</Text>
            <TextInput style={s.input} value={server} onChangeText={setServer} placeholder="192.168.29.12/marketing_crm" placeholderTextColor={colors.muted}
              autoCapitalize="none" autoCorrect={false} keyboardType="url" accessibilityLabel="Server address" />
            <Text style={s.sub}>The office PC address, as used in the browser on Wi-Fi.</Text>
            <Text style={s.label}>Username or email</Text>
            <TextInput style={s.input} value={username} onChangeText={setUsername} autoCapitalize="none" autoCorrect={false}
              textContentType="username" accessibilityLabel="Username" />
            <Text style={s.label}>Password</Text>
            <TextInput style={s.input} value={password} onChangeText={setPassword} secureTextEntry textContentType="password"
              onSubmitEditing={submit} accessibilityLabel="Password" />
            <View style={{ marginTop: 16 }}>
              <Button title={busy ? 'Signing in…' : 'Sign in'} onPress={submit} disabled={busy} />
            </View>
          </View>
          <Text style={[s.sub, { textAlign: 'center' }]}>First time? Sign in once on the web and change your temporary password.</Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
