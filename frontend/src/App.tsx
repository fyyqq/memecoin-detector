import { useEffect, useState } from 'react'
import './App.css'

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8010'

type HealthState = 'checking' | 'ok' | 'error'

function App() {
  const [health, setHealth] = useState<HealthState>('checking')

  useEffect(() => {
    fetch(`${API_URL}/api/health`)
      .then((res) => res.json())
      .then((data) => setHealth(data.status === 'ok' ? 'ok' : 'error'))
      .catch(() => setHealth('error'))
  }, [])

  return (
    <main className="app">
      <h1>Memecoin Detector</h1>
      <p>Local development foundation</p>
      <p>
        API health:{' '}
        <strong data-status={health}>
          {health === 'checking' ? 'checking…' : health}
        </strong>
      </p>
      <p className="hint">
        API base URL: <code>{API_URL}</code>
      </p>
    </main>
  )
}

export default App
