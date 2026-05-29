// Smoke test: pocas VUs, valida que el backend responde correctamente.
// Si esto falla, no tiene sentido correr el resto.
//
//   k6 run loadtest/smoke.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export const options = {
  vus: 5,
  duration: '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
};

export default function () {
  const r = http.get(`${BASE}/api/health`);
  check(r, {
    'health 200': (res) => res.status === 200,
    'health body ok': (res) => res.json('status') === 'ok',
  });
  sleep(1);
}
