// Stress: empuja hasta 300 VUs para ver dónde revienta.
// Útil DESPUÉS de pasar el baseline. Espera ver errores en algún punto.
//
//   k6 run loadtest/stress.js
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';

export const options = {
  scenarios: {
    stress: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '30s', target: 50 },
        { duration: '30s', target: 100 },
        { duration: '30s', target: 200 },
        { duration: '30s', target: 300 },
        { duration: '30s', target: 300 },
        { duration: '20s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.10'],
  },
};

const paths = [
  '/api/health',
  '/api/dashboard',
  '/api/exercises',
  '/api/plans',
  '/api/classes',
  '/api/membership-plans',
];

export default function () {
  const path = paths[Math.floor(Math.random() * paths.length)];
  const r = http.get(`${BASE}${path}`);
  check(r, { 'status 2xx': (res) => res.status >= 200 && res.status < 300 });
}
