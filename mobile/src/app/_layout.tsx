import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { AuthProvider, useAuth } from '@/lib/auth';
import { Loading } from '@/components/ui';

function RootStack() {
  const { ready, token } = useAuth();
  if (!ready) return <Loading />;
  const signedIn = token !== null;
  return (
    <Stack screenOptions={{ headerTintColor: '#2357d6', headerTitleStyle: { color: '#172033' } }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="customer/[id]" options={{ title: 'Customer' }} />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <AuthProvider>
      <StatusBar style="dark" />
      <RootStack />
    </AuthProvider>
  );
}
