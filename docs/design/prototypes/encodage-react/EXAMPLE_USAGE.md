import React, { useState } from 'react';
import { EncodeScreen } from './src/EncodeScreen';
import './src/styles.css';

export default function App() {
  const [screen, setScreen] = useState<'app' | 'encode'>('encode');
  if (screen === 'app') return <div>Votre app…</div>;
  return (
    <EncodeScreen
      missionId="529"
      onBack={() => setScreen('app')}
      onValidated={() => { alert('Mission clôturée.'); setScreen('app'); }}
    />
  );
}
