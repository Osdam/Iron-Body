// Baseline: mezcla realista de endpoints públicos (los que la app y el CRM
// golpean sin auth). Sube de 0 → 50 VUs gradualmente para ver dónde empieza
// a doler.
//
//   k6 run loadtest/baseline.js
//   k6 run -e BASE_URL=http://127.0.0.1:8000 loadtest/baseline.js
import http from 'k6/http';
import { check, group } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';

// Trends por endpoint para ver cuál se degrada primero.
const tHealth = new Trend('endpoint_health', true);
const tDashboard = new Trend('endpoint_dashboard', true);
const tExercises = new Trend('endpoint_exercises', true);
const tPlans = new Trend('endpoint_plans', true);
const tClasses = new Trend('endpoint_classes', true);
const tReports = new Trend('endpoint_reports_stats', true);
const tMembershipPlans = new Trend('endpoint_membership_plans', true);

export const options = {
  scenarios: {
    rampup: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '20s', target: 10 },
        { duration: '40s', target: 50 },
        { duration: '20s', target: 50 },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    'http_req_duration{type:read}': ['p(95)<1500'],
  },
};

function get(path, trend) {
  const r = http.get(`${BASE}${path}`, { tags: { type: 'read' } });
  trend.add(r.timings.duration);
  check(r, { [`${path} 2xx`]: (res) => res.status >= 200 && res.status < 300 });
  return r;
}

export default function () {
  group('catalog reads', () => {
    get('/api/health', tHealth);
    get('/api/dashboard', tDashboard);
    get('/api/exercises', tExercises);
    get('/api/plans', tPlans);
    get('/api/classes', tClasses);
    get('/api/membership-plans', tMembershipPlans);
    get('/api/reports/stats', tReports);
  });
}
