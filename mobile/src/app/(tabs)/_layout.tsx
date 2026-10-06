import { Tabs } from 'expo-router';
import { Platform, Text, type ColorValue } from 'react-native';
import { useAuth } from '@/lib/auth';

function Glyph({ glyph, color }: { glyph: string; color: ColorValue }) {
  return <Text style={{ color, fontSize: 18 }}>{glyph}</Text>;
}

const icon = (glyph: string) => {
  const TabIcon = ({ color }: { color: ColorValue }) => <Glyph glyph={glyph} color={color} />;
  TabIcon.displayName = `TabIcon(${glyph})`;
  return TabIcon;
};

export default function TabsLayout() {
  const { can } = useAuth();
  return (
    <Tabs screenOptions={{ tabBarActiveTintColor: '#2357d6', headerTitleStyle: { color: '#172033' },
      // Browsers have no safe-area inset, so give the labels room on the web build.
      tabBarStyle: Platform.OS === 'web' ? { height: 62, paddingBottom: 8 } : undefined }}>
      <Tabs.Screen name="index" options={{ title: 'Dashboard', tabBarIcon: icon('▤') }} />
      <Tabs.Screen name="customers" options={{ title: 'Customers', tabBarIcon: icon('◉'), href: can('customers.view') ? undefined : null }} />
      <Tabs.Screen name="leads" options={{ title: 'Leads', tabBarIcon: icon('✦'), href: can('leads.view') ? undefined : null }} />
      <Tabs.Screen name="account" options={{ title: 'Account', tabBarIcon: icon('☰') }} />
    </Tabs>
  );
}
